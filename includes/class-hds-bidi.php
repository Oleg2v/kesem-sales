<?php
/**
 * Hebrew bidi repair for text extracted from PDFs.
 *
 * Many PDF generators store right-to-left text in VISUAL order: glyphs are
 * written left to right as they appear on the page, so a naive extraction
 * yields reversed Hebrew ("טפשמ תיב" instead of "בית משפט") while numbers
 * and Latin words keep their normal order. This class detects such text and
 * converts it back to logical (reading) order.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visual-to-logical conversion for Hebrew text.
 *
 * The conversion is the inverse of the Unicode Bidirectional Algorithm for
 * a right-to-left paragraph without explicit embeddings: the line is
 * reversed, then every left-to-right run (Latin words, numbers such as
 * 57879-06-22, 12/03/2024, 1.5, 17%) is reversed back so it reads
 * correctly. Paired brackets are mirrored when the generator stored the
 * mirrored glyphs (legacy generators) - this is detected per document.
 */
final class HDS_Bidi {

	/**
	 * Hebrew letters (no points), for statistics.
	 */
	const HEBREW_WORD_PATTERN = '/[\x{05D0}-\x{05EA}]{2,}/u';

	/**
	 * Any right-to-left character (Hebrew block incl. points, Hebrew
	 * presentation forms, Arabic, Syriac, Thaana, NKo).
	 */
	const RTL_PATTERN = '/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

	/**
	 * Hebrew final letters ך ם ן ף ץ.
	 */
	const FINALS = array( "\u{05DA}", "\u{05DD}", "\u{05DF}", "\u{05E3}", "\u{05E5}" );

	/**
	 * Letters that have a final form, in their regular form: כ מ נ פ צ.
	 */
	const NON_FINALS = array( "\u{05DB}", "\u{05DE}", "\u{05E0}", "\u{05E4}", "\u{05E6}" );

	/**
	 * Frequent Hebrew words (legal texts) whose reversal is not a common
	 * word (so not לא/אל, הם/מה). Extra evidence for words without final
	 * letters, e.g. של/לש, את/תא, על/לע.
	 */
	const COMMON_WORDS = array(
		'של', 'את', 'על', 'כי', 'זה', 'זו', 'או', 'הוא', 'היא', 'אין', 'אשר', 'כל', 'גם', 'עם', 'אם',
		'בית', 'משפט', 'פסק', 'דין', 'ניתן', 'ניתנה', 'לפני', 'נגד', 'החלטה', 'תובע', 'נתבע', 'כבוד', 'השופט',
		'השופטת', 'הצדדים', 'בעניין', 'סעיף', 'חוק', 'לעיל', 'להלן', 'ועדה', 'הוועדה', 'בהתאם', 'כאמור',
	);

	/**
	 * Mirrored bracket pairs (Bidi_Mirroring_Glyph for the common ones).
	 */
	const MIRROR = array(
		'('      => ')',
		')'      => '(',
		'['      => ']',
		']'      => '[',
		'{'      => '}',
		'}'      => '{',
		'<'      => '>',
		'>'      => '<',
		"\u{00AB}" => "\u{00BB}",
		"\u{00BB}" => "\u{00AB}",
	);

	/**
	 * Minimum amount of evidence before text is treated as visual.
	 */
	const MIN_EVIDENCE = 3;

	// Character classes used by visual_to_logical().
	const C_RTL     = 1; // Hebrew/Arabic letters and points.
	const C_LATIN   = 2; // Strong left-to-right letters.
	const C_DIGIT   = 3; // Decimal digits.
	const C_NUM_SEP = 4; // Number separators: . , : / - + and similar.
	const C_NUM_TERM = 5; // Number terminators: % ‰ ° # $ ₪ € ...
	const C_SPACE   = 6; // Whitespace.
	const C_OTHER   = 7; // Other neutrals (brackets, quotes, punctuation).

