<?php
/**
 * Uninstall: removes every table, option, transient, user meta and stored
 * file of the plugin (on every site of a multisite network).
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// WordPress includes this file from inside a function: make $wpdb visible.
global $wpdb;

require_once __DIR__ . '/includes/autoload.php';

$hds_uninstall_site = static function () {
	global $wpdb;

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hds_chunks" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hds_docs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	foreach ( array( 'hds_settings', 'hds_db_version', 'hds_index_version', 'hds_index_queue', 'hds_suggest_dict' ) as $hds_option ) {
		delete_option( $hds_option );
	}

	// Transients (rate limits, answer cache, lock, notices) and their timeouts.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_transient_hds_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_hds_' ) . '%'
		)
	);

	// Stored documents and the vector cache (only <uploads>/hds-docs, checked with realpath).
	HDS_Storage::delete_all();
};

if ( is_multisite() ) {
	$hds_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( (array) $hds_site_ids as $hds_site_id ) {
		switch_to_blog( (int) $hds_site_id );
		$hds_uninstall_site();
		restore_current_blog();
	}
} else {
	$hds_uninstall_site();
}

// User meta is shared by all sites.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->esc_like( 'hds_' ) . '%'
	)
);

// Persistent object caches: drop the cached vector matrices.
if ( function_exists( 'wp_cache_flush_group' ) && ( ! function_exists( 'wp_cache_supports' ) || wp_cache_supports( 'flush_group' ) ) ) {
	wp_cache_flush_group( 'hds' );
}
