<?php
/**
 * Tests for HDS_OpenAI_Client and HDS_OpenAI_Exception.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

// Minimal WordPress HTTP API stubs for testing the default transport. Only
// defined when no other stub file provides them (see test_wp_transport).
if ( ! function_exists( 'wp_remote_post' ) ) {
	define( 'HDS_CORE_TEST_HTTP_STUBS', true );

	/**
	 * Stub: returns $GLOBALS['hds_test']['http_response'] and records the call.
	 *
	 * @param string $url  URL.
	 * @param array  $args Args.
	 * @return array|WP_Error
	 */
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['hds_test']['http_calls'][] = array( $url, $args );
		return $GLOBALS['hds_test']['http_response'];
	}

	/**
	 * Stub.
	 *
	 * @param array|WP_Error $response Response.
	 * @return int|string
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : '';
	}

	/**
	 * Stub.
	 *
	 * @param array|WP_Error $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
	}

	/**
	 * Stub (case-insensitive like WordPress).
	 *
	 * @param array|WP_Error $response Response.
	 * @param string         $header   Header name.
	 * @return array|string
	 */
	function wp_remote_retrieve_header( $response, $header ) {
		if ( ! is_array( $response ) || empty( $response['headers'] ) ) {
			return '';
		}
		$headers = array_change_key_case( $response['headers'], CASE_LOWER );
		$header  = strtolower( $header );
		return isset( $headers[ $header ] ) ? $headers[ $header ] : '';
	}
}

/**
 * OpenAI client tests (fake transport, recording no-op sleep).
 */
class OpenAIClientTest extends TestCase {

	const KEY = 'sk-proj-TESTKEY_0123456789abcdefSECRET';

	/**
	 * Queued transport responses (or callables producing one).
	 *
	 * @var array
	 */
	private $queue = array();

	/**
	 * Recorded transport calls: [url, request].
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Recorded sleep durations.
	 *
	 * @var float[]
	 */
	private $sleeps = array();

	protected function setUp(): void {
		hds_test_reset();
		$this->queue  = array();
		$this->calls  = array();
		$this->sleeps = array();
	}

	/**
	 * Client wired to the fake transport.
	 *
	 * @param array  $options Extra options.
	 * @param string $key     API key.
	 * @return HDS_OpenAI_Client
	 */
	private function client( array $options = array(), string $key = self::KEY ): HDS_OpenAI_Client {
		$transport = function ( string $url, array $request ) {
			$this->calls[] = array( $url, $request );
			$this->assertNotEmpty( $this->queue, 'Unexpected extra HTTP request.' );
			$next = array_shift( $this->queue );
			return is_callable( $next ) ? $next( $url, $request ) : $next;
		};
		$sleep     = function ( $seconds ) {
			$this->sleeps[] = (float) $seconds;
		};
		return new HDS_OpenAI_Client( $key, $transport, array_merge( array( 'sleep' => $sleep ), $options ) );
	}

	/**
	 * JSON response.
	 *
	 * @param int   $code    Status.
	 * @param mixed $data    Body data.
	 * @param array $headers Headers.
	 * @return array
	 */
	private static function json( int $code, $data, array $headers = array() ): array {
		return array(
			'code'    => $code,
			'body'    => json_encode( $data ),
			'headers' => $headers,
		);
	}

	/**
	 * OpenAI error body.
	 *
	 * @param int    $code    Status.
	 * @param string $message Message.
	 * @param string $type    Type.
	 * @param string $err     Code.
	 * @param array  $headers Headers.
	 * @param string $param   Param.
	 * @return array
	 */
	private static function error( int $code, string $message, string $type = '', string $err = '', array $headers = array(), string $param = '' ): array {
		return self::json(
			$code,
			array(
				'error' => array(
					'message' => $message,
					'type'    => $type,
					'code'    => '' === $err ? null : $err,
					'param'   => '' === $param ? null : $param,
				),
			),
			$headers
		);
	}

