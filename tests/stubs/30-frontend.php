<?php
/**
 * Test stubs for the front end: REST request/response classes, REST,
 * nonce, shortcode, block and script registration functions.
 *
 * Every function and class is guarded so other stub files can provide
 * their own. wp_enqueue_script(), wp_enqueue_style() and
 * wp_add_inline_script() are also stubbed by tests/stubs/40-admin.php with
 * the same recording format; ours are declared only by
 * hds_test_frontend_late_stubs() (called from the front-end tests' setUp),
 * so the admin versions win when they exist.
 *
 * Recorded state ($GLOBALS['hds_test']): rest_routes, shortcodes, blocks,
 * registered[script|style][handle], enqueued[script|style][handle],
 * enqueued[inline][handle][] = [code, position], script_translations.
 *
 * @package SmartDocumentSearch
 */

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request.
	 */
	class WP_REST_Request {

		/** @var string */
		private $method;
		/** @var string */
		private $route;
		/** @var array */
		private $params = array();
		/** @var array<string, string[]> */
		private $headers = array();

		/**
		 * @param string $method Method.
		 * @param string $route  Route.
		 */
		public function __construct( $method = '', $route = '' ) {
			$this->method = strtoupper( (string) $method );
			$this->route  = (string) $route;
		}

		/**
		 * Canonical header name (like WordPress: lowercase, '-' → '_').
		 *
		 * @param string $key Header name.
		 * @return string
		 */
		public static function canonicalize_header_name( $key ) {
			return str_replace( '-', '_', strtolower( (string) $key ) );
		}

		/** @return string */
		public function get_method() {
			return $this->method;
		}

		/** @return string */
		public function get_route() {
			return $this->route;
		}

		/**
		 * @param string $key Name.
		 * @return mixed
		 */
		public function get_param( $key ) {
			return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null;
		}

		/**
		 * @param string $key   Name.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		/** @return array */
		public function get_params() {
			return $this->params;
		}

		/**
		 * @param string $key Header name.
		 * @return string|null
		 */
		public function get_header( $key ) {
			$key = self::canonicalize_header_name( $key );
			return isset( $this->headers[ $key ] ) ? implode( ',', $this->headers[ $key ] ) : null;
		}

		/**
		 * @param string $key   Header name.
		 * @param string $value Value.
		 * @return void
		 */
		public function set_header( $key, $value ) {
			$this->headers[ self::canonicalize_header_name( $key ) ] = (array) $value;
		}
	}
}

