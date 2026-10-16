<?php
/**
 * PHPUnit bootstrap: runs the plugin classes without WordPress.
 *
 * WordPress functions used by the classes under test are stubbed in
 * tests/stubs/*.php (every file there is loaded). Stubs keep state in
 * $GLOBALS['hds_test'] and are reset by hds_test_reset().
 *
 * @package SmartDocumentSearch
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HDS_TESTS_DIR', __DIR__ );
define( 'HDS_FIXTURES_DIR', __DIR__ . '/fixtures' );
define( 'HDS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'HDS_PLUGIN_FILE', dirname( __DIR__ ) . '/smart-document-search.php' );
define( 'HDS_PLUGIN_URL', 'https://example.co.il/wp-content/plugins/smart-document-search/' );
define( 'HDS_VERSION', '1.0.0' );
define( 'HDS_DB_VERSION', '1' );

require dirname( __DIR__ ) . '/.dev/vendor/autoload.php';

$hds_stub_files = glob( __DIR__ . '/stubs/*.php' );
sort( $hds_stub_files );
foreach ( $hds_stub_files as $hds_stub_file ) {
	require_once $hds_stub_file;
}
foreach ( glob( __DIR__ . '/helpers/*.php' ) as $hds_helper_file ) {
	require_once $hds_helper_file;
}

require dirname( __DIR__ ) . '/includes/autoload.php';

hds_test_reset();