	/**
	 * Embeddings response for $count inputs ($dims each), optionally shuffled.
	 *
	 * @param int  $count   Count.
	 * @param int  $dims    Dimensions.
	 * @param bool $shuffle Shuffle data order.
	 * @param int  $offset  Value offset (to identify batches).
	 * @return array
	 */
	private static function embeddings( int $count, int $dims = 3, bool $shuffle = false, int $offset = 0 ): array {
		$data = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$vector = array();
			for ( $d = 0; $d < $dims; $d++ ) {
				$vector[] = ( $offset + $i ) + $d / 10;
			}
			$data[] = array(
				'object'    => 'embedding',
				'index'     => $i,
				'embedding' => $vector,
			);
		}
		if ( $shuffle ) {
			$data = array_reverse( $data );
		}
		return self::json(
			200,
			array(
				'object' => 'list',
				'data'   => $data,
				'model'  => 'text-embedding-3-small',
			)
		);
	}

	/**
	 * Chat response.
	 *
	 * @param mixed $content Content.
	 * @return array
	 */
	private static function chat_response( $content ): array {
		return self::json(
			200,
			array(
				'choices' => array(
					array(
						'index'   => 0,
						'message' => array(
							'role'    => 'assistant',
							'content' => $content,
						),
					),
				),
			)
		);
	}

	/**
	 * Run $fn and return the HDS_OpenAI_Exception it throws.
	 *
	 * @param callable $fn Function.
	 * @return HDS_OpenAI_Exception
	 */
	private function catch_exception( callable $fn ): HDS_OpenAI_Exception {
		try {
			$fn();
		} catch ( HDS_OpenAI_Exception $e ) {
			return $e;
		}
		$this->fail( 'HDS_OpenAI_Exception expected.' );
	}

	public function test_constants(): void {
		$this->assertSame( 'https://api.openai.com/v1/', HDS_OpenAI_Client::API_BASE );
		$this->assertSame( 64, HDS_OpenAI_Client::MAX_BATCH );
	}

	public function test_embed_request_and_response(): void {
		$this->queue[] = self::embeddings( 2, 3, true );
		$vectors       = $this->client()->embed( array( 'שלום עולם', 'second' ), 'text-embedding-3-small' );

		$this->assertSame( array( array( 0.0, 0.1, 0.2 ), array( 1.0, 1.1, 1.2 ) ), $vectors, 'Sorted by index, floats.' );
		$this->assertCount( 1, $this->calls );
		list( $url, $request ) = $this->calls[0];
		$this->assertSame( 'https://api.openai.com/v1/embeddings', $url );
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'Bearer ' . self::KEY, $request['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['headers']['Content-Type'] );
		$this->assertSame( 45, $request['timeout'] );
		$this->assertSame(
			array(
				'model' => 'text-embedding-3-small',
				'input' => array( 'שלום עולם', 'second' ),
			),
			json_decode( $request['body'], true )
		);
		$this->assertStringContainsString( 'שלום עולם', $request['body'], 'Hebrew is sent unescaped (smaller body).' );
		$this->assertSame( array(), $this->sleeps );
	}

	public function test_embed_batches_of_max_batch(): void {
		$texts = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$texts[] = 'text ' . $i;
		}
		$this->queue[] = self::embeddings( 64, 2, true, 0 );
		$this->queue[] = self::embeddings( 64, 2, false, 64 );
		$this->queue[] = self::embeddings( 22, 2, true, 128 );

		$vectors = $this->client()->embed( $texts, 'm' );
		$this->assertCount( 150, $vectors );
		$this->assertSame( range( 0, 149 ), array_keys( $vectors ) );
		foreach ( $vectors as $i => $vector ) {
			$this->assertSame( (float) $i, $vector[0] );
		}
		$this->assertCount( 3, $this->calls );
		$sizes = array_map(
			static function ( $call ) {
				return count( json_decode( $call[1]['body'], true )['input'] );
			},
			$this->calls
		);
		$this->assertSame( array( 64, 64, 22 ), $sizes );
		$this->assertSame( 'text 64', json_decode( $this->calls[1][1]['body'], true )['input'][0] );
	}

	public function test_embed_edge_inputs(): void {
		$this->assertSame( array(), $this->client()->embed( array(), 'm' ) );
		$this->assertSame( array(), $this->calls );

		$e = $this->catch_exception(
			function () {
				$this->client()->embed( array( 'ok', '   ' ), 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::BAD_REQUEST, $e->get_error_code() );
		$this->assertSame( array(), $this->calls, 'Nothing is sent for invalid input.' );

		// Keys of the input array are ignored; invalid UTF-8 is still sent as JSON.
		$this->queue[] = self::embeddings( 2 );
		$this->client()->embed( array( 'a' => "bad \xFF utf8", 'b' => 'x' ), 'm' );
		$body = json_decode( $this->calls[0][1]['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( array( 0, 1 ), array_keys( $body['input'] ) );
	}

	/**
	 * @dataProvider bad_embedding_provider
	 *
	 * @param array $response Response.
	 */
	public function test_embed_rejects_bad_responses( array $response ): void {
		$this->queue[] = $response;
		$e             = $this->catch_exception(
			function () {
				$this->client()->embed( array( 'a', 'b' ), 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::BAD_RESPONSE, $e->get_error_code() );
		$this->assertFalse( $e->is_fatal() );
		$this->assertCount( 1, $this->calls, 'Bad responses are not retried.' );
	}

	/**
	 * @return array
	 */
	public function bad_embedding_provider(): array {
		$item = static function ( $index, $embedding ) {
			return array(
				'index'     => $index,
				'embedding' => $embedding,
			);
		};
		return array(
			'not json'        => array(
				array(
					'code'    => 200,
					'body'    => '<html>proxy error</html>',
					'headers' => array(),
				),
			),
			'no data'         => array( self::json( 200, array( 'object' => 'list' ) ) ),
			'data not array'  => array( self::json( 200, array( 'data' => 'x' ) ) ),
			'wrong count'     => array( self::json( 200, array( 'data' => array( $item( 0, array( 1.0 ) ) ) ) ) ),
			'no embedding'    => array( self::json( 200, array( 'data' => array( array( 'index' => 0 ), array( 'index' => 1 ) ) ) ) ),
			'empty embedding' => array( self::json( 200, array( 'data' => array( $item( 0, array() ), $item( 1, array() ) ) ) ) ),
			'non numeric'     => array( self::json( 200, array( 'data' => array( $item( 0, array( 1.0, 'x' ) ), $item( 1, array( 1.0, 2.0 ) ) ) ) ) ),
			'bad index'       => array( self::json( 200, array( 'data' => array( $item( 0, array( 1.0 ) ), $item( 5, array( 1.0 ) ) ) ) ) ),
			'duplicate index' => array( self::json( 200, array( 'data' => array( $item( 1, array( 1.0 ) ), $item( 1, array( 1.0 ) ) ) ) ) ),
			'string index'    => array( self::json( 200, array( 'data' => array( $item( '0', array( 1.0 ) ), $item( 1, array( 1.0 ) ) ) ) ) ),
			'dims mismatch'   => array( self::json( 200, array( 'data' => array( $item( 0, array( 1.0, 2.0 ) ), $item( 1, array( 1.0 ) ) ) ) ) ),
			'scalar body'     => array( self::json( 200, 42 ) ),
		);
	}

	public function test_embed_dims_must_match_across_batches(): void {
		$texts         = array_fill( 0, 65, 'x' );
		$this->queue[] = self::embeddings( 64, 3 );
		$this->queue[] = self::embeddings( 1, 4 );
		$e             = $this->catch_exception(
			function () use ( $texts ) {
				$this->client()->embed( $texts, 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::BAD_RESPONSE, $e->get_error_code() );
	}

	public function test_chat_request_and_response(): void {
		$this->queue[] = self::chat_response( 'תשובה' );
		$messages      = array(
			array(
				'role'    => 'system',
				'content' => 'sys',
			),
			array(
				'role'    => 'user',
				'content' => 'שאלה',
			),
		);
		$answer        = $this->client()->chat(
			$messages,
			'gpt-4o-mini',
			array(
				'temperature' => 0.1,
				'max_tokens'  => 350,
			)
		);

		$this->assertSame( 'תשובה', $answer );
		list( $url, $request ) = $this->calls[0];
		$this->assertSame( 'https://api.openai.com/v1/chat/completions', $url );
		$this->assertSame(
			array(
				'model'       => 'gpt-4o-mini',
				'messages'    => $messages,
				'temperature' => 0.1,
				'max_tokens'  => 350,
			),
			json_decode( $request['body'], true )
		);
	}

	public function test_chat_defaults_and_custom_params(): void {
		$this->queue[] = self::chat_response( 'a' );
		$this->queue[] = self::chat_response( '' );
		$client        = $this->client();
		$client->chat( array( array( 'role' => 'user', 'content' => 'q' ) ), 'm' );
		$body = json_decode( $this->calls[0][1]['body'], true );
		$this->assertSame( array( 'model', 'messages', 'temperature', 'max_tokens' ), array_keys( $body ) );
		$this->assertIsNumeric( $body['temperature'] );
		$this->assertIsInt( $body['max_tokens'] );

		$this->assertSame(
			'',
			$client->chat(
				array( array( 'role' => 'user', 'content' => 'q' ) ),
				'm',
				array(
					'temperature' => 0.7,
					'max_tokens'  => 10,
				)
			)
		);
		$body = json_decode( $this->calls[1][1]['body'], true );
		$this->assertEquals( 0.7, $body['temperature'] );
		$this->assertSame( 10, $body['max_tokens'] );
	}

	public function test_chat_validates_response(): void {
		foreach ( array(
			self::json( 200, array( 'choices' => array() ) ),
			self::json( 200, array( 'choices' => array( array( 'message' => 'x' ) ) ) ),
			self::chat_response( null ),
			self::chat_response( array( 'x' ) ),
			self::json( 200, array() ),
		) as $i => $response ) {
			$this->queue[] = $response;
			$e             = $this->catch_exception(
				function () {
					$this->client()->chat( array( array( 'role' => 'user', 'content' => 'q' ) ), 'm' );
				}
			);
			$this->assertSame( HDS_OpenAI_Exception::BAD_RESPONSE, $e->get_error_code(), "Case $i" );
		}

		// A refusal is returned as text.
		$this->queue[] = self::json(
			200,
			array(
				'choices' => array(
					array(
						'message' => array(
							'content' => null,
							'refusal' => 'I cannot help with that.',
						),
					),
				),
			)
		);
		$this->assertSame( 'I cannot help with that.', $this->client()->chat( array(), 'm' ) );
	}

	public function test_chat_falls_back_for_reasoning_models(): void {
		$this->queue[] = self::error( 400, "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.", 'invalid_request_error', 'unsupported_parameter', array(), 'max_tokens' );
		$this->queue[] = self::chat_response( 'ok' );
		$this->assertSame( 'ok', $this->client()->chat( array( array( 'role' => 'user', 'content' => 'q' ) ), 'gpt-5-mini', array( 'max_tokens' => 350 ) ) );

		$this->assertCount( 2, $this->calls );
		$retry = json_decode( $this->calls[1][1]['body'], true );
		$this->assertArrayNotHasKey( 'max_tokens', $retry );
		$this->assertArrayNotHasKey( 'temperature', $retry );
		$this->assertGreaterThanOrEqual( 350, $retry['max_completion_tokens'] );
		$this->assertSame( 'gpt-5-mini', $retry['model'] );

		// Other 400 errors are not retried.
		$this->calls   = array();
		$this->queue[] = self::error( 400, 'Invalid model', 'invalid_request_error', 'model_not_found', array(), 'model' );
		$e             = $this->catch_exception(
			function () {
				$this->client()->chat( array(), 'nope' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::BAD_REQUEST, $e->get_error_code() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_retries_rate_limit_with_exponential_backoff(): void {
		$this->queue[] = self::error( 429, 'Rate limit reached for requests', 'requests', 'rate_limit_exceeded' );
		$this->queue[] = self::error( 429, 'Rate limit reached for requests', 'requests', 'rate_limit_exceeded' );
		$this->queue[] = self::embeddings( 1 );

		$this->assertCount( 1, $this->client()->embed( array( 'a' ), 'm' ) );
		$this->assertCount( 3, $this->calls );
		$this->assertCount( 2, $this->sleeps );
		$this->assertGreaterThanOrEqual( 1.0, $this->sleeps[0] );
		$this->assertLessThanOrEqual( 1.25, $this->sleeps[0] );
		$this->assertGreaterThanOrEqual( 2.0, $this->sleeps[1] );
		$this->assertLessThanOrEqual( 2.5, $this->sleeps[1] );
		// The request is re-sent unchanged.
		$this->assertSame( $this->calls[0], $this->calls[2] );
	}

	public function test_gives_up_after_max_attempts(): void {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->queue[] = self::error( 429, 'Rate limit reached', 'tokens', 'rate_limit_exceeded' );
		}
		$e = $this->catch_exception(
			function () {
				$this->client()->embed( array( 'a' ), 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::RATE_LIMITED, $e->get_error_code() );
		$this->assertSame( 429, $e->get_http_status() );
		$this->assertFalse( $e->is_fatal() );
		$this->assertCount( 4, $this->calls );
		$this->assertCount( 3, $this->sleeps, 'No sleep after the last attempt.' );
		$expected = array( 1.0, 2.0, 4.0 );
		foreach ( $this->sleeps as $i => $delay ) {
			$this->assertGreaterThanOrEqual( $expected[ $i ], $delay );
			$this->assertLessThanOrEqual( $expected[ $i ] * 1.25, $delay );
		}
	}

	public function test_backoff_is_capped_and_jittered(): void {
		mt_srand( 99 );
		for ( $i = 0; $i < 8; $i++ ) {
			$this->queue[] = self::error( 503, 'busy' );
		}
		$e = $this->catch_exception(
			function () {
				$this->client(
					array(
						'max_attempts' => 8,
						'base_delay'   => 0.5,
						'max_delay'    => 3.0,
					)
				)->chat( array(), 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::SERVER, $e->get_error_code() );
		$this->assertSame( 503, $e->get_http_status() );
		$this->assertCount( 7, $this->sleeps );
		$base = array( 0.5, 1.0, 2.0, 3.0, 3.0, 3.0, 3.0 );
		foreach ( $this->sleeps as $i => $delay ) {
			$this->assertGreaterThanOrEqual( $base[ $i ], $delay );
			$this->assertLessThanOrEqual( $base[ $i ] * 1.25, $delay );
		}
		$this->assertGreaterThan( 1, count( array_unique( array_map( 'strval', array_slice( $this->sleeps, 3 ) ) ) ), 'Jitter varies the delays.' );
	}

	public function test_time_budget_limits_retries_and_timeouts(): void {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->queue[] = self::error( 429, 'Rate limit', 'requests', 'rate_limit_exceeded' );
		}
		$e = $this->catch_exception(
			function () {
				$this->client( array( 'max_total_time' => 3.5 ) )->embed( array( 'a' ), 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::RATE_LIMITED, $e->get_error_code() );
		// 1st wait ~1 s fits; the 2nd (~2 s) plus another request would exceed 3.5 s.
		$this->assertCount( 2, $this->calls );
		$this->assertCount( 1, $this->sleeps );
		$this->assertSame( 3, $this->calls[0][1]['timeout'] );
		$this->assertSame( 2, $this->calls[1][1]['timeout'] );

		// Without a budget the configured timeout is used.
		$this->calls   = array();
		$this->queue[] = self::embeddings( 1 );
		$this->client( array( 'timeout' => 30 ) )->embed( array( 'a' ), 'm' );
		$this->assertSame( 30, $this->calls[0][1]['timeout'] );
	}

	public function test_retry_after_is_honoured_and_capped(): void {
		$this->queue[] = self::error( 429, 'Rate limit', 'requests', 'rate_limit_exceeded', array( 'retry-after' => '7' ) );
		$this->queue[] = self::error( 503, 'busy', '', '', array( 'Retry-After' => '120' ) );
		$this->queue[] = self::error( 429, 'Rate limit', 'requests', 'rate_limit_exceeded', array( 'retry-after-ms' => '1500' ) );
		$this->queue[] = self::embeddings( 1 );
		$this->client()->embed( array( 'a' ), 'm' );
		$this->assertSame( array( 7.0, 20.0, 1.5 ), $this->sleeps );

		// HTTP-date form.
		$this->sleeps  = array();
		$this->queue[] = self::error( 503, 'busy', '', '', array( 'retry-after' => gmdate( 'D, d M Y H:i:s', time() + 5 ) . ' GMT' ) );
		$this->queue[] = self::embeddings( 1 );
		$this->client()->embed( array( 'a' ), 'm' );
		$this->assertGreaterThanOrEqual( 3.0, $this->sleeps[0] );
		$this->assertLessThanOrEqual( 5.0, $this->sleeps[0] );

		// Invalid header → normal backoff.
		$this->sleeps  = array();
		$this->queue[] = self::error( 503, 'busy', '', '', array( 'retry-after' => 'soon' ) );
		$this->queue[] = self::embeddings( 1 );
		$this->client()->embed( array( 'a' ), 'm' );
		$this->assertGreaterThanOrEqual( 1.0, $this->sleeps[0] );
		$this->assertLessThanOrEqual( 1.25, $this->sleeps[0] );
	}

	public function test_server_errors_retried_only_for_transient_statuses(): void {
		foreach ( array( 500, 502, 503, 504 ) as $status ) {
			$this->calls   = array();
			$this->queue[] = self::error( $status, 'oops' );
			$this->queue[] = self::chat_response( 'ok' );
			$this->assertSame( 'ok', $this->client()->chat( array(), 'm' ), "HTTP $status" );
			$this->assertCount( 2, $this->calls );
		}

		$this->calls   = array();
		$this->queue[] = self::error( 501, 'not implemented' );
		$e             = $this->catch_exception(
			function () {
				$this->client()->chat( array(), 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::SERVER, $e->get_error_code() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_network_errors(): void {
		$this->queue[] = array(
			'code'  => 0,
			'error' => 'cURL error 28: Operation timed out',
		);
		$this->queue[] = self::embeddings( 1 );
		$this->assertCount( 1, $this->client()->embed( array( 'a' ), 'm' ) );
		$this->assertCount( 1, $this->sleeps );

		for ( $i = 0; $i < 4; $i++ ) {
			$this->queue[] = array(
				'code'  => 0,
				'error' => 'cURL error 6: Could not resolve host: api.openai.com',
			);
		}
		$e = $this->catch_exception(
			function () {
				$this->client()->embed( array( 'a' ), 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::NETWORK, $e->get_error_code() );
		$this->assertSame( 0, $e->get_http_status() );
		$this->assertFalse( $e->is_fatal() );
		$this->assertStringContainsString( 'Could not resolve host', $e->getMessage() );

		// Garbage from a custom transport counts as a network error.
		$this->queue[] = 'nonsense';
		$e             = $this->catch_exception(
			function () {
				$this->client( array( 'max_attempts' => 1 ) )->embed( array( 'a' ), 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::NETWORK, $e->get_error_code() );
	}

	public function test_quota_is_fatal_and_not_retried(): void {
		$this->queue[] = self::error( 429, 'You exceeded your current quota, please check your plan and billing details.', 'insufficient_quota', 'insufficient_quota' );
		$e             = $this->catch_exception(
			function () {
				$this->client()->embed( array( 'a' ), 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::QUOTA, $e->get_error_code() );
		$this->assertTrue( $e->is_fatal() );
		$this->assertSame( 429, $e->get_http_status() );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( array(), $this->sleeps );
	}

	public function test_invalid_key_is_fatal_and_not_retried(): void {
		foreach ( array( 401, 403 ) as $status ) {
			$this->calls   = array();
			$this->queue[] = self::error( $status, 'Incorrect API key provided: ' . self::KEY . '.', 'invalid_request_error', 'invalid_api_key' );
			$e             = $this->catch_exception(
				function () {
					$this->client()->chat( array(), 'm' );
				}
			);
			$this->assertSame( HDS_OpenAI_Exception::INVALID_KEY, $e->get_error_code() );
			$this->assertSame( $status, $e->get_http_status() );
			$this->assertTrue( $e->is_fatal() );
			$this->assertCount( 1, $this->calls );
		}
	}

	public function test_bad_request_is_fatal_with_api_message(): void {
		foreach ( array( 400, 404, 422 ) as $status ) {
			$this->queue[] = self::error( $status, 'The model `text-embedding-9` does not exist or you do not have access to it.', 'invalid_request_error', 'model_not_found' );
			$e             = $this->catch_exception(
				function () {
					$this->client()->embed( array( 'a' ), 'text-embedding-9' );
				}
			);
			$this->assertSame( HDS_OpenAI_Exception::BAD_REQUEST, $e->get_error_code() );
			$this->assertSame( $status, $e->get_http_status() );
			$this->assertTrue( $e->is_fatal() );
			$this->assertStringContainsString( 'does not exist', $e->getMessage() );
		}
		$this->assertSame( array(), $this->sleeps );
	}

	public function test_no_key(): void {
		$e = $this->catch_exception(
			function () {
				$this->client( array(), '  ' )->embed( array( 'a' ), 'm' );
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::NO_KEY, $e->get_error_code() );
		$this->assertTrue( $e->is_fatal() );
		$this->assertSame( array(), $this->calls );
	}

	public function test_from_settings_without_key(): void {
		if ( ! class_exists( 'HDS_Settings' ) ) {
			$this->markTestSkipped( 'HDS_Settings is not available yet (DATA agent).' );
		}
		if ( defined( 'HDS_OPENAI_KEY' ) && '' !== HDS_OPENAI_KEY ) {
			$this->markTestSkipped( 'HDS_OPENAI_KEY is defined.' );
		}
		$e = $this->catch_exception(
			function () {
				HDS_OpenAI_Client::from_settings();
			}
		);
		$this->assertSame( HDS_OpenAI_Exception::NO_KEY, $e->get_error_code() );
	}

	public function test_messages_never_contain_key_or_body(): void {
		$secret_text = 'סוד-מסמך-' . bin2hex( random_bytes( 4 ) );
		$echo        = 'Incorrect API key provided: ' . self::KEY . ' (Bearer ' . self::KEY . ') for input ' . $secret_text . ' and sk-otherKEY1234567890 <b>html</b>';
		$scenarios   = array(
			self::error( 401, $echo ),
			self::error( 403, $echo ),
			self::error( 400, $echo ),
			self::error( 404, $echo ),
			self::error( 422, $echo ),
			self::error( 429, $echo, 'insufficient_quota', 'insufficient_quota' ),
			self::error( 429, $echo ),
			self::error( 500, $echo ),
			self::error( 501, $echo ),
			array(
				'code'  => 0,
				'error' => $echo,
			),
			array(
				'code'    => 200,
				'body'    => $echo,
				'headers' => array(),
			),
			self::json( 200, array( 'data' => array( array( 'embedding' => $echo ) ) ) ),
			self::json( 200, array( 'choices' => array( array( 'message' => array( 'content' => array( $echo ) ) ) ) ) ),
			array(
				'code'    => 302,
				'body'    => $echo,
				'headers' => array(),
			),
		);

		$messages = array();
		foreach ( $scenarios as $i => $response ) {
			foreach ( array( 'embed', 'chat' ) as $method ) {
				$this->queue = array_fill( 0, 4, $response );
				$client      = $this->client();
				$e           = $this->catch_exception(
					function () use ( $client, $method, $secret_text ) {
						if ( 'embed' === $method ) {
							$client->embed( array( $secret_text ), 'm' );
						} else {
							$client->chat( array( array( 'role' => 'user', 'content' => $secret_text ) ), 'm' );
						}
					}
				);
				$messages[ "$i-$method" ] = $e->getMessage();
				// The whole exception (message, trace args excluded) must not leak.
				$this->assertStringNotContainsString( self::KEY, $e->getMessage(), "Scenario $i $method" );
				$this->assertStringNotContainsString( 'TESTKEY', $e->getMessage(), "Scenario $i $method" );
				$this->assertStringNotContainsString( 'sk-otherKEY', $e->getMessage(), "Scenario $i $method" );
				$this->assertStringNotContainsString( '<b>', $e->getMessage(), "Scenario $i $method" );
				$this->assertStringNotContainsString( $secret_text, $e->getMessage(), "Scenario $i $method" );
				$this->assertStringNotContainsString( '"input"', $e->getMessage(), "Scenario $i $method" );
				$this->assertStringNotContainsString( '"messages"', $e->getMessage(), "Scenario $i $method" );
				$this->assertLessThan( 600, strlen( $e->getMessage() ) );
			}
		}
		$this->assertCount( 28, $messages );

		// An API message that does not quote the request is shown, scrubbed.
		$this->queue   = array();
		$this->queue[] = self::error( 400, "Invalid 'model': key " . self::KEY . ' <i>x</i>' );
		$e             = $this->catch_exception(
			function () use ( $secret_text ) {
				$this->client()->embed( array( $secret_text ), 'm' );
			}
		);
		$this->assertStringContainsString( "Invalid 'model': key [redacted] x", $e->getMessage() );

		// Default messages.
		foreach ( array( 'no_key', 'invalid_key', 'quota', 'rate_limited', 'server_error', 'bad_request', 'network', 'bad_response', 'other' ) as $code ) {
			$e = new HDS_OpenAI_Exception( $code );
			$this->assertNotSame( '', $e->getMessage() );
			$this->assertSame( $code, $e->get_error_code() );
			$this->assertSame( 0, $e->get_http_status() );
		}
	}

	public function test_exception_api(): void {
		$prev = new RuntimeException( 'inner' );
		$e    = new HDS_OpenAI_Exception( HDS_OpenAI_Exception::SERVER, 'Custom message', 502, $prev );
		$this->assertSame( 'server_error', $e->get_error_code() );
		$this->assertSame( 'Custom message', $e->getMessage() );
		$this->assertSame( 502, $e->get_http_status() );
		$this->assertSame( $prev, $e->getPrevious() );
		$this->assertInstanceOf( Exception::class, $e );

		$fatal = array(
			'no_key'       => true,
			'invalid_key'  => true,
			'quota'        => true,
			'bad_request'  => true,
			'rate_limited' => false,
			'server_error' => false,
			'network'      => false,
			'bad_response' => false,
		);
		$this->assertSame( 'no_key', HDS_OpenAI_Exception::NO_KEY );
		$this->assertSame( 'invalid_key', HDS_OpenAI_Exception::INVALID_KEY );
		$this->assertSame( 'quota', HDS_OpenAI_Exception::QUOTA );
		$this->assertSame( 'rate_limited', HDS_OpenAI_Exception::RATE_LIMITED );
		$this->assertSame( 'server_error', HDS_OpenAI_Exception::SERVER );
		$this->assertSame( 'bad_request', HDS_OpenAI_Exception::BAD_REQUEST );
		$this->assertSame( 'network', HDS_OpenAI_Exception::NETWORK );
		$this->assertSame( 'bad_response', HDS_OpenAI_Exception::BAD_RESPONSE );
		foreach ( $fatal as $code => $is_fatal ) {
			$this->assertSame( $is_fatal, ( new HDS_OpenAI_Exception( $code ) )->is_fatal(), $code );
		}
	}

	public function test_default_sleep(): void {
		$t0 = microtime( true );
		HDS_OpenAI_Client::default_sleep( 0.05 );
		HDS_OpenAI_Client::default_sleep( 0 );
		HDS_OpenAI_Client::default_sleep( -1 );
		$elapsed = microtime( true ) - $t0;
		$this->assertGreaterThanOrEqual( 0.045, $elapsed );
		$this->assertLessThan( 1.0, $elapsed );
	}

	public function test_wp_transport(): void {
		if ( ! defined( 'HDS_CORE_TEST_HTTP_STUBS' ) ) {
			$this->markTestSkipped( 'wp_remote_post() is provided by another stub file.' );
		}
		$GLOBALS['hds_test']['http_calls']    = array();
		$GLOBALS['hds_test']['http_response'] = array(
			'response' => array( 'code' => 429 ),
			'headers'  => array(
				'Retry-After'  => '3',
				'Content-Type' => 'application/json',
			),
			'body'     => '{"error":{"message":"slow down"}}',
		);
		$request = array(
			'method'  => 'POST',
			'headers' => array( 'Authorization' => 'Bearer x' ),
			'body'    => '{}',
			'timeout' => 45,
		);
		$result  = HDS_OpenAI_Client::wp_transport( 'https://api.openai.com/v1/embeddings', $request );
		$this->assertSame( 429, $result['code'] );
		$this->assertSame( '{"error":{"message":"slow down"}}', $result['body'] );
		$this->assertSame( '3', $result['headers']['retry-after'] );
		$this->assertSame( 'application/json', $result['headers']['content-type'] );

		list( $url, $args ) = $GLOBALS['hds_test']['http_calls'][0];
		$this->assertSame( 'https://api.openai.com/v1/embeddings', $url );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( 'body', $args['data_format'] );
		$this->assertSame( 45, $args['timeout'] );
		$this->assertSame( '{}', $args['body'] );
		$this->assertSame( array( 'Authorization' => 'Bearer x' ), $args['headers'] );

		$GLOBALS['hds_test']['http_response'] = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		$this->assertSame(
			array(
				'code'  => 0,
				'error' => 'cURL error 7: Failed to connect',
			),
			HDS_OpenAI_Client::wp_transport( 'https://api.openai.com/v1/embeddings', $request )
		);

		// The client uses it by default.
		$GLOBALS['hds_test']['http_response'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => json_encode( array( 'data' => array( array( 'index' => 0, 'embedding' => array( 0.5, 0.25 ) ) ) ) ),
		);
		$client = new HDS_OpenAI_Client( self::KEY );
		$this->assertSame( array( array( 0.5, 0.25 ) ), $client->embed( array( 'a' ), 'm' ) );
	}
}
