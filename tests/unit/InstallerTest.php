<?php
/**
 * Tests for HDS_Installer and uninstall.php.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Installer with a failing requirements check.
 */
class HDS_Test_Broken_Installer extends HDS_Installer {

	/**
	 * Pretend mbstring is missing.
	 *
	 * @return string
	 */
	public static function requirements_error(): string {
		return 'Smart Document Search requires the PHP "mbstring" extension.';
	}
}

/**
 * Installer tests.
 */
class InstallerTest extends TestCase {

	/**
	 * Fake database.
	 *
	 * @var HDS_Test_WPDB
	 */
	private $db;

	protected function setUp(): void {
		hds_test_reset();
		$this->db = hds_test_create_tables();
		hds_test_clean_uploads();
	}

	protected function tearDown(): void {
		hds_test_clean_uploads();
		$GLOBALS['wpdb']->set_blog_id( 1 );
	}

	/**
	 * SQL passed to dbDelta().
	 *
	 * @return array
	 */
	private function dbdelta_calls(): array {
		return isset( $GLOBALS['hds_test']['dbdelta'] ) ? $GLOBALS['hds_test']['dbdelta'] : array();
	}

	public function test_schema_uses_dbdelta_format(): void {
		$schema = HDS_Installer::schema();
		$this->assertStringContainsString( "CREATE TABLE wp_hds_docs (\n", $schema );
		$this->assertStringContainsString( "CREATE TABLE wp_hds_chunks (\n", $schema );
		$this->assertSame( 2, substr_count( $schema, "  PRIMARY KEY  (id),\n" ), 'Two spaces after PRIMARY KEY.' );
		$this->assertStringContainsString( "  KEY status (status),\n", $schema );
		$this->assertStringContainsString( "  KEY file_hash (file_hash)\n", $schema );
		$this->assertStringContainsString( "  KEY doc_id (doc_id),\n", $schema );
		$this->assertStringContainsString( "  UNIQUE KEY doc_chunk (doc_id,chunk_index)\n", $schema );
		$this->assertSame( 2, substr_count( $schema, ') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;' ) );
		$this->assertStringNotContainsString( 'INDEX', $schema );
		$this->assertStringNotContainsString( "\t", $schema );

		// One field per line, each followed by a comma except the last one of a table.
		foreach ( preg_split( '/;\s*/', trim( $schema ), -1, PREG_SPLIT_NO_EMPTY ) as $statement ) {
			$lines = explode( "\n", $statement );
			$body  = array_slice( $lines, 1, -1 );
			$last  = array_pop( $body );
			foreach ( $body as $line ) {
				$this->assertMatchesRegularExpression( '/^  \S.*,$/', $line );
				$this->assertSame( 1, substr_count( $line, ',' ) + ( false !== strpos( $line, '(doc_id,chunk_index)' ) ? -1 : 0 ), $line );
			}
			$this->assertDoesNotMatchRegularExpression( '/,$/', $last );
		}

		$columns = array(
			'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
			'title varchar(255) NOT NULL',
			'file_name varchar(255) NOT NULL',
			'original_name varchar(255) NOT NULL',
			'ext varchar(10) NOT NULL',
			'file_size bigint(20) unsigned NOT NULL DEFAULT 0',
			"file_hash char(64) NOT NULL DEFAULT ''",
			"status varchar(20) NOT NULL DEFAULT 'pending'",
			'status_message text NULL',
			'chunk_count int(10) unsigned NOT NULL DEFAULT 0',
			'char_count int(10) unsigned NOT NULL DEFAULT 0',
			"index_hash char(64) NOT NULL DEFAULT ''",
			'indexed_at datetime NULL',
			'created_at datetime NOT NULL',
			'updated_at datetime NOT NULL',
			'doc_id bigint(20) unsigned NOT NULL',
			'chunk_index int(10) unsigned NOT NULL',
			'text mediumtext NOT NULL',
			'embedding mediumblob NULL',
		);
		foreach ( $columns as $column ) {
			$this->assertStringContainsString( '  ' . $column . ",\n", $schema );
		}
	}

