<?php
/**
 * Tests for HDS_Answerer with a fake OpenAI client and a fake search.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * OpenAI client double: records calls, returns canned values.
 */
class HDS_Answerer_Test_Client {

	/** @var array[] */
	public $embed_calls = array();
	/** @var array[] */
	public $chat_calls = array();
	/** @var float[] */
	public $vector = array( 1.0, 0.0, 0.0 );
	/** @var string */
	public $reply = 'לפי פסק הדין הוחלט על החזר האגרה למבקש.';
	/** @var Throwable|null */
	public $embed_error = null;
	/** @var Throwable|null */
	public $chat_error = null;

	public function embed( array $texts, string $model ): array {
		$this->embed_calls[] = array(
			'texts' => $texts,
			'model' => $model,
		);
		if ( $this->embed_error ) {
			throw $this->embed_error;
		}
		return array( $this->vector );
	}

	public function chat( array $messages, string $model, array $params = array() ): string {
		$this->chat_calls[] = array(
			'messages' => $messages,
			'model'    => $model,
			'params'   => $params,
		);
		if ( $this->chat_error ) {
			throw $this->chat_error;
		}
		return $this->reply;
	}

	public function calls(): int {
		return count( $this->embed_calls ) + count( $this->chat_calls );
	}
}

/**
 * Search double.
 */
class HDS_Answerer_Test_Search {

	/** @var array */
	public $matrix = array(
		'dims' => 3,
		'ids'  => array( 11, 12, 13, 99 ),
		'docs' => array( 1, 2, 1, 3 ),
		'blob' => '',
	);
	/** @var array[] */
	public $hits = array();
	/** @var array<int, array> */
	public $chunks = array();
	/** @var array[] */
	public $search_calls = array();

	public function get_matrix(): array {
		return $this->matrix;
	}

	public function search( array $query_vector, int $top_n ): array {
		$this->search_calls[] = array( $query_vector, $top_n );
		return array_slice( $this->hits, 0, $top_n );
	}

	public function get_chunks( array $ids ): array {
		return array_intersect_key( $this->chunks, array_flip( $ids ) );
	}
}

/**
 * HDS_Answerer tests.
 */
class AnswererTest extends TestCase {

	const QUESTION = 'מה הוחלט לגבי החזר האגרה?';

	/** @var HDS_Answerer_Test_Client */
	private $client;

	/** @var HDS_Answerer_Test_Search */
	private $search;

	protected function setUp(): void {
		hds_test_reset();
		$this->client = new HDS_Answerer_Test_Client();
		$this->search = new HDS_Answerer_Test_Search();

		$filler = str_repeat( 'בית המשפט דן בטענות הצדדים ובחן את הראיות שהוצגו בפניו. ', 6 );
		$this->search->hits   = array(
			array( 'chunk_id' => 11, 'doc_id' => 1, 'score' => 0.91234567 ),
			array( 'chunk_id' => 12, 'doc_id' => 2, 'score' => 0.8 ),
			array( 'chunk_id' => 13, 'doc_id' => 1, 'score' => 0.7 ),
		);
		$this->search->chunks = array(
			11 => array( 'id' => 11, 'doc_id' => 1, 'chunk_index' => 0, 'title' => 'פסק דין א', 'text' => $filler . "לפיכך הוחלט על החזר האגרה למבקש במלואה.\n" . $filler ),
			12 => array( 'id' => 12, 'doc_id' => 2, 'chunk_index' => 0, 'title' => 'החלטה ב', 'text' => 'הבקשה לפטור מאגרה נדחתה.' ),
			13 => array( 'id' => 13, 'doc_id' => 1, 'chunk_index' => 1, 'title' => 'פסק דין א', 'text' => 'המשך פסק הדין.' ),
			99 => array( 'id' => 99, 'doc_id' => 3, 'chunk_index' => 0, 'title' => 'אחר', 'text' => 'SECRET-OTHER-CHUNK' ),
		);
	}

	private function answerer(): HDS_Answerer {
		return new HDS_Answerer( $this->client, $this->search );
	}

