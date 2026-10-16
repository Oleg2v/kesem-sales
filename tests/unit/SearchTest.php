<?php
/**
 * Tests for HDS_Search: ranking, dimension filtering, demo filter and the
 * matrix cache (file and object cache).
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * In-memory vector store double (same methods as HDS_Vector_Store).
 */
class HDS_Search_Test_Store {

	/**
	 * Rows: [chunk_id, doc_id, binary embedding].
	 *
	 * @var array[]
	 */
	public $rows = array();

	/**
	 * each_embedding() calls.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Last $doc_ids argument.
	 *
	 * @var array|null|string
	 */
	public $last_doc_ids = 'none';

	/**
	 * Index version.
	 *
	 * @var string
	 */
	public $version = 'v1';

	/**
	 * Add a row.
	 *
	 * @param int          $chunk_id Chunk id.
	 * @param int          $doc_id   Doc id.
	 * @param float[]|string $vector Vector or raw binary.
	 * @return void
	 */
	public function add( int $chunk_id, int $doc_id, $vector ): void {
		$this->rows[] = array( $chunk_id, $doc_id, is_string( $vector ) ? $vector : HDS_Vector::pack( $vector ) );
	}

	/**
	 * @param callable   $fn      Callback.
	 * @param array|null $doc_ids Filter.
	 * @return void
	 */
	public function each_embedding( callable $fn, ?array $doc_ids = null ): void {
		++$this->calls;
		$this->last_doc_ids = $doc_ids;
		foreach ( $this->rows as $row ) {
			if ( null === $doc_ids || in_array( $row[1], $doc_ids, true ) ) {
				$fn( $row[0], $row[1], $row[2] );
			}
		}
	}

	/**
	 * @return string
	 */
	public function version(): string {
		return $this->version;
	}
}

/**
 * HDS_Search with the demo mode under test control.
 */
class HDS_Search_Test_Search extends HDS_Search {

	/**
	 * Demo mode.
	 *
	 * @var bool
	 */
	public $demo = false;

	/**
	 * Allowed documents in demo mode.
	 *
	 * @var int[]
	 */
	public $allowed = array();

	/**
	 * @return bool
	 */
	protected function is_demo(): bool {
		return $this->demo;
	}

	/**
	 * @return int[]
	 */
	protected function demo_doc_ids(): array {
		return $this->allowed;
	}
}

/**
 * HDS_Search tests.
 */
class SearchTest extends TestCase {

