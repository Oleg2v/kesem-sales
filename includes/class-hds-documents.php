<?php
/**
 * Repository of the hds_docs table.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documents.
 *
 * Every method is static, so the class can be used both as
 * HDS_Documents::get( $id ) and ( new HDS_Documents() )->get( $id ).
 * Rows are associative arrays with the numeric columns cast to int.
 */
class HDS_Documents {

	const STATUS_PENDING     = 'pending';
	const STATUS_PROCESSING  = 'processing';
	const STATUS_INDEXED     = 'indexed';
	const STATUS_ERROR       = 'error';
	const STATUS_SCANNED     = 'scanned';
	const STATUS_DEMO_LOCKED = 'demo_locked';

	/**
	 * Columns that update() may change, with their formats.
	 */
	const UPDATABLE = array(
		'title'          => '%s',
		'status'         => '%s',
		'status_message' => '%s',
		'chunk_count'    => '%d',
		'char_count'     => '%d',
		'index_hash'     => '%s',
		'indexed_at'     => '%s',
		'file_hash'      => '%s',
		'file_size'      => '%d',
	);

	/**
	 * Integer columns.
	 */
	const INT_COLUMNS = array( 'id', 'file_size', 'chunk_count', 'char_count' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hds_docs';
	}

	/**
	 * All statuses.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_PENDING,
			self::STATUS_PROCESSING,
			self::STATUS_INDEXED,
			self::STATUS_ERROR,
			self::STATUS_SCANNED,
			self::STATUS_DEMO_LOCKED,
		);
	}

	/**
	 * Insert a document (status pending).
	 *
	 * @param array $data Keys from HDS_Storage::store_upload(): file_name, original_name, ext, file_size, file_hash, title.
	 * @return int New id, 0 on failure.
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$title = isset( $data['title'] ) ? self::clip( (string) $data['title'], 255 ) : '';
		$row   = array(
			'title'         => '' !== $title ? $title : self::clip( (string) ( isset( $data['original_name'] ) ? $data['original_name'] : '' ), 255 ),
			'file_name'     => self::clip( (string) ( isset( $data['file_name'] ) ? $data['file_name'] : '' ), 255 ),
			'original_name' => self::clip( (string) ( isset( $data['original_name'] ) ? $data['original_name'] : '' ), 255 ),
			'ext'           => substr( strtolower( (string) ( isset( $data['ext'] ) ? $data['ext'] : '' ) ), 0, 10 ),
			'file_size'     => isset( $data['file_size'] ) ? max( 0, (int) $data['file_size'] ) : 0,
			'file_hash'     => isset( $data['file_hash'] ) ? substr( (string) $data['file_hash'], 0, 64 ) : '',
			'status'        => self::STATUS_PENDING,
			'created_at'    => $now,
			'updated_at'    => $now,
		);
		$ok    = $wpdb->insert( self::table(), $row, array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * One document.
	 *
	 * @param int $id Document id.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * All documents, oldest first.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? array_map( array( __CLASS__, 'cast' ), $rows ) : array();
	}

	/**
	 * A document by file hash (duplicate detection).
	 *
	 * @param string $hash sha256 hex.
	 * @return array|null
	 */
	public static function find_by_hash( string $hash ): ?array {
		global $wpdb;
		$hash = strtolower( trim( $hash ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE file_hash = %s ORDER BY id ASC LIMIT 1", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * Update whitelisted columns; sets updated_at.
	 *
	 * @param int   $id   Document id.
	 * @param array $data Column => value (title, status, status_message, chunk_count, char_count, index_hash, indexed_at, file_hash, file_size).
	 * @return bool False on a database error or an invalid status.
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		$row     = array();
		$formats = array();
		foreach ( self::UPDATABLE as $column => $format ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}
			$value = $data[ $column ];
			if ( 'status' === $column && ! in_array( $value, self::statuses(), true ) ) {
				return false;
			}
			if ( null === $value && ( 'status_message' === $column || 'indexed_at' === $column ) ) {
				$row[ $column ] = null;
			} elseif ( '%d' === $format ) {
				$row[ $column ] = max( 0, (int) $value );
			} elseif ( 'title' === $column || 'status_message' === $column ) {
				$row[ $column ] = self::clip( (string) $value, 'title' === $column ? 255 : 1000 );
			} else {
				$row[ $column ] = (string) $value;
			}
			$formats[] = $format;
		}
		$row['updated_at'] = current_time( 'mysql', true );
		$formats[]         = '%s';
		return false !== $wpdb->update( self::table(), $row, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Set the status (and message; null clears it).
	 *
	 * @param int         $id      Document id.
	 * @param string      $status  One of the STATUS_* constants.
	 * @param string|null $message Admin message (no document content).
	 * @return bool
	 */
	public static function set_status( int $id, string $status, ?string $message = null ): bool {
		return self::update(
			$id,
			array(
				'status'         => $status,
				'status_message' => $message,
			)
		);
	}

	/**
	 * Delete a document: chunks, stored file, row; then refresh the search
	 * index version and the suggestion dictionary.
	 *
	 * @param int $id Document id.
	 * @return bool False when the document does not exist or the row could not be deleted.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$doc = self::get( $id );
		if ( null === $doc ) {
			return false;
		}
		HDS_Vector_Store::delete_for_doc( $id );
		HDS_Storage::delete_file( (string) $doc['file_name'] );
		$deleted = $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		HDS_Vector_Store::bump_version();
		HDS_Suggest::rebuild();
		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Number of documents.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Number of documents per status (every known status present).
	 *
	 * @return array<string, int>
	 */
	public static function count_by_status(): array {
		global $wpdb;
		$out = array_fill_keys( self::statuses(), 0 );
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Titles of documents.
	 *
	 * @param int[] $ids Document ids.
	 * @return array<int, string> id => title (existing documents only).
	 */
	public static function titles_for( array $ids ): array {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( array() === $ids ) {
			return array();
		}
		$out   = array();
		$table = self::table();
		foreach ( array_chunk( $ids, 500 ) as $batch ) {
			$placeholders = implode( ', ', array_fill( 0, count( $batch ), '%d' ) );
			$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$table} WHERE id IN ({$placeholders})", $batch ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['id'] ] = (string) $row['title'];
			}
		}
		return $out;
	}

	/**
	 * Ids of all documents, oldest first (created_at, id).
	 *
	 * @param int $limit Maximum number of ids (0 = all).
	 * @return int[]
	 */
	public static function ids_in_order( int $limit = 0 ): array {
		global $wpdb;
		$table = self::table();
		if ( $limit > 0 ) {
			$col = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY created_at ASC, id ASC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$col = $wpdb->get_col( "SELECT id FROM {$table} ORDER BY created_at ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array_map( 'intval', (array) $col );
	}

	/**
	 * Titles of indexed documents, oldest first.
	 *
	 * @return string[]
	 */
	public static function indexed_titles(): array {
		global $wpdb;
		$table = self::table();
		$col   = $wpdb->get_col( $wpdb->prepare( "SELECT title FROM {$table} WHERE status = %s ORDER BY created_at ASC, id ASC", self::STATUS_INDEXED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'strval', (array) $col );
	}

	/**
	 * Mark every indexed (or half-indexed) document as pending, e.g. after
	 * the embedding model changed.
	 *
	 * @return int Number of documents changed.
	 */
	public static function reset_indexed(): int {
		global $wpdb;
		$table  = self::table();
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE status IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PENDING,
				current_time( 'mysql', true ),
				self::STATUS_INDEXED,
				self::STATUS_PROCESSING
			)
		);
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Positive unique int ids.
	 *
	 * @param array $ids Ids.
	 * @return int[]
	 */
	public static function ids( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[ (int) $id ] = (int) $id;
			}
		}
		return array_values( $out );
	}

	/**
	 * Cast numeric columns of a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function cast( array $row ): array {
		foreach ( self::INT_COLUMNS as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		return $row;
	}

	/**
	 * Cut a string to a number of characters.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Characters.
	 * @return string
	 */
	private static function clip( string $text, int $chars ): string {
		return mb_strlen( $text, 'UTF-8' ) > $chars ? mb_substr( $text, 0, $chars, 'UTF-8' ) : $text;
	}
}
