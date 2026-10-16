<?php
/**
 * Tests for HDS_Indexer (real .doc fixtures, fake OpenAI transport).
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Indexer that runs in full (licensed) mode.
 */
class HDS_Test_Full_Indexer extends HDS_Indexer {

	/**
	 * Full mode.
	 *
	 * @return bool
	 */
	protected function is_demo(): bool {
		return false;
	}
}

/**
 * Indexer tests.
 */
class IndexerTest extends TestCase {

	const DIMS = 1536;

	/**
	 * Fake database.
	 *
	 * @var HDS_Test_WPDB
	 */
	private $db;

	/**
	 * Embedding requests sent to the fake transport.
	 *
	 * @var int
	 */
	private $requests = 0;

	/**
	 * Texts embedded by the fake transport.
	 *
	 * @var int
	 */
	private $embedded = 0;

	/**
	 * HTTP status the fake transport answers with (200 = success).
	 *
	 * @var int
	 */
	private $fail_with = 200;

	/**
	 * Vector store.
	 *
	 * @var HDS_Vector_Store
	 */
	private $store;

	protected function setUp(): void {
		hds_test_reset();
		$this->db = hds_test_create_tables();
		hds_test_clean_uploads();
		HDS_License::reset_cache();
		HDS_Storage::ensure_protected();
		$this->store     = new HDS_Vector_Store();
		$this->requests  = 0;
		$this->embedded  = 0;
		$this->fail_with = 200;
	}

	protected function tearDown(): void {
		hds_test_clean_uploads();
	}

	/**
	 * Deterministic unit vector: hashed bag of words.
	 *
	 * @param string $text Text.
	 * @return float[]
	 */
	public static function hashed_vector( string $text ): array {
		$vec = array_fill( 0, self::DIMS, 0.0 );
		preg_match_all( '/[\p{L}\p{N}]+/u', mb_strtolower( $text ), $m );
		foreach ( $m[0] as $word ) {
			$h                       = crc32( $word );
			$vec[ $h % self::DIMS ] += ( $h & 1 ) ? 1.0 : -1.0;
		}
		$norm = sqrt( array_sum( array_map( static function ( $x ) {
			return $x * $x;
		}, $vec ) ) );
		if ( $norm <= 0 ) {
			$vec[0] = 1.0;
			return $vec;
		}
		return array_map( static function ( $x ) use ( $norm ) {
			return round( $x / $norm, 6 );
		}, $vec );
	}

