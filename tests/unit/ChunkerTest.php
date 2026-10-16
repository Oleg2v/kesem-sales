<?php
/**
 * Tests for HDS_Chunker.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Chunker tests.
 */
class ChunkerTest extends TestCase {

	protected function setUp(): void {
		hds_test_reset();
	}

	/**
	 * Normalized long reference document (≈44k characters).
	 *
	 * @return string
	 */
	private function reference_text(): string {
		$raw = file_get_contents( HDS_FIXTURES_DIR . '/reference/ARAR-25-09-8059.txt' );
		$this->assertIsString( $raw );
		return HDS_Normalizer::normalize( $raw );
	}

	/**
	 * Check every structural invariant of a chunking of $text. $text must use
	 * canonical separators (single spaces, "\n", "\n\n") so that every chunk is
	 * an exact substring.
	 *
	 * @param string   $text           Input text.
	 * @param string[] $chunks         Output of split().
	 * @param int      $size           Chunk size.
	 * @param int      $overlap        Overlap.
	 * @param bool     $expect_overlap Whether every pair of chunks must overlap.
	 * @return void
	 */
	private function assert_invariants( string $text, array $chunks, int $size, int $overlap, bool $expect_overlap ): void {
		$this->assertNotEmpty( $chunks );
		$this->assertSame( array_keys( $chunks ), range( 0, count( $chunks ) - 1 ), 'List must be 0-indexed.' );

		$longest_word = 0;
		foreach ( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			$longest_word = max( $longest_word, mb_strlen( $word, 'UTF-8' ) );
		}

		$prev_start   = -1;
		$prev_end     = 0;
		$rebuilt      = '';
		$text_bytes   = strlen( $text );
		$is_ws_or_end = static function ( string $text, int $byte ) use ( $text_bytes ): bool {
			return $byte < 0 || $byte >= $text_bytes || ctype_space( $text[ $byte ] );
		};

		foreach ( $chunks as $i => $chunk ) {
			$this->assertIsString( $chunk );
			$this->assertNotSame( '', $chunk, "Chunk $i is empty." );
			$this->assertSame( trim( $chunk ), $chunk, "Chunk $i has surrounding whitespace." );
			$this->assertLessThanOrEqual( $size, mb_strlen( $chunk, 'UTF-8' ), "Chunk $i is longer than the size." );

			$start = strpos( $text, $chunk, $prev_start + 1 );
			$this->assertNotFalse( $start, "Chunk $i is not a substring of the input (after the previous chunk)." );
			$end = $start + strlen( $chunk );

			// Word boundaries: whitespace (or text edge) right before and after.
			$this->assertTrue( 0 === $start || $is_ws_or_end( $text, $start - 1 ), "Chunk $i starts mid-word." );
			$this->assertTrue( $is_ws_or_end( $text, $end ), "Chunk $i ends mid-word." );

			if ( 0 === $i ) {
				$this->assertSame( 0, $start, 'First chunk must start at the beginning.' );
				$rebuilt = $chunk;
			} else {
				$this->assertGreaterThan( $prev_start, $start );
				if ( $expect_overlap ) {
					$this->assertLessThan( $prev_end, $start, "Chunk $i does not overlap chunk " . ( $i - 1 ) . '.' );
				}
				if ( $start < $prev_end ) {
					$shared       = substr( $text, $start, $prev_end - $start );
					$shared_chars = mb_strlen( $shared, 'UTF-8' );
					// Suffix of the previous chunk == prefix of this chunk.
					$this->assertSame( $shared, substr( $chunks[ $i - 1 ], -strlen( $shared ) ) );
					$this->assertSame( 0, strpos( $chunk, $shared ) );
					$this->assertLessThanOrEqual( $overlap + $longest_word, $shared_chars, "Overlap before chunk $i too long." );
					$this->assertLessThan( mb_strlen( $chunks[ $i - 1 ], 'UTF-8' ), $shared_chars, 'Overlap is the whole previous chunk.' );
					$this->assertGreaterThan( strlen( $shared ), strlen( $chunk ), "Chunk $i adds nothing new." );
					$rebuilt .= substr( $chunk, strlen( $shared ) );
				} else {
					// No overlap: only whitespace between the chunks.
					$gap = substr( $text, $prev_end, $start - $prev_end );
					$this->assertSame( '', trim( $gap ), "Text lost between chunks $i and " . ( $i - 1 ) . '.' );
					$rebuilt .= $gap . $chunk;
				}
			}
			$prev_start = $start;
			$prev_end   = $end;
		}

		$this->assertSame( $text_bytes, $prev_end, 'Last chunk must reach the end of the text.' );
		$this->assertSame( $text, $rebuilt, 'Chunks without their overlaps must rebuild the input exactly.' );
	}

