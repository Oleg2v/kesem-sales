<?php
/**
 * Tests for HDS_REST (nonce, routes, rate limit, endpoints) and the
 * HDS_Frontend markup/assets.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Answerer double.
 */
class HDS_REST_Test_Answerer {

	/** @var int */
	public $calls = 0;
	/** @var string|null */
	public $last_query = null;
	/** @var array|WP_Error */
	public $result = array(
		'answer'  => 'תשובה.',
		'sources' => array(),
		'terms'   => array(),
		'cached'  => false,
		'demo'    => true,
	);
	/** @var Throwable|null */
	public $error = null;

	public function ask( string $query ) {
		++$this->calls;
		$this->last_query = $query;
		if ( $this->error ) {
			throw $this->error;
		}
		return $this->result;
	}
}

/**
 * HDS_REST with an injected answerer.
 */
class HDS_REST_Test_REST extends HDS_REST {

	/** @var HDS_REST_Test_Answerer */
	public static $answerer;

	protected static function answerer() {
		return self::$answerer;
	}
}

/**
 * REST and front-end tests.
 */
class RestTest extends TestCase {

	const NOW = 1700000000;

	/** @var array */
	private $server = array();

	protected function setUp(): void {
		hds_test_reset();
		hds_test_frontend_late_stubs();
		$this->server                 = $_SERVER;
		$_SERVER['REMOTE_ADDR']       = '203.0.113.7';
		HDS_REST_Test_REST::$answerer = new HDS_REST_Test_Answerer();
		foreach ( array( 'config_added' => false, 'instances' => 0 ) as $property => $value ) {
			$ref = new ReflectionProperty( 'HDS_Frontend', $property );
			$ref->setAccessible( true );
			$ref->setValue( null, $value );
		}
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
	}

	private function ask_request( string $q, ?string $nonce = null ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/hds/v1/ask' );
		$request->set_param( 'q', $q );
		if ( null !== $nonce ) {
			$request->set_header( 'X-HDS-Nonce', $nonce );
		}
		return $request;
	}

	/* ------------------------------------------------------------------
	 * Nonce
	 * ---------------------------------------------------------------- */

	public function test_nonce_uses_the_wordpress_algorithm_for_user_0(): void {
		hds_test_set_time( self::NOW );
		$nonce = HDS_REST::create_nonce();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{10}$/', $nonce );
		$tick = wp_nonce_tick( 'hds_ask' );
		$this->assertSame( substr( wp_hash( $tick . '|hds_ask|0|', 'nonce' ), -12, 10 ), $nonce );
		$this->assertTrue( HDS_REST::verify_nonce( $nonce ) );
		$this->assertTrue( HDS_REST::verify_nonce( " {$nonce}\n" ) );
	}

	public function test_nonce_is_valid_for_the_current_and_previous_tick_only(): void {
		hds_test_set_time( self::NOW );
		$nonce = HDS_REST::create_nonce();

		hds_test_set_time( self::NOW + DAY_IN_SECONDS / 2 ); // Next tick.
		$this->assertNotSame( $nonce, HDS_REST::create_nonce() );
		$this->assertTrue( HDS_REST::verify_nonce( $nonce ), 'Previous tick.' );

		hds_test_set_time( self::NOW + DAY_IN_SECONDS ); // Two ticks later.
		$this->assertFalse( HDS_REST::verify_nonce( $nonce ) );

		hds_test_set_time( self::NOW - DAY_IN_SECONDS / 2 ); // A nonce from the future is not valid.
		$this->assertFalse( HDS_REST::verify_nonce( $nonce ) );
	}

	public function test_garbage_nonces_are_rejected(): void {
		hds_test_set_time( self::NOW );
		$nonce = HDS_REST::create_nonce();
		foreach ( array( '', 'x', 'abcdef1234', strtoupper( $nonce ), $nonce . '0', substr( $nonce, 0, 9 ), str_repeat( "\0", 10 ), $this->other_user_nonce() ) as $bad ) {
			$this->assertFalse( HDS_REST::verify_nonce( $bad ), var_export( $bad, true ) );
		}
	}

	/**
	 * The same algorithm for user 1: must not be accepted.
	 *
	 * @return string
	 */
	private function other_user_nonce(): string {
		return substr( wp_hash( wp_nonce_tick( 'hds_ask' ) . '|hds_ask|1|', 'nonce' ), -12, 10 );
	}

