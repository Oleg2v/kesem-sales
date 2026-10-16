<?php
/**
 * Tests for HDS_Settings.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Settings tests.
 */
class SettingsTest extends TestCase {

	const KEY = 'sk-proj-abcdefghijklmnopqrstuvwxyz0123456789';

	protected function setUp(): void {
		hds_test_reset();
		hds_test_create_tables();
		HDS_License::reset_cache();
	}

	protected function tearDown(): void {
		HDS_License::reset_cache();
	}

	/**
	 * Settings errors recorded by the stub.
	 *
	 * @return array[]
	 */
	private function errors(): array {
		return isset( $GLOBALS['hds_test']['settings_errors'] ) ? $GLOBALS['hds_test']['settings_errors'] : array();
	}

	/**
	 * Codes of the recorded settings errors.
	 *
	 * @return string[]
	 */
	private function error_codes(): array {
		return array_column( $this->errors(), 'code' );
	}

	public function test_defaults_and_all(): void {
		$this->assertSame( HDS_Settings::DEFAULTS, HDS_Settings::defaults() );
		$this->assertSame( HDS_Settings::DEFAULTS, HDS_Settings::all() );
		$this->assertSame( 'text-embedding-3-small', HDS_Settings::get( 'embedding_model' ) );
		$this->assertSame( 5, HDS_Settings::get( 'top_n' ) );
		$this->assertNull( HDS_Settings::get( 'nope' ) );
	}

	public function test_all_casts_types_and_ignores_garbage(): void {
		update_option(
			HDS_Settings::OPTION,
			array(
				'top_n'      => '7',
				'rate_limit' => 'abc',
				'chat_model' => array( 'x' ),
				'unknown'    => 'value',
			)
		);
		$all = HDS_Settings::all();
		$this->assertSame( 7, $all['top_n'] );
		$this->assertSame( 10, $all['rate_limit'] );
		$this->assertSame( 'gpt-4o-mini', $all['chat_model'] );
		$this->assertArrayNotHasKey( 'unknown', $all );

		update_option( HDS_Settings::OPTION, 'corrupted' );
		$this->assertSame( HDS_Settings::DEFAULTS, HDS_Settings::all() );
	}

	public function test_update_merges_known_keys(): void {
		HDS_Settings::update(
			array(
				'top_n' => 8,
				'bogus' => 1,
			)
		);
		$stored = get_option( HDS_Settings::OPTION );
		$this->assertSame( 8, $stored['top_n'] );
		$this->assertArrayNotHasKey( 'bogus', $stored );
		$this->assertSame( 'gpt-4o-mini', $stored['chat_model'] );
	}

	public function test_sanitize_non_array_returns_current(): void {
		HDS_Settings::update( array( 'top_n' => 9 ) );
		$this->assertSame( 9, HDS_Settings::sanitize( null )['top_n'] );
		$this->assertSame( 9, HDS_Settings::sanitize( 'x' )['top_n'] );
	}

	public function test_sanitize_models(): void {
		$out = HDS_Settings::sanitize(
			array(
				'embedding_model' => ' text-embedding-3-large ',
				'chat_model'      => 'gpt-4.1-mini',
			)
		);
		$this->assertSame( 'text-embedding-3-large', $out['embedding_model'] );
		$this->assertSame( 'gpt-4.1-mini', $out['chat_model'] );
		$this->assertSame( array(), $this->errors() );

		$out = HDS_Settings::sanitize(
			array(
				'embedding_model' => 'bad model; drop',
				'chat_model'      => '<script>',
			)
		);
		$this->assertSame( 'text-embedding-3-small', $out['embedding_model'] );
		$this->assertSame( 'gpt-4o-mini', $out['chat_model'] );
		$this->assertContains( 'hds_invalid_embedding_model', $this->error_codes() );
		$this->assertContains( 'hds_invalid_chat_model', $this->error_codes() );
	}

	public function test_sanitize_clamps_numbers(): void {
		$out = HDS_Settings::sanitize(
			array(
				'top_n'       => '0',
				'rate_limit'  => '5000',
				'max_file_mb' => '9999',
			)
		);
		$this->assertSame( 1, $out['top_n'] );
		$this->assertSame( 1000, $out['rate_limit'] );
		$this->assertSame( 64, $out['max_file_mb'] ); // wp_max_upload_size() stub: 64 MB.

		$out = HDS_Settings::sanitize(
			array(
				'top_n'       => '50',
				'rate_limit'  => '-3',
				'max_file_mb' => 'abc',
			)
		);
		$this->assertSame( 20, $out['top_n'] );
		$this->assertSame( 1, $out['rate_limit'] );
		$this->assertSame( 20, $out['max_file_mb'] );

		$out = HDS_Settings::sanitize( array( 'top_n' => '12' ) );
		$this->assertSame( 12, $out['top_n'] );
	}

