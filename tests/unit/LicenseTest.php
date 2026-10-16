<?php
/**
 * Tests for HDS_License.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * License tests.
 */
class LicenseTest extends TestCase {

	/**
	 * Secret key (binary) of the test key pair.
	 *
	 * @var string
	 */
	private static $secret;

	/**
	 * Base64 public key of the test key pair.
	 *
	 * @var string
	 */
	private static $public;

	public static function setUpBeforeClass(): void {
		$pair         = sodium_crypto_sign_keypair();
		self::$secret = sodium_crypto_sign_secretkey( $pair );
		self::$public = base64_encode( sodium_crypto_sign_publickey( $pair ) );
	}

	protected function setUp(): void {
		hds_test_reset();
		HDS_License::reset_cache();
	}

	protected function tearDown(): void {
		HDS_License::reset_cache();
	}

	/**
	 * Issue a license the way tools/keygen.php does.
	 *
	 * @param string      $domain Domain or URL.
	 * @param string|null $secret Secret key (default: the test key).
	 * @return string
	 */
	private function issue( string $domain, ?string $secret = null ): string {
		return base64_encode( sodium_crypto_sign_detached( HDS_License::normalize_domain( $domain ), $secret ?? self::$secret ) );
	}

	/**
	 * Store a license key in the settings option.
	 *
	 * @param string $key License key.
	 * @return void
	 */
	private function store_key( string $key ): void {
		$GLOBALS['hds_test']['options']['hds_settings'] = array( 'license_key' => $key );
	}

	public function test_constants(): void {
		$this->assertSame( 15, HDS_License::DEMO_DOC_LIMIT );
		$this->assertIsString( HDS_License::PUBLIC_KEY );
		$this->assertSame( HDS_License::PUBLIC_KEY, HDS_License::public_key() );
		if ( '' !== HDS_License::PUBLIC_KEY ) {
			$this->assertSame( 32, strlen( (string) base64_decode( HDS_License::PUBLIC_KEY, true ) ), 'PUBLIC_KEY must be base64 of 32 bytes.' );
		}
	}

