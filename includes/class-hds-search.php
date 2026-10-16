<?php
/**
 * Vector search over the stored chunk embeddings.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search.
 *
 * All embeddings of indexed documents are kept as one "matrix": a binary
 * blob of concatenated little-endian float32 rows plus the chunk id and
 * document id of every row. The matrix is built by streaming
 * HDS_Vector_Store::each_embedding() and cached under a key derived from the
 * index version (which changes on every index change) and the demo flag:
 *
 * - in the object cache (group 'hds') when a persistent object cache is used;
 * - otherwise in a binary file in HDS_Storage::cache_dir():
 *   'HDSV1' . uint32le( header length ) . JSON header {dims, count, ids, docs} . blob,
 *   written atomically (temporary file in the same directory + rename) and
 *   validated when read (a corrupt file is rebuilt).
 *
 * Ranking is cosine similarity via HDS_Vector::top_n(), which streams the
 * blob one row at a time.
 */
class HDS_Search {

	/**
	 * Object cache group.
	 */
	const CACHE_GROUP = 'hds';

	/**
	 * Cache key prefix.
	 */
	const CACHE_PREFIX = 'hds_vectors_';

	/**
	 * Object cache lifetime (entries are also keyed by the index version).
	 */
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Cache file magic bytes (format version 1).
	 */
	const FILE_MAGIC = 'HDSV1';

	/**
	 * Cache file name prefix (files: vectors-<key>.bin).
	 */
	const FILE_PREFIX = 'vectors-';

	/**
	 * Longest accepted JSON header of a cache file (ids and docs of every row).
	 */
	const MAX_HEADER_BYTES = 67108864;

	/**
	 * Vector store: HDS_Vector_Store or any object with the same methods.
	 *
	 * @var object|null
	 */
	private $store;

	/**
	 * Matrix loaded by this instance and its cache key.
	 *
	 * @var array{key: string, matrix: array|null}
	 */
	private $memo = array(
		'key'    => '',
		'matrix' => null,
	);

	/**
	 * Constructor.
	 *
	 * @param object|null $store Vector store (null = HDS_Vector_Store). Needs
	 *                           each_embedding( callable $fn, ?array $doc_ids ),
	 *                           optionally get_chunks( array $ids ) and version().
	 */
	public function __construct( $store = null ) {
		$this->store = is_object( $store ) ? $store : null;
	}

	/**
	 * Most similar chunks to a query vector.
	 *
	 * Only rows with as many dimensions as the query take part; in demo mode
	 * only rows of the documents allowed by HDS_Indexer::demo_allowed_ids().
	 *
	 * @param float[] $query_vector Query embedding.
	 * @param int     $top_n        Number of chunks wanted.
	 * @return array<int, array{chunk_id: int, doc_id: int, score: float}> Sorted by score desc.
	 */
	public function search( array $query_vector, int $top_n ): array {
		$dims = count( $query_vector );
		if ( $top_n < 1 || 0 === $dims ) {
			return array();
		}
		$matrix = $this->get_matrix();
		if ( $matrix['dims'] !== $dims || 0 === count( $matrix['ids'] ) ) {
			return array();
		}

		$rows = HDS_Vector::top_n( array_values( $query_vector ), $matrix['blob'], $dims, $top_n );
		$out  = array();
		foreach ( $rows as $row ) {
			$index = (int) $row['row_index'];
			if ( ! isset( $matrix['ids'][ $index ], $matrix['docs'][ $index ] ) ) {
				continue;
			}
			$out[] = array(
				'chunk_id' => (int) $matrix['ids'][ $index ],
				'doc_id'   => (int) $matrix['docs'][ $index ],
				'score'    => (float) $row['score'],
			);
		}
		return $out;
	}

	/**
	 * Chunks by id (with document titles), from the vector store.
	 *
	 * @param int[] $chunk_ids Chunk ids.
	 * @return array<int, array> id => {id, doc_id, chunk_index, text, title}.
	 */
	public function get_chunks( array $chunk_ids ): array {
		$store = $this->store();
		if ( ! method_exists( $store, 'get_chunks' ) ) {
			return array();
		}
		$chunks = $store->get_chunks( $chunk_ids );
		return is_array( $chunks ) ? $chunks : array();
	}