	public function test_create_tables_runs_dbdelta(): void {
		HDS_Installer::create_tables();
		$this->assertSame( array( HDS_Installer::schema() ), $this->dbdelta_calls() );
	}

	public function test_activate_single_site(): void {
		HDS_Installer::activate();
		$this->assertCount( 1, $this->dbdelta_calls() );
		$this->assertSame( HDS_DB_VERSION, get_option( 'hds_db_version' ) );
		$this->assertSame( HDS_Settings::defaults(), get_option( HDS_Settings::OPTION ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', (string) get_option( 'hds_index_version' ) );
		foreach ( array( HDS_Storage::dir(), HDS_Storage::cache_dir() ) as $dir ) {
			$this->assertFileExists( $dir . '.htaccess' );
			$this->assertFileExists( $dir . 'index.php' );
			$this->assertFileExists( $dir . 'web.config' );
		}
	}

	public function test_activate_keeps_existing_settings(): void {
		update_option( HDS_Settings::OPTION, array( 'top_n' => 9 ) );
		HDS_Installer::activate( false );
		$this->assertSame( array( 'top_n' => 9 ), get_option( HDS_Settings::OPTION ) );
	}

	public function test_network_activation_installs_every_site(): void {
		$GLOBALS['hds_test']['multisite'] = true;
		$GLOBALS['hds_test']['sites']     = array( 1, 2, 3 );
		HDS_Installer::activate( true );
		$this->assertSame( array( 1, 2, 3 ), $GLOBALS['hds_test']['switched'] );
		$calls = $this->dbdelta_calls();
		$this->assertCount( 3, $calls );
		$this->assertStringContainsString( 'CREATE TABLE wp_hds_docs', $calls[0] );
		$this->assertStringContainsString( 'CREATE TABLE wp_2_hds_docs', $calls[1] );
		$this->assertStringContainsString( 'CREATE TABLE wp_3_hds_chunks', $calls[2] );
		$this->assertSame( 1, get_current_blog_id(), 'Restored.' );
	}

	public function test_single_site_activation_on_multisite(): void {
		$GLOBALS['hds_test']['multisite'] = true;
		$GLOBALS['hds_test']['sites']     = array( 1, 2 );
		HDS_Installer::activate( false );
		$this->assertArrayNotHasKey( 'switched', $GLOBALS['hds_test'] );
		$this->assertCount( 1, $this->dbdelta_calls() );
	}

	public function test_missing_requirement_stops_activation(): void {
		try {
			HDS_Test_Broken_Installer::activate();
			$this->fail( 'wp_die() was not called.' );
		} catch ( HDS_Test_Die_Exception $e ) {
			$this->assertStringContainsString( 'mbstring', $e->getMessage() );
			$this->assertSame( 'Plugin activation failed', $e->title );
		}
		$this->assertSame( array( 'smart-document-search/smart-document-search.php' ), $GLOBALS['hds_test']['deactivated'] );
		$this->assertSame( array(), $this->dbdelta_calls() );
		$this->assertFalse( get_option( 'hds_db_version' ) );
	}

	public function test_requirements_ok_here(): void {
		$this->assertSame( '', HDS_Installer::requirements_error() );
	}

	public function test_deactivate_only_releases_lock(): void {
		set_transient( 'hds_index_lock', 'x', 120 );
		set_transient( 'hds_ans_abc', 'answer', 100 );
		update_option( 'hds_db_version', '1' );
		HDS_Installer::deactivate();
		$this->assertFalse( get_transient( 'hds_index_lock' ) );
		$this->assertSame( 'answer', get_transient( 'hds_ans_abc' ) );
		$this->assertSame( '1', get_option( 'hds_db_version' ) );
	}

	public function test_maybe_upgrade(): void {
		update_option( 'hds_db_version', HDS_DB_VERSION );
		HDS_Installer::maybe_upgrade();
		$this->assertSame( array(), $this->dbdelta_calls(), 'Up to date: nothing to do.' );

		update_option( 'hds_db_version', '0' );
		HDS_Installer::maybe_upgrade();
		$this->assertCount( 1, $this->dbdelta_calls() );
		$this->assertSame( HDS_DB_VERSION, get_option( 'hds_db_version' ) );
		$this->assertFileExists( HDS_Storage::dir() . '.htaccess' );

		delete_option( 'hds_db_version' );
		HDS_Installer::maybe_upgrade();
		$this->assertCount( 2, $this->dbdelta_calls() );
	}

	/**
	 * Seed data that uninstall must remove (and some it must keep).
	 *
	 * @return string A file outside hds-docs that must survive.
	 */
	private function seed_uninstall_data(): string {
		foreach ( array( 'hds_settings', 'hds_db_version', 'hds_index_version', 'hds_index_queue', 'hds_suggest_dict', 'other_plugin' ) as $option ) {
			update_option( $option, 'x' );
		}
		$options = array( '_transient_hds_rl_abc', '_transient_timeout_hds_rl_abc', '_transient_hds_ans_1', '_transient_hdsx', '_transient_other', 'hds_unrelated_option_kept_by_sql' );
		foreach ( $options as $name ) {
			$this->db->insert( $this->db->options, array( 'option_name' => $name, 'option_value' => 'v' ) );
		}
		foreach ( array( 'hds_notice', 'hds_x', 'hdsfoo', 'other' ) as $key ) {
			$this->db->insert( $this->db->usermeta, array( 'user_id' => 1, 'meta_key' => $key, 'meta_value' => 'v' ) );
		}
		HDS_Storage::ensure_protected();
		file_put_contents( HDS_Storage::dir() . 'a-12345678.doc', 'doc' );
		file_put_contents( HDS_Storage::cache_dir() . 'vectors.bin', 'bin' );
		$keep = $GLOBALS['hds_test']['upload_dir'] . '/2026/10/photo.jpg';
		wp_mkdir_p( dirname( $keep ) );
		file_put_contents( $keep, 'jpg' );
		return $keep;
	}

	/**
	 * Assert the global (non per-site) results of uninstall.
	 *
	 * @param string $keep File that must survive.
	 * @return void
	 */
	private function assert_uninstalled( string $keep ): void {
		foreach ( array( 'hds_settings', 'hds_db_version', 'hds_index_version', 'hds_index_queue', 'hds_suggest_dict' ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertSame( 'x', get_option( 'other_plugin' ) );
		$this->assertSame(
			array( '_transient_hdsx', '_transient_other', 'hds_unrelated_option_kept_by_sql' ),
			$this->db->get_col( "SELECT option_name FROM {$this->db->options} ORDER BY option_name" )
		);
		$this->assertSame( array( 'hdsfoo', 'other' ), $this->db->get_col( "SELECT meta_key FROM {$this->db->usermeta} ORDER BY meta_key" ) );
		$this->assertDirectoryDoesNotExist( HDS_Storage::dir() );
		$this->assertFileExists( $keep );
	}

	public function test_uninstall_single_site(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'smart-document-search/smart-document-search.php' );
		}
		$keep = $this->seed_uninstall_data();
		$this->assertTrue( $this->db->table_exists( 'wp_hds_docs' ) );

		include HDS_PLUGIN_DIR . 'uninstall.php';

		$this->assertFalse( $this->db->table_exists( 'wp_hds_docs' ) );
		$this->assertFalse( $this->db->table_exists( 'wp_hds_chunks' ) );
		$this->assert_uninstalled( $keep );
	}

	public function test_uninstall_multisite(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'smart-document-search/smart-document-search.php' );
		}
		$GLOBALS['hds_test']['multisite'] = true;
		$GLOBALS['hds_test']['sites']     = array( 1, 2 );
		$this->db->create_test_tables( 'wp_2_' );
		$keep = $this->seed_uninstall_data();
		$this->assertTrue( $this->db->table_exists( 'wp_2_hds_chunks' ) );

		include HDS_PLUGIN_DIR . 'uninstall.php';

		$this->assertSame( array( 1, 2 ), $GLOBALS['hds_test']['switched'] );
		foreach ( array( 'wp_hds_docs', 'wp_hds_chunks', 'wp_2_hds_docs', 'wp_2_hds_chunks' ) as $table ) {
			$this->assertFalse( $this->db->table_exists( $table ), $table );
		}
		$this->assert_uninstalled( $keep );
	}
}