if ( ! class_exists( 'WP_HTTP_Response' ) ) {
	/**
	 * Minimal WP_HTTP_Response.
	 */
	class WP_HTTP_Response {

		/** @var mixed */
		public $data;
		/** @var array */
		public $headers = array();
		/** @var int */
		public $status = 200;

		/**
		 * @param mixed $data    Data.
		 * @param int   $status  Status.
		 * @param array $headers Headers.
		 */
		public function __construct( $data = null, $status = 200, $headers = array() ) {
			$this->data    = $data;
			$this->status  = (int) $status;
			$this->headers = $headers;
		}

		/** @return mixed */
		public function get_data() {
			return $this->data;
		}

		/** @param mixed $data Data. */
		public function set_data( $data ) {
			$this->data = $data;
		}

		/** @return int */
		public function get_status() {
			return $this->status;
		}

		/** @param int $code Status. */
		public function set_status( $code ) {
			$this->status = (int) $code;
		}

		/** @return array */
		public function get_headers() {
			return $this->headers;
		}

		/**
		 * @param string $key     Header name.
		 * @param string $value   Value.
		 * @param bool   $replace Replace an existing value.
		 * @return void
		 */
		public function header( $key, $value, $replace = true ) {
			if ( $replace || ! isset( $this->headers[ $key ] ) ) {
				$this->headers[ $key ] = $value;
			} else {
				$this->headers[ $key ] .= ', ' . $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response.
	 */
	class WP_REST_Response extends WP_HTTP_Response {
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	/**
	 * Like WordPress: WP_Error and responses pass through, data is wrapped.
	 *
	 * @param mixed $response Response.
	 * @return WP_REST_Response|WP_Error
	 */
	function rest_ensure_response( $response ) {
		if ( is_wp_error( $response ) || $response instanceof WP_REST_Response ) {
			return $response;
		}
		return new WP_REST_Response( $response );
	}
}

if ( ! function_exists( 'rest_convert_error_to_response' ) ) {
	/**
	 * Like WordPress: {code, message, data} with data.status as HTTP status.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_REST_Response
	 */
	function rest_convert_error_to_response( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		$body   = array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $data,
		);
		return new WP_REST_Response( $body, $status );
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Records the route in $GLOBALS['hds_test']['rest_routes'][ '/ns/route' ].
	 *
	 * @param string $namespace Namespace.
	 * @param string $route     Route.
	 * @param array  $args      Args.
	 * @return bool
	 */
	function register_rest_route( $namespace, $route, $args = array() ) {
		$GLOBALS['hds_test']['rest_routes'][ '/' . trim( $namespace, '/' ) . '/' . ltrim( $route, '/' ) ] = $args;
		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function rest_url( $path = '' ) {
		return 'https://example.co.il/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( '__return_true' ) ) {
	/** @return bool */
	function __return_true() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
		return true;
	}
}

if ( ! function_exists( 'wp_nonce_tick' ) ) {
	/**
	 * Like WordPress (honours the test clock and the nonce_life filter).
	 *
	 * @param string|int $action Action.
	 * @return float
	 */
	function wp_nonce_tick( $action = -1 ) {
		$life = apply_filters( 'nonce_life', DAY_IN_SECONDS, $action );
		$now  = null !== $GLOBALS['hds_test']['now'] ? $GLOBALS['hds_test']['now'] : time();
		return ceil( $now / ( $life / 2 ) );
	}
}

if ( ! function_exists( 'wp_hash' ) ) {
	/**
	 * Like WordPress: HMAC-MD5 with the salt of the scheme.
	 *
	 * @param string $data   Data.
	 * @param string $scheme Salt scheme.
	 * @return string
	 */
	function wp_hash( $data, $scheme = 'auth' ) {
		return hash_hmac( 'md5', (string) $data, wp_salt( $scheme ) );
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	/**
	 * @param string   $tag      Tag.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['hds_test']['shortcodes'][ $tag ] = $callback;
	}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	/**
	 * Like WordPress (without the filter).
	 *
	 * @param array  $pairs     Defaults.
	 * @param array  $atts      Attributes.
	 * @param string $shortcode Shortcode.
	 * @return array
	 */
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		$atts = (array) $atts;
		$out  = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
}

if ( ! function_exists( 'register_block_type' ) ) {
	/**
	 * @param string $name Block name.
	 * @param array  $args Args.
	 * @return object
	 */
	function register_block_type( $name, $args = array() ) {
		$GLOBALS['hds_test']['blocks'][ $name ] = $args;
		return (object) array_merge( array( 'name' => $name ), $args );
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	/**
	 * @return bool
	 */
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['hds_test']['registered']['script'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
		return true;
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	/**
	 * @return bool
	 */
	function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['hds_test']['registered']['style'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' );
		return true;
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	/**
	 * @param string $handle Handle.
	 * @param string $list   'registered' or 'enqueued'.
	 * @return bool
	 */
	function wp_script_is( $handle, $list = 'enqueued' ) {
		$group = 'registered' === $list ? 'registered' : 'enqueued';
		return isset( $GLOBALS['hds_test'][ $group ]['script'][ $handle ] );
	}
}

if ( ! function_exists( 'wp_set_script_translations' ) ) {
	/**
	 * @return bool
	 */
	function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) {
		$GLOBALS['hds_test']['script_translations'][ $handle ] = $domain;
		return true;
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	/**
	 * True when $GLOBALS['hds_test']['post'] is set.
	 *
	 * @return bool
	 */
	function is_singular( $post_types = '' ) {
		return isset( $GLOBALS['hds_test']['post'] );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * $GLOBALS['hds_test']['post'] (object with post_content) or null.
	 *
	 * @return object|null
	 */
	function get_post( $post = null, $output = 'OBJECT', $filter = 'raw' ) {
		return isset( $GLOBALS['hds_test']['post'] ) ? $GLOBALS['hds_test']['post'] : null;
	}
}

if ( ! function_exists( 'has_shortcode' ) ) {
	/**
	 * Simplified: '[tag' followed by ']', '/' or whitespace.
	 *
	 * @param string $content Content.
	 * @param string $tag     Shortcode tag.
	 * @return bool
	 */
	function has_shortcode( $content, $tag ) {
		return (bool) preg_match( '/\[' . preg_quote( $tag, '/' ) . '[\s\]\/]/', (string) $content );
	}
}

if ( ! function_exists( 'hds_test_frontend_late_stubs' ) ) {
	/**
	 * Declare the enqueue stubs unless another stub file already did
	 * (same recording format as tests/stubs/40-admin.php).
	 *
	 * @return void
	 */
	function hds_test_frontend_late_stubs() {
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			/**
			 * @return void
			 */
			function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
				$GLOBALS['hds_test']['enqueued']['script'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
			}
		}
		if ( ! function_exists( 'wp_enqueue_style' ) ) {
			/**
			 * @return void
			 */
			function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
				$GLOBALS['hds_test']['enqueued']['style'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' );
			}
		}
		if ( ! function_exists( 'wp_add_inline_script' ) ) {
			/**
			 * @return bool
			 */
			function wp_add_inline_script( $handle, $data, $position = 'after' ) {
				$GLOBALS['hds_test']['enqueued']['inline'][ $handle ][] = array( $data, $position );
				return true;
			}
		}
	}
}