	/**
	 * @dataProvider domain_provider
	 *
	 * @param string $input    Input.
	 * @param string $expected Expected.
	 */
	public function test_normalize_domain( string $input, string $expected ): void {
		$this->assertSame( $expected, HDS_License::normalize_domain( $input ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function domain_provider(): array {
		return array(
			'https www trailing slash' => array( 'https://www.Example.co.il/', 'example.co.il' ),
			'port path query'          => array( 'http://example.co.il:8080/path?x', 'example.co.il' ),
			'contract example'         => array( 'https://www.Example.co.il:8080/x', 'example.co.il' ),
			'uppercase trailing dot'   => array( 'EXAMPLE.CO.IL.', 'example.co.il' ),
			'one www only'             => array( 'www.staging.example.co.il', 'staging.example.co.il' ),
			'subdomain kept'           => array( 'staging.example.co.il', 'staging.example.co.il' ),
			'www without dot'          => array( 'wwwexample.com', 'wwwexample.com' ),
			'only first www'           => array( 'www.www.example.com', 'www.example.com' ),
			'bare host'                => array( 'example.co.il', 'example.co.il' ),
			'whitespace'               => array( "  https://example.co.il/ \n", 'example.co.il' ),
			'userinfo'                 => array( 'https://user:pass@www.example.co.il:443/a/b', 'example.co.il' ),
			'protocol relative'        => array( '//www.example.co.il/path', 'example.co.il' ),
			'host with port'           => array( 'example.co.il:8443', 'example.co.il' ),
			'fragment'                 => array( 'https://example.co.il#top', 'example.co.il' ),
			'multisite subdir'         => array( 'https://example.co.il/site2/', 'example.co.il' ),
			'ip address'               => array( 'http://192.168.1.10:8080/', '192.168.1.10' ),
			'ipv6 literal'             => array( 'http://[2001:db8::1]:8080/', '[2001:db8::1]' ),
			'localhost'                => array( 'http://localhost:8888', 'localhost' ),
			'empty'                    => array( '', '' ),
			'whitespace only'          => array( " \t\n", '' ),
			'scheme only'              => array( 'https://', '' ),
			'garbage'                  => array( 'not a domain!', '' ),
			'html'                     => array( '<script>x</script>', '' ),
		);
	}

	public function test_normalize_idn(): void {
		$hebrew = "https://www.\u{05D3}\u{05D5}\u{05D2}\u{05DE}\u{05D4}.co.il/";
		$result = HDS_License::normalize_domain( $hebrew );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$this->assertStringStartsWith( 'xn--', $result );
			$this->assertStringEndsWith( '.co.il', $result );
			$this->assertSame( $result, HDS_License::normalize_domain( $result ), 'Punycode input is already normalized.' );
			$this->assertSame( $result, HDS_License::normalize_domain( 'WWW.' . strtoupper( $result ) ) );
		} else {
			$this->assertSame( "\u{05D3}\u{05D5}\u{05D2}\u{05DE}\u{05D4}.co.il", $result );
		}
		// Latin IDN with uppercase.
		if ( function_exists( 'idn_to_ascii' ) ) {
			$this->assertSame( 'xn--mnchen-3ya.de', HDS_License::normalize_domain( 'MÜNCHEN.de' ) );
		}
	}

	public function test_normalize_is_idempotent_except_for_repeated_www(): void {
		foreach ( $this->domain_provider() as $name => $case ) {
			if ( 'only first www' === $name ) {
				continue;
			}
			$once = HDS_License::normalize_domain( $case[0] );
			$this->assertSame( $once, HDS_License::normalize_domain( $once ), $name );
		}
	}

	public function test_is_active_does_not_normalize_twice(): void {
		// home_url www.www.example.com → licensed domain www.example.com (one www stripped).
		$GLOBALS['hds_test']['home_url'] = 'https://www.www.example.com';
		$this->assertSame( 'www.example.com', HDS_License::current_domain() );
		$this->store_key( $this->issue( 'https://www.www.example.com/' ) );
		$this->assertTrue( HDS_License::is_active( self::$public ) );
	}

	public function test_current_domain_uses_home_url(): void {
		$GLOBALS['hds_test']['home_url'] = 'https://www.Kesem-Law.co.il/he/';
		$this->assertSame( 'kesem-law.co.il', HDS_License::current_domain() );
	}

	public function test_verify_valid_key(): void {
		$key = $this->issue( 'example.co.il' );
		$this->assertSame( 88, strlen( $key ) );
		$this->assertTrue( HDS_License::verify( $key, 'example.co.il', self::$public ) );
		// Any URL form of the same domain.
		$this->assertTrue( HDS_License::verify( $key, 'https://www.Example.co.il/', self::$public ) );
		$this->assertTrue( HDS_License::verify( $key, 'http://example.co.il:8080/path?x', self::$public ) );
		// Key issued from a URL form verifies on the bare domain.
		$this->assertSame( $key, $this->issue( 'https://www.example.co.il/' ) );
	}

	public function test_signed_message_is_exactly_the_normalized_domain(): void {
		$key = base64_encode( sodium_crypto_sign_detached( 'example.co.il', self::$secret ) );
		$this->assertTrue( HDS_License::verify( $key, 'example.co.il', self::$public ) );
		$bad = base64_encode( sodium_crypto_sign_detached( 'example.co.il|extra', self::$secret ) );
		$this->assertFalse( HDS_License::verify( $bad, 'example.co.il', self::$public ) );
		$www = base64_encode( sodium_crypto_sign_detached( 'www.example.co.il', self::$secret ) );
		$this->assertFalse( HDS_License::verify( $www, 'example.co.il', self::$public ) );
	}

	public function test_verify_rejects_other_domains(): void {
		$key = $this->issue( 'example.co.il' );
		$this->assertFalse( HDS_License::verify( $key, 'example.com', self::$public ) );
		$this->assertFalse( HDS_License::verify( $key, 'other.co.il', self::$public ) );
		$this->assertFalse( HDS_License::verify( $key, 'staging.example.co.il', self::$public ) );
		$this->assertFalse( HDS_License::verify( $key, 'example.co.il.evil.com', self::$public ) );
		$this->assertFalse( HDS_License::verify( $key, '', self::$public ) );

		// A staging key does not work on production and vice versa.
		$staging = $this->issue( 'staging.example.co.il' );
		$this->assertNotSame( $key, $staging );
		$this->assertTrue( HDS_License::verify( $staging, 'https://staging.example.co.il/', self::$public ) );
		$this->assertFalse( HDS_License::verify( $staging, 'example.co.il', self::$public ) );
		$this->assertFalse( HDS_License::verify( $staging, 'https://www.example.co.il', self::$public ) );
	}

	public function test_verify_rejects_garbage_keys(): void {
		$valid = $this->issue( 'example.co.il' );
		$cases = array(
			'',
			'   ',
			'garbage',
			'!!!!' . substr( $valid, 4 ),
			substr( $valid, 0, 40 ),                       // Too short.
			base64_encode( str_repeat( "\0", 63 ) ),       // Wrong length.
			base64_encode( str_repeat( "\0", 65 ) ),
			base64_encode( str_repeat( "\0", 64 ) ),       // Right length, invalid signature.
			base64_encode( random_bytes( 64 ) ),
			$valid . $valid,
			str_repeat( 'A', 100000 ),
			"\xFF\xFE" . $valid,
		);
		// Flip one character of a valid key.
		$flipped    = $valid;
		$flipped[5] = 'A' === $flipped[5] ? 'B' : 'A';
		$cases[]    = $flipped;

		foreach ( $cases as $i => $key ) {
			$this->assertFalse( HDS_License::verify( $key, 'example.co.il', self::$public ), "Case $i must be rejected." );
		}
	}

	public function test_verify_tolerates_whitespace_and_newlines(): void {
		$key = $this->issue( 'example.co.il' );
		$this->assertTrue( HDS_License::verify( "  \n" . $key . "\r\n\t", 'example.co.il', self::$public ) );
		$this->assertTrue( HDS_License::verify( chunk_split( $key, 20, "\n" ), 'example.co.il', self::$public ) );
		$this->assertTrue( HDS_License::verify( $key, 'example.co.il', ' ' . self::$public . "\n" ) );
	}

	public function test_verify_rejects_wrong_or_invalid_public_key(): void {
		$key   = $this->issue( 'example.co.il' );
		$other = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
		$this->assertFalse( HDS_License::verify( $key, 'example.co.il', $other ) );
		$this->assertFalse( HDS_License::verify( $key, 'example.co.il', 'not-base64!' ) );
		$this->assertFalse( HDS_License::verify( $key, 'example.co.il', base64_encode( 'short' ) ) );
		$this->assertFalse( HDS_License::verify( $key, 'example.co.il', base64_encode( str_repeat( "\0", 32 ) ) ) );

		// A key signed by another private key fails with our public key.
		$pair    = sodium_crypto_sign_keypair();
		$foreign = $this->issue( 'example.co.il', sodium_crypto_sign_secretkey( $pair ) );
		$this->assertFalse( HDS_License::verify( $foreign, 'example.co.il', self::$public ) );
	}

	public function test_verify_with_default_public_key(): void {
		$key = $this->issue( 'example.co.il' );
		if ( '' === HDS_License::PUBLIC_KEY ) {
			// No public key configured yet: nothing can be valid.
			$this->assertFalse( HDS_License::verify( $key, 'example.co.il' ) );
			$this->assertFalse( HDS_License::verify( $key, 'example.co.il', '' ) );
		} else {
			// Our random test key pair is not the vendor's.
			$this->assertFalse( HDS_License::verify( $key, 'example.co.il' ) );
		}
	}

	public function test_is_active_and_is_demo(): void {
		$GLOBALS['hds_test']['home_url'] = 'https://www.example.co.il';

		// No key stored.
		$this->assertFalse( HDS_License::is_active( self::$public ) );
		$this->assertTrue( HDS_License::is_demo( self::$public ) );

		// Valid key for this domain.
		$this->store_key( "\n" . $this->issue( 'example.co.il' ) . ' ' );
		$this->assertTrue( HDS_License::is_active( self::$public ) );
		$this->assertFalse( HDS_License::is_demo( self::$public ) );

		// Same key, but the built-in public key (empty or the vendor's) does not match.
		$this->assertFalse( HDS_License::is_active() );
		$this->assertTrue( HDS_License::is_demo() );

		// Key of another domain.
		$this->store_key( $this->issue( 'other.co.il' ) );
		$this->assertFalse( HDS_License::is_active( self::$public ) );

		// Staging key on production.
		$this->store_key( $this->issue( 'staging.example.co.il' ) );
		$this->assertFalse( HDS_License::is_active( self::$public ) );
		$GLOBALS['hds_test']['home_url'] = 'https://staging.example.co.il/';
		$this->assertTrue( HDS_License::is_active( self::$public ) );

		// Garbage.
		$this->store_key( 'garbage' );
		$this->assertFalse( HDS_License::is_active( self::$public ) );

		// Non-string setting.
		$GLOBALS['hds_test']['options']['hds_settings'] = array( 'license_key' => array( 'x' ) );
		$this->assertFalse( HDS_License::is_active( self::$public ) );
	}

	public function test_is_active_cache_follows_settings_and_reset(): void {
		$GLOBALS['hds_test']['home_url'] = 'https://example.co.il';
		$this->store_key( $this->issue( 'example.co.il' ) );
		$this->assertTrue( HDS_License::is_active( self::$public ) );
		$this->assertTrue( HDS_License::is_active( self::$public ) );

		// The key changes within the same request: picked up at once.
		$this->store_key( $this->issue( 'other.co.il' ) );
		$this->assertFalse( HDS_License::is_active( self::$public ) );

		HDS_License::reset_cache();
		$this->store_key( $this->issue( 'example.co.il' ) );
		$this->assertTrue( HDS_License::is_active( self::$public ) );

		// The site moves to another domain.
		$GLOBALS['hds_test']['home_url'] = 'https://example.com';
		$this->assertFalse( HDS_License::is_active( self::$public ) );
	}

	public function test_no_filters_can_bypass_the_check(): void {
		$GLOBALS['hds_test']['home_url'] = 'https://example.co.il';
		$this->store_key( 'garbage' );
		$always_true = static function () {
			return true;
		};
		foreach ( array( 'hds_license_active', 'hds_is_active', 'hds_is_demo', 'hds_license_key', 'hds_license_public_key', 'pre_option_hds_settings' ) as $tag ) {
			$GLOBALS['hds_test']['filters'][ $tag ][] = $always_true;
		}
		$this->assertFalse( HDS_License::is_active( self::$public ) );
		$this->assertTrue( HDS_License::is_demo( self::$public ) );

		$source = (string) file_get_contents( HDS_PLUGIN_DIR . 'includes/class-hds-license.php' );
		$this->assertDoesNotMatchRegularExpression( '/\b(apply_filters|do_action|add_filter|wp_remote_|curl_|file_get_contents|date\(|strtotime|time\()/', $source );
		$this->assertMatchesRegularExpression( "/const PUBLIC_KEY = '[A-Za-z0-9+\\/=]*';/", $source, 'build.sh greps this exact line.' );
	}
}
