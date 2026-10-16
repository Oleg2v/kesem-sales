<?php
/**
 * Activation, deactivation and database schema.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installer.
 */
class HDS_Installer {

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'hds_db_version';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether the plugin is activated for the whole network.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		$error = static::requirements_error();
		if ( '' !== $error ) {
			if ( function_exists( 'deactivate_plugins' ) && defined( 'HDS_PLUGIN_FILE' ) ) {
				deactivate_plugins( plugin_basename( HDS_PLUGIN_FILE ), true, (bool) $network_wide );
			}
			wp_die(
				esc_html( $error ),
				esc_html__( 'Plugin activation failed', 'smart-document-search' ),
				array( 'back_link' => true )
			);
			return;
		}

		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( (array) $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				static::install_site();
				restore_current_blog();
			}
			return;
		}
		static::install_site();
	}

	/**
	 * Deactivation hook: only releases the indexing lock (data is kept).
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		delete_transient( 'hds_index_lock' );
	}

	/**
	 * Create/upgrade the tables when the stored schema version differs
	 * (runs on plugins_loaded; one autoloaded option read when up to date).
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( HDS_DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}
		if ( '' !== static::requirements_error() ) {
			return;
		}
		self::create_tables();
		HDS_Storage::ensure_protected();
		update_option( self::DB_VERSION_OPTION, HDS_DB_VERSION );
	}

	/**
	 * Create or update the tables with dbDelta().
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( self::schema() );
	}

	/**
	 * CREATE TABLE statements in dbDelta format (one field per line, two
	 * spaces after PRIMARY KEY, KEY name (column)).
	 *
	 * @return string
	 */
	public static function schema(): string {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$docs            = $wpdb->prefix . 'hds_docs';
		$chunks          = $wpdb->prefix . 'hds_chunks';

		return "CREATE TABLE {$docs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL,
  file_name varchar(255) NOT NULL,
  original_name varchar(255) NOT NULL,
  ext varchar(10) NOT NULL,
  file_size bigint(20) unsigned NOT NULL DEFAULT 0,
  file_hash char(64) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  status_message text NULL,
  chunk_count int(10) unsigned NOT NULL DEFAULT 0,
  char_count int(10) unsigned NOT NULL DEFAULT 0,
  index_hash char(64) NOT NULL DEFAULT '',
  indexed_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY file_hash (file_hash)
) {$charset_collate};
CREATE TABLE {$chunks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  doc_id bigint(20) unsigned NOT NULL,
  chunk_index int(10) unsigned NOT NULL,
  text mediumtext NOT NULL,
  embedding mediumblob NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY doc_id (doc_id),
  UNIQUE KEY doc_chunk (doc_id,chunk_index)
) {$charset_collate};";
	}

	/**
	 * Why the plugin cannot run on this server ('' when it can).
	 *
	 * @return string English message.
	 */
	public static function requirements_error(): string {
		if ( ! extension_loaded( 'mbstring' ) ) {
			return __( 'Smart Document Search requires the PHP "mbstring" extension, which is not enabled on this server. Ask your hosting provider to enable mbstring, then activate the plugin again.', 'smart-document-search' );
		}
		return '';
	}

	/**
	 * Install for the current site: tables, protected storage, options.
	 *
	 * @return void
	 */
	protected static function install_site(): void {
		self::create_tables();
		HDS_Storage::ensure_protected();
		add_option( HDS_Settings::OPTION, HDS_Settings::defaults() );
		HDS_Vector_Store::version();
		update_option( self::DB_VERSION_OPTION, HDS_DB_VERSION );
	}
}
