<?php
/**
 * OpenAI API error with a machine-readable code.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by HDS_OpenAI_Client. The message is shown to the site admin
 * (English) and never contains the API key, request bodies or document text.
 */
class HDS_OpenAI_Exception extends Exception {

	const NO_KEY       = 'no_key';        // No API key configured.
	const INVALID_KEY  = 'invalid_key';   // 401/403: key rejected.
	const QUOTA        = 'quota';         // 429 insufficient_quota: billing/usage limit.
	const RATE_LIMITED = 'rate_limited';  // 429 rate limit (after retries).
	const SERVER       = 'server_error';  // 5xx (after retries).
	const BAD_REQUEST  = 'bad_request';   // 400/404/422...: model name, input too long...
	const NETWORK      = 'network';       // Connection error/timeout (after retries).
	const BAD_RESPONSE = 'bad_response';  // Unexpected/invalid response body.

	/**
	 * Codes after which retrying later will not help (stop an indexing run).
	 */
	const FATAL_CODES = array( self::NO_KEY, self::INVALID_KEY, self::QUOTA, self::BAD_REQUEST );

	/**
	 * Machine-readable error code (one of the constants).
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * HTTP status of the failed response (0 when there was none).
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Constructor.
	 *
	 * @param string          $error_code  One of the class constants.
	 * @param string          $message     Admin-facing message without secrets or content; '' = default for the code.
	 * @param int             $http_status HTTP status (0 = none).
	 * @param \Throwable|null $previous    Previous exception.
	 */
	public function __construct( string $error_code, string $message = '', int $http_status = 0, $previous = null ) {
		if ( '' === $message ) {
			$message = self::default_message( $error_code );
		}
		parent::__construct( $message, $http_status, $previous instanceof \Throwable ? $previous : null );
		$this->error_code  = $error_code;
		$this->http_status = $http_status;
	}

	/**
	 * Get the machine-readable code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Get the HTTP status (0 when no response was received).
	 *
	 * @return int
	 */
	public function get_http_status(): int {
		return $this->http_status;
	}

	/**
	 * Whether the error will not go away by retrying later (bad key, no quota,
	 * invalid request): an indexing run must stop and the admin must act.
	 *
	 * @return bool
	 */
	public function is_fatal(): bool {
		return in_array( $this->error_code, self::FATAL_CODES, true );
	}

	/**
	 * Default admin message for a code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function default_message( string $code ): string {
		switch ( $code ) {
			case self::NO_KEY:
				return 'OpenAI API key is not configured. Add it in AI Search > Settings.';
			case self::INVALID_KEY:
				return 'OpenAI rejected the API key. Check the key in AI Search > Settings.';
			case self::QUOTA:
				return 'OpenAI quota exceeded: check the billing and usage limits of your OpenAI account.';
			case self::RATE_LIMITED:
				return 'OpenAI rate limit reached. Try again in a few minutes.';
			case self::SERVER:
				return 'OpenAI server error. Try again later.';
			case self::BAD_REQUEST:
				return 'OpenAI rejected the request. Check the model names in AI Search > Settings.';
			case self::NETWORK:
				return 'Could not connect to OpenAI. Check the server\'s internet connection and try again.';
			case self::BAD_RESPONSE:
				return 'OpenAI returned an unexpected response.';
			default:
				return 'OpenAI request failed.';
		}
	}
}