	/**
	 * OpenAI client with a fake transport.
	 *
	 * @return HDS_OpenAI_Client
	 */
	private function client(): HDS_OpenAI_Client {
		$transport = function ( string $url, array $request ): array {
			$this->assertSame( 'https://api.openai.com/v1/embeddings', $url );
			++$this->requests;
			if ( 200 !== $this->fail_with ) {
				return array(
					'code'    => $this->fail_with,
					'body'    => json_encode( array( 'error' => array( 'message' => 'Mock failure', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key' ) ) ),
					'headers' => array(),
				);
			}
			$body = json_decode( $request['body'], true );
			$this->assertSame( 'text-embedding-3-small', $body['model'] );
			$data = array();
			foreach ( $body['input'] as $i => $text ) {
				++$this->embedded;
				$data[] = array(
					'object'    => 'embedding',
					'index'     => $i,
					'embedding' => self::hashed_vector( $text ),
				);
			}
			return array(
				'code'    => 200,
				'body'    => json_encode( array( 'object' => 'list', 'data' => array_reverse( $data ) ) ),
				'headers' => array( 'content-type' => 'application/json' ),
			);
		};
		return new HDS_OpenAI_Client(
			'sk-test-0000000000000000000000000000',
			$transport,
			array(
				'sleep'        => static function () {
				},
				'max_attempts' => 2,
			)
		);
	}

	/**
	 * Store a document file and insert its row.
	 *
	 * @param string $name    Stored file name.
	 * @param string $content Content.
	 * @param string $title   Title.
	 * @return int
	 */
	private function add_doc( string $name, string $content, string $title = '' ): int {
		file_put_contents( HDS_Storage::dir() . $name, $content );
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		return HDS_Documents::insert(
			array(
				'file_name'     => $name,
				'original_name' => $name,
				'ext'           => $ext,
				'file_size'     => strlen( $content ),
				'file_hash'     => hash( 'sha256', $content ),
				'title'         => '' !== $title ? $title : pathinfo( $name, PATHINFO_FILENAME ),
			)
		);
	}

	/**
	 * Add the two real .doc fixtures.
	 *
	 * @return int[] [short id, long id]
	 */
	private function add_fixtures(): array {
		return array(
			$this->add_doc( 'SH-22-06-57879-590-aaaaaaaa.doc', (string) file_get_contents( HDS_FIXTURES_DIR . '/SH-22-06-57879-590.doc' ), 'SH-22-06-57879-590' ),
			$this->add_doc( 'ARAR-25-09-8059-bbbbbbbb.doc', (string) file_get_contents( HDS_FIXTURES_DIR . '/ARAR-25-09-8059.doc' ), 'ARAR-25-09-8059' ),
		);
	}

	/**
	 * Run steps until done (or a step reports an error).
	 *
	 * @param HDS_Indexer $indexer Indexer.
	 * @param float       $budget  Budget per step.
	 * @param int         $max     Maximum steps.
	 * @return array[] Step results.
	 */
	private function run_steps( HDS_Indexer $indexer, float $budget = 30.0, int $max = 500 ): array {
		$results = array();
		for ( $i = 0; $i < $max; $i++ ) {
			$result    = $indexer->step( $budget );
			$results[] = $result;
			if ( $result['done'] || null !== $result['error'] ) {
				return $results;
			}
		}
		$this->fail( 'Indexing did not finish.' );
		return $results;
	}

	/**
	 * Status of a document.
	 *
	 * @param int $id Document id.
	 * @return string
	 */
	private function status( int $id ): string {
		return HDS_Documents::get( $id )['status'];
	}

	public function test_indexes_real_doc_fixtures(): void {
		list( $short, $long ) = $this->add_fixtures();
		$indexer = new HDS_Indexer( $this->client() );

		$start = $indexer->start();
		$this->assertSame( 2, $start['total'] );
		$this->assertSame( 0, $start['skipped'] );
		$this->assertSame( 0, $start['locked'] );
		$this->assertSame( array( $short, $long ), $start['queue'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $start['run_id'] );
		$this->assertSame( array( $short, $long ), get_option( HDS_Indexer::QUEUE_OPTION )['queue'] );
		$this->assertFalse( get_transient( HDS_Indexer::LOCK ), 'start() releases the lock.' );

		$steps = $this->run_steps( $indexer );
		$last  = end( $steps );
		$this->assertTrue( $last['done'] );
		$this->assertSame( 100, $last['percent'] );
		$this->assertNull( $last['error'] );
		$this->assertFalse( $last['fatal'] );
		$this->assertSame( 2, $last['processed'] );
		$this->assertSame( 2, $last['total'] );
		$this->assertFalse( get_option( HDS_Indexer::QUEUE_OPTION ), 'Queue removed when done.' );
		$this->assertFalse( get_transient( HDS_Indexer::LOCK ), 'step() releases the lock.' );

		foreach ( array( $short, $long ) as $id ) {
			$doc = HDS_Documents::get( $id );
			$this->assertSame( HDS_Documents::STATUS_INDEXED, $doc['status'] );
			$this->assertGreaterThan( 0, $doc['chunk_count'] );
			$this->assertGreaterThan( 0, $doc['char_count'] );
			$this->assertSame( $doc['chunk_count'], $this->store->count_for_doc( $id ) );
			$this->assertSame( 0, $this->store->count_pending( $id ) );
			$this->assertSame( HDS_Indexer::index_hash_for( $doc['file_hash'] ), $doc['index_hash'] );
			$this->assertNotNull( $doc['indexed_at'] );
			$this->assertNull( $doc['status_message'] );
		}
		$short_doc = HDS_Documents::get( $short );
		$long_doc  = HDS_Documents::get( $long );
		$this->assertGreaterThan( $short_doc['chunk_count'], $long_doc['chunk_count'] );
		$this->assertGreaterThan( 50, $long_doc['chunk_count'] );

		// Every embedding is a 1536-dim float32 blob.
		$lengths = $this->db->get_col( 'SELECT length(embedding) FROM wp_hds_chunks' );
		$this->assertCount( $short_doc['chunk_count'] + $long_doc['chunk_count'], $lengths );
		$this->assertSame( array( '6144' ), array_values( array_unique( $lengths ) ) );
		$this->assertSame( $this->embedded, count( $lengths ) );
		$this->assertSame( (int) ceil( $long_doc['chunk_count'] / HDS_Indexer::EMBED_BATCH ) + (int) ceil( $short_doc['chunk_count'] / HDS_Indexer::EMBED_BATCH ), $this->requests );

		// Stored text is normalized Hebrew from the document, chunk by chunk.
		$texts = $this->store->all_texts_by_doc();
		$this->assertSame( array( $short, $long ), array_keys( $texts ) );
		$this->assertMatchesRegularExpression( '/\p{Hebrew}{3,}/u', $texts[ $short ][0] );
		$this->assertSame( HDS_Normalizer::normalize( $texts[ $long ][0] ), $texts[ $long ][0] );
		foreach ( $texts[ $long ] as $chunk ) {
			$this->assertLessThanOrEqual( HDS_Chunker::SIZE, mb_strlen( $chunk ) );
		}

		// Search can stream the vectors; suggestions were built.
		$count = 0;
		$this->store->each_embedding(
			static function () use ( &$count ) {
				++$count;
			}
		);
		$this->assertSame( count( $lengths ), $count );
		$dict = HDS_Suggest::get();
		$this->assertSame( array( 'sh-22-06-57879-590', 'arar-25-09-8059' ), $dict['titles'] );
		$this->assertNotEmpty( $dict['phrases'] );

		// Row summaries for the admin table.
		$updated = array();
		foreach ( $steps as $step ) {
			foreach ( $step['updated'] as $row ) {
				$updated[ $row['id'] ] = $row;
			}
			foreach ( $step['messages'] as $message ) {
				$this->assertStringNotContainsString( mb_substr( $texts[ $long ][3], 10, 30 ), $message, 'No document text in messages.' );
			}
		}
		$this->assertSame(
			array(
				'id'             => $long,
				'status'         => 'indexed',
				'status_label'   => 'Indexed',
				'status_message' => '',
				'chunk_count'    => $long_doc['chunk_count'],
			),
			$updated[ $long ]
		);
	}

	public function test_rerun_skips_unchanged_files_and_reindexes_modified_ones(): void {
		list( $short, $long ) = $this->add_fixtures();
		$indexer = new HDS_Indexer( $this->client() );
		$indexer->start();
		$this->run_steps( $indexer );
		$requests = $this->requests;
		$version  = HDS_Vector_Store::version();
		$long_doc = HDS_Documents::get( $long );

		$start = $indexer->start();
		$this->assertSame( 0, $start['total'] );
		$this->assertSame( 2, $start['skipped'] );
		$this->assertContains( '2 documents are up to date (skipped).', $start['messages'] );
		$step = $indexer->step( 30 );
		$this->assertTrue( $step['done'] );
		$this->assertSame( 100, $step['percent'] );
		$this->assertSame( $requests, $this->requests, 'No OpenAI calls for unchanged files.' );
		$this->assertSame( $version, HDS_Vector_Store::version() );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, $this->status( $short ) );

		// Replace the short document's file with the long one's content.
		file_put_contents( HDS_Storage::dir() . 'SH-22-06-57879-590-aaaaaaaa.doc', (string) file_get_contents( HDS_FIXTURES_DIR . '/ARAR-25-09-8059.doc' ) );
		$start = $indexer->start();
		$this->assertSame( 1, $start['total'] );
		$this->assertSame( 1, $start['skipped'] );
		$this->assertSame( array( $short ), $start['queue'] );
		$this->assertSame( HDS_Documents::STATUS_PENDING, $this->status( $short ) );
		$this->assertNotSame( $version, HDS_Vector_Store::version(), 'Its old vectors left the search set.' );
		$this->run_steps( $indexer );

		$doc = HDS_Documents::get( $short );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, $doc['status'] );
		$this->assertSame( $long_doc['chunk_count'], $doc['chunk_count'] );
		$this->assertSame( $long_doc['file_hash'], $doc['file_hash'] );
		$this->assertGreaterThan( $requests, $this->requests );
	}

	public function test_broken_and_missing_files_do_not_stop_the_run(): void {
		list( $short ) = $this->add_fixtures();
		$broken        = $this->add_doc( 'broken-cccccccc.doc', random_bytes( 20000 ) );
		$fake_ole      = $this->add_doc( 'garbage-dddddddd.doc', (string) file_get_contents( HDS_FIXTURES_DIR . '/broken/garbage-with-header.doc' ) );
		$missing       = $this->add_doc( 'missing-eeeeeeee.txt', 'some text here' );
		$text          = $this->add_doc( 'note-ffffffff.txt', "הודעה קצרה בעברית.\nשורה שנייה של טקסט." );
		unlink( HDS_Storage::dir() . 'missing-eeeeeeee.txt' );

		$indexer = new HDS_Indexer( $this->client() );
		$start   = $indexer->start();
		$this->assertSame( 1, $start['errors'] );
		$this->assertSame( 5, $start['total'] );
		$this->assertSame( HDS_Documents::STATUS_ERROR, $this->status( $missing ) );
		$this->assertSame( 'File is missing from the server.', HDS_Documents::get( $missing )['status_message'] );

		$steps = $this->run_steps( $indexer );
		$this->assertTrue( end( $steps )['done'] );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, $this->status( $short ) );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, $this->status( $text ) );
		foreach ( array( $broken, $fake_ole ) as $id ) {
			$doc = HDS_Documents::get( $id );
			$this->assertSame( HDS_Documents::STATUS_ERROR, $doc['status'] );
			$this->assertNotSame( '', (string) $doc['status_message'] );
			$this->assertSame( 0, $doc['chunk_count'] );
			$this->assertSame( 0, $this->store->count_for_doc( $id ) );
		}
		$label = HDS_Indexer::status_label( 'error', (string) HDS_Documents::get( $broken )['status_message'] );
		$this->assertStringStartsWith( 'Error: ', $label );

		// Error documents are retried on the next run; the indexed ones are skipped.
		$start = $indexer->start();
		$this->assertSame( 3, $start['skipped'] );
		$this->assertSame( array( $broken, $fake_ole ), $start['queue'] );
		$this->assertSame( 1, $start['errors'] );
	}