	public function test_prompt_contains_only_the_fragments_and_the_rules(): void {
		$result = $this->answerer()->ask( self::QUESTION );
		$this->assertIsArray( $result );

		$this->assertCount( 1, $this->client->embed_calls );
		$this->assertSame( array( self::QUESTION ), $this->client->embed_calls[0]['texts'] );
		$this->assertSame( 'text-embedding-3-small', $this->client->embed_calls[0]['model'] );
		$this->assertSame( array( array( array( 1.0, 0.0, 0.0 ), 5 ) ), $this->search->search_calls );

		$this->assertCount( 1, $this->client->chat_calls );
		$call = $this->client->chat_calls[0];
		$this->assertSame( 'gpt-4o-mini', $call['model'] );
		$this->assertSame( 0.1, $call['params']['temperature'] );
		$this->assertSame( 350, $call['params']['max_tokens'] );
		$this->assertCount( 2, $call['messages'] );

		list( $system, $user ) = $call['messages'];
		$this->assertSame( 'system', $system['role'] );
		$this->assertStringContainsString( 'Hebrew', $system['content'] );
		$this->assertStringContainsString( 'ONLY', $system['content'] );
		$this->assertStringContainsString( 'exactly NO_ANSWER', $system['content'] );
		$this->assertStringContainsString( 'Never invent', $system['content'] );
		$this->assertStringContainsString( 'not legal advice', $system['content'] );
		$this->assertStringContainsString( 'untrusted data', $system['content'] );
		$this->assertSame( HDS_Answerer::system_prompt(), $system['content'] );

		$this->assertSame( 'user', $user['role'] );
		$content = $user['content'];
		$this->assertStringStartsWith( 'שאלה: ' . self::QUESTION . "\n", $content );
		$this->assertStringContainsString( "[1] מסמך: פסק דין א\nבית המשפט", $content );
		$this->assertStringContainsString( "[2] מסמך: החלטה ב\nהבקשה לפטור מאגרה נדחתה.", $content );
		$this->assertStringContainsString( "[3] מסמך: פסק דין א\nהמשך פסק הדין.", $content );
		$this->assertStringNotContainsString( '[4]', $content );
		$this->assertStringNotContainsString( 'SECRET-OTHER-CHUNK', $content );
		$this->assertLessThan( strpos( $content, '[2]' ), strpos( $content, '[1]' ) );
		// The e2e OpenAI mock reads the first fragment this way.
		$this->assertSame( 1, preg_match( '/\[1\][^\n]*\n(.+)/u', $content ) );

		$this->assertSame( $this->client->reply, $result['answer'] );
		$this->assertFalse( $result['cached'] );
		$this->assertIsBool( $result['demo'] );
	}

	public function test_sources_are_one_per_document_in_rank_order_with_snippets(): void {
		$result = $this->answerer()->ask( self::QUESTION );

		$this->assertSame( array( 1, 2 ), array_column( $result['sources'], 'doc_id' ) );
		$this->assertSame( array( 'פסק דין א', 'החלטה ב' ), array_column( $result['sources'], 'title' ) );
		$this->assertSame( 0.9123, $result['sources'][0]['score'] );
		$this->assertSame( 0.8, $result['sources'][1]['score'] );

		$snippet = $result['sources'][0]['snippet'];
		$this->assertStringContainsString( 'החזר האגרה', $snippet );
		$this->assertStringStartsWith( '…', $snippet );
		$this->assertStringEndsWith( '…', $snippet );
		$this->assertLessThanOrEqual( HDS_Answerer::SNIPPET_LENGTH + 2, mb_strlen( $snippet ) );
		$this->assertGreaterThan( 200, mb_strlen( $snippet ) );
		$this->assertStringNotContainsString( "\n", $snippet );

		// Short text: whole, without ellipses.
		$this->assertSame( 'הבקשה לפטור מאגרה נדחתה.', $result['sources'][1]['snippet'] );

		$this->assertSame( array( 'הוחלט', 'החזר', 'האגרה' ), $result['terms'] );
	}

	public function test_snippet_cuts_on_word_boundaries_around_the_first_match(): void {
		$words = array();
		for ( $i = 0; $i < 120; $i++ ) {
			$words[] = 'מילה' . $i;
		}
		$words[60] = 'והאגרה';
		$text      = implode( ' ', $words );

		$snippet = HDS_Answerer::snippet( $text, array( 'אגרה' ) );
		$this->assertStringContainsString( 'והאגרה', $snippet, 'One-letter prefixes are allowed before a term.' );
		$this->assertStringStartsWith( '…מילה', $snippet );
		$this->assertStringEndsWith( '…', $snippet );
		$inner = trim( $snippet, '…' );
		foreach ( explode( ' ', $inner ) as $word ) {
			$this->assertContains( $word, $words, 'No word is cut.' );
		}
		$this->assertLessThan( 60, mb_strpos( $snippet, 'והאגרה' ) + 1, 'Some context before the match.' );

		// A term with a prefix also matches the bare word.
		$this->assertStringContainsString( 'והאגרה', HDS_Answerer::snippet( $text, array( 'האגרה' ) ) );
		$this->assertStringContainsString( 'מילה110', HDS_Answerer::snippet( $text, array( 'מילה110' ) ) );

		// Terms only match at word starts.
		$this->assertStringStartsWith( 'מילה0 ', HDS_Answerer::snippet( $text, array( 'לה60' ) ) );

		// No match: the beginning of the text.
		$none = HDS_Answerer::snippet( $text, array( 'שאינו' ) );
		$this->assertStringStartsWith( 'מילה0 מילה1 ', $none );
		$this->assertStringEndsWith( '…', $none );

		// Match near the end: the snippet ends with the text.
		$end = HDS_Answerer::snippet( $text, array( 'מילה119' ) );
		$this->assertStringEndsWith( 'מילה119', $end );
		$this->assertStringStartsWith( '…', $end );

		// Case-insensitive for Latin text, whitespace collapsed.
		$latin = str_repeat( 'lorem ipsum dolor ', 20 ) . "The Court\n\nFee was returned. " . str_repeat( 'sit amet ', 30 );
		$this->assertStringContainsString( 'The Court Fee was returned.', HDS_Answerer::snippet( $latin, array( 'fee' ) ) );

		// Short text is returned whole.
		$this->assertSame( 'קצר מאוד', HDS_Answerer::snippet( "  קצר\n מאוד ", array( 'קצר' ) ) );
	}