	/**
	 * The embedding matrix (cached).
	 *
	 * @return array{dims: int, ids: int[], docs: int[], blob: string}
	 */
	public function get_matrix(): array {
		$key = $this->cache_key();
		if ( $key === $this->memo['key'] && is_array( $this->memo['matrix'] ) ) {
			return $this->memo['matrix'];
		}

		$matrix = null;
		$ext    = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		if ( $ext ) {
			$found  = false;
			$cached = wp_cache_get( $key, self::CACHE_GROUP, false, $found );
			if ( $found && self::is_valid_matrix( $cached ) ) {
				$matrix = $cached;
			}
		}
		if ( null === $matrix ) {
			// Also the fallback when the object cache refused the value (size limits).
			$matrix = $this->read_file( $key );
		}
		if ( null === $matrix ) {
			$matrix = $this->build_matrix();
			$stored = false;
			if ( $ext ) {
				$stored = (bool) wp_cache_set( $key, $matrix, self::CACHE_GROUP, self::CACHE_TTL );
			}
			if ( ! $stored ) {
				$this->write_file( $key, $matrix );
			}
		}

		$this->memo = array(
			'key'    => $key,
			'matrix' => $matrix,
		);
		return $matrix;
	}

	/**
	 * Drop every cached matrix: cache files and object cache entries.
	 *
	 * Called by HDS_Vector_Store::bump_version() (after the version changed).
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}
		$version = HDS_Vector_Store::version();
		foreach ( array( true, false ) as $demo ) {
			wp_cache_delete( self::key_for( $version, $demo ), self::CACHE_GROUP );
		}

		$dir = self::cache_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( $dir . self::FILE_PREFIX . '*' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best effort; a vanished file is fine.
			}
		}
	}

	/**
	 * Cache key of the current matrix.
	 *
	 * @return string
	 */
	public function cache_key(): string {
		$store   = $this->store();
		$version = method_exists( $store, 'version' ) ? (string) $store->version() : HDS_Vector_Store::version();
		return self::key_for( $version, $this->is_demo() );
	}

	/**
	 * Path of the cache file for a key.
	 *
	 * @param string $key Cache key.
	 * @return string '' when the storage directory is unknown.
	 */
	public static function cache_file( string $key ): string {
		$dir = self::cache_dir();
		if ( '' === $dir || ! preg_match( '/^[a-z0-9_]+$/', $key ) ) {
			return '';
		}
		return $dir . self::FILE_PREFIX . $key . '.bin';
	}

	/**
	 * Cache key for an index version and mode.
	 *
	 * @param string $version Index version.
	 * @param bool   $demo    Demo mode.
	 * @return string
	 */
	protected static function key_for( string $version, bool $demo ): string {
		return self::CACHE_PREFIX . md5( $version . '|' . ( $demo ? 'demo' : 'full' ) );
	}

	/**
	 * Whether the plugin runs in demo mode (seam for tests).
	 *
	 * @return bool
	 */
	protected function is_demo(): bool {
		return HDS_License::is_demo();
	}

	/**
	 * Documents searchable in demo mode (seam for tests).
	 *
	 * @return int[]
	 */
	protected function demo_doc_ids(): array {
		return HDS_Indexer::demo_allowed_ids( true );
	}

	/**
	 * The vector store.
	 *
	 * @return object
	 */
	private function store() {
		if ( null === $this->store ) {
			$this->store = new HDS_Vector_Store();
		}
		return $this->store;
	}

