<?php
/**
 * Plugin Name:       Smart Document Search
 * Description:       AI search across your documents (Hebrew, RTL): answers are generated only from the uploaded files, with sources.
 * Version:           1.0.0
 * Author:            Oleg Gendelman
 * Text Domain:       smart-document-search
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HDS_VERSION', '1.0.0' );
define( 'HDS_DB_VERSION', '1' );
define( 'HDS_PLUGIN_FILE', __FILE__ );
define( 'HDS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HDS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HDS_MIN_PHP', '7.4' );

if ( version_compare( PHP_VERSION, HDS_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: required PHP version. */
					__( 'Smart Document Search requires PHP %s or newer. The plugin is inactive.', 'smart-document-search' ),
					HDS_MIN_PHP
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once HDS_PLUGIN_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'HDS_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HDS_Installer', 'deactivate' ) );

HDS_Plugin::instance()->init();