	public function test_check_nonce_reads_the_header_or_the_parameter(): void {
		$nonce = HDS_REST::create_nonce();
		$this->assertTrue( HDS_REST::check_nonce( $this->ask_request( 'שאלה', $nonce ) ) );

		$request = $this->ask_request( 'שאלה' );
		$request->set_param( '_hds_nonce', $nonce );
		$this->assertTrue( HDS_REST::check_nonce( $request ) );

		foreach ( array( null, 'bad', '' ) as $value ) {
			$error = HDS_REST::check_nonce( $this->ask_request( 'שאלה', $value ) );
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertSame( 'hds_bad_nonce', $error->get_error_code() );
			$this->assertSame( array( 'status' => 403 ), $error->get_error_data() );
			$this->assertSame( 'פג תוקף הדף. רעננו את הדף ונסו שוב.', $error->get_error_message() );
		}

		$request = $this->ask_request( 'שאלה' );
		$request->set_param( '_hds_nonce', array( $nonce ) );
		$this->assertInstanceOf( WP_Error::class, HDS_REST::check_nonce( $request ) );
	}

	/* ------------------------------------------------------------------
	 * Routes
	 * ---------------------------------------------------------------- */

	public function test_routes_are_registered(): void {
		HDS_REST::register_routes();
		$routes = $GLOBALS['hds_test']['rest_routes'];
		$this->assertSame( array( '/hds/v1/ask', '/hds/v1/suggest', '/hds/v1/nonce' ), array_keys( $routes ) );

		$ask = $routes['/hds/v1/ask'];
		$this->assertSame( 'POST', $ask['methods'] );
		$this->assertSame( array( 'HDS_REST', 'ask' ), $ask['callback'] );
		$this->assertSame( array( 'HDS_REST', 'check_nonce' ), $ask['permission_callback'] );
		$this->assertTrue( $ask['args']['q']['required'] );
		$this->assertSame( 'string', $ask['args']['q']['type'] );
		$this->assertIsCallable( $ask['args']['q']['validate_callback'] );
		$this->assertIsCallable( $ask['args']['q']['sanitize_callback'] );

		foreach ( array( 'suggest', 'nonce' ) as $name ) {
			$route = $routes[ '/hds/v1/' . $name ];
			$this->assertSame( 'GET', $route['methods'] );
			$this->assertSame( '__return_true', $route['permission_callback'] );
			$this->assertSame( array( 'HDS_REST', $name ), $route['callback'] );
			$this->assertIsCallable( $route['callback'] );
		}
	}

