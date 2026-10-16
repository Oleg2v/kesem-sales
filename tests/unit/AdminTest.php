<?php
/**
 * Tests for HDS_Admin: menu, notices, upload/delete/rename handlers and the
 * two page views.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers HDS_Admin
 */
class AdminTest extends TestCase {

	/**
	 * Temporary directory of this test.
	 *
	 * @var string
	 */
	private $tmp;

	protected function setUp(): void {
		hds_test_reset();
		hds_test_create_tables();
		$this->tmp = sys_get_temp_dir() . '/hds-admin-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp . '/uploads', 0777, true );
		$GLOBALS['hds_test']['upload_dir'] = $this->tmp . '/uploads';
		$GLOBALS['hds_test']['filters']['hds_is_uploaded_file'][] = static function () {
			return true;
		};

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		unset( $_SERVER['CONTENT_LENGTH'] );

		HDS_License::reset_cache();
		$this->set_hooks( array() );
	}

	protected function tearDown(): void {
		hds_test_rmdir( $this->tmp );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
		unset( $_SERVER['CONTENT_LENGTH'] );
		$this->set_hooks( array() );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Reset the private static hook map.
	 *
	 * @param array $hooks Hook suffix => page.
	 */
	private function set_hooks( array $hooks ): void {
		$prop = new ReflectionProperty( 'HDS_Admin', 'hooks' );
		$prop->setAccessible( true );
		$prop->setValue( null, $hooks );
	}

	/**
	 * Whether a WordPress stub comes from tests/stubs/40-admin.php.
	 *
	 * @param string $function Function name.
	 * @return bool
	 */
	private function stub_is_ours( string $function ): bool {
		$ref = new ReflectionFunction( $function );
		return '40-admin.php' === basename( (string) $ref->getFileName() );
	}

	/**
	 * Run a handler that must redirect; returns the redirect.
	 *
	 * @param callable $handler Handler.
	 * @return HDS_Test_Redirect_Exception
	 */
	private function expect_redirect( callable $handler ): HDS_Test_Redirect_Exception {
		try {
			$handler();
		} catch ( HDS_Test_Redirect_Exception $e ) {
			return $e;
		}
		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * Run a handler that must call wp_die().
	 *
	 * @param callable $handler Handler.
	 * @return HDS_Test_Die_Exception
	 */
	private function expect_die( callable $handler ): HDS_Test_Die_Exception {
		try {
			$handler();
		} catch ( HDS_Test_Die_Exception $e ) {
			return $e;
		}
		$this->fail( 'The handler did not stop with wp_die().' );
	}

	/**
	 * Queued notices of user 1 (without consuming them).
	 *
	 * @return array[]
	 */
	private function notices(): array {
		$value = get_transient( 'hds_notices_1' );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Copy a fixture to a temp "upload" file.
	 *
	 * @param string $fixture Fixture path relative to tests/fixtures.
	 * @param string $name    Temp name.
	 * @return string Path.
	 */
	private function tmp_upload( string $fixture, string $name ): string {
		$path = $this->tmp . '/' . $name;
		copy( HDS_FIXTURES_DIR . '/' . $fixture, $path );
		return $path;
	}

	/**
	 * Fake a multi-file upload request.
	 *
	 * @param array $files List of [client name, tmp path].
	 */
	private function post_upload( array $files ): void {
		$_POST    = array(
			'action'     => 'hds_upload',
			'_hds_nonce' => wp_create_nonce( 'hds_upload' ),
		);
		$_REQUEST = $_POST;
		$field    = array(
			'name'     => array(),
			'type'     => array(),
			'tmp_name' => array(),
			'error'    => array(),
			'size'     => array(),
		);
		foreach ( $files as $file ) {
			$field['name'][]     = $file[0];
			$field['type'][]     = 'application/octet-stream';
			$field['tmp_name'][] = $file[1];
			$field['error'][]    = UPLOAD_ERR_OK;
			$field['size'][]     = is_file( $file[1] ) ? filesize( $file[1] ) : 0;
		}
		$_FILES = array( 'hds_files' => $field );
	}

	/**
	 * Insert a document row.
	 *
	 * @param array $data Overrides.
	 * @return int Id.
	 */
	private function insert_doc( array $data = array() ): int {
		$id = HDS_Documents::insert(
			array_merge(
				array(
					'title'         => 'Document',
					'file_name'     => 'document-abcdefgh.doc',
					'original_name' => 'document.doc',
					'ext'           => 'doc',
					'file_size'     => 2048,
					'file_hash'     => hash( 'sha256', uniqid( '', true ) ),
				),
				$data
			)
		);
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	/**
	 * Render a page and return the HTML.
	 *
	 * @param callable $render Renderer.
	 * @return string
	 */
	private function capture( callable $render ): string {
		ob_start();
		try {
			$render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Parse HTML into an XPath.
	 *
	 * @param string $html HTML.
	 * @return DOMXPath
	 */
	private function xpath( string $html ): DOMXPath {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return new DOMXPath( $dom );
	}

	/**
	 * Assert all id attributes are unique.
	 *
	 * @param DOMXPath $xp XPath.
	 */
	private function assert_unique_ids( DOMXPath $xp ): void {
		$ids = array();
		foreach ( $xp->query( '//*[@id]' ) as $node ) {
			$ids[] = $node->getAttribute( 'id' );
		}
		$this->assertSame( array(), array_values( array_diff_assoc( $ids, array_unique( $ids ) ) ), 'Duplicate ids in the markup.' );
	}

	/* ------------------------------------------------------------------ */

	public function test_register_adds_hooks(): void {
		HDS_Admin::register();
		$actions = $GLOBALS['hds_test']['actions'];
		foreach ( array( 'admin_menu', 'admin_init', 'admin_enqueue_scripts', 'admin_notices', 'admin_post_hds_upload', 'admin_post_hds_delete', 'admin_post_hds_rename' ) as $hook ) {
			$this->assertArrayHasKey( $hook, $actions, $hook );
		}
		$this->assertArrayNotHasKey( 'admin_post_nopriv_hds_upload', $actions );
		$this->assertArrayHasKey( 'plugin_action_links_' . plugin_basename( HDS_PLUGIN_FILE ), $GLOBALS['hds_test']['filters'] );
	}

	public function test_admin_menu_registers_pages_with_manage_options(): void {
		HDS_Admin::admin_menu();
		$menu = $GLOBALS['hds_test']['menu'][0];
		$this->assertSame( 'AI Search', $menu['menu_title'] );
		$this->assertSame( 'manage_options', $menu['capability'] );
		$this->assertSame( 'hds-documents', $menu['menu_slug'] );
		$this->assertSame( 'dashicons-search', $menu['icon_url'] );
		$this->assertSame( 58, $menu['position'] );

		$slugs = array();
		foreach ( $GLOBALS['hds_test']['submenu'] as $sub ) {
			$this->assertSame( 'manage_options', $sub['capability'] );
			$this->assertSame( 'hds-documents', $sub['parent_slug'] );
			$slugs[ $sub['menu_slug'] ] = $sub['menu_title'];
		}
		$this->assertSame(
			array(
				'hds-documents' => 'Documents',
				'hds-settings'  => 'Settings',
			),
			$slugs
		);
	}

	public function test_register_settings_uses_settings_sanitize(): void {
		HDS_Admin::register_settings();
		$setting = $GLOBALS['hds_test']['registered_settings'][0];
		$this->assertSame( 'hds_settings_group', $setting['group'] );
		$this->assertSame( 'hds_settings', $setting['option'] );
		$this->assertSame( array( 'HDS_Settings', 'sanitize' ), $setting['args']['sanitize_callback'] );
	}

	public function test_plugin_action_links(): void {
		$links = HDS_Admin::plugin_action_links( array( '<a href="#">Deactivate</a>' ) );
		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'admin.php?page=hds-settings', $links[0] );
		$this->assertStringContainsString( '>Settings<', $links[0] );

		$GLOBALS['hds_test']['caps'] = array();
		$this->assertCount( 1, HDS_Admin::plugin_action_links( array( 'x' ) ) );
	}

	public function test_normalize_files_multi_single_and_garbage(): void {
		$multi = array(
			'name'     => array( 'a.doc', '', 'b.pdf' ),
			'type'     => array( 'application/msword', '', 'application/pdf' ),
			'tmp_name' => array( '/tmp/a', '', '/tmp/b' ),
			'error'    => array( 0, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_INI_SIZE ),
			'size'     => array( 10, 0, 20 ),
		);
		$this->assertSame(
			array(
				array(
					'name'     => 'a.doc',
					'type'     => 'application/msword',
					'tmp_name' => '/tmp/a',
					'error'    => 0,
					'size'     => 10,
				),
				array(
					'name'     => 'b.pdf',
					'type'     => 'application/pdf',
					'tmp_name' => '/tmp/b',
					'error'    => UPLOAD_ERR_INI_SIZE,
					'size'     => 20,
				),
			),
			HDS_Admin::normalize_files( $multi )
		);

		$single = HDS_Admin::normalize_files(
			array(
				'name'     => 'c.txt',
				'type'     => 'text/plain',
				'tmp_name' => '/tmp/c',
				'error'    => '0',
				'size'     => '5',
			)
		);
		$this->assertCount( 1, $single );
		$this->assertSame( 0, $single[0]['error'] );
		$this->assertSame( 5, $single[0]['size'] );

		$this->assertSame( array(), HDS_Admin::normalize_files( null ) );
		$this->assertSame( array(), HDS_Admin::normalize_files( 'x' ) );
		$this->assertSame( array(), HDS_Admin::normalize_files( array( 'name' => array( array( 'nested' ) ), 'tmp_name' => array( array( 'x' ) ), 'error' => array( array( 0 ) ) ) ) );
	}

	public function test_notices_are_per_user_validated_and_consumed_once(): void {
		HDS_Admin::add_notice( 'success', 'One' );
		HDS_Admin::add_notice( 'bogus', 'Two' );
		HDS_Admin::add_notice( 'error', '   ' );
		$this->assertSame(
			array(
				array(
					'type'    => 'success',
					'message' => 'One',
				),
				array(
					'type'    => 'info',
					'message' => 'Two',
				),
			),
			$this->notices()
		);

		$GLOBALS['hds_test']['user_id'] = 2;
		$this->assertSame( array(), HDS_Admin::pop_notices() );
		$GLOBALS['hds_test']['user_id'] = 1;

		$this->assertCount( 2, HDS_Admin::pop_notices() );
		$this->assertSame( array(), HDS_Admin::pop_notices() );

		for ( $i = 0; $i < 60; $i++ ) {
			HDS_Admin::add_notice( 'error', 'E' . $i );
		}
		$this->assertCount( HDS_Admin::MAX_NOTICES, $this->notices() );
	}

	public function test_render_notices_groups_and_escapes(): void {
		$html = $this->capture(
			static function () {
				HDS_Admin::render_notices(
					array(
						array(
							'type'    => 'success',
							'message' => '2 documents uploaded.',
						),
						array(
							'type'    => 'error',
							'message' => '<b>a.doc</b>: bad',
						),
						array(
							'type'    => 'error',
							'message' => 'b.doc: bad',
						),
					)
				);
			}
		);
		$this->assertSame( 1, substr_count( $html, 'notice-success' ) );
		$this->assertSame( 1, substr_count( $html, 'notice-error' ) );
		// Untranslated English sentences stay LTR inside an RTL (Hebrew) admin.
		$this->assertSame( 'ltr', HDS_Admin::text_dir() );
		$this->assertStringContainsString( '<p dir="ltr">2 documents uploaded.</p>', $html );
		$this->assertStringContainsString( '<ul class="hds-notice-list" dir="ltr">', $html );
		$this->assertStringContainsString( '<li>&lt;b&gt;a.doc&lt;/b&gt;: bad</li>', $html );
		$this->assertStringNotContainsString( '<b>a.doc', $html );
	}

	public function test_admin_notices_only_on_plugin_pages(): void {
		HDS_Admin::add_notice( 'success', 'Queued message' );

		$_GET['page'] = 'some-other-page';
		$this->assertSame( '', $this->capture( array( 'HDS_Admin', 'admin_notices' ) ) );

		$_GET['page'] = 'hds-documents';
		$html         = $this->capture( array( 'HDS_Admin', 'admin_notices' ) );
		$this->assertStringContainsString( 'notice notice-info hds-notice-demo', $html );
		$this->assertStringContainsString( 'Demo version: only the first 15 documents are indexed.', $html );
		$this->assertMatchesRegularExpression( '/<div class="notice notice-info hds-notice-demo">/', $html, 'Demo notice must not be dismissible.' );
		$this->assertStringContainsString( 'notice-warning hds-notice-api-key', $html );
		$this->assertStringContainsString( 'define( &#039;HDS_OPENAI_KEY&#039;, &#039;sk-...&#039; );', $html );
		$this->assertStringContainsString( 'Queued message', $html );

		// Shown once.
		$this->assertStringNotContainsString( 'Queued message', $this->capture( array( 'HDS_Admin', 'admin_notices' ) ) );

		// Once the menu is registered the current screen decides.
		HDS_Admin::admin_menu();
		$GLOBALS['hds_test']['screen'] = (object) array( 'id' => 'dashboard' );
		$this->assertSame( '', $this->capture( array( 'HDS_Admin', 'admin_notices' ) ) );
		$GLOBALS['hds_test']['screen'] = (object) array( 'id' => 'ai-search_page_hds-settings' );
		$this->assertStringContainsString( 'Demo version', $this->capture( array( 'HDS_Admin', 'admin_notices' ) ) );

		// Users without the capability see nothing.
		$GLOBALS['hds_test']['caps'] = array();
		$this->assertSame( '', $this->capture( array( 'HDS_Admin', 'admin_notices' ) ) );
	}

	public function test_no_api_key_warning_when_key_is_stored(): void {
		HDS_Settings::update( array( 'api_key_enc' => HDS_Settings::encrypt( 'sk-test' . str_repeat( 'A', 40 ) . 'wxyz' ) ) );
		$_GET['page'] = 'hds-settings';
		$html         = $this->capture( array( 'HDS_Admin', 'admin_notices' ) );
		$this->assertStringNotContainsString( 'hds-notice-api-key', $html );
	}

	public function test_enqueue_assets_only_on_plugin_pages_and_config(): void {
		if ( ! $this->stub_is_ours( 'wp_enqueue_script' ) || ! $this->stub_is_ours( 'wp_add_inline_script' ) ) {
			$this->markTestSkipped( 'Script stubs come from another stub file.' );
		}
		HDS_Settings::update( array( 'api_key_enc' => HDS_Settings::encrypt( 'sk-secret' . str_repeat( 'B', 40 ) . 'zzzz' ) ) );
		HDS_Admin::admin_menu();

		HDS_Admin::enqueue_assets( 'index.php' );
		$this->assertArrayNotHasKey( 'enqueued', $GLOBALS['hds_test'] );

		HDS_Admin::enqueue_assets( 'toplevel_page_hds-documents' );
		$script = $GLOBALS['hds_test']['enqueued']['script']['hds-admin'];
		$this->assertSame( HDS_PLUGIN_URL . 'assets/js/hds-admin.js', $script['src'] );
		$this->assertSame( array(), $script['deps'] );
		$this->assertTrue( $script['in_footer'] );
		$this->assertSame( HDS_PLUGIN_URL . 'assets/css/hds-admin.css', $GLOBALS['hds_test']['enqueued']['style']['hds-admin']['src'] );

		list( $code, $position ) = $GLOBALS['hds_test']['enqueued']['inline']['hds-admin'][0];
		$this->assertSame( 'before', $position );
		$this->assertStringStartsWith( 'window.hdsAdmin = ', $code );
		$this->assertStringNotContainsString( 'sk-secret', $code );
		$config = json_decode( substr( $code, strlen( 'window.hdsAdmin = ' ), -1 ), true );
		$this->assertSame( 'https://example.co.il/wp-admin/admin-ajax.php', $config['ajaxUrl'] );
		$this->assertSame( wp_create_nonce( 'hds_admin' ), $config['nonce'] );
		$this->assertSame( 'documents', $config['page'] );
		$this->assertSame( 3, $config['maxRetries'] );
		foreach ( array( 'confirmLeave', 'starting', 'queued', 'skipped', 'done', 'retrying', 'resumable', 'fatal', 'networkError', 'sessionExpired', 'testing', 'percent', 'waiting' ) as $key ) {
			$this->assertArrayHasKey( $key, $config['i18n'], $key );
		}

		HDS_Admin::enqueue_assets( 'ai-search_page_hds-settings' );
		list( $code ) = $GLOBALS['hds_test']['enqueued']['inline']['hds-admin'][1];
		$this->assertStringContainsString( '"page":"settings"', $code );
	}

	public function test_inline_config_cannot_break_out_of_script(): void {
		$code = wp_json_encode( array( 'x' => '</script><script>alert(1)</script>' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
		$this->assertStringNotContainsString( '</script', $code );
	}

	/* ------------------------------------------------------------------
	 * Upload
	 * ---------------------------------------------------------------- */

	public function test_upload_stores_documents_and_rejects_duplicates(): void {
		$a = $this->tmp_upload( 'SH-22-06-57879-590.doc', 'php-a' );
		$b = $this->tmp_upload( 'ARAR-25-09-8059.doc', 'php-b' );
		$this->post_upload( array( array( 'SH-22-06-57879-590.doc', $a ), array( 'ARAR-25-09-8059.doc', $b ) ) );

		$redirect = $this->expect_redirect( array( 'HDS_Admin', 'handle_upload' ) );
		$this->assertSame( 'https://example.co.il/wp-admin/admin.php?page=hds-documents', $redirect->location );
		$this->assertSame( 303, $redirect->status );
		$this->assertContains( array( 'hds_upload', '_hds_nonce' ), $GLOBALS['hds_test']['nonce_checks'] );

		$docs = HDS_Documents::all();
		$this->assertCount( 2, $docs );
		$this->assertSame( 'SH-22-06-57879-590', $docs[0]['title'] );
		$this->assertSame( 'pending', $docs[0]['status'] );
		$this->assertSame(
			array(
				array(
					'type'    => 'success',
					'message' => '2 documents uploaded. Click “Update index” to make them searchable.',
				),
			),
			$this->notices()
		);
		delete_transient( 'hds_notices_1' );

		// Same file again (under another name) → skipped, nothing stored.
		$c = $this->tmp_upload( 'SH-22-06-57879-590.doc', 'php-c' );
		$this->post_upload( array( array( 'copy.doc', $c ) ) );
		$this->expect_redirect( array( 'HDS_Admin', 'handle_upload' ) );
		$this->assertCount( 2, HDS_Documents::all() );
		$notices = $this->notices();
		$this->assertCount( 1, $notices );
		$this->assertSame( 'warning', $notices[0]['type'] );
		$this->assertStringContainsString( 'already uploaded as “SH-22-06-57879-590”', $notices[0]['message'] );
		$this->assertStringContainsString( '“copy.doc”', $notices[0]['message'] );

		$stored = glob( HDS_Storage::dir() . '*.doc' );
		$this->assertCount( 2, $stored, 'The duplicate file must be removed from storage.' );
	}

	public function test_upload_reports_each_bad_file_and_keeps_good_ones(): void {
		$good = $this->tmp_upload( 'SH-22-06-57879-590.doc', 'php-good' );
		$bad  = $this->tmp . '/php-bad';
		file_put_contents( $bad, "This is plain text pretending to be a Word file.\n" );
		$exe = $this->tmp . '/php-exe';
		file_put_contents( $exe, 'MZ' );
		$this->post_upload( array( array( 'good.doc', $good ), array( 'fake.doc', $bad ), array( 'virus.exe', $exe ) ) );

		$this->expect_redirect( array( 'HDS_Admin', 'handle_upload' ) );
		$this->assertCount( 1, HDS_Documents::all() );
		$notices = $this->notices();
		$this->assertSame( 'success', $notices[0]['type'] );
		$this->assertStringStartsWith( '1 document uploaded.', $notices[0]['message'] );
		$this->assertSame( 'error', $notices[1]['type'] );
		$this->assertStringContainsString( 'fake.doc', $notices[1]['message'] );
		$this->assertSame( 'error', $notices[2]['type'] );
		$this->assertStringContainsString( 'virus.exe', $notices[2]['message'] );
		$this->assertCount( 3, $notices );
	}

	public function test_upload_without_files_adds_error(): void {
		$_POST    = array(
			'action'     => 'hds_upload',
			'_hds_nonce' => wp_create_nonce( 'hds_upload' ),
		);
		$_REQUEST = $_POST;
		$_FILES   = array(
			'hds_files' => array(
				'name'     => array( '' ),
				'type'     => array( '' ),
				'tmp_name' => array( '' ),
				'error'    => array( UPLOAD_ERR_NO_FILE ),
				'size'     => array( 0 ),
			),
		);
		$this->expect_redirect( array( 'HDS_Admin', 'handle_upload' ) );
		$notices = $this->notices();
		$this->assertSame( 'error', $notices[0]['type'] );
		$this->assertStringContainsString( 'No files were selected', $notices[0]['message'] );
	}

	public function test_upload_larger_than_post_max_size(): void {
		$_SERVER['CONTENT_LENGTH'] = (string) ( 50 * 1073741824 );
		$this->expect_redirect( array( 'HDS_Admin', 'handle_upload' ) );
		$notices = $this->notices();
		$this->assertCount( 1, $notices );
		$this->assertSame( 'error', $notices[0]['type'] );
		$this->assertStringContainsString( 'post_max_size', $notices[0]['message'] );
		$this->assertSame( 0, HDS_Documents::count() );
	}

	public function test_upload_requires_nonce_capability_and_post(): void {
		$a = $this->tmp_upload( 'SH-22-06-57879-590.doc', 'php-a' );
		$this->post_upload( array( array( 'a.doc', $a ) ) );

		$_POST['_hds_nonce']    = 'wrong';
		$_REQUEST['_hds_nonce'] = 'wrong';
		$this->expect_die( array( 'HDS_Admin', 'handle_upload' ) );
		$this->assertSame( 0, HDS_Documents::count() );

		$this->post_upload( array( array( 'a.doc', $a ) ) );
		$GLOBALS['hds_test']['caps'] = array();
		$this->expect_die( array( 'HDS_Admin', 'handle_upload' ) );
		$this->assertSame( 0, HDS_Documents::count() );

		$GLOBALS['hds_test']['caps'] = array( 'manage_options' => true );
		$_SERVER['REQUEST_METHOD']   = 'GET';
		$this->expect_die( array( 'HDS_Admin', 'handle_upload' ) );
		$this->assertSame( 0, HDS_Documents::count() );
	}

	/* ------------------------------------------------------------------
	 * Delete / rename
	 * ---------------------------------------------------------------- */

	public function test_delete_document(): void {
		$keep = $this->insert_doc( array( 'title' => 'Keep me' ) );
		$id   = $this->insert_doc( array( 'title' => 'Old <ruling>' ) );

		$_POST    = array(
			'action'     => 'hds_delete',
			'doc_id'     => (string) $id,
			'_hds_nonce' => wp_create_nonce( 'hds_delete_' . $keep ), // Nonce of another document.
		);
		$_REQUEST = $_POST;
		$this->expect_die( array( 'HDS_Admin', 'handle_delete' ) );
		$this->assertNotNull( HDS_Documents::get( $id ) );

		$_POST['_hds_nonce']    = wp_create_nonce( 'hds_delete_' . $id );
		$_REQUEST['_hds_nonce'] = $_POST['_hds_nonce'];
		$this->expect_redirect( array( 'HDS_Admin', 'handle_delete' ) );
		$this->assertNull( HDS_Documents::get( $id ) );
		$this->assertNotNull( HDS_Documents::get( $keep ) );
		$this->assertSame(
			array(
				array(
					'type'    => 'success',
					'message' => 'Document “Old <ruling>” deleted.',
				),
			),
			$this->notices()
		);

		// Deleting again: not found.
		delete_transient( 'hds_notices_1' );
		$this->expect_redirect( array( 'HDS_Admin', 'handle_delete' ) );
		$this->assertSame( 'error', $this->notices()[0]['type'] );
	}

	public function test_delete_requires_capability(): void {
		$id                          = $this->insert_doc();
		$_POST                       = array(
			'doc_id'     => (string) $id,
			'_hds_nonce' => wp_create_nonce( 'hds_delete_' . $id ),
		);
		$_REQUEST                    = $_POST;
		$GLOBALS['hds_test']['caps'] = array();
		$this->expect_die( array( 'HDS_Admin', 'handle_delete' ) );
		$this->assertNotNull( HDS_Documents::get( $id ) );
	}

	/**
	 * @param int    $id    Document id.
	 * @param string $title Posted title.
	 */
	private function post_rename( int $id, string $title ): void {
		$_POST    = array(
			'action'     => 'hds_rename',
			'doc_id'     => (string) $id,
			'title'      => addslashes( $title ), // WordPress slashes $_POST.
			'_hds_nonce' => wp_create_nonce( 'hds_rename_' . $id ),
		);
		$_REQUEST = $_POST;
	}

	public function test_rename_document(): void {
		$id = $this->insert_doc( array( 'title' => 'Old title' ) );
		update_option( 'hds_index_version', 'v1' );

		$this->post_rename( $id, "  פסק דין <b>\"חדש\"</b>\n" );
		$this->expect_redirect( array( 'HDS_Admin', 'handle_rename' ) );
		$this->assertSame( 'פסק דין "חדש"', HDS_Documents::get( $id )['title'] );
		$this->assertSame( 'success', $this->notices()[0]['type'] );
		$this->assertIsArray( get_option( 'hds_suggest_dict' ), 'Suggestions are rebuilt.' );
		$this->assertNotSame( 'v1', get_option( 'hds_index_version' ), 'Cached answers with the old title are invalidated.' );
		$this->assertContains( array( 'hds_rename_' . $id, '_hds_nonce' ), $GLOBALS['hds_test']['nonce_checks'] );
	}

	public function test_rename_validation(): void {
		$id = $this->insert_doc( array( 'title' => 'Old title' ) );

		$this->post_rename( $id, " <i></i> \t" );
		$this->expect_redirect( array( 'HDS_Admin', 'handle_rename' ) );
		$this->assertSame( 'Old title', HDS_Documents::get( $id )['title'] );
		$this->assertSame( 'The title cannot be empty.', $this->notices()[0]['message'] );
		delete_transient( 'hds_notices_1' );

		$this->post_rename( $id, str_repeat( 'א', 300 ) );
		$this->expect_redirect( array( 'HDS_Admin', 'handle_rename' ) );
		$this->assertSame( 255, mb_strlen( HDS_Documents::get( $id )['title'], 'UTF-8' ) );
		delete_transient( 'hds_notices_1' );

		$this->post_rename( 999999, 'Whatever' );
		$this->expect_redirect( array( 'HDS_Admin', 'handle_rename' ) );
		$this->assertSame( 'error', $this->notices()[0]['type'] );
		delete_transient( 'hds_notices_1' );

		$this->post_rename( $id, 'x' );
		$_POST['_hds_nonce']    = wp_create_nonce( 'hds_rename_' . ( $id + 1 ) );
		$_REQUEST['_hds_nonce'] = $_POST['_hds_nonce'];
		$this->expect_die( array( 'HDS_Admin', 'handle_rename' ) );
	}

	public function test_sanitize_title(): void {
		$this->assertSame( 'a b', HDS_Admin::sanitize_title( " a\n\tb " ) );
		$this->assertSame( '', HDS_Admin::sanitize_title( '<script></script>' ) );
		$this->assertSame( 255, mb_strlen( HDS_Admin::sanitize_title( str_repeat( 'ש', 400 ) ), 'UTF-8' ) );
	}

	/* ------------------------------------------------------------------
	 * Views
	 * ---------------------------------------------------------------- */

	public function test_documents_page_markup(): void {
		$indexed = $this->insert_doc(
			array(
				'title'         => 'Ruling <script>alert(1)</script>',
				'original_name' => 'ruling.doc',
			)
		);
		HDS_Documents::update(
			$indexed,
			array(
				'status'      => 'indexed',
				'chunk_count' => 1234,
			)
		);
		$broken = $this->insert_doc( array( 'title' => 'Broken' ) );
		HDS_Documents::set_status( $broken, 'error', 'The file is damaged.' );
		$locked = $this->insert_doc( array( 'title' => 'Locked' ) );
		HDS_Documents::set_status( $locked, 'demo_locked' );

		$html = $this->capture( array( 'HDS_Admin', 'render_documents_page' ) );
		$xp   = $this->xpath( $html );
		$this->assert_unique_ids( $xp );

		// Upload form.
		$form = $xp->query( '//form[@id="hds-upload-form"]' )->item( 0 );
		$this->assertNotNull( $form );
		$this->assertSame( 'multipart/form-data', $form->getAttribute( 'enctype' ) );
		$this->assertSame( 'post', $form->getAttribute( 'method' ) );
		$this->assertStringContainsString( 'admin-post.php?action=hds_upload', $form->getAttribute( 'action' ) );
		$this->assertSame( 1, $xp->query( '//form[@id="hds-upload-form"]//input[@name="action"][@value="hds_upload"]' )->length );
		$this->assertSame( wp_create_nonce( 'hds_upload' ), $xp->query( '//form[@id="hds-upload-form"]//input[@name="_hds_nonce"]' )->item( 0 )->getAttribute( 'value' ) );
		$file = $xp->query( '//input[@id="hds-files"]' )->item( 0 );
		$this->assertSame( 'hds_files[]', $file->getAttribute( 'name' ) );
		$this->assertTrue( $file->hasAttribute( 'multiple' ) );
		$this->assertSame( '.doc,.docx,.pdf,.txt', $file->getAttribute( 'accept' ) );
		$this->assertSame( 1, $xp->query( '//label[@for="hds-files"]' )->length );
		$this->assertSame( 1, $xp->query( '//form[@id="hds-upload-form"]//*[@type="submit"]' )->length );
		$this->assertStringContainsString( 'Maximum file size: ' . size_format( HDS_Settings::max_upload_bytes() ), $html );

		// Index button, progress bar, log.
		$button = $xp->query( '//button[@id="hds-index-button"]' )->item( 0 );
		$this->assertSame( 'Update index', trim( $button->textContent ) );
		$this->assertFalse( $button->hasAttribute( 'disabled' ) );
		$progress = $xp->query( '//*[@id="hds-progress"]' )->item( 0 );
		$this->assertSame( 'progressbar', $progress->getAttribute( 'role' ) );
		$this->assertSame( '0', $progress->getAttribute( 'aria-valuemin' ) );
		$this->assertSame( '100', $progress->getAttribute( 'aria-valuemax' ) );
		$this->assertSame( '0', $progress->getAttribute( 'aria-valuenow' ) );
		$this->assertSame( 1, $xp->query( '//*[@id="hds-progress"]/*[contains(@class,"hds-progress__bar")]' )->length );
		$this->assertSame( 'polite', $xp->query( '//ul[@id="hds-index-log"]' )->item( 0 )->getAttribute( 'aria-live' ) );

		// Table.
		$this->assertStringContainsString( 'widefat', $xp->query( '//table[@id="hds-docs-table"]' )->item( 0 )->getAttribute( 'class' ) );
		$rows = $xp->query( '//table[@id="hds-docs-table"]/tbody/tr[@data-doc-id]' );
		$this->assertSame( 3, $rows->length );
		$this->assertSame( (string) $indexed, $rows->item( 0 )->getAttribute( 'data-doc-id' ) );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( 'Ruling &lt;script&gt;alert(1)&lt;/script&gt;', $html );

		$first = $rows->item( 0 );
		$this->assertSame( 'Indexed', trim( $xp->query( './/*[@data-hds-status]', $first )->item( 0 )->textContent ) );
		$this->assertStringContainsString( 'hds-status--indexed', $xp->query( './/*[@data-hds-status]', $first )->item( 0 )->getAttribute( 'class' ) );
		$this->assertSame( '1,234', trim( $xp->query( './/*[@data-hds-chunks]', $first )->item( 0 )->textContent ) );
		$this->assertStringContainsString( 'ruling.doc', $first->textContent );
		$this->assertStringContainsString( 'DOC', $first->textContent );
		$this->assertStringContainsString( '2 KB', $first->textContent );
		$this->assertMatchesRegularExpression( '/\d{4}/', trim( $xp->query( './/td[contains(@class,"hds-col-date")]', $first )->item( 0 )->textContent ) );

		$this->assertSame( 'Error: The file is damaged.', trim( $xp->query( './/*[@data-hds-status]', $rows->item( 1 ) )->item( 0 )->textContent ) );
		$this->assertSame( 'Available in the full version', trim( $xp->query( './/*[@data-hds-status]', $rows->item( 2 ) )->item( 0 )->textContent ) );

		// Rename and delete forms: POST to admin-post.php with per-document nonces.
		foreach ( array( $indexed, $broken, $locked ) as $i => $id ) {
			$row    = $rows->item( $i );
			$rename = $xp->query( './/form[contains(@class,"hds-rename-form")]', $row )->item( 0 );
			$this->assertSame( 'https://example.co.il/wp-admin/admin-post.php', $rename->getAttribute( 'action' ) );
			$this->assertSame( 'hds_rename', $xp->query( './/input[@name="action"]', $rename )->item( 0 )->getAttribute( 'value' ) );
			$this->assertSame( wp_create_nonce( 'hds_rename_' . $id ), $xp->query( './/input[@name="_hds_nonce"]', $rename )->item( 0 )->getAttribute( 'value' ) );
			$this->assertSame( '255', $xp->query( './/input[@name="title"]', $rename )->item( 0 )->getAttribute( 'maxlength' ) );
			$this->assertSame( $rename->getAttribute( 'id' ), $xp->query( './/*[contains(@class,"hds-rename-toggle")]', $row )->item( 0 )->getAttribute( 'aria-controls' ) );

			$delete = $xp->query( './/form[contains(@class,"hds-delete-form")]', $row )->item( 0 );
			$this->assertSame( 'post', $delete->getAttribute( 'method' ) );
			$this->assertSame( 'hds_delete', $xp->query( './/input[@name="action"]', $delete )->item( 0 )->getAttribute( 'value' ) );
			$this->assertSame( (string) $id, $xp->query( './/input[@name="doc_id"]', $delete )->item( 0 )->getAttribute( 'value' ) );
			$this->assertSame( wp_create_nonce( 'hds_delete_' . $id ), $xp->query( './/input[@name="_hds_nonce"]', $delete )->item( 0 )->getAttribute( 'value' ) );
			$this->assertNotSame( '', $delete->getAttribute( 'data-hds-confirm' ) );
			$this->assertSame( 'Delete', trim( $xp->query( './/button[contains(@class,"hds-delete-button")]', $delete )->item( 0 )->textContent ) );
		}

		// Demo: badge and limit info.
		$this->assertSame( 'Demo', trim( $xp->query( '//*[contains(@class,"hds-badge--demo")]' )->item( 0 )->textContent ) );
		$this->assertStringContainsString( 'Documents: 3 · Indexed: 1', $html );
		$this->assertStringContainsString( 'up to 15 documents', $html );
	}

	public function test_documents_page_empty_state(): void {
		$html = $this->capture( array( 'HDS_Admin', 'render_documents_page' ) );
		$xp   = $this->xpath( $html );
		$this->assertStringContainsString( 'No documents yet.', $html );
		$this->assertSame( 0, $xp->query( '//tr[@data-doc-id]' )->length );
		$this->assertTrue( $xp->query( '//button[@id="hds-index-button"]' )->item( 0 )->hasAttribute( 'disabled' ) );
		$this->assertStringNotContainsString( 'hds-notice-reindex', $html );
	}

	public function test_documents_page_asks_to_update_the_index(): void {
		$this->insert_doc( array( 'title' => 'New' ) );
		$html = $this->capture( array( 'HDS_Admin', 'render_documents_page' ) );
		$this->assertStringContainsString( '1 document is not indexed yet. Click “Update index”', $html );

		// A document that was indexed before and became pending (embedding model changed).
		$id = $this->insert_doc( array( 'title' => 'Was indexed' ) );
		HDS_Documents::update(
			$id,
			array(
				'chunk_count' => 7,
				'index_hash'  => str_repeat( 'b', 64 ),
			)
		);
		$html = $this->capture( array( 'HDS_Admin', 'render_documents_page' ) );
		$this->assertStringContainsString( 'The search index is out of date (for example, after the embedding model was changed): 2 documents need to be indexed again.', $html );
	}

	public function test_documents_page_over_demo_limit(): void {
		for ( $i = 0; $i < 17; $i++ ) {
			$this->insert_doc( array( 'title' => 'Doc ' . $i ) );
		}
		$html = $this->capture( array( 'HDS_Admin', 'render_documents_page' ) );
		$this->assertStringContainsString( 'only the first 15 documents are indexed; 2 more are available in the full version', $html );
		// Only the 15 documents that demo mode will index are "waiting".
		$this->assertStringContainsString( '15 documents are not indexed yet.', $html );
	}

	public function test_pages_require_capability(): void {
		$GLOBALS['hds_test']['caps'] = array();
		ob_start();
		try {
			$this->expect_die( array( 'HDS_Admin', 'render_documents_page' ) );
			$this->expect_die( array( 'HDS_Admin', 'render_settings_page' ) );
		} finally {
			$this->assertSame( '', ob_get_clean() );
		}
	}

	public function test_settings_page_without_key(): void {
		$html = $this->capture( array( 'HDS_Admin', 'render_settings_page' ) );
		$xp   = $this->xpath( $html );
		$this->assert_unique_ids( $xp );

		$form = $xp->query( '//form[@id="hds-settings-form"]' )->item( 0 );
		$this->assertStringEndsWith( 'options.php', $form->getAttribute( 'action' ) );
		$this->assertSame( 'hds_settings_group', $xp->query( '//input[@name="option_page"]' )->item( 0 )->getAttribute( 'value' ) );

		$key = $xp->query( '//input[@id="hds-api-key"]' )->item( 0 );
		$this->assertSame( 'password', $key->getAttribute( 'type' ) );
		$this->assertSame( 'hds_settings[api_key_new]', $key->getAttribute( 'name' ) );
		$this->assertSame( '', $key->getAttribute( 'value' ) );
		$this->assertSame( 'new-password', $key->getAttribute( 'autocomplete' ) );
		$this->assertSame( 0, $xp->query( '//input[@name="hds_settings[api_key_remove]"]' )->length );
		$this->assertSame( 1, $xp->query( '//button[@id="hds-test-api"]' )->length );

		foreach ( array( 'embedding_model' => 'text-embedding-3-small', 'chat_model' => 'gpt-4o-mini', 'top_n' => '5', 'rate_limit' => '10', 'max_file_mb' => '20' ) as $field => $value ) {
			$input = $xp->query( '//input[@name="hds_settings[' . $field . ']"]' )->item( 0 );
			$this->assertNotNull( $input, $field );
			$this->assertSame( $value, $input->getAttribute( 'value' ), $field );
			$this->assertSame( 1, $xp->query( '//label[@for="' . $input->getAttribute( 'id' ) . '"]' )->length, $field );
		}
		$this->assertSame( 'hds-embedding-models', $xp->query( '//input[@name="hds_settings[embedding_model]"]' )->item( 0 )->getAttribute( 'list' ) );
		$this->assertSame( 1, $xp->query( '//datalist[@id="hds-embedding-models"]' )->length );

		$this->assertStringContainsString( 'Demo mode', $html );
		$this->assertStringContainsString( 'Site domain for licensing:', $html );
		$this->assertStringContainsString( '>example.co.il<', $html );
		$this->assertSame( 1, $xp->query( '//textarea[@name="hds_settings[license_key]"]' )->length );
		$this->assertSame( 'Demo', trim( $xp->query( '//*[contains(@class,"hds-badge--demo")]' )->item( 0 )->textContent ) );
	}

	public function test_settings_page_never_prints_stored_key(): void {
		$secret = 'sk-proj-' . str_repeat( 'Q', 40 ) . 'wxyz';
		HDS_Settings::update(
			array(
				'api_key_enc' => HDS_Settings::encrypt( $secret ),
				'license_key' => 'AbC+/=123',
			)
		);
		$this->assertSame( 'option', HDS_Settings::api_key_source() );

		$html = $this->capture( array( 'HDS_Admin', 'render_settings_page' ) );
		$xp   = $this->xpath( $html );
		$this->assertStringNotContainsString( $secret, $html );
		$this->assertStringNotContainsString( str_repeat( 'Q', 20 ), $html );
		$this->assertStringNotContainsString( HDS_Settings::get( 'api_key_enc' ), $html );

		$key = $xp->query( '//input[@id="hds-api-key"]' )->item( 0 );
		$this->assertSame( '', $key->getAttribute( 'value' ) );
		$this->assertSame( 'sk-…wxyz', $key->getAttribute( 'placeholder' ) );
		$this->assertSame( 'checkbox', $xp->query( '//input[@name="hds_settings[api_key_remove]"]' )->item( 0 )->getAttribute( 'type' ) );
		$this->assertSame( 'AbC+/=123', $xp->query( '//textarea[@name="hds_settings[license_key]"]' )->item( 0 )->textContent );
	}

	public function test_settings_page_shows_settings_errors(): void {
		add_settings_error( 'hds_settings', 'x', 'License key is not valid for example.co.il.', 'error' );
		$html = $this->capture( array( 'HDS_Admin', 'render_settings_page' ) );
		$this->assertStringContainsString( 'License key is not valid for example.co.il.', $html );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	public function test_format_helpers(): void {
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		$this->assertSame( '2026-01-02 03:04', HDS_Admin::format_gmt_date( '2026-01-02 03:04:05' ) );
		$this->assertSame( '—', HDS_Admin::format_gmt_date( '' ) );
		$this->assertSame( '—', HDS_Admin::format_gmt_date( '0000-00-00 00:00:00' ) );

		$this->assertSame( 'hds-status hds-status--demo_locked', HDS_Admin::status_class( 'demo_locked' ) );
		$this->assertSame( 'hds-status hds-status--xscript', HDS_Admin::status_class( '"x<script>' ) );
		$this->assertSame( 'hds-status hds-status--unknown', HDS_Admin::status_class( '' ) );

		$this->assertSame( array( 'doc', 'docx', 'pdf', 'txt' ), HDS_Admin::supported_extensions() );
		$this->assertSame( (int) wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ), HDS_Admin::post_max_bytes() );
	}

	public function test_current_page(): void {
		$this->assertSame( '', HDS_Admin::current_page() );
		$_GET['page'] = 'hds-settings';
		$this->assertSame( 'hds-settings', HDS_Admin::current_page() );
		$_GET['page'] = array( 'hds-settings' );
		$this->assertSame( '', HDS_Admin::current_page() );

		HDS_Admin::admin_menu();
		$this->assertSame( 'hds-documents', HDS_Admin::current_page( 'toplevel_page_hds-documents' ) );
		$this->assertSame( 'hds-settings', HDS_Admin::current_page( 'ai-search_page_hds-settings' ) );
		$this->assertSame( '', HDS_Admin::current_page( 'edit.php' ) );
	}
}