	/**
	 * Count the evidence for visual and for logical order.
	 *
	 * Evidence for LOGICAL order: words ending with a final letter
	 * (ךםןףץ), words starting with the regular form of a letter that has a
	 * final form (כמנפצ), frequent words written normally. Evidence for
	 * VISUAL order: the same patterns mirrored. Lines without Hebrew do not
	 * contribute.
	 *
	 * @param string $text UTF-8 text.
	 * @return array{visual: int, logical: int}
	 */
	public static function order_evidence( string $text ): array {
		$visual  = 0;
		$logical = 0;
		if ( '' === $text || ! preg_match( '/[\x{05D0}-\x{05EA}]/u', $text ) ) {
			return array(
				'visual'  => 0,
				'logical' => 0,
			);
		}

		static $common = null;
		static $reversed_common = null;
		if ( null === $common ) {
			$common          = array_fill_keys( self::COMMON_WORDS, true );
			$reversed_common = array();
			foreach ( self::COMMON_WORDS as $word ) {
				$reversed = self::reverse( $word );
				if ( $reversed !== $word && ! isset( $common[ $reversed ] ) ) {
					$reversed_common[ $reversed ] = true;
				}
			}
		}

		$finals     = array_fill_keys( self::FINALS, true );
		$non_finals = array_fill_keys( self::NON_FINALS, true );

		// Points between letters would split words.
		$text = HDS_Normalizer::remove_nikud( $text );
		if ( ! preg_match_all( self::HEBREW_WORD_PATTERN, $text, $matches ) ) {
			return array(
				'visual'  => 0,
				'logical' => 0,
			);
		}

		foreach ( $matches[0] as $word ) {
			$first = mb_substr( $word, 0, 1, 'UTF-8' );
			$last  = mb_substr( $word, -1, 1, 'UTF-8' );

			if ( isset( $finals[ $last ] ) ) {
				$logical++;
			}
			if ( isset( $finals[ $first ] ) ) {
				$visual++;
			}
			if ( isset( $non_finals[ $first ] ) ) {
				$logical++;
			}
			if ( isset( $non_finals[ $last ] ) ) {
				$visual++;
			}
			if ( isset( $common[ $word ] ) && ! isset( $reversed_common[ $word ] ) ) {
				$logical++;
			} elseif ( isset( $reversed_common[ $word ] ) ) {
				$visual++;
			}
		}

		return array(
			'visual'  => $visual,
			'logical' => $logical,
		);
	}

	/**
	 * Is the Hebrew in this text stored in visual (reversed) order?
	 *
	 * Decided for the whole text; requires a clear margin (more than twice
	 * as much evidence for visual as for logical order, and at least
	 * MIN_EVIDENCE observations), so logical text is never touched by
	 * accident.
	 *
	 * @param string $text UTF-8 text.
	 * @return bool
	 */
	public static function is_visual_order( string $text ): bool {
		$evidence = self::order_evidence( $text );
		return $evidence['visual'] >= self::MIN_EVIDENCE && $evidence['visual'] > 2 * $evidence['logical'];
	}

	/**
	 * Convert one line from visual to logical order.
	 *
	 * Reverses the line by characters, then reverses back every maximal
	 * left-to-right run: Latin words (joined across spaces/punctuation when
	 * both sides are Latin, or Latin and a number), numbers with internal
	 * separators (57879-06-22, 12/03/2024, 1.5, 1,000) and adjacent
	 * terminators (17%, ₪100). Brackets outside those runs are mirrored
	 * when $mirror is true. Spaces are kept as they are.
	 *
	 * @param string $line   One line of text (no "\n").
	 * @param bool   $mirror Mirror ()[]{}<>«» outside left-to-right runs.
	 * @return string
	 */
	public static function visual_to_logical( string $line, bool $mirror = true ): string {
		if ( '' === $line ) {
			return '';
		}
		$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars || ! $chars ) {
			return $line;
		}
		$chars = array_reverse( $chars );
		$count = count( $chars );

		$classes = array();
		foreach ( $chars as $i => $char ) {
			$classes[ $i ] = self::classify( $char );
		}
		// Terminators (%, ₪ ...) touching a digit belong to the number.
		for ( $i = 0; $i < $count; $i++ ) {
			if ( self::C_NUM_TERM === $classes[ $i ]
				&& ( ( $i > 0 && self::C_DIGIT === $classes[ $i - 1 ] ) || ( $i + 1 < $count && self::C_DIGIT === $classes[ $i + 1 ] ) ) ) {
				$classes[ $i ] = self::C_DIGIT;
			}
		}

