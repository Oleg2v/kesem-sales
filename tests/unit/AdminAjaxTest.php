<?php
/**
 * Tests for HDS_Admin_Ajax: nonce/capability checks, indexing endpoints and
 * the OpenAI connection test.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * HDS_Admin_Ajax with an OpenAI client on a fake transport (no HTTP).
 */
class HDS_Test_Admin_Ajax_Fake_Client extends HDS_Admin_Ajax {

	/**
	 * Transport callable( $url, $request ): array.
	 *
	 * @var callable|null
	 */
	public static $transport = null;

	/**
	 * Client on the fake transport, never sleeping.
	 *
	 * @return HDS_OpenAI_Client
	 */
	protected static function client(): HDS_OpenAI_Client {
		$key = HDS_Settings::get_api_key();
		if ( '' === $key ) {
			throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::NO_KEY );
		}
		return new HDS_OpenAI_Client(
			$key,
			self::$transport,
			array(
				'sleep' => static function () {
				},
			)
		);
	}
}

/**
 * @covers HDS_Admin_Ajax
 */
class AdminAjaxTest extends TestCase {

	/**
	 * Temporary directory of this test.
	 *
	 * @var string
	 */
	private $tmp;

	protected function setUp(): void {
		hds_test_reset();
		hds_test_create_tables();
		$this->tmp = sys_get_temp_dir() . '/hds-ajax-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp . '/uploads', 0777, true );
		$GLOBALS['hds_test']['upload_dir'] = $this->tmp . '/uploads';
		HDS_License::reset_cache();

		$_POST    = array(
			'nonce' => wp_create_nonce( 'hds_admin' ),
		);
		$_REQUEST = $_POST;
	}

