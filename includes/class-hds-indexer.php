<?php
/**
 * Indexing pipeline: extract → normalize → chunk → embed, in time-boxed steps.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indexer.
 *
 * start() decides which documents need work (unchanged files are skipped
 * without any API call, documents beyond the demo limit are locked) and
 * stores a queue; the admin page then calls step() over AJAX until it
 * reports done. Every step holds a transient lock and stops before its time
 * budget is used, so it fits shared-hosting time limits. A document whose
 * chunks are stored but not all embedded resumes where it stopped (its
 * index_hash holds a "partial" marker of the expected hash).
 *
 * Errors are per document: a broken file is marked and the run continues.
 * OpenAI errors stop the run: fatal ones (bad key, quota, bad request) until
 * the admin fixes the cause; temporary ones can be resumed. Document text is
 * never logged or put into messages.
 */
class HDS_Indexer {

	/**
	 * Chunks per embeddings request.
	 */
	const EMBED_BATCH = 32;

	/**
	 * Transient name of the step lock.
	 */
	const LOCK = 'hds_index_lock';

	/**
	 * Lock lifetime in seconds (a crashed step frees it after this time).
	 */
	const LOCK_TTL = 120;

	/**
	 * Option holding the queue: {run_id, queue:int[], total, processed, attempts}.
	 */
	const QUEUE_OPTION = 'hds_index_queue';

	/**
	 * Default step time budget in seconds.
	 */
	const DEFAULT_BUDGET = 20.0;

	/**
	 * Smallest step time budget in seconds.
	 */
	const MIN_BUDGET = 5.0;

	/**
	 * Extraction attempts of one file before it is marked as failed (protects
	 * against files that kill PHP with a memory or time limit every time).
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * OpenAI client (or a test double with the same embed() method).
	 *
	 * @var HDS_OpenAI_Client|null
	 */
	private $client;

	/**
	 * Token of the lock held by this instance ('' = none).
	 *
	 * @var string
	 */
	private $lock_token = '';

	/**
	 * Start time of the current step (microtime).
	 *
	 * @var float
	 */
	private $step_started = 0.0;

	/**
	 * Budget of the current step in seconds.
	 *
	 * @var float
	 */
	private $budget = 0.0;

	/**
	 * Whether the current step has done any work yet (each step makes progress).
	 *
	 * @var bool
	 */
	private $worked = false;

	/**
	 * Embedding batches sent in the current step and their total duration.
	 *
	 * @var int
	 */
	private $batches = 0;

	/**
	 * Seconds spent in embedding batches in the current step.
	 *
	 * @var float
	 */
	private $batch_seconds = 0.0;

	/**
	 * Constructor.
	 *
	 * @param HDS_OpenAI_Client|null $client OpenAI client; null = created from the settings when first needed.
	 */
	public function __construct( $client = null ) {
		$this->client = is_object( $client ) ? $client : null;
	}