		// Find the left-to-right runs: start index => end index (inclusive).
		$runs = array();
		$i    = 0;
		while ( $i < $count ) {
			$class = $classes[ $i ];
			if ( self::C_LATIN !== $class && self::C_DIGIT !== $class ) {
				$i++;
				continue;
			}

			// Grow a left-to-right run starting at $i.
			$end = $i; // Inclusive index of the last strong (Latin/digit) char.
			$j   = $i + 1;
			while ( $j < $count ) {
				$next = $classes[ $j ];
				if ( self::C_LATIN === $next || self::C_DIGIT === $next ) {
					$end = $j;
					$j++;
					continue;
				}
				// Neutral stretch [$j, $k).
				$k = $j;
				while ( $k < $count && in_array( $classes[ $k ], array( self::C_NUM_SEP, self::C_NUM_TERM, self::C_SPACE, self::C_OTHER ), true ) ) {
					$k++;
				}
				if ( $k >= $count ) {
					break;
				}
				$before = $classes[ $end ];
				$after  = $classes[ $k ];
				if ( self::C_RTL === $after ) {
					break;
				}
				$length = $k - $j;
				$joins  = false;
				if ( self::C_DIGIT === $before && self::C_DIGIT === $after ) {
					// 57879-06-22, 1.5, 12/03/2024, 1,000: one separator between digits.
					$joins = 1 === $length && self::C_NUM_SEP === $classes[ $j ];
				} elseif ( self::C_LATIN === $before && self::C_LATIN === $after ) {
					// Latin words and the neutrals between them form one run.
					$joins = true;
				} else {
					// Latin next to a number ("Word 2010"): spaces or a separator only.
					$joins = true;
					for ( $m = $j; $m < $k; $m++ ) {
						if ( self::C_SPACE !== $classes[ $m ] && self::C_NUM_SEP !== $classes[ $m ] ) {
							$joins = false;
							break;
						}
					}
				}
				if ( ! $joins ) {
					break;
				}
				$end = $k;
				$j   = $k + 1;
			}

			$runs[ $i ] = $end;
			$i          = $end + 1;
		}

		// A number wrapped at its hyphen or slash ("8069-" + "74/25" on the
		// next line) keeps that separator inside its run when the generator
		// resolved the paragraph before breaking lines: in visual order the
		// line then starts with "8069-". Reversed, "-9608" ends the line.
		if ( $runs ) {
			$last_start = max( array_keys( $runs ) );
			$tail       = $runs[ $last_start ] + 1;
			while ( $tail < $count && self::C_SPACE === $classes[ $tail ] ) {
				$tail++;
			}
			if ( $tail === $count && $last_start > 0 && self::C_DIGIT === $classes[ $last_start ]
				&& in_array( $chars[ $last_start - 1 ], array( '-', '/' ), true )
				&& ( 1 === $last_start || self::C_SPACE === $classes[ $last_start - 2 ] ) ) {
				$runs[ $last_start - 1 ] = $runs[ $last_start ];
				unset( $runs[ $last_start ] );
			}
		}

		$out = '';
		$i   = 0;
		while ( $i < $count ) {
			if ( isset( $runs[ $i ] ) ) {
				$out .= implode( '', array_reverse( array_slice( $chars, $i, $runs[ $i ] - $i + 1 ) ) );
				$i    = $runs[ $i ] + 1;
				continue;
			}
			$char = $chars[ $i ];
			$out .= ( $mirror && isset( self::MIRROR[ $char ] ) ) ? self::MIRROR[ $char ] : $char;
			$i++;
		}

