<?php
/**
 * Tests for tools/keygen.php (run as a separate CLI process).
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * License key tool tests.
 */
class KeygenTest extends TestCase {

	/**
	 * Temporary directory for key files.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Key file path inside $dir.
	 *
	 * @var string
	 */
	private $key_file;

	protected function setUp(): void {
		hds_test_reset();
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is disabled.' );
		}
		$this->dir      = sys_get_temp_dir() . '/hds-keygen-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		$this->key_file = $this->dir . '/private.key';
		mkdir( $this->dir, 0700, true );
	}

	protected function tearDown(): void {
		if ( $this->dir && is_dir( $this->dir ) ) {
			foreach ( array_diff( (array) scandir( $this->dir ), array( '.', '..' ) ) as $file ) {
				if ( is_file( $this->dir . '/' . $file ) ) {
					unlink( $this->dir . '/' . $file );
				}
			}
			rmdir( $this->dir );
		}
	}

	/**
	 * Run the tool.
	 *
	 * @param string[] $args Arguments.
	 * @return array{code: int, out: string, err: string}
	 */
	private function run_tool( array $args ): array {
		$cmd   = array_merge( array( PHP_BINARY, HDS_PLUGIN_DIR . 'tools/keygen.php' ), $args );
		$pipes = array();
		$proc  = proc_open(
			$cmd,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->dir
		);
		$this->assertIsResource( $proc );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );
		return array(
			'code' => $code,
			'out'  => (string) $out,
			'err'  => (string) $err,
		);
	}

	/**
	 * Run init and return the printed public key.
	 *
	 * @return string
	 */
	private function init(): string {
		$result = $this->run_tool( array( 'init', '--key-file=' . $this->key_file ) );
		$this->assertSame( 0, $result['code'], $result['err'] );
		$this->assertMatchesRegularExpression( "/^\\s*const PUBLIC_KEY = '([A-Za-z0-9+\\/]{43}=)';$/m", $result['out'] );
		preg_match( "/const PUBLIC_KEY = '([^']+)';/", $result['out'], $m );
		return $m[1];
	}

	/**
	 * Sign a domain and return [domain, key].
	 *
	 * @param string $target Domain or URL.
	 * @return string[]
	 */
	private function sign( string $target ): array {
		$result = $this->run_tool( array( 'sign', $target, '--key-file=' . $this->key_file ) );
		$this->assertSame( 0, $result['code'], $result['err'] );
		$this->assertMatchesRegularExpression( '/^Domain: (\S+)$/m', $result['out'] );
		$this->assertMatchesRegularExpression( '/^License key: ([A-Za-z0-9+\/]{86}==)$/m', $result['out'] );
		preg_match( '/^Domain: (\S+)$/m', $result['out'], $d );
		preg_match( '/^License key: (\S+)$/m', $result['out'], $k );
		return array( $d[1], $k[1] );
	}

	public function test_init_sign_verify_round_trip(): void {
		$public = $this->init();

		// Key file: base64 of the 64-byte secret, mode 0600, matching the printed public key.
		$this->assertFileExists( $this->key_file );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $this->key_file ) ), -4 ) );
		$secret = base64_decode( trim( (string) file_get_contents( $this->key_file ) ), true );
		$this->assertSame( 64, strlen( (string) $secret ) );
		$this->assertSame( $public, base64_encode( sodium_crypto_sign_publickey_from_secretkey( $secret ) ) );

		list( $domain, $key ) = $this->sign( 'example.co.il' );
		$this->assertSame( 'example.co.il', $domain );
		$this->assertTrue( HDS_License::verify( $key, 'example.co.il', $public ) );

		// Any URL form gives the same key (Ed25519 is deterministic).
		list( $domain2, $key2 ) = $this->sign( 'https://www.example.co.il/' );
		$this->assertSame( 'example.co.il', $domain2 );
		$this->assertSame( $key, $key2 );

		// Staging is a different domain.
		list( $staging_domain, $staging ) = $this->sign( 'https://staging.example.co.il/' );
		$this->assertSame( 'staging.example.co.il', $staging_domain );
		$this->assertNotSame( $key, $staging );
		$this->assertTrue( HDS_License::verify( $staging, 'staging.example.co.il', $public ) );
		$this->assertFalse( HDS_License::verify( $staging, 'example.co.il', $public ) );

		// is_active() with the generated public key.
		$GLOBALS['hds_test']['home_url']                = 'https://www.example.co.il';
		$GLOBALS['hds_test']['options']['hds_settings'] = array( 'license_key' => $key );
		HDS_License::reset_cache();
		$this->assertTrue( HDS_License::is_active( $public ) );
		HDS_License::reset_cache();
	}

	public function test_verify_command(): void {
		$this->init();
		list( , $key ) = $this->sign( 'example.co.il' );

		$ok = $this->run_tool( array( 'verify', 'https://www.example.co.il/', $key, '--key-file=' . $this->key_file ) );
		$this->assertSame( 0, $ok['code'] );
		$this->assertStringContainsString( 'OK', $ok['out'] );

		$fail = $this->run_tool( array( 'verify', 'staging.example.co.il', $key, '--key-file=' . $this->key_file ) );
		$this->assertSame( 1, $fail['code'] );
		$this->assertStringContainsString( 'FAIL', $fail['out'] );

		$garbage = $this->run_tool( array( 'verify', 'example.co.il', 'garbage', '--key-file=' . $this->key_file ) );
		$this->assertSame( 1, $garbage['code'] );
		$this->assertStringContainsString( 'FAIL', $garbage['out'] );
	}

	public function test_init_refuses_to_overwrite_without_force(): void {
		$public   = $this->init();
		$original = file_get_contents( $this->key_file );

		$again = $this->run_tool( array( 'init', '--key-file=' . $this->key_file ) );
		$this->assertNotSame( 0, $again['code'] );
		$this->assertStringContainsString( 'already exists', $again['err'] );
		$this->assertSame( $original, file_get_contents( $this->key_file ), 'Key must be untouched.' );

		$forced = $this->run_tool( array( 'init', '--force', '--key-file=' . $this->key_file ) );
		$this->assertSame( 0, $forced['code'], $forced['err'] );
		$this->assertNotSame( $original, file_get_contents( $this->key_file ) );
		$this->assertStringNotContainsString( $public, $forced['out'] );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $this->key_file ) ), -4 ) );
		// No temporary files left behind.
		$this->assertSame( array( 'private.key' ), array_values( array_diff( scandir( $this->dir ), array( '.', '..' ) ) ) );
	}

	public function test_init_output_mentions_backup_and_paste_line(): void {
		$result = $this->run_tool( array( 'init', '--key-file', $this->key_file ) );
		$this->assertSame( 0, $result['code'], $result['err'] );
		$this->assertStringContainsString( 'BACK IT UP', $result['out'] );
		$this->assertStringContainsString( 'includes/class-hds-license.php', $result['out'] );
		$this->assertStringContainsString( $this->key_file, $result['out'] );

		$pub = $this->run_tool( array( 'pubkey', '--key-file=' . $this->key_file ) );
		$this->assertSame( 0, $pub['code'] );
		preg_match( "/const PUBLIC_KEY = '([^']+)';/", $result['out'], $a );
		preg_match( "/const PUBLIC_KEY = '([^']+)';/", $pub['out'], $b );
		$this->assertSame( $a[1], $b[1] );
	}

	public function test_errors_and_usage(): void {
		$help = $this->run_tool( array( 'help' ) );
		$this->assertSame( 0, $help['code'] );
		$this->assertStringContainsString( 'Usage:', $help['out'] );
		foreach ( array( 'init', 'sign', 'verify' ) as $command ) {
			$this->assertStringContainsString( 'keygen.php ' . $command, $help['out'] );
		}

		$none = $this->run_tool( array() );
		$this->assertNotSame( 0, $none['code'] );
		$this->assertStringContainsString( 'Usage:', $none['err'] );

		$unknown = $this->run_tool( array( 'frobnicate' ) );
		$this->assertNotSame( 0, $unknown['code'] );
		$this->assertStringContainsString( 'Unknown command', $unknown['err'] );

		$bad_option = $this->run_tool( array( 'init', '--forse', '--key-file=' . $this->key_file ) );
		$this->assertNotSame( 0, $bad_option['code'] );
		$this->assertFileDoesNotExist( $this->key_file );

		// Sign without a key file.
		$missing = $this->run_tool( array( 'sign', 'example.co.il', '--key-file=' . $this->key_file ) );
		$this->assertSame( 1, $missing['code'] );
		$this->assertStringContainsString( 'not found', $missing['err'] );
		$this->assertSame( '', $missing['out'] );

		// Missing argument.
		$this->init();
		$no_domain = $this->run_tool( array( 'sign', '--key-file=' . $this->key_file ) );
		$this->assertNotSame( 0, $no_domain['code'] );

		// Invalid domain.
		$invalid = $this->run_tool( array( 'sign', 'not a domain', '--key-file=' . $this->key_file ) );
		$this->assertSame( 1, $invalid['code'] );
		$this->assertSame( '', $invalid['out'] );

		// Corrupt key file.
		file_put_contents( $this->key_file, "not-a-key\n" );
		$corrupt = $this->run_tool( array( 'sign', 'example.co.il', '--key-file=' . $this->key_file ) );
		$this->assertSame( 1, $corrupt['code'] );
		$this->assertStringContainsString( 'not a valid private key', $corrupt['err'] );
	}

	public function test_refuses_web_sapi(): void {
		$source = (string) file_get_contents( HDS_PLUGIN_DIR . 'tools/keygen.php' );
		$this->assertMatchesRegularExpression( "/if \\( 'cli' !== PHP_SAPI \\) \\{\\s*exit;/", $source );
		$this->assertLessThan( strpos( $source, 'require_once' ), strpos( $source, "'cli' !== PHP_SAPI" ), 'The SAPI check must come first.' );
	}
}
