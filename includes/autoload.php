<?php
/**
 * Class autoloader for the plugin.
 *
 * Maps HDS_Foo_Bar to class-hds-foo-bar.php and HDS_Foo_Interface to
 * interface-hds-foo.php inside the plugin's source directories. Has no
 * WordPress dependencies so the unit tests can use it directly.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hds_autoload' ) ) {
	/**
	 * Autoload a plugin class.
	 *
	 * @param string $class Class name.
	 * @return void
	 */
	function hds_autoload( $class ) {
		if ( 0 !== strpos( $class, 'HDS_' ) ) {
			return;
		}

		$slug = strtolower( str_replace( '_', '-', $class ) );
		if ( '-interface' === substr( $slug, -10 ) ) {
			$file = 'interface-' . substr( $slug, 0, -10 ) . '.php';
		} else {
			$file = 'class-' . $slug . '.php';
		}

		$base = dirname( __DIR__ );
		foreach ( array( 'includes', 'includes/extractors', 'admin' ) as $dir ) {
			$path = $base . '/' . $dir . '/' . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}

	spl_autoload_register( 'hds_autoload' );
}