	/**
	 * Build the matrix from the store.
	 *
	 * Rows whose size is not a whole number of float32 values are skipped.
	 * When rows of different sizes exist (embedding model changed while
	 * documents were being re-indexed), the size shared by most rows wins.
	 *
	 * @return array{dims: int, ids: int[], docs: int[], blob: string}
	 */
	private function build_matrix(): array {
		$doc_ids = null;
		if ( $this->is_demo() ) {
			$doc_ids = array_values( array_map( 'intval', $this->demo_doc_ids() ) );
			if ( array() === $doc_ids ) {
				return self::empty_matrix();
			}
		}

		$groups = array();
		$this->store()->each_embedding(
			static function ( $chunk_id, $doc_id, $embedding ) use ( &$groups ) {
				$bytes = strlen( (string) $embedding );
				if ( 0 === $bytes || 0 !== $bytes % HDS_Vector::FLOAT_BYTES ) {
					return;
				}
				$dims = intdiv( $bytes, HDS_Vector::FLOAT_BYTES );
				if ( ! isset( $groups[ $dims ] ) ) {
					$groups[ $dims ] = array(
						'ids'  => array(),
						'docs' => array(),
						'blob' => '',
					);
				}
				$groups[ $dims ]['ids'][]  = (int) $chunk_id;
				$groups[ $dims ]['docs'][] = (int) $doc_id;
				$groups[ $dims ]['blob']  .= $embedding;
			},
			$doc_ids
		);

		$best = 0;
		foreach ( $groups as $dims => $group ) {
			if ( 0 === $best || count( $group['ids'] ) > count( $groups[ $best ]['ids'] ) ) {
				$best = $dims;
			}
		}
		if ( 0 === $best ) {
			return self::empty_matrix();
		}
		$matrix = array(
			'dims' => (int) $best,
			'ids'  => $groups[ $best ]['ids'],
			'docs' => $groups[ $best ]['docs'],
			'blob' => $groups[ $best ]['blob'],
		);
		unset( $groups );
		return $matrix;
	}

	/**
	 * Matrix without rows.
	 *
	 * @return array{dims: int, ids: int[], docs: int[], blob: string}
	 */
	private static function empty_matrix(): array {
		return array(
			'dims' => 0,
			'ids'  => array(),
			'docs' => array(),
			'blob' => '',
		);
	}

	/**
	 * Whether a value is a consistent matrix.
	 *
	 * @param mixed $matrix Value.
	 * @return bool
	 */
	private static function is_valid_matrix( $matrix ): bool {
		if ( ! is_array( $matrix ) || ! isset( $matrix['dims'], $matrix['ids'], $matrix['docs'], $matrix['blob'] ) ) {
			return false;
		}
		if ( ! is_int( $matrix['dims'] ) || ! is_array( $matrix['ids'] ) || ! is_array( $matrix['docs'] ) || ! is_string( $matrix['blob'] ) ) {
			return false;
		}
		$count = count( $matrix['ids'] );
		if ( count( $matrix['docs'] ) !== $count || $matrix['dims'] < 0 || ( $count > 0 && $matrix['dims'] < 1 ) ) {
			return false;
		}
		return strlen( $matrix['blob'] ) === $count * $matrix['dims'] * HDS_Vector::FLOAT_BYTES;
	}

