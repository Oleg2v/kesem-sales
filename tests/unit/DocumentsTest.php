<?php
/**
 * Tests for HDS_Documents and HDS_Vector_Store.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Repository tests (fake $wpdb on SQLite).
 */
class DocumentsTest extends TestCase {

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
	}

	/**
	 * Insert a document.
	 *
	 * @param string $title Title.
	 * @param array  $extra Extra data.
	 * @return int
	 */
	private function add( string $title, array $extra = array() ): int {
		return HDS_Documents::insert(
			array_merge(
				array(
					'file_name'     => 'doc-' . md5( $title ) . '.txt',
					'original_name' => $title . '.txt',
					'ext'           => 'txt',
					'file_size'     => 123,
					'file_hash'     => hash( 'sha256', $title ),
					'title'         => $title,
				),
				$extra
			)
		);
	}

	/**
	 * A deterministic vector.
	 *
	 * @param int $seed Seed.
	 * @param int $dims Dimensions.
	 * @return float[]
	 */
	private function vector( int $seed, int $dims = 1536 ): array {
		$v = array();
		for ( $i = 0; $i < $dims; $i++ ) {
			$v[] = sin( $seed * 31 + $i ) / 3;
		}
		return $v;
	}

	public function test_tables(): void {
		$this->assertSame( 'wp_hds_docs', HDS_Documents::table() );
		$this->assertSame( 'wp_hds_chunks', HDS_Vector_Store::table() );
	}

	public function test_insert_and_get(): void {
		hds_test_set_time( 1700000000 );
		$id = $this->add( 'פסק דין "מיוחד" O\'Brien \\ 100%' );
		$this->assertGreaterThan( 0, $id );

		$doc = HDS_Documents::get( $id );
		$this->assertSame( $id, $doc['id'] );
		$this->assertSame( 'פסק דין "מיוחד" O\'Brien \\ 100%', $doc['title'] );
		$this->assertSame( 'pending', $doc['status'] );
		$this->assertSame( 123, $doc['file_size'] );
		$this->assertSame( 0, $doc['chunk_count'] );
		$this->assertSame( 0, $doc['char_count'] );
		$this->assertSame( '', $doc['index_hash'] );
		$this->assertNull( $doc['indexed_at'] );
		$this->assertNull( $doc['status_message'] );
		$this->assertSame( '2023-11-14 22:13:20', $doc['created_at'] );
		$this->assertSame( $doc['created_at'], $doc['updated_at'] );

		$this->assertNull( HDS_Documents::get( 9999 ) );
		$this->assertNull( HDS_Documents::get( 0 ) );
	}

	public function test_all_order_count_and_titles(): void {
		hds_test_set_time( 1700000100 );
		$b = $this->add( 'B' );
		hds_test_set_time( 1700000000 );
		$a = $this->add( 'A' );
		hds_test_set_time( 1700000100 );
		$c = $this->add( 'C' );

		$this->assertSame( array( $a, $b, $c ), array_column( HDS_Documents::all(), 'id' ) );
		$this->assertSame( array( $a, $b, $c ), HDS_Documents::ids_in_order() );
		$this->assertSame( array( $a, $b ), HDS_Documents::ids_in_order( 2 ) );
		$this->assertSame( 3, HDS_Documents::count() );
		$this->assertSame(
			array(
				$a => 'A',
				$c => 'C',
			),
			HDS_Documents::titles_for( array( $c, (string) $a, 0, -1, 'x', 999 ) )
		);
		$this->assertSame( array(), HDS_Documents::titles_for( array() ) );

		$by_status = HDS_Documents::count_by_status();
		$this->assertSame( 3, $by_status['pending'] );
		$this->assertSame( 0, $by_status['indexed'] );
		$this->assertSame( HDS_Documents::statuses(), array_keys( $by_status ) );
	}

	public function test_instance_calls_work_too(): void {
		$docs = new HDS_Documents();
		$id   = $docs->insert( array( 'title' => 'x', 'file_name' => 'x-12345678.txt', 'original_name' => 'x.txt', 'ext' => 'txt' ) );
		$this->assertSame( 'x', $docs->get( $id )['title'] );
	}

	public function test_find_by_hash(): void {
		$id = $this->add( 'A' );
		$this->assertSame( $id, HDS_Documents::find_by_hash( hash( 'sha256', 'A' ) )['id'] );
		$this->assertSame( $id, HDS_Documents::find_by_hash( strtoupper( hash( 'sha256', 'A' ) ) )['id'] );
		$this->assertNull( HDS_Documents::find_by_hash( hash( 'sha256', 'B' ) ) );
		$this->assertNull( HDS_Documents::find_by_hash( "' OR 1=1 --" ) );
	}

	public function test_update_whitelist_and_status(): void {
		hds_test_set_time( 1700000000 );
		$id = $this->add( 'A' );
		hds_test_set_time( 1700000500 );
		$this->assertTrue(
			HDS_Documents::update(
				$id,
				array(
					'title'       => 'New "title"',
					'chunk_count' => '7',
					'char_count'  => 5000,
					'file_name'   => '../../evil.php',
					'created_at'  => '2000-01-01 00:00:00',
					'indexed_at'  => '2023-11-14 22:21:40',
				)
			)
		);
		$doc = HDS_Documents::get( $id );
		$this->assertSame( 'New "title"', $doc['title'] );
		$this->assertSame( 7, $doc['chunk_count'] );
		$this->assertSame( 5000, $doc['char_count'] );
		$this->assertSame( 'doc-' . md5( 'A' ) . '.txt', $doc['file_name'], 'Not updatable.' );
		$this->assertSame( '2023-11-14 22:13:20', $doc['created_at'], 'Not updatable.' );
		$this->assertSame( '2023-11-14 22:21:40', $doc['updated_at'] );
		$this->assertSame( '2023-11-14 22:21:40', $doc['indexed_at'] );

		$this->assertFalse( HDS_Documents::update( $id, array( 'status' => 'hacked' ) ) );
		$this->assertSame( 'pending', HDS_Documents::get( $id )['status'] );

		$this->assertTrue( HDS_Documents::set_status( $id, HDS_Documents::STATUS_ERROR, 'The file is damaged.' ) );
		$doc = HDS_Documents::get( $id );
		$this->assertSame( 'error', $doc['status'] );
		$this->assertSame( 'The file is damaged.', $doc['status_message'] );

		$this->assertTrue( HDS_Documents::set_status( $id, HDS_Documents::STATUS_INDEXED ) );
		$this->assertNull( HDS_Documents::get( $id )['status_message'] );
		$this->assertFalse( HDS_Documents::update( 0, array( 'title' => 'x' ) ) );
	}

	public function test_reset_indexed(): void {
		$a = $this->add( 'A' );
		$b = $this->add( 'B' );
		$c = $this->add( 'C' );
		HDS_Documents::set_status( $a, HDS_Documents::STATUS_INDEXED );
		HDS_Documents::set_status( $b, HDS_Documents::STATUS_SCANNED );
		HDS_Documents::set_status( $c, HDS_Documents::STATUS_PROCESSING );
		$this->assertSame( 2, HDS_Documents::reset_indexed() );
		$this->assertSame( 'pending', HDS_Documents::get( $a )['status'] );
		$this->assertSame( 'scanned', HDS_Documents::get( $b )['status'] );
		$this->assertSame( 'pending', HDS_Documents::get( $c )['status'] );
	}

	public function test_delete(): void {
		HDS_Storage::ensure_protected();
		$id    = $this->add( 'A', array( 'file_name' => 'a-12345678.txt' ) );
		$other = $this->add( 'B', array( 'file_name' => 'b-12345678.txt' ) );
		file_put_contents( HDS_Storage::dir() . 'a-12345678.txt', 'x' );
		file_put_contents( HDS_Storage::dir() . 'b-12345678.txt', 'y' );
		$store = new HDS_Vector_Store();
		$store->replace_chunks( $id, array( 'one', 'two' ) );
		$store->replace_chunks( $other, array( 'three' ) );
		update_option( HDS_Suggest::OPTION, array( 'version' => 'old', 'titles' => array( 'a' ), 'phrases' => array() ) );
		$version = HDS_Vector_Store::version();

		$this->assertTrue( HDS_Documents::delete( $id ) );
		$this->assertNull( HDS_Documents::get( $id ) );
		$this->assertSame( 0, $store->count_for_doc( $id ) );
		$this->assertSame( 1, $store->count_for_doc( $other ) );
		$this->assertFileDoesNotExist( HDS_Storage::dir() . 'a-12345678.txt' );
		$this->assertFileExists( HDS_Storage::dir() . 'b-12345678.txt' );
		$this->assertNotSame( $version, HDS_Vector_Store::version() );
		$this->assertNotSame( 'old', HDS_Suggest::get()['version'], 'Suggestions rebuilt.' );

		$this->assertFalse( HDS_Documents::delete( $id ) );
	}

	public function test_replace_and_pending_chunks(): void {
		$store = new HDS_Vector_Store();
		$id    = $this->add( 'A' );
		$this->assertSame( 3, $store->replace_chunks( $id, array( 'אחד', '  ', 'שתיים', "שלוש 'quoted' \\ %s %d" ) ) );
		$this->assertSame( 3, $store->count_for_doc( $id ) );
		$this->assertSame( 3, $store->count_pending( $id ) );

		$pending = $store->pending_chunks( $id, 2 );
		$this->assertSame( array( 'אחד', 'שתיים' ), array_column( $pending, 'text' ) );
		$this->assertIsInt( $pending[0]['id'] );

		$store->save_embedding( $pending[0]['id'], $this->vector( 1 ) );
		$this->assertSame( 2, $store->count_pending( $id ) );
		$this->assertSame( array( 'שתיים', "שלוש 'quoted' \\ %s %d" ), array_column( $store->pending_chunks( $id, 10 ), 'text' ) );

		// Replacing removes old chunks and embeddings.
		$this->assertSame( 2, $store->replace_chunks( $id, array( 'new 1', 'new 2' ) ) );
		$this->assertSame( 2, $store->count_for_doc( $id ) );
		$this->assertSame( 2, $store->count_pending( $id ) );
		$this->assertSame( 0, $store->replace_chunks( $id, array() ) );
		$this->assertSame( 0, $store->count_for_doc( $id ) );
	}

	public function test_replace_many_chunks_in_batches(): void {
		$store = new HDS_Vector_Store();
		$id    = $this->add( 'A' );
		$texts = array();
		for ( $i = 0; $i < 237; $i++ ) {
			$texts[] = 'chunk ' . $i;
		}
		$this->assertSame( 237, $store->replace_chunks( $id, $texts ) );
		$rows = $this->db->get_col( 'SELECT chunk_index FROM wp_hds_chunks ORDER BY chunk_index' );
		$this->assertSame( range( 0, 236 ), array_map( 'intval', $rows ) );
	}

	public function test_embeddings_are_float32_blobs(): void {
		$store = new HDS_Vector_Store();
		$id    = $this->add( 'A' );
		$store->replace_chunks( $id, array( 'one', 'two' ) );
		$chunks = $store->pending_chunks( $id, 10 );
		$vector = $this->vector( 3 );
		$store->save_embedding( $chunks[0]['id'], $vector );

		$blob = $this->db->get_var( $this->db->prepare( 'SELECT embedding FROM wp_hds_chunks WHERE id = %d', $chunks[0]['id'] ) );
		$this->assertSame( 6144, strlen( $blob ) );
		$this->assertSame( 'blob', $this->db->get_var( 'SELECT typeof(embedding) FROM wp_hds_chunks WHERE id = ' . (int) $chunks[0]['id'] ) );
		$back = HDS_Vector::unpack( $blob );
		$this->assertCount( 1536, $back );
		$this->assertEqualsWithDelta( $vector[100], $back[100], 1e-6 );
		$this->assertNull( $this->db->get_var( 'SELECT embedding FROM wp_hds_chunks WHERE id = ' . (int) $chunks[1]['id'] ) );

		$store->save_embedding( $chunks[1]['id'], array() );
		$this->assertSame( 1, $store->count_pending( $id ), 'Empty vectors are ignored.' );
	}

	public function test_each_embedding_streams_indexed_documents_only(): void {
		$store   = new HDS_Vector_Store();
		$indexed = $this->add( 'A' );
		$pending = $this->add( 'B' );
		$second  = $this->add( 'C' );
		$texts   = array();
		for ( $i = 0; $i < 1201; $i++ ) {
			$texts[] = 'text ' . $i;
		}
		$store->replace_chunks( $indexed, $texts );
		$store->replace_chunks( $pending, array( 'p1', 'p2' ) );
		$store->replace_chunks( $second, array( 's1', 's2', 's3' ) );
		foreach ( array( $indexed, $pending, $second ) as $doc_id ) {
			foreach ( $store->pending_chunks( $doc_id, 2000 ) as $n => $chunk ) {
				if ( $doc_id === $second && 2 === $n ) {
					continue; // Not embedded yet.
				}
				$store->save_embedding( $chunk['id'], $this->vector( $chunk['id'], 4 ) );
			}
		}
		HDS_Documents::set_status( $indexed, HDS_Documents::STATUS_INDEXED );
		HDS_Documents::set_status( $second, HDS_Documents::STATUS_INDEXED );

		$seen    = array();
		$last_id = 0;
		$queries = count( $this->db->queries );
		$store->each_embedding(
			function ( int $chunk_id, int $doc_id, string $bin ) use ( &$seen, &$last_id ) {
				$this->assertGreaterThan( $last_id, $chunk_id, 'Ordered by id.' );
				$last_id         = $chunk_id;
				$seen[ $doc_id ] = isset( $seen[ $doc_id ] ) ? $seen[ $doc_id ] + 1 : 1;
				$this->assertSame( 16, strlen( $bin ) );
			}
		);
		$this->assertSame(
			array(
				$indexed => 1201,
				$second  => 2,
			),
			$seen
		);
		$this->assertSame( 3, count( $this->db->queries ) - $queries, 'Pages of 500 rows: 500 + 500 + 203.' );

		$seen = array();
		$store->each_embedding(
			static function ( int $chunk_id, int $doc_id ) use ( &$seen ) {
				$seen[ $doc_id ] = true;
			},
			array( $second, $pending )
		);
		$this->assertSame( array( $second ), array_keys( $seen ) );

		$called = false;
		$store->each_embedding(
			static function () use ( &$called ) {
				$called = true;
			},
			array()
		);
		$this->assertFalse( $called, 'Empty filter: nothing.' );
	}

	public function test_get_chunks_and_texts(): void {
		$store = new HDS_Vector_Store();
		$a     = $this->add( 'Doc A' );
		$b     = $this->add( 'Doc B' );
		$store->replace_chunks( $a, array( 'a0', 'a1' ) );
		$store->replace_chunks( $b, array( 'b0' ) );
		$ids = array_column( $store->pending_chunks( $a, 10 ), 'id' );

		$chunks = $store->get_chunks( array( $ids[1], 99999, 'x' ) );
		$this->assertSame( array( $ids[1] ), array_keys( $chunks ) );
		$this->assertSame(
			array(
				'id'          => $ids[1],
				'doc_id'      => $a,
				'chunk_index' => 1,
				'text'        => 'a1',
				'title'       => 'Doc A',
			),
			$chunks[ $ids[1] ]
		);
		$this->assertSame( array(), $store->get_chunks( array() ) );

		$this->assertSame( array(), $store->all_texts_by_doc(), 'No indexed documents.' );
		HDS_Documents::set_status( $a, HDS_Documents::STATUS_INDEXED );
		$this->assertSame( array( $a => array( 'a0', 'a1' ) ), $store->all_texts_by_doc() );

		$store->delete_for_doc( $a );
		$this->assertSame( 0, $store->count_for_doc( $a ) );
		$this->assertSame( 1, $store->count_for_doc( $b ) );
	}

	public function test_store_static_and_instance_calls(): void {
		$id = $this->add( 'A' );
		$this->assertSame( 2, HDS_Vector_Store::replace_chunks( $id, array( 'one', 'two' ) ) );
		$this->assertSame( 2, ( new HDS_Vector_Store() )->count_for_doc( $id ) );
		$this->assertSame( 2, HDS_Vector_Store::count_pending( $id ) );
	}

	public function test_version(): void {
		$this->assertFalse( get_option( HDS_Vector_Store::VERSION_OPTION ) );
		$v1 = HDS_Vector_Store::version();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $v1 );
		$this->assertSame( $v1, HDS_Vector_Store::version() );
		HDS_Vector_Store::bump_version();
		$v2 = HDS_Vector_Store::version();
		$this->assertNotSame( $v1, $v2 );
	}
}
