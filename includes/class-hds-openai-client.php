<?php
/**
 * Minimal OpenAI REST client: embeddings and chat completions.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI client.
 *
 * - Retries 429 rate limits (not insufficient_quota), 408, 500/502/503/504
 *   and network errors with exponential backoff + jitter, honouring
 *   Retry-After (capped at max_delay).
 * - Validates response shapes; maps failures to HDS_OpenAI_Exception codes.
 * - Never puts the API key, request bodies or texts into exception messages.
 *
 * The HTTP transport is injectable: callable( string $url, array $request ): array
 * with $request = {method, headers, body, timeout}, returning
 * {code:int, body:string, headers:array (lowercase keys)} or {code:0, error:string}.
 * The default transport uses wp_remote_post(), so WordPress' pre_http_request
 * filter can mock it.
 */
class HDS_OpenAI_Client {

	const API_BASE  = 'https://api.openai.com/v1/';
	const MAX_BATCH = 64;

	/**
	 * Default options.
	 */
	const DEFAULTS = array(
		'max_attempts'   => 4,
		'base_delay'     => 1.0,
		'max_delay'      => 20.0,
		'timeout'        => 45,
		'max_total_time' => 0.0,
	);

	/**
	 * Default chat parameters.
	 */
	const CHAT_DEFAULTS = array(
		'temperature' => 0.1,
		'max_tokens'  => 350,
	);

	/**
	 * Longest API error text kept in a message.
	 */
	const MAX_ERROR_LENGTH = 300;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * HTTP transport.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Sleep function: callable( float $seconds ).
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * Attempts per request (>= 1).
	 *
	 * @var int
	 */
	private $max_attempts;

	/**
	 * First retry delay in seconds.
	 *
	 * @var float
	 */
	private $base_delay;

	/**
	 * Longest retry delay in seconds.
	 *
	 * @var float
	 */
	private $max_delay;

	/**
	 * HTTP timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Time budget in seconds for one call including retries and waits (0 = none).
	 *
	 * @var float
	 */
	private $max_total_time;

	/**
	 * error.param and error.code of the last failed response (internal; never shown).
	 *
	 * @var array{param: string, code: string}
	 */
	private $last_api_error = array(
		'param' => '',
		'code'  => '',
	);

	/**
	 * Beginnings of the texts sent in the current request: an error text that
	 * quotes one of them is not shown (privacy).
	 *
	 * @var string[]
	 */
	private $sent_texts = array();

	/**
	 * Constructor.
	 *
	 * @param string        $api_key   OpenAI API key.
	 * @param callable|null $transport HTTP transport (null = wp_remote_post).
	 * @param array         $options   max_attempts (4), base_delay (1.0 s), max_delay (20.0 s),
	 *                                 timeout (45 s), sleep (callable( float $seconds )),
	 *                                 max_total_time (0 = unlimited): seconds one request may take
	 *                                 including retries and waits; per-attempt timeouts shrink to fit
	 *                                 and no retry is started that would not fit (for PHP time limits).
	 */
	public function __construct( string $api_key, $transport = null, array $options = array() ) {
		$options = array_merge( self::DEFAULTS, $options );

		$this->api_key      = trim( $api_key );
		$this->transport    = is_callable( $transport ) ? $transport : array( __CLASS__, 'wp_transport' );
		$this->sleep        = ( isset( $options['sleep'] ) && is_callable( $options['sleep'] ) ) ? $options['sleep'] : array( __CLASS__, 'default_sleep' );
		$this->max_attempts = max( 1, (int) $options['max_attempts'] );
		$this->base_delay   = max( 0.0, (float) $options['base_delay'] );
		$this->max_delay    = max( 0.0, (float) $options['max_delay'] );
		$this->timeout      = max( 1, (int) $options['timeout'] );

		$this->max_total_time = max( 0.0, (float) $options['max_total_time'] );
	}