	public function test_api_key_is_encrypted_and_never_returned(): void {
		$out = HDS_Settings::sanitize( array( 'api_key_new' => '  ' . self::KEY . "\n" ) );
		$this->assertSame( array_keys( HDS_Settings::DEFAULTS ), array_keys( $out ) );
		$this->assertNotSame( '', $out['api_key_enc'] );
		$this->assertStringNotContainsString( self::KEY, serialize( $out ) );
		$this->assertStringNotContainsString( 'abcdefghijklmnop', serialize( $out ) );

		update_option( HDS_Settings::OPTION, $out );
		$this->assertSame( self::KEY, HDS_Settings::get_api_key() );
		$this->assertSame( 'option', HDS_Settings::api_key_source() );
		$this->assertSame( 'sk-…6789', HDS_Settings::mask_api_key() );
	}

	public function test_sanitize_is_idempotent_when_called_twice(): void {
		$first = HDS_Settings::sanitize(
			array(
				'api_key_new' => self::KEY,
				'top_n'       => '3',
				'chat_model'  => 'gpt-4o',
			)
		);
		// WordPress passes the sanitized value through the callback again when the option is created.
		$second = HDS_Settings::sanitize( $first );
		$this->assertSame( $first, $second );

		update_option( HDS_Settings::OPTION, $second );
		$this->assertSame( self::KEY, HDS_Settings::get_api_key() );

		// A later form save without a new key keeps the stored key.
		$third = HDS_Settings::sanitize(
			array(
				'api_key_new' => '',
				'top_n'       => '4',
			)
		);
		$this->assertSame( $first['api_key_enc'], $third['api_key_enc'] );
		$this->assertSame( 4, $third['top_n'] );
	}

	public function test_invalid_api_key_keeps_old_and_reports_once(): void {
		update_option( HDS_Settings::OPTION, HDS_Settings::sanitize( array( 'api_key_new' => self::KEY ) ) );
		$before = HDS_Settings::get( 'api_key_enc' );

		$out = HDS_Settings::sanitize( array( 'api_key_new' => 'short key!' ) );
		$this->assertSame( $before, $out['api_key_enc'] );
		HDS_Settings::sanitize( array( 'api_key_new' => 'short key!' ) );
		$this->assertSame( array( 'hds_invalid_api_key' ), $this->error_codes() );
		foreach ( $this->errors() as $error ) {
			$this->assertStringNotContainsString( 'short key', $error['message'] );
		}
	}

	public function test_api_key_remove(): void {
		update_option( HDS_Settings::OPTION, HDS_Settings::sanitize( array( 'api_key_new' => self::KEY ) ) );
		$out = HDS_Settings::sanitize( array( 'api_key_remove' => '1' ) );
		$this->assertSame( '', $out['api_key_enc'] );
		update_option( HDS_Settings::OPTION, $out );
		$this->assertSame( '', HDS_Settings::get_api_key() );
		$this->assertSame( 'none', HDS_Settings::api_key_source() );
		$this->assertSame( '', HDS_Settings::mask_api_key() );
	}

	public function test_internal_api_key_enc_is_kept_as_is(): void {
		$enc = HDS_Settings::encrypt( self::KEY );
		$out = HDS_Settings::sanitize( array_merge( HDS_Settings::all(), array( 'api_key_enc' => $enc ) ) );
		$this->assertSame( $enc, $out['api_key_enc'] );
		$this->assertSame( array(), $this->errors() );
	}

	public function test_encrypt_decrypt(): void {
		$a = HDS_Settings::encrypt( self::KEY );
		$b = HDS_Settings::encrypt( self::KEY );
		$this->assertNotSame( $a, $b, 'Random nonce per encryption.' );
		$this->assertSame( self::KEY, HDS_Settings::decrypt( $a ) );
		$this->assertSame( self::KEY, HDS_Settings::decrypt( $b ) );
		$this->assertStringNotContainsString( 'sk-', base64_decode( $a ) );

		$raw    = base64_decode( $a );
		$raw[30] = chr( ord( $raw[30] ) ^ 1 );
		$this->assertSame( '', HDS_Settings::decrypt( base64_encode( $raw ) ), 'Tampered ciphertext.' );
		$this->assertSame( '', HDS_Settings::decrypt( 'not base64 !!' ) );
		$this->assertSame( '', HDS_Settings::decrypt( base64_encode( 'short' ) ) );
		$this->assertSame( '', HDS_Settings::decrypt( '' ) );
		$this->assertSame( '', HDS_Settings::encrypt( '' ) );
	}