	public function test_scanned_pdf_is_marked_and_skipped_later(): void {
		$pdf     = $this->add_doc( 'scan-aaaaaaaa.pdf', (string) file_get_contents( HDS_FIXTURES_DIR . '/generated/scanned.pdf' ) );
		$indexer = new HDS_Indexer( $this->client() );
		$indexer->start();
		$this->run_steps( $indexer );
		$doc = HDS_Documents::get( $pdf );
		$this->assertSame( HDS_Documents::STATUS_SCANNED, $doc['status'], (string) $doc['status_message'] );
		$this->assertSame( 'Scanned PDF – no text found', HDS_Indexer::status_label( $doc['status'] ) );
		$this->assertSame( 0, $this->requests );

		$start = $indexer->start();
		$this->assertSame( 0, $start['total'] );
		$this->assertSame( 1, $start['skipped'] );
		$this->assertSame( HDS_Documents::STATUS_SCANNED, $this->status( $pdf ) );
	}

	public function test_demo_mode_locks_documents_beyond_the_limit(): void {
		$ids = array();
		for ( $i = 1; $i <= 17; $i++ ) {
			$ids[] = $this->add_doc( sprintf( 'doc%02d-aaaaaaaa.txt', $i ), "מסמך מספר {$i}. פסק הדין ניתן בבית המשפט המחוזי. הערעור נדחה." );
		}
		$this->assertSame( 15, HDS_License::DEMO_DOC_LIMIT );
		$this->assertSame( array_slice( $ids, 0, 15 ), HDS_Indexer::demo_allowed_ids() );
		$this->assertSame( $ids, HDS_Indexer::demo_allowed_ids( false ) );

		$indexer = new HDS_Indexer( $this->client() );
		$start   = $indexer->start();
		$this->assertSame( 15, $start['total'] );
		$this->assertSame( 2, $start['locked'] );
		$this->run_steps( $indexer );
		foreach ( $ids as $n => $id ) {
			$this->assertSame( $n < 15 ? HDS_Documents::STATUS_INDEXED : HDS_Documents::STATUS_DEMO_LOCKED, $this->status( $id ), 'Document ' . ( $n + 1 ) );
		}
		$this->assertSame( 'Available in the full version', HDS_Indexer::status_label( HDS_Documents::STATUS_DEMO_LOCKED ) );
		$this->assertSame( 0, $this->store->count_for_doc( $ids[16] ) );

		// Full version: the locked documents are indexed, the others skipped.
		$full  = new HDS_Test_Full_Indexer( $this->client() );
		$start = $full->start();
		$this->assertSame( 2, $start['total'] );
		$this->assertSame( 15, $start['skipped'] );
		$this->assertSame( 0, $start['locked'] );
		$this->run_steps( $full );
		foreach ( $ids as $id ) {
			$this->assertSame( HDS_Documents::STATUS_INDEXED, $this->status( $id ) );
		}

		// Back to demo: locked again, chunks kept; full again: no API calls.
		$version = HDS_Vector_Store::version();
		$start   = $indexer->start();
		$this->assertSame( 2, $start['locked'] );
		$this->assertSame( 0, $start['total'] );
		$this->assertSame( HDS_Documents::STATUS_DEMO_LOCKED, $this->status( $ids[16] ) );
		$this->assertGreaterThan( 0, $this->store->count_for_doc( $ids[16] ) );
		$this->assertNotSame( $version, HDS_Vector_Store::version(), 'Searchable set changed.' );
		$this->assertNotContains( 'doc17-aaaaaaaa', HDS_Suggest::get()['titles'], 'Suggestions rebuilt.' );

		$requests = $this->requests;
		$start    = $full->start();
		$this->assertSame( 0, $start['total'] );
		$this->assertSame( 17, $start['skipped'] );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, $this->status( $ids[16] ) );
		$this->assertSame( $requests, $this->requests );
		$this->assertContains( 'doc17-aaaaaaaa', HDS_Suggest::get()['titles'] );
	}

	public function test_fatal_openai_error_stops_the_run(): void {
		list( $short, $long ) = $this->add_fixtures();
		$this->fail_with      = 401;
		$indexer              = new HDS_Indexer( $this->client() );
		$indexer->start();

		$step = $indexer->step( 30 );
		$this->assertTrue( $step['fatal'] );
		$this->assertFalse( $step['done'] );
		$this->assertStringContainsString( 'API key', (string) $step['error'] );
		$this->assertStringNotContainsString( 'sk-test', (string) $step['error'] );
		$this->assertSame( 1, $this->requests, '401 is not retried.' );
		$this->assertSame( HDS_Documents::STATUS_PROCESSING, $this->status( $short ), 'Stays resumable.' );
		$this->assertSame( HDS_Documents::STATUS_PENDING, $this->status( $long ) );
		$this->assertFalse( get_transient( HDS_Indexer::LOCK ) );
		$chunks = $this->store->count_for_doc( $short );
		$this->assertGreaterThan( 0, $chunks );

		// Fixed key: the run resumes without extracting again.
		$this->fail_with = 200;
		$steps           = $this->run_steps( $indexer );
		$this->assertTrue( end( $steps )['done'] );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, $this->status( $short ) );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, $this->status( $long ) );
		$this->assertSame( $chunks, $this->store->count_for_doc( $short ) );
	}

	public function test_temporary_openai_error_is_resumable(): void {
		list( $short ) = $this->add_fixtures();
		$this->fail_with = 503;
		$indexer         = new HDS_Indexer( $this->client() );
		$indexer->start();

		$step = $indexer->step( 30 );
		$this->assertFalse( $step['fatal'] );
		$this->assertFalse( $step['done'] );
		$this->assertSame( 'OpenAI is temporarily unavailable, try again later.', $step['error'] );
		$this->assertSame( 2, $this->requests, 'Retried (max_attempts 2).' );
		$this->assertNotFalse( get_option( HDS_Indexer::QUEUE_OPTION ) );

		$this->fail_with = 200;
		$this->run_steps( $indexer );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, $this->status( $short ) );
	}

	public function test_missing_api_key_is_fatal(): void {
		$this->add_fixtures();
		$indexer = new HDS_Indexer();
		$indexer->start();
		$step = $indexer->step( 30 );
		$this->assertTrue( $step['fatal'] );
		$this->assertStringContainsString( 'API key is not configured', (string) $step['error'] );
	}

	public function test_step_honours_the_lock(): void {
		$this->add_fixtures();
		$indexer = new HDS_Indexer( $this->client() );
		$indexer->start();

		set_transient( HDS_Indexer::LOCK, 'another-tab', 120 );
		$step = $indexer->step( 30 );
		$this->assertSame( 'Indexing is already running in another tab.', $step['error'] );
		$this->assertFalse( $step['fatal'] );
		$this->assertFalse( $step['done'] );
		$this->assertSame( 0, $this->requests );
		$this->assertSame( 'another-tab', get_transient( HDS_Indexer::LOCK ), 'Foreign lock untouched.' );

		$start = $indexer->start();
		$this->assertSame( 'Indexing is already running in another tab.', $start['error'] );
		$this->assertSame( 0, $start['total'] );

		// The lock expires after 120 s.
		hds_test_set_time( time() + 121 );
		$steps = $this->run_steps( $indexer );
		$this->assertTrue( end( $steps )['done'] );
	}

	public function test_small_budget_makes_progress_in_many_steps(): void {
		list( , $long ) = $this->add_fixtures();
		$indexer        = new HDS_Indexer( $this->client() );
		$indexer->start();

		$steps    = $this->run_steps( $indexer, 0.000001 );
		$percents = array_column( $steps, 'percent' );
		$this->assertGreaterThan( 4, count( $steps ) );
		$sorted = $percents;
		sort( $sorted );
		$this->assertSame( $sorted, $percents, 'Progress never goes back.' );
		$this->assertSame( 100, end( $percents ) );
		$partial = array_filter(
			$percents,
			static function ( $p ) {
				return $p > 0 && $p < 100 && 0 !== $p % 50;
			}
		);
		$this->assertNotEmpty( $partial, 'Percent includes the fraction of the current document.' );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, $this->status( $long ) );
		$this->assertSame( (int) ceil( HDS_Documents::get( $long )['chunk_count'] / HDS_Indexer::EMBED_BATCH ), $this->requests - (int) ceil( HDS_Documents::get( $long - 1 )['chunk_count'] / HDS_Indexer::EMBED_BATCH ) );
	}

	public function test_crashing_file_is_given_up_after_max_attempts(): void {
		list( $short ) = $this->add_fixtures();
		$indexer       = new HDS_Indexer( $this->client() );
		$indexer->start();
		// Simulate PHP dying while parsing: the attempt was recorded, nothing else happened.
		$state                         = get_option( HDS_Indexer::QUEUE_OPTION );
		$doc                           = HDS_Documents::get( $short );
		$state['attempts'][ $short ]   = array( HDS_Indexer::index_hash_for( $doc['file_hash'] ), HDS_Indexer::MAX_ATTEMPTS );
		update_option( HDS_Indexer::QUEUE_OPTION, $state );

		$this->run_steps( $indexer );
		$doc = HDS_Documents::get( $short );
		$this->assertSame( HDS_Documents::STATUS_ERROR, $doc['status'] );
		$this->assertStringContainsString( 'ran out of memory or time', (string) $doc['status_message'] );
		$this->assertSame( HDS_Documents::STATUS_INDEXED, $this->status( $short + 1 ), 'The other document is indexed.' );

		// Later runs do not try again (no more PHP crashes) ...
		$start = $indexer->start();
		$this->assertSame( 1, $start['failed'] );
		$this->assertSame( 0, $start['total'] );
		$this->assertSame( HDS_Documents::STATUS_ERROR, $this->status( $short ) );

		// ... until the file changes.
		file_put_contents( HDS_Storage::dir() . 'SH-22-06-57879-590-aaaaaaaa.doc', "\n", FILE_APPEND );
		$start = $indexer->start();
		$this->assertSame( 0, $start['failed'] );
		$this->assertSame( array( $short ), $start['queue'] );
	}

	public function test_no_queue_and_status_labels(): void {
		$indexer = new HDS_Indexer( $this->client() );
		$step    = $indexer->step( 30 );
		$this->assertTrue( $step['done'] );
		$this->assertSame( 100, $step['percent'] );
		$this->assertSame( 0, $step['total'] );

		$start = $indexer->start();
		$this->assertSame( 0, $start['total'] );
		$this->assertFalse( get_option( HDS_Indexer::QUEUE_OPTION ) );

		$this->assertSame( 'Not indexed', HDS_Indexer::status_label( 'pending' ) );
		$this->assertSame( 'Indexing…', HDS_Indexer::status_label( 'processing' ) );
		$this->assertSame( 'Indexed', HDS_Indexer::status_label( 'indexed' ) );
		$this->assertSame( 'Error: Broken.', HDS_Indexer::status_label( 'error', 'Broken.' ) );
		$this->assertSame( 'Error', HDS_Indexer::status_label( 'error' ) );
	}

	public function test_time_budget(): void {
		$old = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '0' );
		$this->assertSame( 20.0, HDS_Indexer::time_budget() );
		ini_set( 'max_execution_time', '30' );
		$this->assertSame( 15.0, HDS_Indexer::time_budget() );
		ini_set( 'max_execution_time', '6' );
		$this->assertSame( 5.0, HDS_Indexer::time_budget() );
		ini_set( 'max_execution_time', '300' );
		$this->assertSame( 20.0, HDS_Indexer::time_budget() );
		ini_set( 'max_execution_time', (string) $old );
	}
}
