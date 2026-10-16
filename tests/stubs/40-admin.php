<?php
/**
 * Test stubs for the admin UI (HDS_Admin, HDS_Admin_Ajax).
 *
 * Functions that end a request in WordPress (wp_safe_redirect + exit,
 * wp_send_json_*) throw instead, so tests can inspect what would have
 * been sent. Nonces are deterministic: wp_create_nonce( $action ) is
 * 'nonce-' . md5( $action . '|' . user id ) (10 chars).
 *
 * Every function is guarded with function_exists so other stub files win
 * when they load first. State lives in $GLOBALS['hds_test'].
 *
 * @package SmartDocumentSearch
 */

if ( ! class_exists( 'HDS_Test_Die_Exception' ) ) {
	/**
	 * Thrown by the wp_die() stub (also defined in 20-data.php).
	 */
	class HDS_Test_Die_Exception extends RuntimeException {

		/**
		 * Title passed to wp_die().
		 *
		 * @var string
		 */
		public $title = '';
	}
}

if ( ! class_exists( 'HDS_Test_Redirect_Exception' ) ) {
	/**
	 * Thrown by the wp_safe_redirect()/wp_redirect() stubs.
	 */
	class HDS_Test_Redirect_Exception extends RuntimeException {

		/**
		 * Location.
		 *
		 * @var string
		 */
		public $location = '';

		/**
		 * HTTP status.
		 *
		 * @var int
		 */
		public $status = 302;
	}
}