	public function test_terms_drop_short_tokens_and_stopwords(): void {
		$this->assertSame( array( 'הוחלט', 'החזר', 'האגרה' ), HDS_Answerer::terms( HDS_Normalizer::normalize_query( self::QUESTION ) ) );
		$this->assertSame( array( 'court', 'fee' ), HDS_Answerer::terms( HDS_Normalizer::normalize_query( 'What is the Court fee? court' ) ) );
		$this->assertSame( array( 'גד', 'עו"ד' ), HDS_Answerer::terms( 'א ב גד עו"ד' ) );
		$this->assertSame( array(), HDS_Answerer::terms( 'מה של את' ) );
	}

	public function test_cached_answer_avoids_api_calls(): void {
		$first = $this->answerer()->ask( self::QUESTION );
		$this->assertSame( 2, $this->client->calls() );

		$second = $this->answerer()->ask( '  מָה הוחלט   לגבי החזר האגרה?  ' ); // Nikud and spaces normalize away.
		$this->assertTrue( $second['cached'] );
		$this->assertSame( 2, $this->client->calls(), 'Served from the cache.' );
		$this->assertSame( $first['answer'], $second['answer'] );
		$this->assertSame( $first['sources'], $second['sources'] );
		$this->assertSame( $first['terms'], $second['terms'] );

		$keys = preg_grep( '/^hds_ans_[0-9a-f]{32}$/', array_keys( $GLOBALS['hds_test']['transients'] ) );
		$this->assertCount( 1, $keys );
		list( , $expires ) = $GLOBALS['hds_test']['transients'][ reset( $keys ) ];
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $expires, 5 );

