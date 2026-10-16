<?php
/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Only what plugin classes need outside of $wpdb/HTTP. Each stub is
 * guarded so module-specific stub files (tests/stubs/NN-*.php) can add or
 * override behaviour. State lives in $GLOBALS['hds_test'].
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
}

/**
 * Reset all stub state. Call from setUp().
 *
 * @return void
 */
function hds_test_reset() {
	$tmp = sys_get_temp_dir() . '/hds-tests-' . getmypid();
	$GLOBALS['hds_test'] = array(
		'options'    => array(),
		'transients' => array(),
		'cache'      => array(),
		'actions'    => array(),
		'filters'    => array(),
		'home_url'   => 'https://www.example.co.il',
		'now'        => null,
		'upload_dir' => $tmp . '/uploads',
		'ext_cache'  => false,
		'user_id'    => 1,
		'caps'       => array( 'manage_options' => true ),
	);
}

/**
 * Test clock: hds_test_set_time() sets the time used by the stubs (see hds_test_now()).
 *
 * @param int|null $timestamp Timestamp or null for real time.
 * @return void
 */
function hds_test_set_time( $timestamp ) {
	$GLOBALS['hds_test']['now'] = $timestamp;
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {
		/** @var array<string, string[]> */
		public $errors = array();
		/** @var array<string, mixed> */
		public $error_data = array();

		/**
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}
		/** @return string */
		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? (string) $codes[0] : '';
		}
		/** @return string */
		public function get_error_message( $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}
		/** @return mixed */
		public function get_error_data( $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// Options.
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['hds_test']['options'] ) ? $GLOBALS['hds_test']['options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['hds_test']['options'][ $name ] = $value;
		return true;
	}
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $name, $GLOBALS['hds_test']['options'] ) ) {
			return false;
		}
		$GLOBALS['hds_test']['options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['hds_test']['options'][ $name ] );
		return true;
	}
}

// Transients (honour expiry against the test clock).
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		if ( ! isset( $GLOBALS['hds_test']['transients'][ $name ] ) ) {
			return false;
		}
		list( $value, $expires ) = $GLOBALS['hds_test']['transients'][ $name ];
		$now = null !== $GLOBALS['hds_test']['now'] ? $GLOBALS['hds_test']['now'] : time();
		if ( $expires && $expires <= $now ) {
			unset( $GLOBALS['hds_test']['transients'][ $name ] );
			return false;
		}
		return $value;
	}
	function set_transient( $name, $value, $expiration = 0 ) {
		$now = null !== $GLOBALS['hds_test']['now'] ? $GLOBALS['hds_test']['now'] : time();
		$GLOBALS['hds_test']['transients'][ $name ] = array( $value, $expiration ? $now + (int) $expiration : 0 );
		return true;
	}
	function delete_transient( $name ) {
		unset( $GLOBALS['hds_test']['transients'][ $name ] );
		return true;
	}
}

// Object cache (non-persistent unless hds_test ext_cache is true).
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		$k     = $group . ':' . $key;
		$found = array_key_exists( $k, $GLOBALS['hds_test']['cache'] );
		return $found ? $GLOBALS['hds_test']['cache'][ $k ] : false;
	}
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		$GLOBALS['hds_test']['cache'][ $group . ':' . $key ] = $data;
		return true;
	}
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['hds_test']['cache'][ $group . ':' . $key ] );
		return true;
	}
	function wp_using_ext_object_cache( $using = null ) {
		return (bool) $GLOBALS['hds_test']['ext_cache'];
	}
}

// Hooks: actions are recorded, filters return the value unless a test
// registered a callback in $GLOBALS['hds_test']['filters'][ $tag ].
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['hds_test']['actions'][ $tag ][] = $callback;
		return true;
	}
	function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['hds_test']['filters'][ $tag ][] = $callback;
		return true;
	}
	function do_action( $tag, ...$args ) {
	}
	function apply_filters( $tag, $value, ...$args ) {
		if ( ! empty( $GLOBALS['hds_test']['filters'][ $tag ] ) ) {
			foreach ( $GLOBALS['hds_test']['filters'][ $tag ] as $cb ) {
				$value = call_user_func_array( $cb, array_merge( array( $value ), $args ) );
			}
		}
		return $value;
	}
}

// i18n and escaping.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
	function _e( $text, $domain = 'default' ) {
		echo $text; // phpcs:ignore
	}
	function _x( $text, $context, $domain = 'default' ) {
		return $text;
	}
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
	function esc_attr__( $text, $domain = 'default' ) {
		return esc_attr( $text );
	}
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_url( $url ) {
		return (string) $url;
	}
	function esc_url_raw( $url ) {
		return (string) $url;
	}
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function wp_kses_post( $text ) {
		return (string) $text;
	}
}

// Sanitizing and misc.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = strip_tags( $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( $str );
	}
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
	function sanitize_file_name( $name ) {
		$name = preg_replace( '/[^A-Za-z0-9._\-\x{0590}-\x{05FF}]+/u', '-', (string) $name );
		return trim( $name, '.-' );
	}
	function absint( $n ) {
		return abs( (int) $n );
	}
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
	function trailingslashit( $s ) {
		return rtrim( (string) $s, '/\\' ) . '/';
	}
	function untrailingslashit( $s ) {
		return rtrim( (string) $s, '/\\' );
	}
	function home_url( $path = '' ) {
		return rtrim( $GLOBALS['hds_test']['home_url'], '/' ) . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	}
	function site_url( $path = '' ) {
		return home_url( $path );
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'hds-test-salt-' . $scheme . '-0123456789abcdef0123456789abcdef';
	}
	function is_admin() {
		return false;
	}
	function current_user_can( $cap, ...$args ) {
		return ! empty( $GLOBALS['hds_test']['caps'][ $cap ] );
	}
	function get_current_user_id() {
		return (int) $GLOBALS['hds_test']['user_id'];
	}
	function current_time( $type, $gmt = 0 ) {
		$now = null !== $GLOBALS['hds_test']['now'] ? $GLOBALS['hds_test']['now'] : time();
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s', $now ) : $now;
	}
	function wp_upload_dir( $time = null, $create_dir = true, $refresh = false ) {
		$base = $GLOBALS['hds_test']['upload_dir'];
		return array(
			'basedir' => $base,
			'baseurl' => 'https://example.co.il/wp-content/uploads',
			'path'    => $base,
			'url'     => 'https://example.co.il/wp-content/uploads',
			'subdir'  => '',
			'error'   => false,
		);
	}
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0755, true );
	}
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( bin2hex( random_bytes( $length ) ), 0, $length );
	}
	function size_format( $bytes, $decimals = 0 ) {
		return round( $bytes / 1024, $decimals ) . ' KB';
	}
	function wp_max_upload_size() {
		return 64 * 1024 * 1024;
	}
}

/**
 * Current time honouring the test clock (tests only; production code uses time()).
 *
 * @return int
 */
function hds_test_now() {
	return null !== $GLOBALS['hds_test']['now'] ? (int) $GLOBALS['hds_test']['now'] : time();
}