	/**
	 * Prepare an indexing run.
	 *
	 * @return array {total:int (queued), skipped:int (unchanged), locked:int (demo-locked), errors:int (missing files),
	 *               failed:int (files that repeatedly crashed PHP, not retried), queue:int[], run_id:string,
	 *               messages:string[], updated:array[], error:?string}
	 */
	public function start(): array {
		$result = array(
			'total'    => 0,
			'skipped'  => 0,
			'locked'   => 0,
			'errors'   => 0,
			'failed'   => 0,
			'queue'    => array(),
			'run_id'   => '',
			'messages' => array(),
			'updated'  => array(),
			'error'    => null,
		);
		if ( ! $this->acquire_lock() ) {
			$result['error']      = self::locked_message();
			$result['messages'][] = $result['error'];
			return $result;
		}

		try {
			$previous    = get_option( self::QUEUE_OPTION, array() );
			$attempts    = is_array( $previous ) && isset( $previous['attempts'] ) && is_array( $previous['attempts'] ) ? $previous['attempts'] : array();
			$fingerprint = HDS_Settings::index_fingerprint();
			$allowed     = array_flip( $this->allowed_ids() );
			$queue       = array();
			$touched     = array();
			$changed     = false; // Set of searchable documents changed.

			foreach ( HDS_Documents::all() as $doc ) {
				$id     = (int) $doc['id'];
				$status = (string) $doc['status'];

				if ( ! isset( $allowed[ $id ] ) ) {
					if ( HDS_Documents::STATUS_DEMO_LOCKED !== $status ) {
						$changed = $changed || HDS_Documents::STATUS_INDEXED === $status;
						HDS_Documents::set_status( $id, HDS_Documents::STATUS_DEMO_LOCKED, null );
						$touched[ $id ] = true;
					}
					++$result['locked'];
					continue;
				}

				$path = HDS_Storage::path( (string) $doc['file_name'] );
				if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
					$changed = $changed || HDS_Documents::STATUS_INDEXED === $status;
					HDS_Documents::set_status( $id, HDS_Documents::STATUS_ERROR, self::missing_message() );
					$touched[ $id ] = true;
					++$result['errors'];
					continue;
				}

				$hash = (string) hash_file( 'sha256', $path );
				if ( $hash !== (string) $doc['file_hash'] ) {
					HDS_Documents::update(
						$id,
						array(
							'file_hash' => $hash,
							'file_size' => (int) filesize( $path ),
						)
					);
				}
				$expected = self::index_hash_for( $hash, $fingerprint );

				$up_to_date = $hash === (string) $doc['file_hash']
					&& $expected === (string) $doc['index_hash']
					&& (int) $doc['chunk_count'] > 0
					&& 0 === HDS_Vector_Store::count_pending( $id )
					&& HDS_Vector_Store::count_for_doc( $id ) === (int) $doc['chunk_count'];
				if ( $up_to_date ) {
					if ( HDS_Documents::STATUS_INDEXED !== $status ) {
						HDS_Documents::set_status( $id, HDS_Documents::STATUS_INDEXED, null );
						$touched[ $id ] = true;
						$changed        = true;
					}
					++$result['skipped'];
					continue;
				}
				if ( HDS_Documents::STATUS_SCANNED === $status && $hash === (string) $doc['file_hash'] && $expected === (string) $doc['index_hash'] ) {
					++$result['skipped']; // Same scanned PDF, same extractor: still no text layer.
					continue;
				}
				if ( HDS_Documents::STATUS_ERROR === $status && $hash === (string) $doc['file_hash'] && self::crash_marker( $expected ) === (string) $doc['index_hash'] ) {
					++$result['failed']; // Killed PHP MAX_ATTEMPTS times; retried when the file or the plugin changes.
					continue;
				}

				if ( HDS_Documents::STATUS_PENDING !== $status ) {
					$changed = $changed || HDS_Documents::STATUS_INDEXED === $status;
					HDS_Documents::set_status( $id, HDS_Documents::STATUS_PENDING, null );
					$touched[ $id ] = true;
				}
				$queue[] = $id;
			}

			$run_id = self::random_id();
			if ( array() !== $queue ) {
				$keep = array();
				foreach ( $queue as $id ) {
					if ( isset( $attempts[ $id ] ) && is_array( $attempts[ $id ] ) ) {
						$keep[ $id ] = $attempts[ $id ];
					}
				}
				self::save_queue(
					array(
						'run_id'    => $run_id,
						'queue'     => $queue,
						'total'     => count( $queue ),
						'processed' => 0,
						'attempts'  => $keep,
					)
				);
			} else {
				delete_option( self::QUEUE_OPTION );
			}
			if ( $changed ) {
				HDS_Vector_Store::bump_version();
			}
			if ( array() === $queue && ( $changed || array() !== $touched ) ) {
				HDS_Suggest::rebuild();
			}

			$result['total']    = count( $queue );
			$result['queue']    = $queue;
			$result['run_id']   = $run_id;
			$result['updated']  = $this->summaries( array_keys( $touched ) );
			$result['messages'] = self::start_messages( $result );
		} finally {
			$this->release_lock();
		}
		return $result;
	}

	/**
	 * Process the queue for up to $budget_seconds.
	 *
	 * @param float $budget_seconds Time budget in seconds (0 = time_budget()).
	 * @return array {done:bool, processed:int, total:int, percent:int, current:string, messages:string[],
	 *               error:?string, fatal:bool, updated:[{id, status, status_label, status_message, chunk_count}]}
	 */
	public function step( float $budget_seconds = 0 ): array {
		$this->step_started  = microtime( true );
		$this->budget        = $budget_seconds > 0 ? $budget_seconds : self::time_budget();
		$this->worked        = false;
		$this->batches       = 0;
		$this->batch_seconds = 0.0;

		$result = array(
			'done'      => false,
			'processed' => 0,
			'total'     => 0,
			'percent'   => 0,
			'current'   => '',
			'messages'  => array(),
			'error'     => null,
			'fatal'     => false,
			'updated'   => array(),
		);
		if ( ! $this->acquire_lock() ) {
			$result['error'] = self::locked_message();
			return $result;
		}

		$touched = array();
		try {
			self::extend_time_limit( $this->budget );
			$state = self::load_queue();
			if ( null === $state ) {
				$result['done']       = true;
				$result['percent']    = 100;
				$result['messages'][] = __( 'All documents are up to date.', 'smart-document-search' );
				return $result;
			}

			$fraction = 0.0;
			try {
				while ( array() !== $state['queue'] ) {
					if ( $this->worked && $this->elapsed() >= $this->budget ) {
						break;
					}
					$id  = (int) $state['queue'][0];
					$doc = HDS_Documents::get( $id );
					if ( null === $doc || HDS_Documents::STATUS_DEMO_LOCKED === $doc['status'] ) {
						array_shift( $state['queue'] );
						++$state['processed'];
						continue;
					}
					$touched[ $id ]    = true;
					$result['current'] = (string) $doc['title'];

					$fraction = 0.0;
					if ( ! $this->process_document( $doc, $state, $result['messages'], $fraction ) ) {
						break; // Budget used; the document continues in the next step.
					}
					array_shift( $state['queue'] );
					unset( $state['attempts'][ $id ] );
					++$state['processed'];
					$fraction = 0.0;
				}
			} catch ( HDS_OpenAI_Exception $e ) {
				if ( $e->is_fatal() ) {
					$result['error'] = $e->getMessage();
					$result['fatal'] = true;
				} else {
					$result['error'] = __( 'OpenAI is temporarily unavailable, try again later.', 'smart-document-search' );
				}
				$result['messages'][] = $result['error'];
			}

			$result['processed'] = (int) $state['processed'];
			$result['total']     = (int) $state['total'];

			if ( array() === $state['queue'] && null === $result['error'] ) {
				if ( ! $this->worked || $this->elapsed() < $this->budget * 0.5 ) {
					HDS_Suggest::rebuild();
					delete_option( self::QUEUE_OPTION );
					$result['done']       = true;
					$result['messages'][] = __( 'Indexing complete.', 'smart-document-search' );
				} else {
					self::save_queue( $state ); // Suggestions are rebuilt by the next step.
				}
			} else {
				self::save_queue( $state );
			}

			$result['percent'] = $result['done'] ? 100 : self::percent( (int) $state['processed'], $fraction, (int) $state['total'] );
		} finally {
			$result['updated'] = $this->summaries( array_keys( $touched ) );
			$this->release_lock();
		}
		return $result;
	}

	/**
	 * Step time budget: 20 s, or half of max_execution_time when that is
	 * lower, but at least 5 s.
	 *
	 * @return float
	 */
	public static function time_budget(): float {
		$budget = self::DEFAULT_BUDGET;
		$max    = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			$budget = min( $budget, $max * 0.5 );
		}
		return (float) max( self::MIN_BUDGET, $budget );
	}

	/**
	 * Ids of the documents that may be indexed and searched: in demo mode the
	 * first HDS_License::DEMO_DOC_LIMIT documents (created_at, id), else all.
	 *
	 * @param bool|null $demo Demo mode; null = HDS_License::is_demo().
	 * @return int[]
	 */
	public static function demo_allowed_ids( ?bool $demo = null ): array {
		if ( null === $demo ) {
			$demo = HDS_License::is_demo();
		}
		return HDS_Documents::ids_in_order( $demo ? (int) HDS_License::DEMO_DOC_LIMIT : 0 );
	}

	/**
	 * Admin label of a status (English).
	 *
	 * @param string $status  Status.
	 * @param string $message Status message (used for errors).
	 * @return string
	 */
	public static function status_label( string $status, string $message = '' ): string {
		switch ( $status ) {
			case HDS_Documents::STATUS_PENDING:
				return __( 'Not indexed', 'smart-document-search' );
			case HDS_Documents::STATUS_PROCESSING:
				return __( 'Indexing…', 'smart-document-search' );
			case HDS_Documents::STATUS_INDEXED:
				return __( 'Indexed', 'smart-document-search' );
			case HDS_Documents::STATUS_ERROR:
				if ( '' === trim( $message ) ) {
					return __( 'Error', 'smart-document-search' );
				}
				/* translators: %s: error message. */
				return sprintf( __( 'Error: %s', 'smart-document-search' ), $message );
			case HDS_Documents::STATUS_SCANNED:
				return __( 'Scanned PDF – no text found', 'smart-document-search' );
			case HDS_Documents::STATUS_DEMO_LOCKED:
				return __( 'Available in the full version', 'smart-document-search' );
			default:
				return $status;
		}
	}

	/**
	 * Expected index_hash of a fully indexed file.
	 *
	 * @param string      $file_hash   sha256 of the file.
	 * @param string|null $fingerprint HDS_Settings::index_fingerprint() (null = current).
	 * @return string
	 */
	public static function index_hash_for( string $file_hash, ?string $fingerprint = null ): string {
		return hash( 'sha256', $file_hash . '|' . ( null === $fingerprint ? HDS_Settings::index_fingerprint() : $fingerprint ) );
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
	 * Document ids allowed in the current mode.
	 *
	 * @return int[]
	 */
	protected function allowed_ids(): array {
		return self::demo_allowed_ids( $this->is_demo() );
	}

	/**
	 * Work on one document until it is finished or the budget is used.
	 *
	 * @param array    $doc      Document row.
	 * @param array    $state    Queue state (attempts are updated).
	 * @param string[] $messages Messages (appended).
	 * @param float    $fraction Embedded fraction of the document when unfinished (out).
	 * @return bool True when the document is finished (indexed or failed).
	 * @throws HDS_OpenAI_Exception On OpenAI errors (the document stays resumable).
	 */
	private function process_document( array $doc, array &$state, array &$messages, float &$fraction ): bool {
		$id    = (int) $doc['id'];
		$title = (string) $doc['title'];
		$path  = HDS_Storage::path( (string) $doc['file_name'] );
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			$this->fail( $doc, HDS_Documents::STATUS_ERROR, self::missing_message(), '' );
			$messages[] = self::doc_message( $title, self::missing_message() );
			return true;
		}

		$hash     = (string) hash_file( 'sha256', $path );
		$expected = self::index_hash_for( $hash );
		$marker   = self::partial_marker( $expected );
		$total    = HDS_Vector_Store::count_for_doc( $id );

		if ( $marker !== (string) $doc['index_hash'] || 0 === $total ) {
			$total = $this->extract_and_chunk( $doc, $path, $hash, $expected, $state, $messages );
			if ( $total <= 0 ) {
				return true; // Failed; status and message are set.
			}
			if ( $this->elapsed() >= $this->budget ) {
				return false;
			}
		} elseif ( HDS_Documents::STATUS_PROCESSING !== $doc['status'] ) {
			HDS_Documents::set_status( $id, HDS_Documents::STATUS_PROCESSING, null );
		}

		$model = (string) HDS_Settings::get( 'embedding_model' );
		while ( true ) {
			if ( $this->worked && $this->out_of_time() ) {
				$pending  = HDS_Vector_Store::count_pending( $id );
				$fraction = $total > 0 ? ( $total - $pending ) / $total : 0.0;
				return false;
			}
			$batch = HDS_Vector_Store::pending_chunks( $id, self::EMBED_BATCH );
			if ( array() === $batch ) {
				break;
			}
			$texts   = array();
			$started = microtime( true );
			foreach ( $batch as $chunk ) {
				$texts[] = $chunk['text'];
			}
			$vectors = $this->client()->embed( $texts, $model );
			unset( $texts );
			if ( ! is_array( $vectors ) || count( $vectors ) !== count( $batch ) ) {
				throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned a wrong number of embeddings.' );
			}
			$vectors = array_values( $vectors );
			foreach ( $batch as $i => $chunk ) {
				if ( ! is_array( $vectors[ $i ] ) || array() === $vectors[ $i ] ) {
					throw new HDS_OpenAI_Exception( HDS_OpenAI_Exception::BAD_RESPONSE, 'OpenAI returned an invalid embedding.' );
				}
				HDS_Vector_Store::save_embedding( (int) $chunk['id'], $vectors[ $i ] );
			}
			$this->worked         = true;
			$this->batches       += 1;
			$this->batch_seconds += microtime( true ) - $started;
		}

		$count = HDS_Vector_Store::count_for_doc( $id );
		HDS_Documents::update(
			$id,
			array(
				'status'         => HDS_Documents::STATUS_INDEXED,
				'status_message' => null,
				'chunk_count'    => $count,
				'index_hash'     => $expected,
				'indexed_at'     => current_time( 'mysql', true ),
				'file_hash'      => $hash,
			)
		);
		HDS_Vector_Store::bump_version();
		$messages[] = sprintf(
			/* translators: 1: document title, 2: number of fragments. */
			_n( '"%1$s": indexed (%2$d fragment).', '"%1$s": indexed (%2$d fragments).', $count, 'smart-document-search' ),
			$title,
			$count
		);
		return true;
	}

	/**
	 * Extract, normalize, chunk and store the chunks of a document.
	 *
	 * @param array    $doc      Document row.
	 * @param string   $path     File path.
	 * @param string   $hash     sha256 of the file.
	 * @param string   $expected Expected index hash.
	 * @param array    $state    Queue state (attempts).
	 * @param string[] $messages Messages (appended).
	 * @return int Number of chunks stored; 0 when the document failed.
	 */
	private function extract_and_chunk( array $doc, string $path, string $hash, string $expected, array &$state, array &$messages ): int {
		$id    = (int) $doc['id'];
		$title = (string) $doc['title'];

		$attempt = isset( $state['attempts'][ $id ] ) && is_array( $state['attempts'][ $id ] ) && isset( $state['attempts'][ $id ][0], $state['attempts'][ $id ][1] ) && $expected === $state['attempts'][ $id ][0]
			? (int) $state['attempts'][ $id ][1]
			: 0;
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$message = __( 'The file could not be processed: the server ran out of memory or time while reading it.', 'smart-document-search' );
			$this->fail( $doc, HDS_Documents::STATUS_ERROR, $message, self::crash_marker( $expected ), $hash );
			$messages[] = self::doc_message( $title, $message );
			$this->worked = true;
			return 0;
		}
		// Persist the attempt first: if PHP dies while parsing, the next steps know.
		$state['attempts'][ $id ] = array( $expected, $attempt + 1 );
		self::save_queue( $state );

		try {
			$text = HDS_Extractor_Factory::extract( $path, (string) $doc['ext'] );
		} catch ( HDS_Extraction_Exception $e ) {
			unset( $state['attempts'][ $id ] );
			$this->worked = true;
			if ( HDS_Extraction_Exception::SCANNED_PDF === $e->get_error_code() ) {
				$this->fail( $doc, HDS_Documents::STATUS_SCANNED, $e->getMessage(), $expected, $hash );
				$messages[] = self::doc_message( $title, self::status_label( HDS_Documents::STATUS_SCANNED ) );
			} else {
				$this->fail( $doc, HDS_Documents::STATUS_ERROR, $e->getMessage(), '', $hash );
				$messages[] = self::doc_message( $title, $e->getMessage() );
			}
			return 0;
		} catch ( \Throwable $e ) {
			unset( $state['attempts'][ $id ] );
			$this->worked = true;
			$message      = __( 'The file could not be processed (it may be damaged).', 'smart-document-search' );
			$this->fail( $doc, HDS_Documents::STATUS_ERROR, $message, '', $hash );
			$messages[] = self::doc_message( $title, $message );
			return 0;
		}
		unset( $state['attempts'][ $id ] );

		$text   = HDS_Normalizer::normalize( $text );
		$chars  = (int) mb_strlen( $text, 'UTF-8' );
		$chunks = ( new HDS_Chunker() )->split( $text );
		unset( $text );
		$this->worked = true;

		if ( array() === $chunks ) {
			$message = __( 'No text was found in the file.', 'smart-document-search' );
			$this->fail( $doc, HDS_Documents::STATUS_ERROR, $message, '', $hash );
			$messages[] = self::doc_message( $title, $message );
			return 0;
		}

		$count  = count( $chunks );
		$stored = HDS_Vector_Store::replace_chunks( $id, $chunks );
		unset( $chunks );
		if ( $stored !== $count ) {
			$message = __( 'The document fragments could not be saved to the database.', 'smart-document-search' );
			$this->fail( $doc, HDS_Documents::STATUS_ERROR, $message, '', $hash );
			$messages[] = self::doc_message( $title, $message );
			return 0;
		}

		HDS_Documents::update(
			$id,
			array(
				'status'         => HDS_Documents::STATUS_PROCESSING,
				'status_message' => null,
				'chunk_count'    => $stored,
				'char_count'     => $chars,
				'index_hash'     => self::partial_marker( $expected ),
				'indexed_at'     => null,
				'file_hash'      => $hash,
				'file_size'      => (int) filesize( $path ),
			)
		);
		if ( HDS_Documents::STATUS_INDEXED === $doc['status'] ) {
			HDS_Vector_Store::bump_version();
		}
		$messages[] = sprintf(
			/* translators: 1: document title, 2: number of fragments. */
			_n( '"%1$s": text extracted (%2$d fragment).', '"%1$s": text extracted (%2$d fragments).', $stored, 'smart-document-search' ),
			$title,
			$stored
		);
		return $stored;
	}

	/**
	 * Mark a document as failed (or scanned) and drop its chunks.
	 *
	 * @param array  $doc        Document row.
	 * @param string $status     STATUS_ERROR or STATUS_SCANNED.
	 * @param string $message    Admin message (no content).
	 * @param string $index_hash Index hash to store ('' = none).
	 * @param string $file_hash  Current file hash ('' = keep).
	 * @return void
	 */
	private function fail( array $doc, string $status, string $message, string $index_hash, string $file_hash = '' ): void {
		$id = (int) $doc['id'];
		HDS_Vector_Store::delete_for_doc( $id );
		$data = array(
			'status'         => $status,
			'status_message' => $message,
			'chunk_count'    => 0,
			'char_count'     => 0,
			'index_hash'     => $index_hash,
			'indexed_at'     => null,
		);
		if ( '' !== $file_hash ) {
			$data['file_hash'] = $file_hash;
		}
		HDS_Documents::update( $id, $data );
		if ( HDS_Documents::STATUS_INDEXED === $doc['status'] ) {
			HDS_Vector_Store::bump_version();
		}
	}

	/**
	 * The OpenAI client.
	 *
	 * @return HDS_OpenAI_Client
	 * @throws HDS_OpenAI_Exception NO_KEY when no key is configured.
	 */
	private function client() {
		if ( null === $this->client ) {
			$this->client = HDS_OpenAI_Client::from_settings(
				array(
					'timeout'        => 30,
					'max_total_time' => max( 10.0, $this->budget ),
				)
			);
		}
		return $this->client;
	}

	/**
	 * Seconds since the step started.
	 *
	 * @return float
	 */
	private function elapsed(): float {
		return microtime( true ) - $this->step_started;
	}

	/**
	 * Whether another embedding batch would exceed the budget.
	 *
	 * @return bool
	 */
	private function out_of_time(): bool {
		$elapsed = $this->elapsed();
		if ( $elapsed >= $this->budget ) {
			return true;
		}
		$average = $this->batches > 0 ? $this->batch_seconds / $this->batches : 0.0;
		return $elapsed + $average > $this->budget;
	}

	/**
	 * Row summaries for the admin table.
	 *
	 * @param int[] $ids Document ids.
	 * @return array[]
	 */
	private function summaries( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$doc = HDS_Documents::get( (int) $id );
			if ( null === $doc ) {
				continue;
			}
			$message = null === $doc['status_message'] ? '' : (string) $doc['status_message'];
			$out[]   = array(
				'id'             => (int) $doc['id'],
				'status'         => (string) $doc['status'],
				'status_label'   => self::status_label( (string) $doc['status'], $message ),
				'status_message' => $message,
				'chunk_count'    => (int) $doc['chunk_count'],
			);
		}
		return $out;
	}

	/**
	 * Take the step lock.
	 *
	 * @return bool
	 */
	private function acquire_lock(): bool {
		$current = get_transient( self::LOCK );
		if ( false !== $current && '' !== $current ) {
			return false;
		}
		$token = self::random_id();
		set_transient( self::LOCK, $token, self::LOCK_TTL );
		if ( get_transient( self::LOCK ) !== $token ) {
			return false;
		}
		$this->lock_token = $token;
		return true;
	}

	/**
	 * Release the step lock if this instance holds it.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		if ( '' !== $this->lock_token && get_transient( self::LOCK ) === $this->lock_token ) {
			delete_transient( self::LOCK );
		}
		$this->lock_token = '';
	}

	/**
	 * Load and validate the queue option.
	 *
	 * @return array|null Null when there is no run.
	 */
	private static function load_queue(): ?array {
		$state = get_option( self::QUEUE_OPTION, null );
		if ( ! is_array( $state ) || ! isset( $state['queue'] ) || ! is_array( $state['queue'] ) ) {
			return null;
		}
		$queue = HDS_Documents::ids( $state['queue'] );
		$total = isset( $state['total'] ) ? max( 0, (int) $state['total'] ) : count( $queue );
		return array(
			'run_id'    => isset( $state['run_id'] ) ? (string) $state['run_id'] : '',
			'queue'     => $queue,
			'total'     => max( $total, count( $queue ) ),
			'processed' => isset( $state['processed'] ) ? max( 0, (int) $state['processed'] ) : 0,
			'attempts'  => isset( $state['attempts'] ) && is_array( $state['attempts'] ) ? $state['attempts'] : array(),
		);
	}

	/**
	 * Save the queue option (not autoloaded).
	 *
	 * @param array $state Queue state.
	 * @return void
	 */
	private static function save_queue( array $state ): void {
		if ( false === get_option( self::QUEUE_OPTION, false ) ) {
			add_option( self::QUEUE_OPTION, $state, '', 'no' );
		} else {
			update_option( self::QUEUE_OPTION, $state, false );
		}
	}

	/**
	 * Progress in percent.
	 *
	 * @param int   $processed Finished documents.
	 * @param float $fraction  Fraction of the current document.
	 * @param int   $total     Documents in the run.
	 * @return int
	 */
	private static function percent( int $processed, float $fraction, int $total ): int {
		if ( $total <= 0 ) {
			return 100;
		}
		$fraction = max( 0.0, min( 1.0, $fraction ) );
		return (int) max( 0, min( 100, round( 100 * ( $processed + $fraction ) / $total ) ) );
	}

	/**
	 * Index hash marker of a document whose chunks are stored but not all embedded.
	 *
	 * @param string $expected Expected index hash.
	 * @return string
	 */
	private static function partial_marker( string $expected ): string {
		return hash( 'sha256', $expected . '|partial' );
	}

	/**
	 * Index hash marker of a file that killed PHP MAX_ATTEMPTS times while
	 * being read (not retried until the file or the index fingerprint changes).
	 *
	 * @param string $expected Expected index hash.
	 * @return string
	 */
	private static function crash_marker( string $expected ): string {
		return hash( 'sha256', $expected . '|crashed' );
	}

	/**
	 * Allow the PHP time limit to cover a step (never shortens it; no-op when unlimited).
	 *
	 * @param float $budget Step budget in seconds.
	 * @return void
	 */
	private static function extend_time_limit( float $budget ): void {
		$max  = (int) ini_get( 'max_execution_time' );
		$want = (int) ceil( $budget * 2 + 30 );
		if ( $max > 0 && $max < $want && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $want ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Random hex id.
	 *
	 * @return string
	 */
	private static function random_id(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			return md5( uniqid( '', true ) );
		}
	}

	/**
	 * Message for a document.
	 *
	 * @param string $title   Document title.
	 * @param string $message Message.
	 * @return string
	 */
	private static function doc_message( string $title, string $message ): string {
		return sprintf( '"%1$s": %2$s', $title, $message );
	}

	/**
	 * Message for a missing file.
	 *
	 * @return string
	 */
	private static function missing_message(): string {
		return __( 'File is missing from the server.', 'smart-document-search' );
	}

	/**
	 * Message for a held lock.
	 *
	 * @return string
	 */
	private static function locked_message(): string {
		return __( 'Indexing is already running in another tab.', 'smart-document-search' );
	}

	/**
	 * Summary messages of start().
	 *
	 * @param array $result start() result.
	 * @return string[]
	 */
	private static function start_messages( array $result ): array {
		$messages = array();
		if ( $result['total'] > 0 ) {
			/* translators: %d: number of documents. */
			$messages[] = sprintf( _n( '%d document queued for indexing.', '%d documents queued for indexing.', $result['total'], 'smart-document-search' ), $result['total'] );
		}
		if ( $result['skipped'] > 0 ) {
			/* translators: %d: number of documents. */
			$messages[] = sprintf( _n( '%d document is up to date (skipped).', '%d documents are up to date (skipped).', $result['skipped'], 'smart-document-search' ), $result['skipped'] );
		}
		if ( $result['locked'] > 0 ) {
			/* translators: %d: number of documents. */
			$messages[] = sprintf( _n( '%d document is available in the full version only.', '%d documents are available in the full version only.', $result['locked'], 'smart-document-search' ), $result['locked'] );
		}
		if ( $result['errors'] > 0 ) {
			/* translators: %d: number of documents. */
			$messages[] = sprintf( _n( '%d file is missing from the server.', '%d files are missing from the server.', $result['errors'], 'smart-document-search' ), $result['errors'] );
		}
		if ( $result['failed'] > 0 ) {
			/* translators: %d: number of documents. */
			$messages[] = sprintf( _n( '%d document was skipped: it could not be processed earlier (upload the file again to retry).', '%d documents were skipped: they could not be processed earlier (upload the files again to retry).', $result['failed'], 'smart-document-search' ), $result['failed'] );
		}
		if ( 0 === $result['total'] && 0 === $result['errors'] && 0 === $result['failed'] ) {
			$messages[] = __( 'All documents are up to date.', 'smart-document-search' );
		}
		return $messages;
	}
}