	public function test_constants(): void {
		$this->assertSame( 800, HDS_Chunker::SIZE );
		$this->assertSame( 150, HDS_Chunker::OVERLAP );
	}

	public function test_empty_and_whitespace_input(): void {
		$chunker = new HDS_Chunker();
		$this->assertSame( array(), $chunker->split( '' ) );
		$this->assertSame( array(), $chunker->split( "  \n\n\t \r\n " ) );
		$this->assertSame( array(), $chunker->split( "\u{00A0}\u{2003}\n" ) );
	}

	public function test_short_text_is_one_chunk(): void {
		$chunker = new HDS_Chunker();
		$this->assertSame( array( 'שלום עולם.' ), $chunker->split( "  שלום עולם.\n" ) );

		$raw  = file_get_contents( HDS_FIXTURES_DIR . '/reference/SH-22-06-57879-590.txt' );
		$text = HDS_Normalizer::normalize( (string) $raw );
		$this->assertLessThan( 800, mb_strlen( $text ) );
		$this->assertSame( array( $text ), $chunker->split( $text ) );
	}

	public function test_reference_document(): void {
		$text = $this->reference_text();
		$this->assertGreaterThan( 40000, mb_strlen( $text, 'UTF-8' ) );

		$chunker = new HDS_Chunker();
		$t0      = microtime( true );
		$chunks  = $chunker->split( $text );
		$elapsed = microtime( true ) - $t0;

		$this->assertLessThan( 0.5, $elapsed, 'Chunking 44k characters must take < 0.5 s.' );
		$this->assert_invariants( $text, $chunks, 800, 150, true );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 1000, mb_strlen( $chunk, 'UTF-8' ) );
		}

		// Chunks are reasonably full: ~44k / (800 - 150) ≈ 68 chunks.
		$count = count( $chunks );
		$this->assertGreaterThan( 55, $count );
		$this->assertLessThan( 90, $count );
		$lengths = array_map( 'mb_strlen', array_slice( $chunks, 0, -1 ) );
		$this->assertGreaterThan( 600, array_sum( $lengths ) / count( $lengths ), 'Average chunk length too small.' );
		$this->assertGreaterThanOrEqual( 400, min( $lengths ), 'A non-final chunk is less than half full.' );

		// Deterministic.
		$this->assertSame( $chunks, $chunker->split( $text ) );
		$this->assertSame( $chunks, ( new HDS_Chunker() )->split( $text ) );
	}

	public function test_overlap_is_about_the_configured_size(): void {
		$text   = $this->reference_text();
		$chunks = ( new HDS_Chunker() )->split( $text );
		$total  = 0;
		$pairs  = 0;
		$pos    = -1;
		$ends   = array();
		foreach ( $chunks as $i => $chunk ) {
			$pos      = strpos( $text, $chunk, $pos + 1 );
			$ends[$i] = $pos + strlen( $chunk );
			if ( $i > 0 ) {
				$total += mb_strlen( substr( $text, $pos, $ends[ $i - 1 ] - $pos ), 'UTF-8' );
				++$pairs;
			}
		}
		$average = $total / $pairs;
		$this->assertGreaterThan( 90, $average );
		$this->assertLessThanOrEqual( 150, $average );
	}

	public function test_prefers_paragraph_breaks(): void {
		// Paragraphs of ~300 characters separated by blank lines.
		$sentence   = 'בית המשפט קבע כי הוועדה המקומית פעלה כדין בעניין זה. ';
		$paragraphs = array();
		for ( $p = 0; $p < 12; $p++ ) {
			$paragraphs[] = 'פסקה ' . ( $p + 1 ) . ': ' . trim( str_repeat( $sentence, 5 ) );
		}
		$text   = implode( "\n\n", $paragraphs );
		$chunks = ( new HDS_Chunker() )->split( $text );
		$this->assert_invariants( $text, $chunks, 800, 150, true );

		$pos = -1;
		foreach ( array_slice( $chunks, 0, -1 ) as $chunk ) {
			$pos = strpos( $text, $chunk, $pos + 1 );
			$end = $pos + strlen( $chunk );
			$this->assertSame( "\n\n", substr( $text, $end, 2 ), 'Non-final chunks should end at a paragraph end.' );
		}
	}

	public function test_hebrew_sentence_ends_including_sof_pasuq(): void {
		$sentences = array(
			'בראשית ברא אלוהים את השמים ואת הארץ׃',
			'והארץ הייתה תוהו ובוהו וחושך על פני תהום׃',
			'ויאמר אלוהים יהי אור ויהי אור׃',
			'האם יש עוד שאלות בעניין?',
			'הערר נדחה!',
			'הצדדים טענו כדלקמן;',
			'סוף דבר.',
		);
		$text = implode( ' ', array_merge( $sentences, $sentences, $sentences, $sentences ) );

		// Every sentence (≤ 41 characters) fits in the overlap budget.
		$chunker = new HDS_Chunker( 150, 60 );
		$chunks  = $chunker->split( $text );
		$this->assert_invariants( $text, $chunks, 150, 60, true );

		foreach ( array_slice( $chunks, 0, -1 ) as $i => $chunk ) {
			$this->assertMatchesRegularExpression( '/[.!?;:\x{05C3}]$/u', $chunk, "Chunk $i should end at a sentence end." );
		}
		// Overlaps are whole sentences: every chunk after the first starts a sentence.
		$starts = array_map(
			static function ( $s ) {
				return explode( ' ', $s )[0];
			},
			$sentences
		);
		foreach ( array_slice( $chunks, 1 ) as $i => $chunk ) {
			$this->assertContains( explode( ' ', $chunk )[0], $starts, 'Chunk ' . ( $i + 1 ) . ' should start a sentence.' );
		}
	}

	public function test_full_stop_preferred_over_colon(): void {
		// '.' at ~60 % of the window, a "(להלן: ...)" colon at ~90 %.
		$text   = 'הוועדה המקומית דנה בבקשה והחליטה לדחות את ההתנגדות במלואה. '
			. 'העוררים הגישו ערר (להלן: "הערר") ובו טענו טענות רבות ושונות נגד ההחלטה ונגד השומה המכרעת.';
		$chunks = ( new HDS_Chunker( 100, 30 ) )->split( $text );
		$this->assert_invariants( $text, $chunks, 100, 30, false );
		$this->assertStringEndsWith( 'במלואה.', $chunks[0] );
	}

	public function test_line_breaks_are_sentence_breaks_and_are_kept(): void {
		$lines = array();
		for ( $i = 1; $i <= 40; $i++ ) {
			$lines[] = 'שורה מספר ' . $i . ' ללא סימן פיסוק בסופה';
		}
		$text   = implode( "\n", $lines );
		$chunks = ( new HDS_Chunker( 200, 50 ) )->split( $text );
		$this->assert_invariants( $text, $chunks, 200, 50, true );
		foreach ( $chunks as $chunk ) {
			$this->assertSame( 0, strpos( $chunk, 'שורה מספר' ), 'Chunks should start at a line start.' );
			$this->assertStringEndsWith( 'בסופה', $chunk, 'Chunks should end at a line end.' );
		}
		$this->assertStringContainsString( "\n", $chunks[0], 'Line breaks inside a chunk are preserved.' );
	}

	public function test_list_markers_stay_with_their_text(): void {
		$items = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$items[] = $i . '. טענה מספר ' . $i . ' של העוררים בעניין השומה המכרעת והיטל ההשבחה';
		}
		$text   = implode( "\n", $items );
		$chunks = ( new HDS_Chunker( 300, 80 ) )->split( $text );
		$this->assert_invariants( $text, $chunks, 300, 80, true );
		foreach ( $chunks as $chunk ) {
			$this->assertDoesNotMatchRegularExpression( '/(^|\n)\d+\.$/u', $chunk, 'A chunk must not end with a bare list marker.' );
		}
	}

	public function test_text_without_any_punctuation_splits_on_words(): void {
		$words = array();
		for ( $i = 0; $i < 600; $i++ ) {
			$words[] = 'מילה' . $i;
		}
		$text   = implode( ' ', $words );
		$chunks = ( new HDS_Chunker() )->split( $text );
		$this->assert_invariants( $text, $chunks, 800, 150, true );
		foreach ( array_slice( $chunks, 0, -1 ) as $chunk ) {
			$this->assertGreaterThan( 780, mb_strlen( $chunk ), 'Word-split chunks should be nearly full.' );
		}
	}

	public function test_overlong_word_is_hard_split_as_last_resort(): void {
		$long   = str_repeat( 'אבגדהוזחטי', 250 ); // 2500 characters, no spaces.
		$text   = 'לפני המילה הארוכה ' . $long . ' ואחריה עוד טקסט קצר.';
		$chunks = ( new HDS_Chunker() )->split( $text );

		$this->assertNotEmpty( $chunks );
		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 800, mb_strlen( $chunk, 'UTF-8' ) );
			$this->assertTrue( mb_check_encoding( $chunk, 'UTF-8' ) );
		}
		// Every character of the long word is kept, in order.
		$this->assertStringContainsString( 'ואחריה עוד טקסט קצר.', $chunks[ count( $chunks ) - 1 ] );
		$pieces = '';
		foreach ( $chunks as $chunk ) {
			if ( preg_match( '/[\x{05D0}-\x{05D9}]{10,}/u', $chunk, $m ) ) {
				$pieces .= $m[0];
			}
		}
		$this->assertSame( $long, $pieces, 'The hard-split pieces must rebuild the long word without duplication.' );

		// A word that is only long is split into full pieces.
		$only = ( new HDS_Chunker( 100, 20 ) )->split( str_repeat( 'א', 250 ) );
		$this->assertSame( array( str_repeat( 'א', 100 ), str_repeat( 'א', 100 ), str_repeat( 'א', 50 ) ), $only );
	}

	public function test_zero_overlap_concatenates_to_input(): void {
		$text   = $this->reference_text();
		$chunks = ( new HDS_Chunker( 500, 0 ) )->split( $text );
		$this->assert_invariants( $text, $chunks, 500, 0, false );

		$words_in  = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$words_out = preg_split( '/\s+/u', implode( ' ', $chunks ), -1, PREG_SPLIT_NO_EMPTY );
		$this->assertSame( $words_in, $words_out );
	}

	public function test_overlap_is_clamped_to_half_the_size(): void {
		$text   = $this->reference_text();
		$chunks = ( new HDS_Chunker( 200, 500 ) )->split( $text );
		$this->assert_invariants( $text, $chunks, 200, 100, true );
	}

	public function test_unnormalized_whitespace_and_crlf(): void {
		$text   = "  פסקה ראשונה.\r\n\r\n\r\nפסקה   שנייה\tעם רווחים.\rשורה שלישית.  ";
		$chunks = ( new HDS_Chunker() )->split( $text );
		$this->assertSame( array( "פסקה ראשונה.\n\nפסקה שנייה עם רווחים.\nשורה שלישית." ), $chunks );
	}

	public function test_invalid_utf8_does_not_break(): void {
		$text   = "טקסט \xFF\xFE תקין " . str_repeat( 'מילה ', 400 );
		$chunks = ( new HDS_Chunker() )->split( $text );
		$this->assertNotEmpty( $chunks );
		foreach ( $chunks as $chunk ) {
			$this->assertTrue( mb_check_encoding( $chunk, 'UTF-8' ) );
			$this->assertLessThanOrEqual( 800, mb_strlen( $chunk, 'UTF-8' ) );
		}
	}

	public function test_invalid_size_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new HDS_Chunker( 0 );
	}

	public function test_large_text_across_blocks(): void {
		// ≈ 440k characters / 780 KB: many tokenizer blocks and buffer compactions.
		$text   = implode( "\n\n", array_fill( 0, 10, $this->reference_text() ) );
		$t0     = microtime( true );
		$chunks = ( new HDS_Chunker() )->split( $text );
		$this->assertLessThan( 3.0, microtime( true ) - $t0 );
		$this->assert_invariants( $text, $chunks, 800, 150, true );
	}

	public function test_whitespace_runs_are_canonicalized_across_blocks(): void {
		mt_srand( 777 );
		$gaps      = array( ' ', ' ', ' ', "  ", "\t", " \u{00A0} ", "\n", " \n ", "\n\n", "\n \n", "\n\n\n\n", "\u{2003}\n" );
		$messy     = '';
		$canonical = '';
		$n         = 0;
		while ( strlen( $messy ) < 300000 ) {
			$word = 'מילה' . ( $n % 97 ) . ( 0 === $n % 13 ? '.' : '' );
			$gap  = $gaps[ mt_rand( 0, count( $gaps ) - 1 ) ];
			$nl   = substr_count( $gap, "\n" );
			$messy     .= ( $n > 0 ? $gap : '' ) . $word;
			$canonical .= ( $n > 0 ? ( $nl >= 2 ? "\n\n" : ( 1 === $nl ? "\n" : ' ' ) ) : '' ) . $word;
			++$n;
		}
		$chunker = new HDS_Chunker();
		$this->assertSame( $chunker->split( $canonical ), $chunker->split( "\u{00A0}\n " . $messy . " \n" ) );
	}

	public function test_memory_stays_bounded_for_huge_text(): void {
		if ( ! function_exists( 'memory_reset_peak_usage' ) ) {
			$this->markTestSkipped( 'memory_reset_peak_usage() needs PHP 8.2+.' );
		}
		$text = str_repeat( $this->reference_text() . "\n\n", 40 ); // ≈ 3 MB.
		$size = strlen( $text );
		gc_collect_cycles();
		memory_reset_peak_usage();
		$before = memory_get_usage();
		$chunks = ( new HDS_Chunker() )->split( $text );
		$peak   = memory_get_peak_usage() - $before;
		$output = 0;
		foreach ( $chunks as $chunk ) {
			$output += strlen( $chunk ) + 64;
		}
		// Working memory: a copy of the input + the output + a bounded window.
		$this->assertLessThan( 2 * $size + $output + 16 * 1024 * 1024, $peak );
	}

	public function test_random_texts_keep_all_invariants(): void {
		mt_srand( 12345 );
		$alphabet = array_merge( preg_split( '//u', 'אבגדהוזחטיכלמנסעפצקרשתךםןףץ', -1, PREG_SPLIT_NO_EMPTY ), range( 'a', 'z' ), range( '0', '9' ) );
		$punct    = array( '', '', '', '', '.', ',', ':', ';', '?', '!', "\u{05C3}", '"', ')' );
		$seps     = array( ' ', ' ', ' ', ' ', ' ', ' ', ' ', "\n", "\n\n" );

		for ( $round = 0; $round < 60; $round++ ) {
			$size    = mt_rand( 60, 900 );
			$overlap = mt_rand( 0, (int) ( $size / 2 ) );
			$n_words = mt_rand( 1, 700 );
			$text    = '';
			for ( $w = 0; $w < $n_words; $w++ ) {
				$len  = mt_rand( 1, 100 ) <= 2 ? mt_rand( 20, 40 ) : mt_rand( 1, 12 );
				$word = '';
				for ( $c = 0; $c < $len; $c++ ) {
					$word .= $alphabet[ mt_rand( 0, count( $alphabet ) - 1 ) ];
				}
				$text .= ( $w > 0 ? $seps[ mt_rand( 0, count( $seps ) - 1 ) ] : '' ) . $word . $punct[ mt_rand( 0, count( $punct ) - 1 ) ];
			}
			$chunks = ( new HDS_Chunker( $size, $overlap ) )->split( $text );
			$this->assert_invariants( $text, $chunks, $size, $overlap, false );
		}
	}
}
