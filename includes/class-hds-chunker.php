<?php
/**
 * Splits normalized document text into overlapping chunks for embedding.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hebrew-aware text chunker.
 *
 * The text is cut into words (runs of non-whitespace). Every gap after a word
 * gets a break level:
 *
 * - 4 paragraph end (blank line),
 * - 3 sentence end: . ! ? … Hebrew sof pasuq, or a line break,
 * - 2 weak sentence end: ; or : (e.g. the colon in "(להלן: ...)"),
 * - 1 plain word boundary,
 * - 0 inside a word (only between the pieces of a word longer than the chunk
 *   size, which is hard-split as a last resort).
 *
 * A chunk is grown word by word up to SIZE characters, then cut at the last
 * paragraph end that keeps it at least half full, else at the last sentence
 * end that does (strong before weak), else after the last whole word that
 * fits. The next chunk
 * starts with roughly OVERLAP characters of whole trailing sentences (or, if
 * no sentence boundary is close enough, whole words) of the previous chunk.
 * Chunks never exceed SIZE characters, never start or end inside a word, and
 * keep the line/paragraph breaks between their words (whitespace runs are
 * collapsed to one space, "\n" or "\n\n").
 *
 * Linear time; deterministic; lengths in characters (mb_*), not bytes. The
 * text is tokenized in blocks and consumed words are dropped, so the memory
 * used besides the input and the output is bounded even for huge documents.
 */
final class HDS_Chunker {

	/**
	 * Target (and maximum) chunk length in characters.
	 */
	const SIZE = 800;

	/**
	 * Approximate overlap between consecutive chunks in characters.
	 */
	const OVERLAP = 150;

	/**
	 * A cut at a paragraph or sentence end must keep the chunk at least this
	 * full (fraction of the size); otherwise the chunk is filled with words.
	 */
	const MIN_FILL = 0.5;

	/**
	 * A sentence-aligned overlap is preferred when it is at least this
	 * fraction of the requested overlap.
	 */
	const MIN_SENTENCE_OVERLAP = 0.5;

	/**
	 * Characters that end a sentence when they end a word: . ! ? …, Hebrew
	 * sof pasuq, Arabic question mark.
	 */
	const SENTENCE_END_PATTERN = '/[.!?\x{2026}\x{05C3}\x{061F}]$/u';

	/**
	 * Characters that end a sentence weakly when they end a word: ; :
	 */
	const CLAUSE_END_PATTERN = '/[;:]$/u';

	/**
	 * Closing quotes/brackets that may follow sentence-ending punctuation.
	 */
	const CLOSING_PATTERN = '/["\'\x{201D}\x{2019}\x{00BB}\x{05F4}\x{05F3})\]}]+$/u';

	/**
	 * Break levels.
	 */
	const BREAK_NONE      = 0;
	const BREAK_WORD      = 1;
	const BREAK_CLAUSE    = 2;
	const BREAK_SENTENCE  = 3;
	const BREAK_PARAGRAPH = 4;

	/**
	 * Bytes of text tokenized at a time.
	 */
	const BLOCK_BYTES = 65536;

	/**
	 * Consumed words are dropped from the buffers once there are this many.
	 */
	const COMPACT_WORDS = 4096;

	/**
	 * ASCII whitespace bytes (block boundaries are placed after them).
	 */
	const ASCII_SPACE = " \t\n\r\x0B\x0C";

	/**
	 * Chunk size in characters.
	 *
	 * @var int
	 */
	private $size;

	/**
	 * Overlap in characters.
	 *
	 * @var int
	 */
	private $overlap;

	/**
	 * Text being split (per split() call).
	 *
	 * @var string
	 */
	private $text = '';

	/**
	 * Byte length of $text.
	 *
	 * @var int
	 */
	private $text_bytes = 0;

	/**
	 * Bytes of $text already tokenized.
	 *
	 * @var int
	 */
	private $offset = 0;

	/**
	 * Absolute index of the first buffered word.
	 *
	 * @var int
	 */
	private $base = 0;

	/**
	 * Number of words tokenized so far (absolute).
	 *
	 * @var int
	 */
	private $total = 0;

	/**
	 * Buffered words.
	 *
	 * @var string[]
	 */
	private $words = array();

	/**
	 * Separator after each buffered word: ' ', "\n", "\n\n" or '' (inside a word).
	 *
	 * @var string[]
	 */
	private $seps = array();

