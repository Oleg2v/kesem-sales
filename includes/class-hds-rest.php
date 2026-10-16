<?php
/**
 * Public REST API of the search form (namespace hds/v1).
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes.
 *
 * - POST /ask      {q} → answer with sources (nonce + per-IP rate limit).
 * - GET  /suggest  → autocomplete dictionary (public, cacheable).
 * - GET  /nonce    → a fresh nonce for /ask (public, not cacheable).
 *
 * The nonce uses WordPress' nonce algorithm for the anonymous context
 * (user 0, no session token). The front end calls the API with
 * credentials:'omit', so requests always run as user 0 regardless of the
 * visitor's cookies, and a cached page whose nonce expired recovers by
 * fetching a new one from /nonce.
 */
class HDS_REST {

	/**
	 * REST namespace.
	 */
	const NS = 'hds/v1';

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'hds_ask';

	/**
	 * Request header carrying the nonce.
	 */
	const NONCE_HEADER = 'X-HDS-Nonce';

	/**
	 * Request parameter carrying the nonce (alternative to the header).
	 */
	const NONCE_PARAM = '_hds_nonce';

	/**
	 * Register the routes (rest_api_init).
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ask' ),
				'permission_callback' => array( __CLASS__, 'check_nonce' ),
				'args'                => array(
					'q' => array(
						'description'       => 'Question.',
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( __CLASS__, 'validate_query' ),
						'sanitize_callback' => array( __CLASS__, 'sanitize_query' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/suggest',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'suggest' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'nonce' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * A nonce for /ask (anonymous context).
	 *
	 * @return string 10 hex characters.
	 */
	public static function create_nonce(): string {
		return self::nonce_for_tick( wp_nonce_tick( self::NONCE_ACTION ) );
	}

	/**
	 * Verify a nonce made by create_nonce() in the current or the previous tick.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public static function verify_nonce( string $nonce ): bool {
		$nonce = trim( $nonce );
		if ( 10 !== strlen( $nonce ) ) {
			return false;
		}
		$tick = wp_nonce_tick( self::NONCE_ACTION );
		// Not short-circuited: the time taken does not tell which tick matched.
		$current  = hash_equals( self::nonce_for_tick( $tick ), $nonce );
		$previous = hash_equals( self::nonce_for_tick( $tick - 1 ), $nonce );
		return $current || $previous;
	}

	/**
	 * Permission callback of /ask: valid nonce in the X-HDS-Nonce header or
	 * the _hds_nonce parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( self::NONCE_HEADER );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$nonce = $request->get_param( self::NONCE_PARAM );
		}
		if ( is_string( $nonce ) && '' !== $nonce && self::verify_nonce( $nonce ) ) {
			return true;
		}
		return new WP_Error(
			'hds_bad_nonce',
			__( 'פג תוקף הדף. רעננו את הדף ונסו שוב.', 'smart-document-search' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Validate callback of the q argument: a string of at most MAX_LEN characters.
	 *
	 * @param mixed $value Value.
	 * @return true|WP_Error
	 */
	public static function validate_query( $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'hds_query_short', __( 'נא להקליד לפחות 2 תווים.', 'smart-document-search' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( trim( $value ), 'UTF-8' ) > HDS_Answerer::MAX_LEN ) {
			return new WP_Error( 'hds_query_long', __( 'השאלה ארוכה מדי.', 'smart-document-search' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Sanitize callback of the q argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function sanitize_query( $value ): string {
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/**
	 * POST /ask.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function ask( WP_REST_Request $request ): WP_REST_Response {
		$ip      = HDS_Rate_Limiter::client_ip();
		$limiter = new HDS_Rate_Limiter( max( 1, (int) HDS_Settings::get( 'rate_limit' ) ) );
		if ( ! $limiter->hit( $ip ) ) {
			$response = self::error_response(
				new WP_Error(
					'hds_rate_limited',
					__( 'נשלחו יותר מדי בקשות. נסו שוב בעוד דקה.', 'smart-document-search' ),
					array( 'status' => 429 )
				)
			);
			$response->header( 'Retry-After', (string) max( 1, $limiter->retry_after( $ip ) ) );
			return $response;
		}

		$query = $request->get_param( 'q' );
		try {
			$result = static::answerer()->ask( is_string( $query ) ? $query : '' );
		} catch ( \Throwable $e ) {
			// Never expose internals (or the question) to the visitor.
			$result = new WP_Error( 'hds_error', __( 'אירעה שגיאה. נסו שוב מאוחר יותר.', 'smart-document-search' ), array( 'status' => 500 ) );
		}
		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * GET /suggest: the autocomplete dictionary.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function suggest( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( HDS_Suggest::get(), 200 );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * GET /nonce: a fresh nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function nonce( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( array( 'nonce' => self::create_nonce() ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The answerer (seam for tests).
	 *
	 * @return HDS_Answerer
	 */
	protected static function answerer() {
		return new HDS_Answerer();
	}

	/**
	 * Nonce of a tick.
	 *
	 * @param int|float $tick Nonce tick.
	 * @return string
	 */
	private static function nonce_for_tick( $tick ): string {
		return substr( wp_hash( $tick . '|' . self::NONCE_ACTION . '|0|', 'nonce' ), -12, 10 );
	}

	/**
	 * REST response for an error (same body as WordPress gives a WP_Error),
	 * never cached.
	 *
	 * @param WP_Error $error Error with data.status.
	 * @return WP_REST_Response
	 */
	private static function error_response( WP_Error $error ): WP_REST_Response {
		if ( function_exists( 'rest_convert_error_to_response' ) ) {
			$response = rest_convert_error_to_response( $error );
		} else {
			$data     = $error->get_error_data();
			$status   = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			$response = new WP_REST_Response(
				array(
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
					'data'    => is_array( $data ) ? $data : array( 'status' => $status ),
				),
				$status
			);
		}
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