	public function test_undecryptable_stored_key_reads_as_none(): void {
		update_option( HDS_Settings::OPTION, array( 'api_key_enc' => base64_encode( str_repeat( 'x', 80 ) ) ) );
		$this->assertSame( '', HDS_Settings::get_api_key() );
		$this->assertSame( 'none', HDS_Settings::api_key_source() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_key_wins(): void {
		define( 'HDS_OPENAI_KEY', ' sk-constant-0123456789abcdefghij ' );
		hds_test_reset();
		update_option( HDS_Settings::OPTION, HDS_Settings::sanitize( array( 'api_key_new' => self::KEY ) ) );
		$this->assertSame( 'sk-constant-0123456789abcdefghij', HDS_Settings::get_api_key() );
		$this->assertSame( 'constant', HDS_Settings::api_key_source() );
		$this->assertSame( 'sk-…ghij', HDS_Settings::mask_api_key() );
	}

	public function test_license_key_is_cleaned_and_validated(): void {
		$out = HDS_Settings::sanitize( array( 'license_key' => "  AbC+/=\n dEf=  " ) );
		$this->assertSame( 'AbC+/=dEf=', $out['license_key'] );
		$messages = array_column( $this->errors(), 'message' );
		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'License key is not valid for example.co.il', $messages[0] );

		$GLOBALS['hds_test']['settings_errors'] = array();
		$out = HDS_Settings::sanitize( array( 'license_key' => 'bad<key>' ) );
		$this->assertSame( '', $out['license_key'] );
		$this->assertSame( array( 'hds_invalid_license_format' ), $this->error_codes() );

		$GLOBALS['hds_test']['settings_errors'] = array();
		$out = HDS_Settings::sanitize( array( 'license_key' => '' ) );
		$this->assertSame( '', $out['license_key'] );
		$this->assertSame( array(), $this->errors() );
	}

	public function test_embedding_model_change_requeues_indexed_documents(): void {
		$indexed = HDS_Documents::insert( $this->doc( 'a' ) );
		$errored = HDS_Documents::insert( $this->doc( 'b' ) );
		$process = HDS_Documents::insert( $this->doc( 'c' ) );
		HDS_Documents::set_status( $indexed, HDS_Documents::STATUS_INDEXED );
		HDS_Documents::set_status( $errored, HDS_Documents::STATUS_ERROR, 'Broken.' );
		HDS_Documents::set_status( $process, HDS_Documents::STATUS_PROCESSING );
		$version = HDS_Vector_Store::version();

		HDS_Settings::sanitize( array( 'embedding_model' => 'text-embedding-3-small' ) );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, HDS_Documents::get( $indexed )['status'], 'Unchanged model: no side effects.' );
		$this->assertSame( $version, HDS_Vector_Store::version() );

		$fingerprint = HDS_Settings::index_fingerprint();
		$out         = HDS_Settings::sanitize( array( 'embedding_model' => 'text-embedding-3-large' ) );
		$this->assertSame( HDS_Documents::STATUS_PENDING, HDS_Documents::get( $indexed )['status'] );
		$this->assertSame( HDS_Documents::STATUS_PENDING, HDS_Documents::get( $process )['status'] );
		$this->assertSame( HDS_Documents::STATUS_ERROR, HDS_Documents::get( $errored )['status'] );
		$this->assertNotSame( $version, HDS_Vector_Store::version() );

		update_option( HDS_Settings::OPTION, $out );
		$this->assertNotSame( $fingerprint, HDS_Settings::index_fingerprint() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', HDS_Settings::index_fingerprint() );
	}

	public function test_max_upload_bytes(): void {
		$this->assertSame( 20 * 1048576, HDS_Settings::max_upload_bytes() );
		HDS_Settings::update( array( 'max_file_mb' => 500 ) );
		$this->assertSame( 64 * 1048576, HDS_Settings::max_upload_bytes() );
	}

	/**
	 * Minimal document data.
	 *
	 * @param string $name Name.
	 * @return array
	 */
	private function doc( string $name ): array {
		return array(
			'file_name'     => $name . '-abcdefgh.txt',
			'original_name' => $name . '.txt',
			'ext'           => 'txt',
			'file_size'     => 10,
			'file_hash'     => hash( 'sha256', $name ),
			'title'         => $name,
		);
	}
}