	/**
	 * The cache directory ('' when unknown).
	 *
	 * @return string
	 */
	private static function cache_dir(): string {
		try {
			return (string) HDS_Storage::cache_dir();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Read and validate a cache file.
	 *
	 * @param string $key Cache key.
	 * @return array|null Matrix, or null when missing or invalid (an invalid file is deleted).
	 */
	private function read_file( string $key ) {
		$file = self::cache_file( $key );
		if ( '' === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}
		$matrix = self::parse_file( $file );
		if ( null === $matrix ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Corrupt cache file; rebuilt next.
		}
		return $matrix;
	}

	/**
	 * Parse a cache file.
	 *
	 * @param string $file Path.
	 * @return array|null
	 */
	private static function parse_file( string $file ) {
		$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$head = strlen( self::FILE_MAGIC ) + 4;
		if ( false === $size || $size < $head ) {
			return null;
		}
		$fh = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return null;
		}
		try {
			$prefix = self::read_exactly( $fh, $head );
			if ( null === $prefix || self::FILE_MAGIC !== substr( $prefix, 0, strlen( self::FILE_MAGIC ) ) ) {
				return null;
			}
			$length = unpack( 'V', substr( $prefix, strlen( self::FILE_MAGIC ) ) );
			$length = is_array( $length ) ? (int) $length[1] : 0;
			if ( $length < 2 || $length > self::MAX_HEADER_BYTES || $head + $length > $size ) {
				return null;
			}
			$json   = self::read_exactly( $fh, $length );
			$header = null === $json ? null : json_decode( $json, true );
			unset( $json );
			if ( ! is_array( $header ) || ! isset( $header['dims'], $header['count'], $header['ids'], $header['docs'] )
				|| ! is_int( $header['dims'] ) || ! is_int( $header['count'] ) || ! is_array( $header['ids'] ) || ! is_array( $header['docs'] ) ) {
				return null;
			}
			$count     = $header['count'];
			$dims      = $header['dims'];
			$blob_size = $count * $dims * HDS_Vector::FLOAT_BYTES;
			if ( $count < 0 || $dims < 0 || count( $header['ids'] ) !== $count || count( $header['docs'] ) !== $count
				|| $head + $length + $blob_size !== $size ) {
				return null;
			}
			$blob = 0 === $blob_size ? '' : self::read_exactly( $fh, $blob_size );
			if ( null === $blob ) {
				return null;
			}
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$matrix = array(
			'dims' => $dims,
			'ids'  => array_map( 'intval', array_values( $header['ids'] ) ),
			'docs' => array_map( 'intval', array_values( $header['docs'] ) ),
			'blob' => $blob,
		);
		return self::is_valid_matrix( $matrix ) ? $matrix : null;
	}

	/**
	 * Read exactly $length bytes.
	 *
	 * @param resource $fh     File handle.
	 * @param int      $length Bytes.
	 * @return string|null Null when the file is shorter.
	 */
	private static function read_exactly( $fh, int $length ) {
		$data = stream_get_contents( $fh, $length );
		return ( is_string( $data ) && strlen( $data ) === $length ) ? $data : null;
	}

	/**
	 * Write a cache file atomically (temporary file in the same directory,
	 * then rename). Failures are silent: the matrix is simply rebuilt later.
	 *
	 * @param string $key    Cache key.
	 * @param array  $matrix Matrix.
	 * @return bool
	 */
	private function write_file( string $key, array $matrix ): bool {
		$file = self::cache_file( $key );
		if ( '' === $file ) {
			return false;
		}
		$dir = dirname( $file );
		try {
			// Creates the directories and their deny-all files when missing.
			HDS_Storage::ensure_protected();
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		$header = wp_json_encode(
			array(
				'dims'  => (int) $matrix['dims'],
				'count' => count( $matrix['ids'] ),
				'ids'   => array_values( $matrix['ids'] ),
				'docs'  => array_values( $matrix['docs'] ),
			)
		);
		if ( ! is_string( $header ) ) {
			return false;
		}

		$tmp = @tempnam( $dir, self::FILE_PREFIX . 'tmp-' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $tmp ) {
			return false;
		}
		// tempnam() falls back to the system temp dir: a rename from there is not atomic.
		if ( realpath( dirname( $tmp ) ) !== realpath( $dir ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}

		$ok = false;
		$fh = @fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false !== $fh ) {
			$prefix = self::FILE_MAGIC . pack( 'V', strlen( $header ) ) . $header;
			$ok     = self::write_all( $fh, $prefix ) && self::write_all( $fh, $matrix['blob'] );
			$ok     = fclose( $fh ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $ok ) {
			@chmod( $tmp, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			$ok = @rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! $ok && is_file( $file ) ) {
				// Windows does not replace an existing file on rename.
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				$ok = @rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			}
		}
		if ( ! $ok ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}
		self::delete_stale_files( $file );
		return true;
	}

	/**
	 * Write a whole string to a handle.
	 *
	 * @param resource $fh   Handle.
	 * @param string   $data Data.
	 * @return bool
	 */
	private static function write_all( $fh, string $data ): bool {
		$length  = strlen( $data );
		$written = 0;
		while ( $written < $length ) {
			$n = fwrite( $fh, 0 === $written ? $data : substr( $data, $written ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $n || 0 === $n ) {
				return false;
			}
			$written += $n;
		}
		return true;
	}

	/**
	 * Delete cache files of other keys (older index versions, the other mode).
	 * Temporary files are left alone: another request may be writing one.
	 *
	 * @param string $keep Path of the current cache file.
	 * @return void
	 */
	private static function delete_stale_files( string $keep ): void {
		$files = glob( dirname( $keep ) . '/' . self::FILE_PREFIX . '*.bin' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( basename( $file ) !== basename( $keep ) && is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
}
