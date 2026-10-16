<?php
/**
 * E2E-only OpenAI mock (mapped into wp-content/mu-plugins by tests/e2e/.wp-env.json).
 *
 * Active only when the option `hds_e2e_mock` is truthy - tests/e2e/run.sh sets it
 * when no real OPENAI_API_KEY is provided. Intercepts requests to api.openai.com
 * through the standard `pre_http_request` filter (no plugin hooks involved):
 *   - embeddings: deterministic hashed bag-of-words vectors (1536 dims), so
 *     semantically related text really ranks higher;
 *   - chat: a short Hebrew answer quoting the first fragment it was given.
 * Counts calls in the option `hds_e2e_calls` so tests can assert caching.
 *
 * NOT part of the plugin; never shipped (tests/ is excluded from the zip).
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 0 !== strpos( (string) $url, 'https://api.openai.com/v1/' ) || ! get_option( 'hds_e2e_mock' ) ) {
			return $pre;
		}
		$body  = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );
		$calls = (array) get_option( 'hds_e2e_calls', array() );
		$kind  = false !== strpos( $url, '/embeddings' ) ? 'embeddings' : ( false !== strpos( $url, '/chat/completions' ) ? 'chat' : 'other' );

		$calls[ $kind ] = isset( $calls[ $kind ] ) ? $calls[ $kind ] + 1 : 1;
		update_option( 'hds_e2e_calls', $calls, false );

		$fail = get_option( 'hds_e2e_fail' ); // e.g. 401 to simulate an invalid key.
		if ( $fail ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Mock failure', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key' ) ) ),
				'response' => array( 'code' => (int) $fail, 'message' => 'Mock' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		if ( 'embeddings' === $kind ) {
			$data = array();
			foreach ( array_values( (array) $body['input'] ) as $i => $text ) {
				$data[] = array(
					'object'    => 'embedding',
					'index'     => $i,
					'embedding' => hds_e2e_vector( (string) $text ),
				);
			}
			$response = array( 'object' => 'list', 'data' => array_reverse( $data ), 'model' => $body['model'] );
		} elseif ( 'chat' === $kind ) {
			$user = '';
			foreach ( (array) $body['messages'] as $message ) {
				if ( 'user' === $message['role'] ) {
					$user = (string) $message['content'];
				}
			}
			$answer = 'לא מצאתי תשובה לשאלה זו במסמכים.';
			if ( preg_match( '/\[1\][^\n]*\n(.+)/u', $user, $m ) ) {
				$answer = 'לפי המסמכים: ' . trim( mb_substr( $m[1], 0, 160 ) ) . '…';
			}
			$response = array(
				'object'  => 'chat.completion',
				'model'   => $body['model'],
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array( 'role' => 'assistant', 'content' => $answer ),
						'finish_reason' => 'stop',
					),
				),
			);
		} else {
			return $pre;
		}

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $response ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

/**
 * Deterministic 1536-dim unit vector: hashed bag of words + word prefixes,
 * with one-letter Hebrew prefixes (ו ה ב כ ל מ ש) folded away.
 *
 * @param string $text Text.
 * @return float[]
 */
function hds_e2e_vector( $text ) {
	$dims = 1536;
	$vec  = array_fill( 0, $dims, 0.0 );
	$text = mb_strtolower( preg_replace( '/[\x{0591}-\x{05C7}]/u', '', $text ) );
	preg_match_all( '/[\p{L}\p{N}]+/u', $text, $m );
	foreach ( $m[0] as $word ) {
		$forms = array( $word );
		if ( mb_strlen( $word ) > 3 && false !== mb_strpos( 'והבכלמש', mb_substr( $word, 0, 1 ) ) ) {
			$forms[] = mb_substr( $word, 1 );
		}
		foreach ( $forms as $form ) {
			$h            = crc32( $form );
			$vec[ $h % $dims ] += ( $h & 0x80000000 ) ? -1.0 : 1.0;
		}
	}
	$norm = sqrt( array_sum( array_map( static function ( $x ) {
		return $x * $x;
	}, $vec ) ) );
	if ( $norm <= 0 ) {
		$vec[0] = 1.0;
		return $vec;
	}
	return array_map( static function ( $x ) use ( $norm ) {
		return round( $x / $norm, 6 );
	}, $vec );
}