		return $out;
	}

	/**
	 * Convert text to logical order if it is stored in visual order.
	 *
	 * The decision is made once for the whole text (is_visual_order()).
	 * Then every line containing right-to-left characters - or no strong
	 * characters at all, like ".1" list numbers - is converted; lines that
	 * contain only Latin text are left alone. Bracket mirroring is chosen
	 * by whichever variant yields properly ordered brackets.
	 *
	 * @param string $text UTF-8 text, lines separated by "\n".
	 * @return string
	 */
	public static function fix_text( string $text ): string {
		if ( ! self::is_visual_order( $text ) ) {
			return $text;
		}
		$lines   = explode( "\n", $text );
		$convert = array();
		foreach ( $lines as $index => $line ) {
			if ( self::should_convert_line( $line ) ) {
				$convert[ $index ] = true;
			}
		}

		$mirror = self::detect_mirroring( $lines, $convert );
		foreach ( $convert as $index => $unused ) {
			$lines[ $index ] = self::visual_to_logical( $lines[ $index ], $mirror );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Should this line of a visual-order text be converted?
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private static function should_convert_line( string $line ): bool {
		if ( '' === trim( $line ) ) {
			return false;
		}
		if ( preg_match( self::RTL_PATTERN, $line ) ) {
			return true;
		}
		// No strong character at all (".1", "06-22"): part of a right-to-left paragraph.
		return ! preg_match( '/\p{L}/u', $line );
	}

	/**
	 * Decide whether brackets must be mirrored: legacy generators store the
	 * mirrored glyphs, modern ones (LibreOffice, Word) keep the logical
	 * characters. The variant that gives more well-formed bracket pairs
	 * wins; mirroring is the default (Unicode rule L4).
	 *
	 * @param string[]          $lines   All lines.
	 * @param array<int, bool> $convert Indexes of lines to convert.
	 * @return bool
	 */
	private static function detect_mirroring( array $lines, array $convert ): bool {
		$score_mirrored = 0;
		$score_plain    = 0;
		foreach ( $convert as $index => $unused ) {
			$line = $lines[ $index ];
			if ( ! preg_match( '/[()\[\]{}<>\x{00AB}\x{00BB}]/u', $line ) ) {
				continue;
			}
			$score_mirrored += self::bracket_score( self::visual_to_logical( $line, true ) );
			$score_plain    += self::bracket_score( self::visual_to_logical( $line, false ) );
		}
		return $score_mirrored >= $score_plain;
	}

	/**
	 * Score how well-formed the brackets in a line are: +1 for each
	 * matched pair, -1 for each closing bracket without an opener and for
	 * each opener left open.
	 *
	 * @param string $line Line.
	 * @return int
	 */
	private static function bracket_score( string $line ): int {
		$pairs  = array(
			')'        => '(',
			']'        => '[',
			'}'        => '{',
			'>'        => '<',
			"\u{00BB}" => "\u{00AB}",
		);
		$stack  = array();
		$score  = 0;
		$chars  = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $chars as $char ) {
			if ( in_array( $char, $pairs, true ) ) {
				$stack[] = $char;
			} elseif ( isset( $pairs[ $char ] ) ) {
				if ( $stack && end( $stack ) === $pairs[ $char ] ) {
					array_pop( $stack );
					$score++;
				} else {
					$score--;
				}
			}
		}
		return $score - count( $stack );
	}

	/**
	 * Classify a character for visual_to_logical().
	 *
	 * @param string $char One UTF-8 character.
	 * @return int One of the C_* constants.
	 */
	private static function classify( string $char ): int {
		if ( 1 === strlen( $char ) ) {
			$ord = ord( $char );
			if ( $ord >= 0x30 && $ord <= 0x39 ) {
				return self::C_DIGIT;
			}
			if ( ( $ord >= 0x41 && $ord <= 0x5A ) || ( $ord >= 0x61 && $ord <= 0x7A ) ) {
				return self::C_LATIN;
			}
			if ( ' ' === $char || "\t" === $char ) {
				return self::C_SPACE;
			}
			if ( false !== strpos( '.,:/-+', $char ) ) {
				return self::C_NUM_SEP;
			}
			if ( false !== strpos( '%#$', $char ) ) {
				return self::C_NUM_TERM;
			}
			return self::C_OTHER;
		}
		if ( preg_match( self::RTL_PATTERN, $char ) ) {
			return self::C_RTL;
		}
		if ( preg_match( '/\p{Nd}/u', $char ) ) {
			return self::C_DIGIT;
		}
		if ( preg_match( '/\p{L}|\p{M}/u', $char ) ) {
			return self::C_LATIN;
		}
		if ( preg_match( '/\s/u', $char ) ) {
			return self::C_SPACE;
		}
		if ( preg_match( '/[\x{00A0}\x{2212}\x{2010}\x{2011}]/u', $char ) ) {
			return self::C_NUM_SEP;
		}
		if ( preg_match( '/[\x{00A2}-\x{00A5}\x{00B0}\x{00B1}\x{2030}\x{2031}\x{2032}-\x{2034}\x{20A0}-\x{20CF}]/u', $char ) ) {
			return self::C_NUM_TERM;
		}
		return self::C_OTHER;
	}

	/**
	 * Reverse a UTF-8 string by characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function reverse( string $text ): string {
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return false === $chars ? $text : implode( '', array_reverse( $chars ) );
	}
}
