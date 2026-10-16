<?php
/**
 * AJAX endpoints of the admin UI: indexing (start/step) and the OpenAI
 * connection test.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin AJAX handlers (wp_ajax_* only: never available to visitors).
 */
class HDS_Admin_Ajax {

	/**
	 * Nonce action shared with assets/js/hds-admin.js (window.hdsAdmin.nonce).
	 */
	const NONCE_ACTION = 'hds_admin';

	/**
	 * Seconds requested from set_time_limit() for one indexing request.
	 */
	const TIME_LIMIT = 120;

	/**
	 * Text embedded by the connection test ("test" in Hebrew).
	 */
	const TEST_TEXT = 'בדיקה';

	/**
	 * Register the AJAX actions.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_hds_index_start', array( __CLASS__, 'index_start' ) );
		add_action( 'wp_ajax_hds_index_step', array( __CLASS__, 'index_step' ) );
		add_action( 'wp_ajax_hds_test_api', array( __CLASS__, 'test_api' ) );
	}

	/**
	 * Start (or restart) an indexing run: builds the queue.
	 *
	 * @return void
	 */
	public static function index_start(): void {
		self::guard();
		self::extend_time_limit();

		$result = null;
		try {
			$indexer = new HDS_Indexer();
			$result  = $indexer->start();
		} catch ( \Throwable $e ) {
			$result = null;
		}

		if ( ! is_array( $result ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Indexing could not be started because of an unexpected server error. Please try again.', 'smart-document-search' ) ),
				500
			);
			return;
		}
		wp_send_json_success( $result );
	}

	/**
	 * Process the next part of the queue within the time budget.
	 *
	 * @return void
	 */
	public static function index_step(): void {
		self::guard();
		self::extend_time_limit();

		$result = null;
		try {
			$indexer = new HDS_Indexer();
			$result  = $indexer->step();
		} catch ( \Throwable $e ) {
			$result = null;
		}

		if ( ! is_array( $result ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Indexing stopped because of an unexpected server error. Click “Update index” to try again.', 'smart-document-search' ) ),
				500
			);
			return;
		}
		wp_send_json_success( $result );
	}

	/**
	 * Embed a short Hebrew text with the saved key and embedding model.
	 *
	 * @return void
	 */
	public static function test_api(): void {
		self::guard();

		$model   = (string) HDS_Settings::get( 'embedding_model' );
		$error   = '';
		$code    = '';
		$vectors = array();
		try {
			$vectors = static::client()->embed( array( self::TEST_TEXT ), $model );
		} catch ( HDS_OpenAI_Exception $e ) {
			$error = $e->getMessage();
			$code  = $e->get_error_code();
		} catch ( \Throwable $e ) {
			$error = __( 'The connection test failed because of an unexpected server error.', 'smart-document-search' );
			$code  = 'internal';
		}

		if ( '' !== $error ) {
			wp_send_json_error(
				array(
					'message' => self::redact( $error ),
					'code'    => $code,
				)
			);
			return;
		}

		$dims = isset( $vectors[0] ) && is_array( $vectors[0] ) ? count( $vectors[0] ) : 0;
		if ( $dims < 1 ) {
			wp_send_json_error(
				array(
					'message' => __( 'OpenAI returned an empty embedding.', 'smart-document-search' ),
					'code'    => 'bad_response',
				)
			);
			return;
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: embedding model name, 2: number of dimensions. */
					__( 'Connection OK: the model %1$s returned a %2$d-dimensional embedding.', 'smart-document-search' ),
					$model,
					$dims
				),
				'model'   => $model,
				'dims'    => $dims,
			)
		);
	}

	/**
	 * OpenAI client for the connection test: short timeouts, one retry, so
	 * the admin gets an answer quickly.
	 *
	 * @return HDS_OpenAI_Client
	 * @throws HDS_OpenAI_Exception NO_KEY when no key is configured.
	 */
	protected static function client(): HDS_OpenAI_Client {
		return HDS_OpenAI_Client::from_settings(
			array(
				'max_attempts'   => 2,
				'timeout'        => 20,
				'max_total_time' => 25,
			)
		);
	}

	/**
	 * Common checks: no caching, valid nonce, capability.
	 *
	 * @return void
	 */
	private static function guard(): void {
		nocache_headers();
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( HDS_Admin::CAP ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to manage AI Search.', 'smart-document-search' ) ),
				403
			);
		}
	}

	/**
	 * Ask PHP for more time where the host allows it. HDS_Indexer keeps each
	 * step within its own time budget either way.
	 *
	 * @return void
	 */
	private static function extend_time_limit(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			$current = (int) ini_get( 'max_execution_time' );
			if ( 0 !== $current && $current < self::TIME_LIMIT ) {
				@set_time_limit( self::TIME_LIMIT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May be disabled by the host.
			}
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			// Finish the current step even if the tab is closed (keeps a document's state consistent).
			@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Remove anything that looks like an API key from a message (defence in depth:
	 * HDS_OpenAI_Exception messages never contain the key).
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function redact( string $message ): string {
		$key = '';
		try {
			$key = (string) HDS_Settings::get_api_key();
		} catch ( \Throwable $e ) {
			$key = '';
		}
		if ( strlen( $key ) >= 8 ) {
			$message = str_replace( $key, '[redacted]', $message );
		}
		return (string) preg_replace( '/\bsk-[A-Za-z0-9_\-]{8,}/', 'sk-…', $message );
	}
}
