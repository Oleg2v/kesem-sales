<?php
/**
 * Plugin bootstrap: wires every hook in one place.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class HDS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var HDS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return HDS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'plugins_loaded', array( 'HDS_Installer', 'maybe_upgrade' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( 'HDS_Frontend', 'register' ) );
		add_action( 'rest_api_init', array( 'HDS_REST', 'register_routes' ) );

		if ( is_admin() ) {
			HDS_Admin::register();
			HDS_Admin_Ajax::register();
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'smart-document-search', false, dirname( plugin_basename( HDS_PLUGIN_FILE ) ) . '/languages' );
	}
}