	protected function setUp(): void {
		hds_test_reset();
		$GLOBALS['hds_test']['upload_dir'] = sys_get_temp_dir() . '/hds-search-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	}

	protected function tearDown(): void {
		$this->rmdir( $GLOBALS['hds_test']['upload_dir'] );
	}

	private function rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->rmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	private function store(): HDS_Search_Test_Store {
		$store = new HDS_Search_Test_Store();
		$store->add( 101, 1, array( 1, 0, 0 ) );
		$store->add( 102, 1, array( 0.8, 0.6, 0 ) );
		$store->add( 103, 2, array( 0, 1, 0 ) );
		$store->add( 104, 3, array( 0, 0, 1 ) );
		$store->add( 105, 2, array( 0.6, 0.8, 0 ) );
		return $store;
	}

	private function search( $store, bool $demo = false, array $allowed = array() ): HDS_Search_Test_Search {
		$search          = new HDS_Search_Test_Search( $store );
		$search->demo    = $demo;
		$search->allowed = $allowed;
		return $search;
	}

	private function cache_files(): array {
		$files = glob( HDS_Storage::cache_dir() . 'vectors-*' );
		return is_array( $files ) ? $files : array();
	}

	public function test_ranking_returns_best_chunks_with_document_ids(): void {
		$search = $this->search( $this->store() );

		$hits = $search->search( array( 1, 0, 0 ), 3 );
		$this->assertSame( array( 101, 102, 105 ), array_column( $hits, 'chunk_id' ) );
		$this->assertSame( array( 1, 1, 2 ), array_column( $hits, 'doc_id' ) );
		$this->assertEqualsWithDelta( 1.0, $hits[0]['score'], 1e-6 );
		$this->assertEqualsWithDelta( 0.8, $hits[1]['score'], 1e-6 );
		$this->assertEqualsWithDelta( 0.6, $hits[2]['score'], 1e-6 );
		$this->assertIsFloat( $hits[0]['score'] );

		$hits = $search->search( array( 0, 2, 0 ), 2 );
		$this->assertSame( array( 103, 105 ), array_column( $hits, 'chunk_id' ) );

		$this->assertSame( array(), $search->search( array( 1, 0, 0 ), 0 ) );
		$this->assertSame( array(), $search->search( array(), 5 ) );
		$this->assertCount( 5, $search->search( array( 1, 1, 1 ), 50 ) );
	}

	public function test_rows_with_other_dimensions_are_filtered(): void {
		$store = $this->store();
		$store->add( 200, 4, array( 1, 0 ) );        // 2 dims.
		$store->add( 201, 4, "\x00\x00\x80\x3f\x00" ); // Not whole floats.
		$store->add( 202, 4, '' );                   // Empty.
		$search = $this->search( $store );

		$matrix = $search->get_matrix();
		$this->assertSame( 3, $matrix['dims'] );
		$this->assertSame( array( 101, 102, 103, 104, 105 ), $matrix['ids'] );
		$this->assertSame( array( 1, 1, 2, 3, 2 ), $matrix['docs'] );
		$this->assertSame( 5 * 3 * 4, strlen( $matrix['blob'] ) );

		$this->assertNotContains( 200, array_column( $search->search( array( 1, 0, 0 ), 10 ), 'chunk_id' ) );
		$this->assertSame( array(), $search->search( array( 1, 0 ), 10 ), 'A query of other dimensions matches nothing.' );
	}

	public function test_dimension_shared_by_most_rows_wins(): void {
		$store = new HDS_Search_Test_Store();
		$store->add( 1, 1, array( 1, 0, 0 ) );
		$store->add( 2, 1, array( 1, 0 ) );
		$store->add( 3, 2, array( 0, 1 ) );
		$matrix = $this->search( $store )->get_matrix();
		$this->assertSame( 2, $matrix['dims'] );
		$this->assertSame( array( 2, 3 ), $matrix['ids'] );
	}

	public function test_empty_store_gives_empty_matrix(): void {
		$search = $this->search( new HDS_Search_Test_Store() );
		$this->assertSame(
			array(
				'dims' => 0,
				'ids'  => array(),
				'docs' => array(),
				'blob' => '',
			),
			$search->get_matrix()
		);
		$this->assertSame( array(), $search->search( array( 1, 0, 0 ), 5 ) );
	}

	public function test_demo_mode_limits_documents(): void {
		$store  = $this->store();
		$search = $this->search( $store, true, array( 1, 3 ) );
		$hits   = $search->search( array( 1, 1, 1 ), 10 );
		$this->assertSame( array( 1, 3 ), $store->last_doc_ids );
		$this->assertEqualsCanonicalizing( array( 101, 102, 104 ), array_column( $hits, 'chunk_id' ) );

		// Demo and full mode are cached under different keys.
		$full = $this->search( $store );
		$this->assertNotSame( $search->cache_key(), $full->cache_key() );
		$this->assertCount( 5, $full->search( array( 1, 1, 1 ), 10 ) );
		$this->assertNull( $store->last_doc_ids );

		// No allowed documents: nothing to search, the store is not queried.
		$calls = $store->calls;
		$none  = $this->search( $store, true, array() );
		$none->get_matrix();
		$this->assertSame( $calls, $store->calls );
	}

	public function test_matrix_is_cached_in_a_file_and_reused(): void {
		$store  = $this->store();
		$search = $this->search( $store );
		$matrix = $search->get_matrix();
		$this->assertSame( 1, $store->calls );

		$file = HDS_Search::cache_file( $search->cache_key() );
		$this->assertFileExists( $file );
		$this->assertSame( array( $file ), $this->cache_files() );
		$this->assertStringStartsWith( HDS_Storage::cache_dir() . 'vectors-', $file );
		$this->assertStringEndsWith( '.bin', $file );
		$this->assertFileExists( HDS_Storage::cache_dir() . '.htaccess', 'The cache directory is protected.' );

		// Format: magic, uint32le header length, JSON header, blob.
		$raw    = file_get_contents( $file );
		$length = unpack( 'V', substr( $raw, 5, 4 ) )[1];
		$header = json_decode( substr( $raw, 9, $length ), true );
		$this->assertSame( 'HDSV1', substr( $raw, 0, 5 ) );
		$this->assertSame( 3, $header['dims'] );
		$this->assertSame( 5, $header['count'] );
		$this->assertSame( $matrix['ids'], $header['ids'] );
		$this->assertSame( $matrix['docs'], $header['docs'] );
		$this->assertSame( $matrix['blob'], substr( $raw, 9 + $length ) );

		// Same instance: memoized; new instance: read from the file.
		$search->get_matrix();
		$again = $this->search( $store );
		$this->assertSame( $matrix, $again->get_matrix() );
		$this->assertSame( 1, $store->calls );
		$this->assertSame(
			array_column( $search->search( array( 1, 0, 0 ), 3 ), 'chunk_id' ),
			array_column( $again->search( array( 1, 0, 0 ), 3 ), 'chunk_id' )
		);

		// A new index version is a new key: rebuilt, the stale file is removed.
		$store->version = 'v2';
		$this->search( $store )->get_matrix();
		$this->assertSame( 2, $store->calls );
		$this->assertFileDoesNotExist( $file );
		$this->assertCount( 1, $this->cache_files() );
	}

	/**
	 * @return array<string, array{callable}>
	 */
	public function corruptions(): array {
		return array(
			'truncated blob'      => array(
				static function ( string $raw ): string {
					return substr( $raw, 0, -3 );
				},
			),
			'extra bytes'         => array(
				static function ( string $raw ): string {
					return $raw . 'xxxx';
				},
			),
			'bad magic'           => array(
				static function ( string $raw ): string {
					return 'XXXX9' . substr( $raw, 5 );
				},
			),
			'header length'       => array(
				static function ( string $raw ): string {
					return substr( $raw, 0, 5 ) . pack( 'V', 0x7fffffff ) . substr( $raw, 9 );
				},
			),
			'count mismatch'      => array(
				static function ( string $raw ): string {
					return str_replace( '"count":5', '"count":4', $raw );
				},
			),
			'not json'            => array(
				static function ( string $raw ): string {
					$length = unpack( 'V', substr( $raw, 5, 4 ) )[1];
					return substr( $raw, 0, 9 ) . str_repeat( '{', $length ) . substr( $raw, 9 + $length );
				},
			),
			'empty file'          => array(
				static function ( string $raw ): string {
					return '';
				},
			),
			'ids/docs mismatch'   => array(
				static function ( string $raw ): string {
					return str_replace( '"docs":[1,1,2,3,2]', '"docs":[1,1,2,3]', $raw );
				},
			),
		);
	}

	/**
	 * @dataProvider corruptions
	 *
	 * @param callable $corrupt Corrupts the file content.
	 */
	public function test_corrupt_cache_file_is_rebuilt( callable $corrupt ): void {
		$store  = $this->store();
		$search = $this->search( $store );
		$matrix = $search->get_matrix();
		$file   = HDS_Search::cache_file( $search->cache_key() );
		$good   = file_get_contents( $file );
		file_put_contents( $file, $corrupt( $good ) );

		$fresh = $this->search( $store );
		$this->assertSame( $matrix, $fresh->get_matrix() );
		$this->assertSame( 2, $store->calls, 'Rebuilt from the store.' );
		$this->assertSame( $good, file_get_contents( $file ), 'The cache file was rewritten.' );
	}

	public function test_object_cache_is_used_with_a_persistent_cache(): void {
		$GLOBALS['hds_test']['ext_cache'] = true;
		$store                            = $this->store();
		$search                           = $this->search( $store );
		$matrix                           = $search->get_matrix();

		$this->assertSame( $matrix, $GLOBALS['hds_test']['cache'][ 'hds:' . $search->cache_key() ] );
		$this->assertSame( array(), $this->cache_files(), 'No file when the object cache took the value.' );

		$this->assertSame( $matrix, $this->search( $store )->get_matrix() );
		$this->assertSame( 1, $store->calls );

		// An invalid cached value is ignored.
		$GLOBALS['hds_test']['cache'][ 'hds:' . $search->cache_key() ] = array( 'dims' => 3 );
		$this->assertSame( $matrix, $this->search( $store )->get_matrix() );
		$this->assertSame( 2, $store->calls );
	}

	public function test_flush_cache_deletes_files_and_object_cache_entries(): void {
		$store          = $this->store();
		$store->version = HDS_Vector_Store::version();
		$search         = new HDS_Search( $store );
		$search->get_matrix();
		$this->assertCount( 1, $this->cache_files() );
		touch( HDS_Storage::cache_dir() . 'vectors-tmp-abc123' );
		file_put_contents( HDS_Storage::cache_dir() . 'keep.txt', 'x' );

		$GLOBALS['hds_test']['ext_cache'] = true;
		$key                              = $search->cache_key();
		wp_cache_set( $key, array( 'x' ), 'hds' );

		HDS_Search::flush_cache();
		$this->assertSame( array(), $this->cache_files() );
		$this->assertFileExists( HDS_Storage::cache_dir() . 'keep.txt' );
		$this->assertFileExists( HDS_Storage::cache_dir() . 'index.php' );
		$this->assertArrayNotHasKey( 'hds:' . $key, $GLOBALS['hds_test']['cache'] );

		// Without a cache directory it does nothing.
		$this->rmdir( $GLOBALS['hds_test']['upload_dir'] );
		HDS_Search::flush_cache();
		$this->assertDirectoryDoesNotExist( HDS_Storage::cache_dir() );
	}

	public function test_bump_version_flushes_the_cache(): void {
		$search = new HDS_Search( $this->store() );
		$search->get_matrix();
		$this->assertCount( 1, $this->cache_files() );
		HDS_Vector_Store::bump_version();
		$this->assertSame( array(), $this->cache_files() );
	}

	public function test_cache_file_names_are_safe(): void {
		$this->assertSame( '', HDS_Search::cache_file( '../etc/passwd' ) );
		$this->assertSame( HDS_Storage::cache_dir() . 'vectors-hds_vectors_abc.bin', HDS_Search::cache_file( 'hds_vectors_abc' ) );
		$this->assertMatchesRegularExpression( '/^hds_vectors_[0-9a-f]{32}$/', ( new HDS_Search( $this->store() ) )->cache_key() );
	}

	public function test_real_store_with_demo_limit(): void {
		if ( ! function_exists( 'hds_test_create_tables' ) ) {
			$this->markTestSkipped( 'The fake $wpdb (tests/stubs/20-data.php) is not available.' );
		}
		hds_test_create_tables();
		$this->assertTrue( HDS_License::is_demo(), 'Unit tests run without a public key.' );

		$ids = array();
		for ( $i = 1; $i <= HDS_License::DEMO_DOC_LIMIT + 1; $i++ ) {
			$ids[ $i ] = HDS_Documents::insert(
				array(
					'file_name'     => "doc-{$i}.txt",
					'original_name' => "doc-{$i}.txt",
					'ext'           => 'txt',
					'file_size'     => 10,
					'file_hash'     => str_repeat( 'a', 64 ),
					'title'         => "Doc {$i}",
				)
			);
			HDS_Documents::set_status( $ids[ $i ], HDS_Documents::STATUS_INDEXED );
		}
		$first = $ids[1];
		$last  = $ids[ HDS_License::DEMO_DOC_LIMIT + 1 ];
		HDS_Vector_Store::replace_chunks( $first, array( 'טקסט ראשון', 'טקסט שני' ) );
		HDS_Vector_Store::replace_chunks( $last, array( 'טקסט נעול' ) );
		$chunks = array();
		foreach ( array( $first, $last ) as $doc ) {
			foreach ( HDS_Vector_Store::pending_chunks( $doc, 10 ) as $chunk ) {
				$chunks[ $chunk['id'] ] = $doc;
				HDS_Vector_Store::save_embedding( $chunk['id'], array( 1, 0, 0.5 ) );
			}
		}

		$search = new HDS_Search();
		$hits   = $search->search( array( 1, 0, 0.5 ), 10 );
		$this->assertCount( 2, $hits );
		foreach ( $hits as $hit ) {
			$this->assertSame( $first, $hit['doc_id'], 'Documents beyond the demo limit are not searched.' );
		}

		$loaded = $search->get_chunks( array_column( $hits, 'chunk_id' ) );
		$this->assertSame( 'Doc 1', $loaded[ $hits[0]['chunk_id'] ]['title'] );
	}
}