	/**
	 * Client with the API key from the settings.
	 *
	 * @param array $options Client options (see the constructor), e.g. a shorter
	 *                       timeout and max_total_time inside a time-limited request.
	 * @return self
	 * @throws HDS_OpenAI_Exception NO_KEY when no key is configured.
	 */
	public static function from_settings( array $options = array() ): self {
		$key = (string) HDS_Settings::get_api_key();
		if ( '' === trim( $key ) ) {
			throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::NO_KEY );
		}
		return new self( $key, null, $options );
	}

	/**
	 * Embed texts.
	 *
	 * @param string[] $texts Texts (non-empty strings).
	 * @param string   $model Embedding model.
	 * @return array<int, float[]> One vector per text, same order (0-indexed).
	 * @throws HDS_OpenAI_Exception On failure.
	 */
	public function embed( array $texts, string $model ): array {
		$texts = array_values( $texts );
		if ( array() === $texts ) {
			return array();
		}
		foreach ( $texts as $text ) {
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_REQUEST, 'Cannot embed an empty text.' );
			}
		}

		$vectors = array();
		$dims    = 0;
		foreach ( array_chunk( $texts, self::MAX_BATCH ) as $batch ) {
			$response = $this->request(
				'embeddings',
				array(
					'model' => $model,
					'input' => $batch,
				)
			);
			foreach ( $this->parse_embeddings( $response, count( $batch ) ) as $vector ) {
				if ( 0 === $dims ) {
					$dims = count( $vector );
				} elseif ( count( $vector ) !== $dims ) {
					throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned embeddings of different sizes.' );
				}
				$vectors[] = $vector;
			}
		}
		return $vectors;
	}

	/**
	 * Chat completion.
	 *
	 * @param array  $messages List of {role, content}.
	 * @param string $model    Chat model.
	 * @param array  $params   temperature (0.1), max_tokens (350).
	 * @return string Assistant message content.
	 * @throws HDS_OpenAI_Exception On failure.
	 */
	public function chat( array $messages, string $model, array $params = array() ): string {
		$params  = array_merge( self::CHAT_DEFAULTS, $params );
		$payload = array(
			'model'       => $model,
			'messages'    => array_values( $messages ),
			'temperature' => (float) $params['temperature'],
			'max_tokens'  => (int) $params['max_tokens'],
		);

		try {
			$response = $this->request( 'chat/completions', $payload );
		} catch ( HDS_OpenAI_Exception $e ) {
			// Reasoning models (o-series, gpt-5...) reject max_tokens and a custom
			// temperature: retry once with the parameters they accept.
			if ( ! $this->is_unsupported_parameter_error( $e ) ) {
				throw $e;
			}
			unset( $payload['max_tokens'], $payload['temperature'] );
			$payload['max_completion_tokens'] = max( 4 * (int) $params['max_tokens'], 2000 );
			$response                         = $this->request( 'chat/completions', $payload );
		}

		return $this->parse_chat( $response );
	}

	/**
	 * Default transport: wp_remote_post().
	 *
	 * @param string $url     URL.
	 * @param array  $request {method, headers, body, timeout}.
	 * @return array {code, body, headers} or {code:0, error}.
	 */
	public static function wp_transport( string $url, array $request ): array {
		$response = wp_remote_post(
			$url,
			array(
				'method'      => 'POST',
				'headers'     => $request['headers'],
				'body'        => $request['body'],
				'timeout'     => $request['timeout'],
				'data_format' => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'code'  => 0,
				'error' => $response->get_error_message(),
			);
		}

		$headers = array();
		foreach ( array( 'retry-after', 'retry-after-ms', 'content-type' ) as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}
			if ( '' !== (string) $value ) {
				$headers[ $name ] = (string) $value;
			}
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => $headers,
		);
	}

	/**
	 * Default sleep.
	 *
	 * @param float $seconds Seconds.
	 * @return void
	 */
	public static function default_sleep( $seconds ): void {
		$seconds = (float) $seconds;
		if ( $seconds <= 0 ) {
			return;
		}
		$whole = (int) floor( $seconds );
		if ( $whole > 0 ) {
			sleep( $whole );
		}
		$micro = (int) round( ( $seconds - $whole ) * 1000000 );
		if ( $micro > 0 ) {
			usleep( $micro );
		}
	}

	/**
	 * POST a JSON request with retries.
	 *
	 * @param string $endpoint Path below API_BASE.
	 * @param array  $payload  Request body.
	 * @return array Decoded JSON response.
	 * @throws HDS_OpenAI_Exception On failure.
	 */
	protected function request( string $endpoint, array $payload ): array {
		if ( '' === $this->api_key ) {
			throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::NO_KEY );
		}
		$body = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $body ) ) {
			throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_REQUEST, 'Could not encode the request to OpenAI.' );
		}
		$request = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => $body,
			'timeout' => $this->timeout,
		);
		$url     = self::API_BASE . $endpoint;

		$this->last_api_error = array(
			'param' => '',
			'code'  => '',
		);
		$this->sent_texts     = self::text_prefixes( $payload );

		$budget = $this->max_total_time;
		$spent  = 0.0; // Seconds spent in requests and waits (waits counted as requested).

		for ( $attempt = 1; ; $attempt++ ) {
			if ( $budget > 0 ) {
				$request['timeout'] = (int) max( 1, min( $this->timeout, floor( $budget - $spent ) ) );
			}
			$started  = microtime( true );
			$response = call_user_func( $this->transport, $url, $request );
			$spent   += microtime( true ) - $started;
			$response = is_array( $response ) ? $response : array(
				'code'  => 0,
				'error' => 'invalid transport response',
			);
			$code     = isset( $response['code'] ) ? (int) $response['code'] : 0;

			if ( $code >= 200 && $code < 300 ) {
				$data = json_decode( isset( $response['body'] ) ? (string) $response['body'] : '', true );
				if ( ! is_array( $data ) ) {
					throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned a response that is not valid JSON.', $code );
				}
				return $data;
			}

			$error = $this->classify( $response, $code );
			if ( ! $error['retry'] || $attempt >= $this->max_attempts ) {
				throw $error['exception'];
			}
			$delay = $this->retry_delay( $attempt, $response );
			if ( $budget > 0 && $spent + $delay + 1 > $budget ) {
				throw $error['exception']; // The next attempt would not fit in the time budget.
			}
			call_user_func( $this->sleep, $delay );
			$spent += $delay;
		}
	}

	/**
	 * Map a failed response to an exception and decide whether to retry.
	 *
	 * @param array $response Transport response.
	 * @param int   $code     HTTP status (0 = network error).
	 * @return array{exception: HDS_OpenAI_Exception, retry: bool}
	 */
	private function classify( array $response, int $code ): array {
		if ( $code <= 0 ) {
			$detail = $this->scrub( isset( $response['error'] ) ? (string) $response['error'] : '' );
			return array(
				'exception' => new HDS_OpenAI_Exception(
					HDS_OpenAI_Exception::NETWORK,
					'Could not connect to OpenAI' . ( '' !== $detail ? ': ' . $detail : '.' )
				),
				'retry'     => true,
			);
		}

		$api                  = $this->api_error( $response );
		$api_code             = $api['code'];
		$api_detail           = '' !== $api['message'] ? ' OpenAI says: ' . $api['message'] : '';
		$this->last_api_error = array(
			'param' => $api['param'],
			'code'  => $api_code,
		);

		if ( 401 === $code || 403 === $code ) {
			$message = 401 === $code
				? 'OpenAI rejected the API key (HTTP 401). Check the key in AI Search > Settings.'
				: 'OpenAI denied access (HTTP 403). Check the API key, its project permissions and region.' . $api_detail;
			return array(
				'exception' => new HDS_OpenAI_Exception( HDS_OpenAI_Exception::INVALID_KEY, $message, $code ),
				'retry'     => false,
			);
		}
		if ( 429 === $code ) {
			if ( 'insufficient_quota' === $api_code || 'insufficient_quota' === $api['type'] ) {
				return array(
					'exception' => new HDS_OpenAI_Exception( HDS_OpenAI_Exception::QUOTA, '', $code ),
					'retry'     => false,
				);
			}
			return array(
				'exception' => new HDS_OpenAI_Exception( HDS_OpenAI_Exception::RATE_LIMITED, '', $code ),
				'retry'     => true,
			);
		}
		if ( 408 === $code ) {
			return array(
				'exception' => new HDS_OpenAI_Exception( HDS_OpenAI_Exception::NETWORK, 'OpenAI request timed out (HTTP 408).', $code ),
				'retry'     => true,
			);
		}
		if ( $code >= 500 ) {
			return array(
				'exception' => new HDS_OpenAI_Exception( HDS_OpenAI_Exception::SERVER, 'OpenAI server error (HTTP ' . $code . '). Try again later.', $code ),
				'retry'     => in_array( $code, array( 500, 502, 503, 504 ), true ),
			);
		}
		if ( $code >= 400 ) {
			return array(
				'exception' => new HDS_OpenAI_Exception(
					HDS_OpenAI_Exception::BAD_REQUEST,
					'OpenAI rejected the request (HTTP ' . $code . ').' . ( '' !== $api_detail ? $api_detail : ' Check the model names in AI Search > Settings.' ),
					$code
				),
				'retry'     => false,
			);
		}
		// 1xx/3xx: redirects are not followed for POST; treat as a bad response.
		return array(
			'exception' => new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned an unexpected HTTP status (' . $code . ').', $code ),
			'retry'     => false,
		);
	}

	/**
	 * Error details from an OpenAI error body: {error: {message, type, code, param}}.
	 *
	 * @param array $response Transport response.
	 * @return array{message: string, type: string, code: string, param: string} Message scrubbed of secrets.
	 */
	private function api_error( array $response ): array {
		$out  = array(
			'message' => '',
			'type'    => '',
			'code'    => '',
			'param'   => '',
		);
		$data = json_decode( isset( $response['body'] ) ? (string) $response['body'] : '', true );
		if ( ! is_array( $data ) || ! isset( $data['error'] ) || ! is_array( $data['error'] ) ) {
			return $out;
		}
		foreach ( array( 'type', 'code', 'param' ) as $field ) {
			if ( isset( $data['error'][ $field ] ) && is_scalar( $data['error'][ $field ] ) ) {
				$out[ $field ] = (string) $data['error'][ $field ];
			}
		}
		if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			$out['message'] = $this->scrub( $data['error']['message'] );
		}
		return $out;
	}

	/**
	 * Whether a 400 error says that max_tokens/temperature are not supported by the model.
	 *
	 * @param HDS_OpenAI_Exception $e Exception.
	 * @return bool
	 */
	private function is_unsupported_parameter_error( HDS_OpenAI_Exception $e ): bool {
		if ( HDS_OpenAI_Exception::BAD_REQUEST !== $e->get_error_code() || 400 !== $e->get_http_status() ) {
			return false;
		}
		return in_array( $this->last_api_error['param'], array( 'max_tokens', 'temperature' ), true )
			&& in_array( $this->last_api_error['code'], array( 'unsupported_parameter', 'unsupported_value' ), true );
	}

	/**
	 * Seconds to wait before the next attempt.
	 *
	 * Retry-After (seconds, or an HTTP date) / retry-after-ms when present,
	 * capped at max_delay; otherwise min(max_delay, base * 2^(attempt-1)) plus
	 * up to 25 % random jitter.
	 *
	 * @param int   $attempt  Attempt that just failed (1-based).
	 * @param array $response Transport response.
	 * @return float
	 */
	protected function retry_delay( int $attempt, array $response ): float {
		$retry_after = $this->retry_after_seconds( isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array() );
		if ( null !== $retry_after ) {
			return min( $this->max_delay, max( 0.0, $retry_after ) );
		}
		$delay  = min( $this->max_delay, $this->base_delay * pow( 2, $attempt - 1 ) );
		$jitter = $delay * 0.25 * ( mt_rand() / mt_getrandmax() );
		return $delay + $jitter;
	}

	/**
	 * Parse Retry-After / retry-after-ms headers.
	 *
	 * @param array $headers Response headers (any key case).
	 * @return float|null Seconds, or null when absent/invalid.
	 */
	private function retry_after_seconds( array $headers ): ?float {
		$headers = array_change_key_case( $headers, CASE_LOWER );
		if ( isset( $headers['retry-after-ms'] ) && is_numeric( $headers['retry-after-ms'] ) ) {
			return (float) $headers['retry-after-ms'] / 1000;
		}
		if ( ! isset( $headers['retry-after'] ) ) {
			return null;
		}
		$value = is_array( $headers['retry-after'] ) ? (string) reset( $headers['retry-after'] ) : (string) $headers['retry-after'];
		$value = trim( $value );
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		$when = strtotime( $value );
		return false === $when ? null : (float) ( $when - time() );
	}

	/**
	 * Validate an embeddings response.
	 *
	 * @param array $response Decoded JSON.
	 * @param int   $count    Number of inputs sent.
	 * @return array<int, float[]> Vectors in input order.
	 * @throws HDS_OpenAI_Exception BAD_RESPONSE.
	 */
	private function parse_embeddings( array $response, int $count ): array {
		if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) || count( $response['data'] ) !== $count ) {
			throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned a wrong number of embeddings.' );
		}
		$vectors = array();
		foreach ( array_values( $response['data'] ) as $position => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['embedding'] ) || ! is_array( $item['embedding'] ) || array() === $item['embedding'] ) {
				throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned an invalid embedding.' );
			}
			$index = $position;
			if ( array_key_exists( 'index', $item ) ) {
				if ( ! is_int( $item['index'] ) || $item['index'] < 0 || $item['index'] >= $count ) {
					throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned an embedding with an invalid index.' );
				}
				$index = $item['index'];
			}
			if ( isset( $vectors[ $index ] ) ) {
				throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned duplicate embeddings.' );
			}
			$vector = array();
			foreach ( $item['embedding'] as $value ) {
				if ( ! is_float( $value ) && ! is_int( $value ) ) {
					throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned an embedding with non-numeric values.' );
				}
				$vector[] = (float) $value;
			}
			$vectors[ $index ] = $vector;
		}
		ksort( $vectors );
		return array_values( $vectors );
	}

	/**
	 * Validate a chat completion response.
	 *
	 * @param array $response Decoded JSON.
	 * @return string
	 * @throws HDS_OpenAI_Exception BAD_RESPONSE.
	 */
	private function parse_chat( array $response ): string {
		$message = isset( $response['choices'][0]['message'] ) && is_array( $response['choices'][0]['message'] )
			? $response['choices'][0]['message']
			: null;
		if ( null === $message ) {
			throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned a chat response without a message.' );
		}
		if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
			return $message['content'];
		}
		if ( isset( $message['refusal'] ) && is_string( $message['refusal'] ) ) {
			return $message['refusal'];
		}
		throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned a chat response without text.' );
	}

	/**
	 * Beginnings of the user texts in a payload (embedding inputs, message contents).
	 *
	 * @param array $payload Request payload.
	 * @return string[]
	 */
	private static function text_prefixes( array $payload ): array {
		$texts = array();
		if ( isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
			$texts = $payload['input'];
		}
		if ( isset( $payload['messages'] ) && is_array( $payload['messages'] ) ) {
			foreach ( $payload['messages'] as $message ) {
				if ( is_array( $message ) && isset( $message['content'] ) ) {
					$texts[] = $message['content'];
				}
			}
		}
		$prefixes = array();
		foreach ( $texts as $text ) {
			if ( ! is_string( $text ) ) {
				continue;
			}
			$text = trim( $text );
			if ( mb_strlen( $text, 'UTF-8' ) >= 8 ) {
				$prefixes[] = mb_substr( $text, 0, 24, 'UTF-8' );
			}
		}
		return $prefixes;
	}

	/**
	 * Make external text safe for an admin message: no API key (ours or any
	 * sk-... token), no quoted request text, no markup, one line, bounded length.
	 *
	 * @param string $text Text.
	 * @return string '' when the text must not be shown at all.
	 */
	private function scrub( string $text ): string {
		foreach ( $this->sent_texts as $prefix ) {
			if ( false !== strpos( $text, $prefix ) ) {
				return '';
			}
		}
		if ( strlen( $this->api_key ) >= 4 ) {
			$text = str_replace( $this->api_key, '[redacted]', $text );
		}
		$text = (string) preg_replace( '/\bsk-[A-Za-z0-9_\-*.]{4,}/', 'sk-[redacted]', $text );
		$text = (string) preg_replace( '/Bearer\s+\S+/i', 'Bearer [redacted]', $text );
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $text ) ) );
		if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_ERROR_LENGTH ) {
			$text = rtrim( mb_substr( $text, 0, self::MAX_ERROR_LENGTH - 1, 'UTF-8' ) ) . '…';
		}
		return $text;
	}
}