	protected function tearDown(): void {
		hds_test_rmdir( $this->tmp );
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Call an AJAX handler and return the JSON it would send.
	 *
	 * @param string $method HDS_Admin_Ajax method.
	 * @param string $class  Class to call it on.
	 * @return HDS_Test_Json_Exception
	 */
	private function call( string $method, string $class = 'HDS_Admin_Ajax' ): HDS_Test_Json_Exception {
		try {
			call_user_func( array( $class, $method ) );
		} catch ( HDS_Test_Json_Exception $e ) {
			return $e;
		}
		$this->fail( $method . ' did not send JSON.' );
	}

	/**
	 * Store an API key in the settings.
	 *
	 * @param string $key Key.
	 */
	private function store_key( string $key ): void {
		HDS_Settings::update( array( 'api_key_enc' => HDS_Settings::encrypt( $key ) ) );
	}

	/**
	 * Fake OpenAI transport answering every request with one response.
	 *
	 * @param int   $code Status.
	 * @param array $body JSON body.
	 */
	private function fake_http( int $code, array $body ): void {
		$GLOBALS['hds_test']['http_requests'] = array();
		HDS_Test_Admin_Ajax_Fake_Client::$transport = static function ( $url, $request ) use ( $code, $body ) {
			$GLOBALS['hds_test']['http_requests'][] = array( $url, $request );
			return array(
				'code'    => $code,
				'body'    => json_encode( $body ),
				'headers' => array( 'content-type' => 'application/json' ),
			);
		};
	}

	/* ------------------------------------------------------------------ */

	public function test_register_only_logged_in_actions(): void {
		HDS_Admin_Ajax::register();
		$actions = $GLOBALS['hds_test']['actions'];
		foreach ( array( 'hds_index_start', 'hds_index_step', 'hds_test_api' ) as $action ) {
			$this->assertArrayHasKey( 'wp_ajax_' . $action, $actions );
			$this->assertArrayNotHasKey( 'wp_ajax_nopriv_' . $action, $actions );
		}
	}

	/**
	 * @return array
	 */
	public function handlers(): array {
		return array(
			array( 'index_start' ),
			array( 'index_step' ),
			array( 'test_api' ),
		);
	}

	/**
	 * @dataProvider handlers
	 *
	 * @param string $method Handler.
	 */
	public function test_bad_nonce_dies( string $method ): void {
		$_REQUEST['nonce'] = 'bad';
		try {
			call_user_func( array( 'HDS_Admin_Ajax', $method ) );
			$this->fail( 'No wp_die().' );
		} catch ( HDS_Test_Die_Exception $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}
		$this->assertContains( array( 'hds_admin', 'nonce' ), $GLOBALS['hds_test']['nonce_checks'] );
		$this->assertFalse( get_option( 'hds_index_queue' ) );
	}

	/**
	 * @dataProvider handlers
	 *
	 * @param string $method Handler.
	 */
	public function test_capability_required( string $method ): void {
		$GLOBALS['hds_test']['caps'] = array();
		$json                        = $this->call( $method );
		$this->assertFalse( $json->success );
		$this->assertSame( 403, $json->status );
		$this->assertGreaterThanOrEqual( 1, $GLOBALS['hds_test']['nocache'] );
	}

	public function test_index_start_and_step_with_nothing_to_do(): void {
		$json = $this->call( 'index_start' );
		$this->assertTrue( $json->success );
		$this->assertSame( 0, $json->data['total'] );
		$this->assertArrayHasKey( 'skipped', $json->data );
		$this->assertArrayHasKey( 'locked', $json->data );

		$json = $this->call( 'index_step' );
		$this->assertTrue( $json->success );
		$this->assertTrue( $json->data['done'] );
		$this->assertSame( 100, $json->data['percent'] );
		$this->assertGreaterThanOrEqual( 2, $GLOBALS['hds_test']['nocache'] );
	}

	public function test_index_start_queues_documents(): void {
		$path = $this->tmp . '/upload.doc';
		copy( HDS_FIXTURES_DIR . '/SH-22-06-57879-590.doc', $path );
		$GLOBALS['hds_test']['filters']['hds_is_uploaded_file'][] = static function () {
			return true;
		};
		$stored = HDS_Storage::store_upload(
			array(
				'name'     => 'SH-22-06-57879-590.doc',
				'type'     => 'application/msword',
				'tmp_name' => $path,
				'error'    => 0,
				'size'     => filesize( $path ),
			),
			HDS_Settings::max_upload_bytes()
		);
		$this->assertIsArray( $stored );
		$id = HDS_Documents::insert( $stored );

		$json = $this->call( 'index_start' );
		$this->assertTrue( $json->success );
		$this->assertSame( 1, $json->data['total'] );
		$this->assertSame( array( $id ), $json->data['queue'] );

		// No API key: the step stops with a fatal, key-free error.
		$json = $this->call( 'index_step' );
		$this->assertTrue( $json->success );
		$this->assertFalse( $json->data['done'] );
		$this->assertTrue( $json->data['fatal'] );
		$this->assertNotEmpty( $json->data['error'] );
	}

	public function test_internal_errors_are_generic(): void {
		$wpdb            = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = null; // Every query now throws an Error.
		try {
			$start = $this->call( 'index_start' );
			delete_transient( HDS_Indexer::LOCK );
			update_option(
				'hds_index_queue',
				array(
					'run_id'    => 'r1',
					'queue'     => array( 1 ),
					'total'     => 1,
					'processed' => 0,
				)
			);
			$step = $this->call( 'index_step' );
		} finally {
			$GLOBALS['wpdb'] = $wpdb;
		}
		foreach ( array( $start, $step ) as $json ) {
			$this->assertFalse( $json->success );
			$this->assertSame( 500, $json->status );
			$this->assertSame( array( 'message' ), array_keys( $json->data ) );
			$this->assertStringContainsString( 'unexpected server error', $json->data['message'] );
			$this->assertStringNotContainsString( 'null', $json->data['message'] );
		}
	}

	public function test_api_without_key(): void {
		$json = $this->call( 'test_api' );
		$this->assertFalse( $json->success );
		$this->assertSame( 'no_key', $json->data['code'] );
		$this->assertStringContainsString( 'not configured', $json->data['message'] );
	}

	public function test_api_success_reports_dimensions(): void {
		$key = 'sk-test-' . str_repeat( 'K', 40 );
		$this->store_key( $key );
		$this->fake_http(
			200,
			array(
				'object' => 'list',
				'data'   => array(
					array(
						'object'    => 'embedding',
						'index'     => 0,
						'embedding' => array( 0.1, 0.2, 0.3 ),
					),
				),
			)
		);

		$json = $this->call( 'test_api', 'HDS_Test_Admin_Ajax_Fake_Client' );
		$this->assertTrue( $json->success, print_r( $json->data, true ) );
		$this->assertSame( 3, $json->data['dims'] );
		$this->assertSame( 'text-embedding-3-small', $json->data['model'] );
		$this->assertStringContainsString( '3-dimensional', $json->data['message'] );
		$this->assertStringNotContainsString( $key, json_encode( $json->data ) );

		list( $url, $request ) = $GLOBALS['hds_test']['http_requests'][0];
		$this->assertSame( 'https://api.openai.com/v1/embeddings', $url );
		$body = json_decode( $request['body'], true );
		$this->assertSame( 'text-embedding-3-small', $body['model'] );
		$this->assertSame( array( 'בדיקה' ), (array) $body['input'] );
	}

	public function test_api_invalid_key_message_has_no_key(): void {
		$key = 'sk-test-' . str_repeat( 'Z', 40 );
		$this->store_key( $key );
		$this->fake_http(
			401,
			array(
				'error' => array(
					'message' => 'Incorrect API key provided: ' . $key,
					'code'    => 'invalid_api_key',
				),
			)
		);

		$json = $this->call( 'test_api', 'HDS_Test_Admin_Ajax_Fake_Client' );
		$this->assertFalse( $json->success );
		$this->assertSame( 'invalid_key', $json->data['code'] );
		$this->assertStringNotContainsString( $key, json_encode( $json->data ) );
		$this->assertStringNotContainsString( str_repeat( 'Z', 20 ), $json->data['message'] );
	}

	public function test_redact(): void {
		$key = 'sk-test-' . str_repeat( 'R', 40 );
		$this->store_key( $key );
		$this->assertSame( 'bad [redacted] x', HDS_Admin_Ajax::redact( 'bad ' . $key . ' x' ) );
		$this->assertSame( 'other sk-… key', HDS_Admin_Ajax::redact( 'other sk-abcdefghijklmnop key' ) );
		$this->assertSame( 'no secrets here', HDS_Admin_Ajax::redact( 'no secrets here' ) );
	}
}