	/**
	 * Break level after each buffered word.
	 *
	 * @var int[]
	 */
	private $levels = array();

	/**
	 * Characters before each buffered word (one extra entry at the end).
	 *
	 * @var int[]
	 */
	private $pos = array();

	/**
	 * Last word seen, waiting for the end of the whitespace after it.
	 *
	 * @var string|null
	 */
	private $open_word = null;

	/**
	 * Whitespace seen after $open_word so far.
	 *
	 * @var string
	 */
	private $open_gap = '';

	/**
	 * Whether the next word starts a line.
	 *
	 * @var bool
	 */
	private $line_start = true;

	/**
	 * Constructor.
	 *
	 * @param int $size    Maximum chunk length in characters (>= 1).
	 * @param int $overlap Overlap in characters; clamped to 0..size/2.
	 * @throws InvalidArgumentException When $size is not positive.
	 */
	public function __construct( int $size = self::SIZE, int $overlap = self::OVERLAP ) {
		if ( $size < 1 ) {
			throw new InvalidArgumentException( 'Chunk size must be positive.' );
		}
		$this->size    = $size;
		$this->overlap = max( 0, min( $overlap, intdiv( $size, 2 ) ) );
	}

	/**
	 * Split text into chunks.
	 *
	 * @param string $text UTF-8 text (normally HDS_Normalizer::normalize() output).
	 * @return string[] Chunks in document order; [] for empty/whitespace-only text.
	 */
	public function split( string $text ): array {
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = HDS_Encoding::scrub_utf8( $text );
		}
		$text = (string) preg_replace( '/^\s+|\s+$/u', '', str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		if ( '' === $text ) {
			return array();
		}

		$this->reset( $text );
		$chunks   = array();
		$min_fill = (int) ceil( $this->size * self::MIN_FILL );
		$start    = 0; // First word of the current chunk.
		$fresh    = 0; // First word not yet in any chunk.

		while ( $this->has( $start ) ) {
			$this->compact( $start );

			// Last word that still fits (a single word or piece always fits).
			$end = $start;
			while ( $this->has( $end + 1 ) && $this->span( $start, $end + 1 ) <= $this->size ) {
				++$end;
			}

			if ( ! $this->has( $end + 1 ) ) {
				$chunks[] = $this->join( $start, $end );
				break;
			}

			$cut      = $this->choose_cut( $start, $fresh, $end, $min_fill );
			$chunks[] = $this->join( $start, $cut );
			$start    = $this->overlap_start( $start, $cut );
			$fresh    = $cut + 1;
		}

		$this->reset( '' );
		return $chunks;
	}

	/**
	 * Reset the per-call state.
	 *
	 * @param string $text Text to split ('' to free memory).
	 * @return void
	 */
	private function reset( string $text ): void {
		$this->text       = $text;
		$this->text_bytes = strlen( $text );
		$this->offset     = 0;
		$this->base       = 0;
		$this->total      = 0;
		$this->words      = array();
		$this->seps       = array();
		$this->levels     = array();
		$this->pos        = array( 0 );
		$this->open_word  = null;
		$this->open_gap   = '';
		$this->line_start = true;
	}

	/**
	 * Whether word $index exists, tokenizing more text when needed.
	 *
	 * @param int $index Absolute word index.
	 * @return bool
	 */
	private function has( int $index ): bool {
		while ( $index >= $this->total && ( $this->offset < $this->text_bytes || null !== $this->open_word ) ) {
			$this->fill();
		}
		return $index < $this->total;
	}

