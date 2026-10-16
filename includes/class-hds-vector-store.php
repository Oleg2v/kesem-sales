<?php
/**
 * Repository of the hds_chunks table (chunk texts and embeddings).
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vector store.
 *
 * Embeddings are stored as little-endian float32 (HDS_Vector::pack()) in a
 * MEDIUMBLOB, written as a hex literal (X'..') so the binary value never
 * passes through the connection character set. NULL = not embedded yet.
 *
 * Every method is static, so both HDS_Vector_Store::get_chunks( $ids ) and
 * ( new HDS_Vector_Store() )->get_chunks( $ids ) work. Code that accepts an
 * injected store (HDS_Search) can be given any object with the same methods
 * (a test double must not extend this class to override them).
 */
class HDS_Vector_Store {

	/**
	 * Option holding the index version (changes on every index change).
	 */
	const VERSION_OPTION = 'hds_index_version';

	/**
	 * Rows per page when streaming embeddings.
	 */
	const PAGE_SIZE = 500;

	/**
	 * Rows per multi-row INSERT.
	 */
	const INSERT_BATCH = 50;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hds_chunks';
	}

	/**
	 * Replace the chunks of a document (new rows have no embedding).
	 *
	 * @param int      $doc_id Document id.
	 * @param string[] $texts  Chunk texts in order.
	 * @return int Number of chunks stored.
	 */
	public static function replace_chunks( int $doc_id, array $texts ): int {
		global $wpdb;
		self::delete_for_doc( $doc_id );
		if ( $doc_id <= 0 ) {
			return 0;
		}
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$rows  = array();
		$index = 0;
		foreach ( $texts as $text ) {
			$text = (string) $text;
			if ( '' === trim( $text ) ) {
				continue;
			}
			$rows[] = array( $index, $text );
			++$index;
		}
		$stored = 0;
		foreach ( array_chunk( $rows, self::INSERT_BATCH ) as $batch ) {
			$values = array();
			$args   = array();
			foreach ( $batch as $row ) {
				$values[] = '(%d, %d, %s, %s)';
				$args[]   = $doc_id;
				$args[]   = $row[0];
				$args[]   = $row[1];
				$args[]   = $now;
			}
			$sql    = "INSERT INTO {$table} (doc_id, chunk_index, text, created_at) VALUES " . implode( ', ', $values );
			$result = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( false !== $result ) {
				$stored += count( $batch );
			}
		}
		return $stored;
	}

	/**
	 * Chunks of a document without an embedding.
	 *
	 * @param int $doc_id Document id.
	 * @param int $limit  Maximum rows.
	 * @return array[] [{id:int, text:string}] in chunk order.
	 */
	public static function pending_chunks( int $doc_id, int $limit ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, text FROM {$table} WHERE doc_id = %d AND embedding IS NULL ORDER BY chunk_index ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$doc_id,
				max( 1, $limit )
			),
			ARRAY_A
		);
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'   => (int) $row['id'],
				'text' => (string) $row['text'],
			);
		}
		return $out;
	}

	/**
	 * Number of chunks of a document without an embedding.
	 *
	 * @param int $doc_id Document id.
	 * @return int
	 */
	public static function count_pending( int $doc_id ): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE doc_id = %d AND embedding IS NULL", $doc_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Number of chunks of a document.
	 *
	 * @param int $doc_id Document id.
	 * @return int
	 */
	public static function count_for_doc( int $doc_id ): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE doc_id = %d", $doc_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Store the embedding of a chunk.
	 *
	 * @param int     $chunk_id Chunk id.
	 * @param float[] $vector   Embedding.
	 * @return void
	 */
	public static function save_embedding( int $chunk_id, array $vector ): void {
		global $wpdb;
		if ( $chunk_id <= 0 || array() === $vector ) {
			return;
		}
		$hex   = bin2hex( HDS_Vector::pack( $vector ) );
		$table = self::table();
		// The hex literal contains only [0-9a-f]; it is safe to concatenate.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET embedding = X'{$hex}' WHERE id = %d", $chunk_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Delete all chunks of a document.
	 *
	 * @param int $doc_id Document id.
	 * @return void
	 */
	public static function delete_for_doc( int $doc_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'doc_id' => $doc_id ), array( '%d' ) );
	}

	/**
	 * Chunks by id with their document title.
	 *
	 * @param int[] $chunk_ids Chunk ids.
	 * @return array<int, array> id => {id, doc_id, chunk_index, text, title}.
	 */
	public static function get_chunks( array $chunk_ids ): array {
		global $wpdb;
		$ids = HDS_Documents::ids( $chunk_ids );
		if ( array() === $ids ) {
			return array();
		}
		$chunks = self::table();
		$docs   = HDS_Documents::table();
		$out    = array();
		foreach ( array_chunk( $ids, self::PAGE_SIZE ) as $batch ) {
			$placeholders = implode( ', ', array_fill( 0, count( $batch ), '%d' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.id, c.doc_id, c.chunk_index, c.text, d.title FROM {$chunks} c LEFT JOIN {$docs} d ON d.id = c.doc_id WHERE c.id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$batch
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['id'] ] = array(
					'id'          => (int) $row['id'],
					'doc_id'      => (int) $row['doc_id'],
					'chunk_index' => (int) $row['chunk_index'],
					'text'        => (string) $row['text'],
					'title'       => null === $row['title'] ? '' : (string) $row['title'],
				);
			}
		}
		return $out;
	}

	/**
	 * Stream the embeddings of indexed documents in pages of PAGE_SIZE rows
	 * (by chunk id), without loading everything into memory.
	 *
	 * @param callable   $fn      function( int $chunk_id, int $doc_id, string $embedding_bin ).
	 * @param int[]|null $doc_ids Only these documents (null = all indexed documents).
	 * @return void
	 */
	public static function each_embedding( callable $fn, ?array $doc_ids = null ): void {
		global $wpdb;
		$filter = '';
		$args   = array();
		if ( null !== $doc_ids ) {
			$doc_ids = HDS_Documents::ids( $doc_ids );
			if ( array() === $doc_ids ) {
				return;
			}
			$filter = ' AND c.doc_id IN (' . implode( ', ', array_fill( 0, count( $doc_ids ), '%d' ) ) . ')';
			$args   = $doc_ids;
		}
		$chunks = self::table();
		$docs   = HDS_Documents::table();
		$last   = 0;
		do {
			$sql  = "SELECT c.id, c.doc_id, c.embedding FROM {$chunks} c INNER JOIN {$docs} d ON d.id = c.doc_id AND d.status = %s"
				. " WHERE c.embedding IS NOT NULL AND c.id > %d{$filter} ORDER BY c.id ASC LIMIT %d";
			$rows = $wpdb->get_results(
				$wpdb->prepare( $sql, array_merge( array( HDS_Documents::STATUS_INDEXED, $last ), $args, array( self::PAGE_SIZE ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_N
			);
			if ( ! is_array( $rows ) ) {
				return;
			}
			foreach ( $rows as $row ) {
				$last = (int) $row[0];
				call_user_func( $fn, $last, (int) $row[1], (string) $row[2] );
			}
			$count = count( $rows );
			unset( $rows );
		} while ( self::PAGE_SIZE === $count );
	}

	/**
	 * Chunk texts of indexed documents.
	 *
	 * @return array<int, string[]> doc_id => chunk texts in order.
	 */
	public static function all_texts_by_doc(): array {
		$out = array();
		self::each_doc_texts(
			static function ( int $doc_id, array $texts ) use ( &$out ) {
				$out[ $doc_id ] = $texts;
			}
		);
		return $out;
	}

	/**
	 * Stream the chunk texts of indexed documents, one document at a time.
	 *
	 * @param callable $fn function( int $doc_id, string[] $texts ).
	 * @return void
	 */
	public static function each_doc_texts( callable $fn ): void {
		global $wpdb;
		$docs_table = HDS_Documents::table();
		$chunks     = self::table();
		$doc_ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$docs_table} WHERE status = %s ORDER BY id ASC", HDS_Documents::STATUS_INDEXED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $doc_ids as $doc_id ) {
			$doc_id = (int) $doc_id;
			$texts  = $wpdb->get_col( $wpdb->prepare( "SELECT text FROM {$chunks} WHERE doc_id = %d ORDER BY chunk_index ASC", $doc_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( is_array( $texts ) && array() !== $texts ) {
				call_user_func( $fn, $doc_id, array_map( 'strval', $texts ) );
			}
		}
	}

	/**
	 * Current index version (created when missing).
	 *
	 * @return string
	 */
	public static function version(): string {
		$version = get_option( self::VERSION_OPTION, '' );
		if ( ! is_string( $version ) || '' === $version ) {
			$version = self::new_version();
			if ( ! add_option( self::VERSION_OPTION, $version ) ) {
				$stored  = get_option( self::VERSION_OPTION, '' );
				$version = is_string( $stored ) && '' !== $stored ? $stored : $version;
				if ( '' === $stored ) {
					update_option( self::VERSION_OPTION, $version );
				}
			}
		}
		return $version;
	}

	/**
	 * Change the index version and drop cached vector matrices.
	 *
	 * @return void
	 */
	public static function bump_version(): void {
		update_option( self::VERSION_OPTION, self::new_version() );
		if ( class_exists( 'HDS_Search' ) && method_exists( 'HDS_Search', 'flush_cache' ) ) {
			HDS_Search::flush_cache();
		}
	}

	/**
	 * Random version string.
	 *
	 * @return string
	 */
	private static function new_version(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			return md5( uniqid( '', true ) );
		}
	}
}