	public function test_query_argument_validation_and_sanitizing(): void {
		$this->assertTrue( HDS_REST::validate_query( str_repeat( 'א', 300 ) ) );
		$this->assertTrue( HDS_REST::validate_query( '  ' . str_repeat( 'a', 300 ) . '  ' ) );

		$error = HDS_REST::validate_query( str_repeat( 'א', 301 ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'hds_query_long', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );

		$this->assertInstanceOf( WP_Error::class, HDS_REST::validate_query( array( 'x' ) ) );
		$this->assertInstanceOf( WP_Error::class, HDS_REST::validate_query( 5 ) );

		$this->assertSame( 'מה הוחלט לגבי האגרה?', HDS_REST::sanitize_query( "<b>מה הוחלט</b>\n לגבי האגרה? " ) );
		$this->assertSame( '', HDS_REST::sanitize_query( array( 'x' ) ) );
	}

	/* ------------------------------------------------------------------
	 * /ask
	 * ---------------------------------------------------------------- */

	public function test_ask_returns_the_answer_without_caching(): void {
		$response = HDS_REST_Test_REST::ask( $this->ask_request( 'מה הוחלט?' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( HDS_REST_Test_REST::$answerer->result, $response->get_data() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'מה הוחלט?', HDS_REST_Test_REST::$answerer->last_query );
	}

	public function test_ask_maps_answerer_errors_to_responses(): void {
		HDS_REST_Test_REST::$answerer->result = new WP_Error( 'hds_no_index', 'עדיין אין מסמכים זמינים לחיפוש.', array( 'status' => 503 ) );
		$response                             = HDS_REST_Test_REST::ask( $this->ask_request( 'שאלה' ) );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'hds_no_index', $response->get_data()['code'] );
		$this->assertSame( 'עדיין אין מסמכים זמינים לחיפוש.', $response->get_data()['message'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		// Unexpected exceptions: generic Hebrew error, no internals.
		HDS_REST_Test_REST::$answerer->error = new RuntimeException( 'SQL failed near SECRET' );
		$response                            = HDS_REST_Test_REST::ask( $this->ask_request( 'שאלה' ) );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'hds_error', $response->get_data()['code'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_rate_limit_returns_429_with_retry_after(): void {
		HDS_Settings::update( array( 'rate_limit' => 2 ) );
		$this->assertSame( 200, HDS_REST_Test_REST::ask( $this->ask_request( 'שאלה 1' ) )->get_status() );
		$this->assertSame( 200, HDS_REST_Test_REST::ask( $this->ask_request( 'שאלה 2' ) )->get_status() );

		$response = HDS_REST_Test_REST::ask( $this->ask_request( 'שאלה 3' ) );
		$this->assertSame( 429, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'hds_rate_limited', $data['code'] );
		$this->assertSame( 'נשלחו יותר מדי בקשות. נסו שוב בעוד דקה.', $data['message'] );
		$this->assertSame( 429, $data['data']['status'] );
		$headers = $response->get_headers();
		$this->assertMatchesRegularExpression( '/^\d+$/', $headers['Retry-After'] );
		$this->assertGreaterThanOrEqual( 1, (int) $headers['Retry-After'] );
		$this->assertLessThanOrEqual( 60, (int) $headers['Retry-After'] );
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
		$this->assertSame( 2, HDS_REST_Test_REST::$answerer->calls, 'The answerer is not called over the limit.' );

		// Other clients are not affected; the IP itself is not stored.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$this->assertSame( 200, HDS_REST_Test_REST::ask( $this->ask_request( 'שאלה 4' ) )->get_status() );
		$this->assertStringNotContainsString( '203.0.113.7', serialize( $GLOBALS['hds_test']['transients'] ) );
	}

	/* ------------------------------------------------------------------
	 * /suggest and /nonce
	 * ---------------------------------------------------------------- */

	public function test_suggest_returns_the_dictionary_publicly_cacheable(): void {
		$response = HDS_REST::suggest( new WP_REST_Request( 'GET', '/hds/v1/suggest' ) );
		$this->assertSame(
			array(
				'version' => '',
				'titles'  => array(),
				'phrases' => array(),
			),
			$response->get_data()
		);

		update_option(
			'hds_suggest_dict',
			array(
				'version' => 'abc',
				'titles'  => array( 'SH-22-06-57879-590' ),
				'phrases' => array( 'החזר אגרה', 'בית המשפט' ),
			)
		);
		$response = HDS_REST::suggest( new WP_REST_Request( 'GET', '/hds/v1/suggest' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'version' => 'abc',
				'titles'  => array( 'SH-22-06-57879-590' ),
				'phrases' => array( 'החזר אגרה', 'בית המשפט' ),
			),
			$response->get_data()
		);
		$this->assertSame( 'public, max-age=300', $response->get_headers()['Cache-Control'] );
	}

	public function test_nonce_endpoint_returns_a_valid_nonce(): void {
		$response = HDS_REST::nonce( new WP_REST_Request( 'GET', '/hds/v1/nonce' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'nonce' ), array_keys( $response->get_data() ) );
		$this->assertTrue( HDS_REST::verify_nonce( $response->get_data()['nonce'] ) );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	/* ------------------------------------------------------------------
	 * Front end (HDS_Frontend)
	 * ---------------------------------------------------------------- */

	public function test_frontend_registers_shortcode_assets_and_block(): void {
		HDS_Frontend::register();

		$this->assertSame( array( 'HDS_Frontend', 'render' ), $GLOBALS['hds_test']['shortcodes']['hds_search'] );

		$script = $GLOBALS['hds_test']['registered']['script']['hds-search'];
		$this->assertSame( HDS_PLUGIN_URL . 'assets/js/hds-search.js', $script['src'] );
		$this->assertSame( array(), $script['deps'] );
		$this->assertSame( HDS_VERSION, $script['ver'] );
		$this->assertTrue( $script['in_footer'] );
		$this->assertSame( HDS_PLUGIN_URL . 'assets/css/hds-search.css', $GLOBALS['hds_test']['registered']['style']['hds-search']['src'] );

		$editor = $GLOBALS['hds_test']['registered']['script']['hds-block-editor'];
		$this->assertSame( HDS_PLUGIN_URL . 'assets/js/hds-block.js', $editor['src'] );
		$this->assertSame( array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ), $editor['deps'] );

		$block = $GLOBALS['hds_test']['blocks']['hds/search'];
		$this->assertSame( 'hds-block-editor', $block['editor_script'] );
		$this->assertSame( 'hds-search', $block['style'] );
		$this->assertSame( array( 'placeholder' => array( 'type' => 'string', 'default' => '' ) ), $block['attributes'] );
		$this->assertSame( array( 'HDS_Frontend', 'render_block' ), $block['render_callback'] );
		$this->assertIsCallable( $block['render_callback'] );

		// Registered, not enqueued.
		$this->assertArrayNotHasKey( 'enqueued', $GLOBALS['hds_test'] );
	}

	public function test_frontend_style_is_enqueued_early_for_posts_with_the_shortcode(): void {
		HDS_Frontend::register();
		$this->assertContains( array( 'HDS_Frontend', 'maybe_enqueue_style' ), $GLOBALS['hds_test']['actions']['wp_enqueue_scripts'] );

		HDS_Frontend::maybe_enqueue_style();
		$this->assertArrayNotHasKey( 'enqueued', $GLOBALS['hds_test'], 'Not singular.' );

		$GLOBALS['hds_test']['post'] = (object) array( 'post_content' => '<p>שלום</p>' );
		HDS_Frontend::maybe_enqueue_style();
		$this->assertArrayNotHasKey( 'enqueued', $GLOBALS['hds_test'], 'No shortcode.' );

		$GLOBALS['hds_test']['post'] = (object) array( 'post_content' => '<p>x</p>[hds_search placeholder="y"]' );
		HDS_Frontend::maybe_enqueue_style();
		$this->assertArrayHasKey( 'hds-search', $GLOBALS['hds_test']['enqueued']['style'] );
		$this->assertArrayNotHasKey( 'script', $GLOBALS['hds_test']['enqueued'], 'The script waits for the form.' );
	}

	public function test_frontend_markup_is_accessible_and_escaped(): void {
		HDS_Frontend::register();
		$html = HDS_Frontend::render( array( 'placeholder' => 'שאלו <b>"כאן"</b> & עוד' ) );

		$this->assertStringNotContainsString( '<b>', $html );
		$doc = new DOMDocument();
		$this->assertTrue( $doc->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR ) );
		$xpath = new DOMXPath( $doc );

		$root = $xpath->query( '//div[contains(@class, "hds-search")]' )->item( 0 );
		$this->assertSame( 'rtl', $root->getAttribute( 'dir' ) );
		$this->assertSame( 'he', $root->getAttribute( 'lang' ) );
		$this->assertTrue( $root->hasAttribute( 'data-hds-search' ) );

		$this->assertSame( 'search', $xpath->query( '//form[@class="hds-form"]' )->item( 0 )->getAttribute( 'role' ) );
		$input = $xpath->query( '//input[@class="hds-input"]' )->item( 0 );
		$this->assertSame( 'search', $input->getAttribute( 'type' ) );
		$this->assertSame( 'combobox', $input->getAttribute( 'role' ) );
		$this->assertSame( 'list', $input->getAttribute( 'aria-autocomplete' ) );
		$this->assertSame( 'false', $input->getAttribute( 'aria-expanded' ) );
		$this->assertSame( 'off', $input->getAttribute( 'autocomplete' ) );
		$this->assertSame( '300', $input->getAttribute( 'maxlength' ) );
		$this->assertSame( 'שאלו "כאן" & עוד', $input->getAttribute( 'placeholder' ) );

		$label = $xpath->query( '//label[@for="' . $input->getAttribute( 'id' ) . '"]' );
		$this->assertSame( 1, $label->length );
		$this->assertStringContainsString( 'hds-sr-only', $label->item( 0 )->getAttribute( 'class' ) );
		$this->assertSame( 'חיפוש במסמכים', $label->item( 0 )->textContent );

		$list = $xpath->query( '//ul[@id="' . $input->getAttribute( 'aria-controls' ) . '"]' )->item( 0 );
		$this->assertSame( 'hds-suggestions', $list->getAttribute( 'class' ) );
		$this->assertSame( 'listbox', $list->getAttribute( 'role' ) );
		$this->assertTrue( $list->hasAttribute( 'hidden' ) );

		$this->assertSame( 'חיפוש', trim( $xpath->query( '//button[@class="hds-submit"]' )->item( 0 )->textContent ) );
		$this->assertSame( 'Demo', $xpath->query( '//span[@class="hds-demo-badge"]' )->item( 0 )->textContent );
		$this->assertSame( 1, $xpath->query( '//div[@class="hds-status"]' )->length );
		$result = $xpath->query( '//div[@class="hds-result"]' )->item( 0 );
		$this->assertSame( 'polite', $result->getAttribute( 'aria-live' ) );
		$this->assertSame( 'false', $result->getAttribute( 'aria-atomic' ) );
		$this->assertStringContainsString( '<noscript>', $html );

		// Default placeholder.
		$this->assertStringContainsString( 'placeholder="הקלידו שאלה או מילות חיפוש…"', HDS_Frontend::render( '' ) );
	}

	public function test_frontend_ids_are_unique_and_config_is_added_once(): void {
		HDS_Frontend::register();
		$first  = HDS_Frontend::render();
		$second = HDS_Frontend::render_block( array( 'placeholder' => 'שאלו על פסקי הדין' ) );
		$this->assertStringContainsString( 'placeholder="שאלו על פסקי הדין"', $second );

		preg_match_all( '/\bid="([^"]+)"/', $first . $second, $m );
		$this->assertSame( count( $m[1] ), count( array_unique( $m[1] ) ), 'Element ids are unique per instance.' );
		$this->assertCount( 10, $m[1] );

		$this->assertArrayHasKey( 'hds-search', $GLOBALS['hds_test']['enqueued']['script'] );
		$this->assertArrayHasKey( 'hds-search', $GLOBALS['hds_test']['enqueued']['style'] );
		$inline = $GLOBALS['hds_test']['enqueued']['inline']['hds-search'];
		$this->assertCount( 1, $inline, 'Configuration added once per page.' );

		list( $code, $position ) = $inline[0];
		$this->assertSame( 'before', $position );
		$this->assertStringStartsWith( 'window.hdsSearch = ', $code );
		$this->assertStringEndsWith( ';', $code );
		$this->assertStringNotContainsString( '<', $code );
		$config = json_decode( substr( $code, strlen( 'window.hdsSearch = ' ), -1 ), true );
		$this->assertSame( 'https://example.co.il/wp-json/hds/v1/', $config['restUrl'] );
		$this->assertTrue( HDS_REST::verify_nonce( $config['nonce'] ) );
		$this->assertTrue( $config['demo'] );
		$this->assertSame( 2, $config['minChars'] );
		$this->assertSame( 300, $config['maxLength'] );
		$this->assertSame( 200, $config['debounce'] );
		$this->assertStringContainsString( 'הקלידו', $code, 'Hebrew is not \\u-escaped.' );
		foreach ( array( 'placeholder', 'submit', 'loading', 'sources', 'disclaimer', 'document', 'suggestions', 'suggestCount', 'suggestOne' ) as $key ) {
			$this->assertNotSame( '', $config['i18n'][ $key ] ?? '', $key );
		}
		$this->assertSame( 'התשובה מבוססת על המסמכים בלבד ואינה מהווה ייעוץ משפטי.', $config['i18n']['disclaimer'] );
		foreach ( array( 'short', 'long', 'rate', 'generic', 'not_configured', 'no_index', 'network', 'nonce' ) as $key ) {
			$this->assertMatchesRegularExpression( '/[\x{0590}-\x{05FF}]/u', $config['i18n']['errors'][ $key ], $key );
		}
		$this->assertStringNotContainsString( 'sk-', $code );
	}

	public function test_frontend_placeholder_is_sanitized_and_limited(): void {
		$long = str_repeat( 'א', 400 );
		$html = HDS_Frontend::render_block( array( 'placeholder' => "<script>x</script>\n" . $long ) );
		$this->assertStringNotContainsString( '<script>', $html );
		preg_match( '/placeholder="([^"]*)"/u', $html, $m );
		$this->assertSame( HDS_Frontend::PLACEHOLDER_MAX, mb_strlen( html_entity_decode( $m[1] ) ) );
		$this->assertStringContainsString( 'placeholder="הקלידו', HDS_Frontend::render_block( array( 'placeholder' => array( 'x' ) ) ) );
		$this->assertStringContainsString( 'placeholder="הקלידו', HDS_Frontend::render_block( array() ) );
	}

	public function test_assets_exist_and_js_is_free_of_innerhtml(): void {
		foreach ( array( 'assets/js/hds-search.js', 'assets/js/hds-block.js', 'assets/css/hds-search.css' ) as $file ) {
			$this->assertFileExists( HDS_PLUGIN_DIR . $file );
		}
		$js = (string) file_get_contents( HDS_PLUGIN_DIR . 'assets/js/hds-search.js' );
		$this->assertStringNotContainsString( 'innerHTML', $js );
		$this->assertStringNotContainsString( 'insertAdjacentHTML', $js );
		$this->assertStringContainsString( "credentials: 'omit'", $js );
		$this->assertStringContainsString( "'X-HDS-Nonce'", $js );
		$this->assertStringNotContainsString( 'console.', $js );
		$block = (string) file_get_contents( HDS_PLUGIN_DIR . 'assets/js/hds-block.js' );
		$this->assertStringContainsString( "registerBlockType( 'hds/search'", $block );
	}
}