		// Index change (new version) or another model: not cached.
		HDS_Vector_Store::bump_version();
		$this->assertFalse( $this->answerer()->ask( self::QUESTION )['cached'] );
		HDS_Settings::update( array( 'chat_model' => 'gpt-4o' ) );
		$this->assertFalse( $this->answerer()->ask( self::QUESTION )['cached'] );
		$this->assertSame( 6, $this->client->calls() );
	}

	public function test_query_length_errors(): void {
		foreach ( array( '', ' ', 'א', "\u{05B8}א\u{05B8}" ) as $query ) {
			$error = $this->answerer()->ask( $query );
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertSame( 'hds_query_short', $error->get_error_code() );
			$this->assertSame( 400, $error->get_error_data()['status'] );
			$this->assertSame( 'נא להקליד לפחות 2 תווים.', $error->get_error_message() );
		}

		$error = $this->answerer()->ask( str_repeat( 'א', HDS_Answerer::MAX_LEN + 1 ) );
		$this->assertSame( 'hds_query_long', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( 'השאלה ארוכה מדי.', $error->get_error_message() );
		$this->assertSame( 0, $this->client->calls() );

		$this->assertIsArray( $this->answerer()->ask( 'אב' ) );
		$this->assertIsArray( $this->answerer()->ask( str_repeat( 'א', HDS_Answerer::MAX_LEN ) ) );
	}

	public function test_not_configured_without_api_key(): void {
		if ( defined( 'HDS_OPENAI_KEY' ) && '' !== trim( (string) constant( 'HDS_OPENAI_KEY' ) ) ) {
			$this->markTestSkipped( 'HDS_OPENAI_KEY is defined.' );
		}
		$error = ( new HDS_Answerer( null, $this->search ) )->ask( self::QUESTION );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'hds_not_configured', $error->get_error_code() );
		$this->assertSame( 503, $error->get_error_data()['status'] );
		$this->assertSame( 'החיפוש אינו זמין כרגע.', $error->get_error_message() );
		$this->assertSame( array(), $this->search->search_calls );

		// A client reporting a missing key maps to the same error.
		$this->client->embed_error = new HDS_OpenAI_Exception( HDS_OpenAI_Exception::NO_KEY );
		$this->assertSame( 'hds_not_configured', $this->answerer()->ask( self::QUESTION )->get_error_code() );
	}

	public function test_no_index_error_without_api_calls(): void {
		$this->search->matrix = array(
			'dims' => 0,
			'ids'  => array(),
			'docs' => array(),
			'blob' => '',
		);
		$error = $this->answerer()->ask( self::QUESTION );
		$this->assertSame( 'hds_no_index', $error->get_error_code() );
		$this->assertSame( 503, $error->get_error_data()['status'] );
		$this->assertSame( 'עדיין אין מסמכים זמינים לחיפוש.', $error->get_error_message() );
		$this->assertSame( 0, $this->client->calls() );
	}

	public function test_openai_error_is_generic_and_not_cached(): void {
		$this->client->chat_error = new HDS_OpenAI_Exception( HDS_OpenAI_Exception::INVALID_KEY, 'OpenAI rejected the API key sk-secret-123', 401 );
		$error                    = $this->answerer()->ask( self::QUESTION );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'hds_openai_error', $error->get_error_code() );
		$this->assertSame(
			array(
				'status' => 503,
				'reason' => 'invalid_key',
			),
			$error->get_error_data()
		);
		$message = $error->get_error_message();
		$this->assertSame( 'אירעה שגיאה. נסו שוב מאוחר יותר.', $message );
		$this->assertStringNotContainsString( 'OpenAI', $message );
		$this->assertStringNotContainsString( 'sk-', $message );
		$this->assertStringNotContainsString( 'sk-secret', (string) wp_json_encode( $error ) );
		$this->assertSame( array(), preg_grep( '/^hds_ans_/', array_keys( $GLOBALS['hds_test']['transients'] ) ), 'Errors are not cached.' );

		// Embedding failures too; the next request calls the API again.
		$this->client->chat_error  = null;
		$this->client->embed_error = new HDS_OpenAI_Exception( HDS_OpenAI_Exception::RATE_LIMITED );
		$this->assertSame( 'rate_limited', $this->answerer()->ask( self::QUESTION )->get_error_data()['reason'] );

		$this->client->embed_error = null;
		$calls                     = $this->client->calls();
		$this->assertIsArray( $this->answerer()->ask( self::QUESTION ) );
		$this->assertSame( $calls + 2, $this->client->calls() );

		// An empty embedding is a bad response.
		$this->client->vector = array();
		$this->assertSame( 'bad_response', $this->answerer()->ask( 'שאלה אחרת' )->get_error_data()['reason'] );
	}

	public function test_no_answer_reply_has_no_sources(): void {
		foreach ( array( 'NO_ANSWER', " NO_ANSWER.\n", '', HDS_Answerer::NO_ANSWER ) as $i => $reply ) {
			$this->client->reply = $reply;
			$result              = $this->answerer()->ask( self::QUESTION . ' ' . $i );
			$this->assertSame( HDS_Answerer::NO_ANSWER, $result['answer'] );
			$this->assertSame( array(), $result['sources'] );
			$this->assertNotEmpty( $result['terms'] );
		}
	}

	public function test_no_hits_answer_without_chat(): void {
		$this->search->hits = array();
		$result             = $this->answerer()->ask( self::QUESTION );
		$this->assertSame( HDS_Answerer::NO_ANSWER, $result['answer'] );
		$this->assertSame( array(), $result['sources'] );
		$this->assertCount( 1, $this->client->embed_calls );
		$this->assertCount( 0, $this->client->chat_calls );
	}

	public function test_answer_markdown_is_removed_and_missing_chunks_skipped(): void {
		$this->client->reply  = "## כותרת\n**הוחלט** על __החזר__ האגרה.";
		$this->search->hits[] = array( 'chunk_id' => 777, 'doc_id' => 9, 'score' => 0.6 );
		$result               = $this->answerer()->ask( self::QUESTION );
		$this->assertSame( "כותרת\nהוחלט על החזר האגרה.", $result['answer'] );
		$this->assertNotContains( 9, array_column( $result['sources'], 'doc_id' ) );
	}

	public function test_top_n_from_settings(): void {
		HDS_Settings::update( array( 'top_n' => 2 ) );
		$this->answerer()->ask( self::QUESTION );
		$this->assertSame( 2, $this->search->search_calls[0][1] );
		$this->assertStringNotContainsString( '[3]', $this->client->chat_calls[0]['messages'][1]['content'] );
	}
}
