<?php
/**
 * Developer tool: print the first 500 characters that the extractors
 * produce for every test fixture, to eyeball extraction quality.
 *
 * Usage (from the plugin root): php tests/print-fixtures.php
 *
 * CLI only; never shipped (tests/ is excluded from the distribution).
 *
 * @package SmartDocumentSearch
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$hds_root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HDS_PLUGIN_DIR' ) ) {
	define( 'HDS_PLUGIN_DIR', $hds_root . '/' );
	define( 'HDS_PLUGIN_FILE', $hds_root . '/smart-document-search.php' );
	define( 'HDS_PLUGIN_URL', 'https://example.co.il/wp-content/plugins/smart-document-search/' );
	define( 'HDS_VERSION', '1.0.0' );
	define( 'HDS_DB_VERSION', '1' );
}

if ( is_readable( $hds_root . '/.dev/vendor/autoload.php' ) ) {
	require_once $hds_root . '/.dev/vendor/autoload.php';
}
require_once __DIR__ . '/stubs/00-wordpress.php';
require_once $hds_root . '/includes/autoload.php';

hds_test_reset();

$hds_files = array();
foreach ( array( 'doc', 'docx', 'pdf', 'txt' ) as $hds_ext ) {
	foreach ( (array) glob( __DIR__ . '/fixtures/*.' . $hds_ext ) as $hds_file ) {
		$hds_files[] = $hds_file;
	}
}
sort( $hds_files );

$hds_generated_dir = __DIR__ . '/fixtures/generated';
if ( is_dir( $hds_generated_dir ) ) {
	$hds_generated = array();
	$hds_iterator  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $hds_generated_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $hds_iterator as $hds_info ) {
		if ( $hds_info->isFile() && ! in_array( strtolower( $hds_info->getExtension() ), array( 'md', 'sh', 'py' ), true ) ) {
			$hds_generated[] = $hds_info->getPathname();
		}
	}
	sort( $hds_generated );
	$hds_files = array_merge( $hds_files, $hds_generated );
}

foreach ( $hds_files as $hds_file ) {
	$hds_relative = substr( $hds_file, strlen( $hds_root ) + 1 );
	try {
		$hds_text = HDS_Extractor_Factory::extract( $hds_file );
		echo '=== ' . $hds_relative . ' (' . mb_strlen( $hds_text, 'UTF-8' ) . ' chars) ===' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
		echo mb_substr( $hds_text, 0, 500, 'UTF-8' ) . PHP_EOL . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	} catch ( HDS_Extraction_Exception $hds_e ) {
		echo '=== ' . $hds_relative . ' ===' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
		echo 'ERROR ' . $hds_e->get_error_code() . ': ' . $hds_e->getMessage() . PHP_EOL . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	} catch ( Throwable $hds_e ) {
		echo '=== ' . $hds_relative . ' ===' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
		echo 'ERROR ' . get_class( $hds_e ) . ': ' . $hds_e->getMessage() . PHP_EOL . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	}
}