if ( ! class_exists( 'HDS_Test_Json_Exception' ) ) {
	/**
	 * Thrown by the wp_send_json_*() stubs.
	 */
	class HDS_Test_Json_Exception extends RuntimeException {

		/**
		 * Whether wp_send_json_success() was called.
		 *
		 * @var bool
		 */
		public $success = false;

		/**
		 * Response data.
		 *
		 * @var mixed
		 */
		public $data = null;

		/**
		 * HTTP status (null = default 200).
		 *
		 * @var int|null
		 */
		public $status = null;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Stub: throws HDS_Test_Die_Exception.
	 *
	 * @param string $message Message.
	 * @param string $title   Title.
	 * @param array  $args    Args.
	 * @return void
	 * @throws HDS_Test_Die_Exception Always.
	 */
	function wp_die( $message = '', $title = '', $args = array() ) {
		$e        = new HDS_Test_Die_Exception( is_string( $message ) ? $message : 'wp_die' );
		$e->title = is_string( $title ) ? $title : '';
		throw $e;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/**
	 * Stub: throws HDS_Test_Redirect_Exception (WordPress would exit after it).
	 *
	 * @param string $location Location.
	 * @param int    $status   Status.
	 * @param string $by       X-Redirect-By.
	 * @return void
	 * @throws HDS_Test_Redirect_Exception Always.
	 */
	function wp_safe_redirect( $location, $status = 302, $by = 'WordPress' ) {
		$e           = new HDS_Test_Redirect_Exception( 'redirect' );
		$e->location = (string) $location;
		$e->status   = (int) $status;
		throw $e;
	}
}

if ( ! function_exists( 'wp_redirect' ) ) {
	/**
	 * Stub: same as wp_safe_redirect().
	 *
	 * @param string $location Location.
	 * @param int    $status   Status.
	 * @param string $by       X-Redirect-By.
	 * @return void
	 */
	function wp_redirect( $location, $status = 302, $by = 'WordPress' ) {
		wp_safe_redirect( $location, $status, $by );
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	/**
	 * Stub: throws HDS_Test_Json_Exception.
	 *
	 * @param mixed    $data   Data.
	 * @param int|null $status Status.
	 * @param int      $flags  Flags.
	 * @return void
	 * @throws HDS_Test_Json_Exception Always.
	 */
	function wp_send_json_success( $data = null, $status = null, $flags = 0 ) {
		$e          = new HDS_Test_Json_Exception( 'json success' );
		$e->success = true;
		$e->data    = $data;
		$e->status  = $status;
		throw $e;
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	/**
	 * Stub: throws HDS_Test_Json_Exception.
	 *
	 * @param mixed    $data   Data.
	 * @param int|null $status Status.
	 * @param int      $flags  Flags.
	 * @return void
	 * @throws HDS_Test_Json_Exception Always.
	 */
	function wp_send_json_error( $data = null, $status = null, $flags = 0 ) {
		$e          = new HDS_Test_Json_Exception( 'json error' );
		$e->success = false;
		$e->data    = $data;
		$e->status  = $status;
		throw $e;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Stub: deterministic nonce per action and user.
	 *
	 * @param string|int $action Action.
	 * @return string
	 */
	function wp_create_nonce( $action = -1 ) {
		$user = isset( $GLOBALS['hds_test']['user_id'] ) ? (int) $GLOBALS['hds_test']['user_id'] : 0;
		return substr( md5( 'nonce|' . $action . '|' . $user ), 0, 10 );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Stub.
	 *
	 * @param string     $nonce  Nonce.
	 * @param string|int $action Action.
	 * @return int|false
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return is_string( $nonce ) && hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	/**
	 * Stub: verifies $_REQUEST[ $query_arg ]; wp_die() on failure.
	 *
	 * @param string|int $action    Action.
	 * @param string     $query_arg Field name.
	 * @return int
	 */
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		$GLOBALS['hds_test']['nonce_checks'][] = array( $action, $query_arg );
		$nonce                                 = isset( $_REQUEST[ $query_arg ] ) ? $_REQUEST[ $query_arg ] : ''; // phpcs:ignore
		$ok                                    = wp_verify_nonce( $nonce, $action );
		if ( ! $ok ) {
			wp_die( 'The link you followed has expired.', 'Something went wrong.', array( 'response' => 403 ) );
		}
		return $ok;
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	/**
	 * Stub: verifies $_REQUEST[ $query_arg ]; wp_die( -1, 403 ) on failure.
	 *
	 * @param string|int  $action    Action.
	 * @param string|bool $query_arg Field name.
	 * @param bool        $stop      Die on failure.
	 * @return int|false
	 */
	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
		$GLOBALS['hds_test']['nonce_checks'][] = array( $action, $query_arg );
		$field                                 = $query_arg ? $query_arg : '_ajax_nonce';
		$nonce                                 = isset( $_REQUEST[ $field ] ) ? $_REQUEST[ $field ] : ''; // phpcs:ignore
		$ok                                    = wp_verify_nonce( $nonce, $action );
		if ( ! $ok && $stop ) {
			wp_die( '-1', '', array( 'response' => 403 ) );
		}
		return $ok;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Stub.
	 *
	 * @param string|int $action  Action.
	 * @param string     $name    Field name.
	 * @param bool       $referer Add referer field.
	 * @param bool       $echo    Echo.
	 * @return string
	 */
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
		$html = '<input type="hidden" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
		if ( $referer ) {
			$html .= '<input type="hidden" name="_wp_http_referer" value="/wp-admin/admin.php?page=hds-documents" />';
		}
		if ( $echo ) {
			echo $html; // phpcs:ignore
		}
		return $html;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $path   Path.
	 * @param string $scheme Scheme.
	 * @return string
	 */
	function admin_url( $path = '', $scheme = 'admin' ) {
		return 'https://example.co.il/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	/**
	 * Stub: records the page, returns the hook suffix.
	 *
	 * @return string
	 */
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
		$GLOBALS['hds_test']['menu'][] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'icon_url', 'position' );
		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	/**
	 * Stub: records the page, returns the hook suffix.
	 *
	 * @return string
	 */
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
		$GLOBALS['hds_test']['submenu'][] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
		return $parent_slug === $menu_slug ? 'toplevel_page_' . $menu_slug : 'ai-search_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	/**
	 * Stub: records the setting.
	 *
	 * @param string $group  Group.
	 * @param string $option Option.
	 * @param array  $args   Args.
	 * @return void
	 */
	function register_setting( $group, $option, $args = array() ) {
		$GLOBALS['hds_test']['registered_settings'][] = compact( 'group', 'option', 'args' );
	}
}

if ( ! function_exists( 'settings_fields' ) ) {
	/**
	 * Stub (same markup as WordPress).
	 *
	 * @param string $option_group Group.
	 * @return void
	 */
	function settings_fields( $option_group ) {
		echo "<input type='hidden' name='option_page' value='" . esc_attr( $option_group ) . "' />";
		echo '<input type="hidden" name="action" value="update" />';
		wp_nonce_field( "$option_group-options" );
	}
}

if ( ! function_exists( 'settings_errors' ) ) {
	/**
	 * Stub: prints recorded settings errors.
	 *
	 * @return void
	 */
	function settings_errors( $setting = '', $sanitize = false, $hide_on_update = false ) {
		$errors = isset( $GLOBALS['hds_test']['settings_errors'] ) ? $GLOBALS['hds_test']['settings_errors'] : array();
		foreach ( $errors as $error ) {
			echo '<div class="notice notice-' . esc_attr( $error['type'] ) . ' settings-error"><p><strong>' . esc_html( $error['message'] ) . '</strong></p></div>';
		}
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	/**
	 * Stub.
	 *
	 * @return void
	 */
	function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ) {
		$text = null === $text ? 'Save Changes' : $text;
		echo '<p class="submit"><input type="submit" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" class="button button-' . esc_attr( $type ) . '" value="' . esc_attr( $text ) . '" /></p>';
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return void
	 */
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text ); // phpcs:ignore
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return void
	 */
	function esc_attr_e( $text, $domain = 'default' ) {
		echo esc_attr( $text ); // phpcs:ignore
	}
}

if ( ! function_exists( '__checked_selected_helper' ) ) {
	/**
	 * Stub of the WordPress helper.
	 *
	 * @return string
	 */
	function __checked_selected_helper( $helper, $current, $echo, $type ) { // phpcs:ignore
		$result = (string) $helper === (string) $current ? " $type='$type'" : '';
		if ( $echo ) {
			echo $result; // phpcs:ignore
		}
		return $result;
	}
}

if ( ! function_exists( 'disabled' ) ) {
	/**
	 * Stub.
	 *
	 * @return string
	 */
	function disabled( $disabled, $current = true, $echo = true ) {
		return __checked_selected_helper( $disabled, $current, $echo, 'disabled' );
	}
}

if ( ! function_exists( 'checked' ) ) {
	/**
	 * Stub.
	 *
	 * @return string
	 */
	function checked( $checked, $current = true, $echo = true ) {
		return __checked_selected_helper( $checked, $current, $echo, 'checked' );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Stub.
	 *
	 * @param float $number   Number.
	 * @param int   $decimals Decimals.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'get_date_from_gmt' ) ) {
	/**
	 * Stub: the site time zone is UTC in tests.
	 *
	 * @param string $date_string GMT date.
	 * @param string $format      Format.
	 * @return string
	 */
	function get_date_from_gmt( $date_string, $format = 'Y-m-d H:i:s' ) {
		$ts = strtotime( $date_string . ' UTC' );
		return false === $ts ? gmdate( $format, 0 ) : gmdate( $format, $ts );
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	/**
	 * Stub.
	 *
	 * @param string   $format    Format.
	 * @param int|bool $timestamp Timestamp with offset.
	 * @param bool     $gmt       GMT.
	 * @return string
	 */
	function date_i18n( $format, $timestamp = false, $gmt = false ) {
		return gmdate( $format, false === $timestamp ? time() : (int) $timestamp );
	}
}

if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
	/**
	 * Copy of the WordPress function.
	 *
	 * @param string $value PHP ini shorthand.
	 * @return int
	 */
	function wp_convert_hr_to_bytes( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$bytes = (int) $value;
		if ( false !== strpos( $value, 'g' ) ) {
			$bytes *= 1073741824;
		} elseif ( false !== strpos( $value, 'm' ) ) {
			$bytes *= 1048576;
		} elseif ( false !== strpos( $value, 'k' ) ) {
			$bytes *= 1024;
		}
		return min( $bytes, PHP_INT_MAX );
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	/**
	 * Stub: counts calls.
	 *
	 * @return void
	 */
	function nocache_headers() {
		$GLOBALS['hds_test']['nocache'] = isset( $GLOBALS['hds_test']['nocache'] ) ? $GLOBALS['hds_test']['nocache'] + 1 : 1;
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	/**
	 * Stub: $GLOBALS['hds_test']['screen'] (object with id) or null.
	 *
	 * @return object|null
	 */
	function get_current_screen() {
		return isset( $GLOBALS['hds_test']['screen'] ) ? $GLOBALS['hds_test']['screen'] : null;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/**
	 * Stub: records the handle.
	 *
	 * @return void
	 */
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['hds_test']['enqueued']['script'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * Stub: records the handle.
	 *
	 * @return void
	 */
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['hds_test']['enqueued']['style'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' );
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	/**
	 * Stub: records the code.
	 *
	 * @return bool
	 */
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		$GLOBALS['hds_test']['enqueued']['inline'][ $handle ][] = array( $data, $position );
		return true;
	}
}