	/**
	 * Tokenize the next block of text.
	 *
	 * Blocks end right after a run of ASCII whitespace, so they never cut a
	 * UTF-8 sequence. A word's separator is only known once the whitespace
	 * after it is complete, so the last word of a block stays open.
	 *
	 * @return void
	 */
	private function fill(): void {
		if ( $this->offset < $this->text_bytes ) {
			$end = $this->offset + self::BLOCK_BYTES;
			if ( $end >= $this->text_bytes ) {
				$end = $this->text_bytes;
			} else {
				$end += strcspn( $this->text, self::ASCII_SPACE, $end );
				$end += strspn( $this->text, self::ASCII_SPACE, min( $end, $this->text_bytes ) );
				$end  = min( $end, $this->text_bytes );
			}
			$block        = substr( $this->text, $this->offset, $end - $this->offset );
			$this->offset = $end;

			$parts = preg_split( '/(\s+)/u', $block, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			foreach ( false === $parts ? array() : $parts as $part ) {
				if ( preg_match( '/^\s/u', $part ) ) {
					$this->open_gap .= $part;
				} elseif ( null === $this->open_word ) {
					$this->open_word = $part;
				} elseif ( '' === $this->open_gap ) {
					$this->open_word .= $part; // Defensive: a word continued across blocks.
				} else {
					$this->add_word( $this->open_word, $this->open_gap );
					$this->open_word = $part;
					$this->open_gap  = '';
				}
			}
		}

		if ( $this->offset >= $this->text_bytes && null !== $this->open_word ) {
			$this->add_word( $this->open_word, '' );
			$this->open_word = null;
			$this->open_gap  = '';
		}
	}

	/**
	 * Append a word (hard-split when longer than the chunk size) to the buffers.
	 *
	 * @param string $word Word.
	 * @param string $gap  Whitespace after it ('' at the end of the text).
	 * @return void
	 */
	private function add_word( string $word, string $gap ): void {
		$newlines = substr_count( $gap, "\n" );
		if ( $newlines >= 2 ) {
			$sep   = "\n\n";
			$level = self::BREAK_PARAGRAPH;
		} elseif ( 1 === $newlines ) {
			$sep   = "\n";
			$level = self::BREAK_SENTENCE;
		} else {
			$sep   = '' === $gap ? '' : ' ';
			$level = $this->sentence_level( $word, $this->line_start );
		}
		$this->line_start = $newlines > 0;

		$length = mb_strlen( $word, 'UTF-8' );
		if ( $length <= $this->size ) {
			$this->push( $word, $length, $sep, $level );
			return;
		}
		// Last resort: hard-split an over-long "word" (URL, garbage) into pieces.
		for ( $offset = 0; $offset < $length; $offset += $this->size ) {
			$piece   = mb_substr( $word, $offset, $this->size, 'UTF-8' );
			$is_last = $offset + $this->size >= $length;
			$this->push( $piece, mb_strlen( $piece, 'UTF-8' ), $is_last ? $sep : '', $is_last ? $level : self::BREAK_NONE );
		}
	}

	/**
	 * Append one buffered word.
	 *
	 * @param string $word   Word or piece.
	 * @param int    $length Its length in characters.
	 * @param string $sep    Separator after it.
	 * @param int    $level  Break level after it.
	 * @return void
	 */
	private function push( string $word, int $length, string $sep, int $level ): void {
		$this->words[]  = $word;
		$this->seps[]   = $sep;
		$this->levels[] = $level;
		$this->pos[]    = $this->pos[ count( $this->pos ) - 1 ] + $length + strlen( $sep );
		++$this->total;
	}

	/**
	 * Drop buffered words before $start once enough have accumulated.
	 *
	 * @param int $start Absolute index of the first word still needed.
	 * @return void
	 */
	private function compact( int $start ): void {
		$drop = $start - $this->base;
		if ( $drop < self::COMPACT_WORDS ) {
			return;
		}
		$this->words  = array_slice( $this->words, $drop );
		$this->seps   = array_slice( $this->seps, $drop );
		$this->levels = array_slice( $this->levels, $drop );
		$this->pos    = array_slice( $this->pos, $drop );
		$this->base   = $start;
	}

	/**
	 * Length in characters of words $a..$b joined with their separators.
	 *
	 * @param int $a First word (absolute index).
	 * @param int $b Last word (absolute index), already tokenized.
	 * @return int
	 */
	private function span( int $a, int $b ): int {
		$b -= $this->base;
		return $this->pos[ $b + 1 ] - $this->pos[ $a - $this->base ] - strlen( $this->seps[ $b ] );
	}

	/**
	 * Break level after word $i.
	 *
	 * @param int $i Absolute word index.
	 * @return int
	 */
	private function level( int $i ): int {
		return $this->levels[ $i - $this->base ];
	}

	/**
	 * Break level after a word followed by a space: sentence, clause or word.
	 *
	 * @param string $word       Word (no whitespace).
	 * @param bool   $line_start Whether the word starts a line.
	 * @return int
	 */
	private function sentence_level( string $word, bool $line_start ): int {
		// A short enumerator at the start of a line ("2.", "א.", "(ב).") is a list
		// marker, not a sentence: do not break between it and its text.
		if ( $line_start && mb_strlen( $word, 'UTF-8' ) <= 4 ) {
			return self::BREAK_WORD;
		}
		$core = (string) preg_replace( self::CLOSING_PATTERN, '', $word );
		if ( preg_match( self::SENTENCE_END_PATTERN, $core ) ) {
			return self::BREAK_SENTENCE;
		}
		if ( preg_match( self::CLAUSE_END_PATTERN, $core ) ) {
			return self::BREAK_CLAUSE;
		}
		return self::BREAK_WORD;
	}

	/**
	 * Choose the last word of a chunk that cannot hold the rest of the text.
	 *
	 * @param int $start    First word of the chunk (may be in the overlap).
	 * @param int $fresh    First word not in the previous chunk; the chunk must include it.
	 * @param int $end      Last word that fits (>= $fresh).
	 * @param int $min_fill Minimum length for a paragraph/sentence cut.
	 * @return int
	 */
	private function choose_cut( int $start, int $fresh, int $end, int $min_fill ): int {
		$sentence_cut = -1;
		$clause_cut   = -1;
		for ( $j = $end; $j >= $fresh && $this->span( $start, $j ) >= $min_fill; $j-- ) {
			$level = $this->level( $j );
			if ( self::BREAK_PARAGRAPH === $level ) {
				return $j;
			}
			if ( -1 === $sentence_cut && self::BREAK_SENTENCE === $level ) {
				$sentence_cut = $j;
			} elseif ( -1 === $clause_cut && self::BREAK_CLAUSE === $level ) {
				$clause_cut = $j;
			}
		}
		if ( -1 !== $sentence_cut ) {
			return $sentence_cut;
		}
		if ( -1 !== $clause_cut ) {
			return $clause_cut;
		}
		// Whole words: the last word that fits, unless it is a piece of a hard-split word.
		for ( $j = $end; $j > $fresh; $j-- ) {
			if ( self::BREAK_NONE !== $this->level( $j ) ) {
				return $j;
			}
		}
		return $end;
	}

	/**
	 * First word of the next chunk: whole trailing sentences (or words) of the
	 * previous chunk worth about OVERLAP characters, never the whole chunk.
	 *
	 * @param int $start First word of the previous chunk.
	 * @param int $cut   Last word of the previous chunk (word $cut + 1 exists).
	 * @return int
	 */
	private function overlap_start( int $start, int $cut ): int {
		$next = $cut + 1;
		if ( 0 === $this->overlap || self::BREAK_NONE === $this->level( $cut ) ) {
			return $next; // No overlap, or the cut is inside a hard-split word.
		}

		// Longest whole-word suffix within the overlap budget (never the first word).
		$word_start = $next;
		for ( $k = $cut; $k > $start && $this->span( $k, $cut ) <= $this->overlap; $k-- ) {
			if ( self::BREAK_NONE !== $this->level( $k - 1 ) ) {
				$word_start = $k;
			}
		}
		if ( $word_start > $cut ) {
			return $next; // Even the last word is longer than the overlap.
		}

		// Prefer the longest suffix made of whole sentences, if it is long enough.
		$overlap_start = $word_start;
		$min_sentence  = (int) ceil( $this->overlap * self::MIN_SENTENCE_OVERLAP );
		for ( $k = $word_start; $k <= $cut; $k++ ) {
			if ( $this->level( $k - 1 ) >= self::BREAK_CLAUSE ) {
				if ( $this->span( $k, $cut ) >= $min_sentence ) {
					$overlap_start = $k;
				}
				break;
			}
		}

		// The next chunk must still be able to take at least one new word.
		while ( $overlap_start <= $cut
			&& ( $this->span( $overlap_start, $next ) > $this->size || self::BREAK_NONE === $this->level( $overlap_start - 1 ) ) ) {
			++$overlap_start;
		}
		return $overlap_start > $cut ? $next : $overlap_start;
	}

	/**
	 * Join words $a..$b with their separators.
	 *
	 * @param int $a First word (absolute index).
	 * @param int $b Last word (absolute index).
	 * @return string
	 */
	private function join( int $a, int $b ): string {
		$out = '';
		for ( $i = $a - $this->base, $last = $b - $this->base; $i < $last; $i++ ) {
			$out .= $this->words[ $i ] . $this->seps[ $i ];
		}
		return $out . $this->words[ $b - $this->base ];
	}
}
