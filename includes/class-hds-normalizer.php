<?php
/**
 * Text normalization for extracted documents and search queries.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizer.
 *
 * - clean_extracted(): layout cleanup right after extraction (control
 *   characters, bidi marks, whitespace). Keeps the text as written.
 * - normalize(): clean_extracted() + search normalization (no nikud,
 *   unified quotes and dashes). Used for stored chunks and queries.
 * - normalize_query(): normalize() + single line + lowercase.
 */
final class HDS_Normalizer {

	/**
	 * Hebrew points and cantillation marks (U+0591-U+05C7), except the
	 * punctuation in that block (maqaf U+05BE, paseq U+05C0, sof pasuq
	 * U+05C3, nun hafukha U+05C6), which are handled separately.
	 */
	const NIKUD_PATTERN = '/[\x{0591}-\x{05BD}\x{05BF}\x{05C1}\x{05C2}\x{05C4}\x{05C5}\x{05C7}]/u';

	/**
	 * Characters that are removed outright: C0/C1 controls except tab and
	 * newline, zero-width and bidi formatting characters, BOM, soft hyphen,
	 * replacement character, private-use area (Word symbol-font glyphs).
	 */
	const STRIP_PATTERN = '/[\x{0000}-\x{0008}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFD}\x{E000}-\x{F8FF}]/u';

	/**
	 * Hebrew presentation forms (U+FB1D-U+FB4F) to base letters.
	 */
	const PRESENTATION_FORMS = array(
		"\u{FB1D}" => "\u{05D9}", "\u{FB1E}" => '', "\u{FB1F}" => "\u{05F2}", "\u{FB20}" => "\u{05E2}", "\u{FB21}" => "\u{05D0}",
		"\u{FB22}" => "\u{05D3}", "\u{FB23}" => "\u{05D4}", "\u{FB24}" => "\u{05DB}", "\u{FB25}" => "\u{05DC}", "\u{FB26}" => "\u{05DD}",
		"\u{FB27}" => "\u{05E8}", "\u{FB28}" => "\u{05EA}", "\u{FB29}" => '+', "\u{FB2A}" => "\u{05E9}", "\u{FB2B}" => "\u{05E9}",
		"\u{FB2C}" => "\u{05E9}", "\u{FB2D}" => "\u{05E9}", "\u{FB2E}" => "\u{05D0}", "\u{FB2F}" => "\u{05D0}", "\u{FB30}" => "\u{05D0}",
		"\u{FB31}" => "\u{05D1}", "\u{FB32}" => "\u{05D2}", "\u{FB33}" => "\u{05D3}", "\u{FB34}" => "\u{05D4}", "\u{FB35}" => "\u{05D5}",
		"\u{FB36}" => "\u{05D6}", "\u{FB38}" => "\u{05D8}", "\u{FB39}" => "\u{05D9}", "\u{FB3A}" => "\u{05DA}", "\u{FB3B}" => "\u{05DB}",
		"\u{FB3C}" => "\u{05DC}", "\u{FB3E}" => "\u{05DE}", "\u{FB40}" => "\u{05E0}", "\u{FB41}" => "\u{05E1}", "\u{FB43}" => "\u{05E3}",
		"\u{FB44}" => "\u{05E4}", "\u{FB46}" => "\u{05E6}", "\u{FB47}" => "\u{05E7}", "\u{FB48}" => "\u{05E8}", "\u{FB49}" => "\u{05E9}",
		"\u{FB4A}" => "\u{05EA}", "\u{FB4B}" => "\u{05D5}", "\u{FB4C}" => "\u{05D1}", "\u{FB4D}" => "\u{05DB}", "\u{FB4E}" => "\u{05E4}",
		"\u{FB4F}" => "\u{05D0}\u{05DC}",
	);

	/**
	 * Quote, apostrophe and dash variants unified for search.
	 */
	const PUNCTUATION = array(
		// Double quotes, incl. Hebrew gershayim.
		"\u{05F4}" => '"', "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
		"\u{2033}" => '"', "\u{00AB}" => '"', "\u{00BB}" => '"', "\u{FF02}" => '"',
		// Single quotes, incl. Hebrew geresh.
		"\u{05F3}" => "'", "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
		"\u{2032}" => "'", "\u{00B4}" => "'", '`' => "'", "\u{FF07}" => "'",
		// Dashes, incl. Hebrew maqaf.
		"\u{05BE}" => '-', "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-',
		"\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-',
		// Hebrew punctuation from the points block.
		"\u{05C0}" => '|', "\u{05C3}" => ':', "\u{05C6}" => "\u{05E0}",
	);

	/**
	 * Boilerplate added by nevo.co.il exports: the "copied from Nevo"
	 * watermark (often glued to the next word) and the standard footer.
	 * Pattern => replacement. Filterable via `hds_boilerplate_patterns`.
	 */
	const BOILERPLATE = array(
		'/הועתק מנבו/u'                                         => '',
		'/נוסח מסמך זה כפוף לשינויי ניסוח ועריכה/u'             => '',
		'/בעניין עריכה ושינויים במסמכי פסיקה, חקיקה ועוד באתר נבו[^\n]*/u' => '',
	);

	/**
	 * Clean text right after extraction.
	 *
	 * @param string $text UTF-8 text (invalid sequences are replaced).
	 * @return string
	 */
	public static function clean_extracted( string $text ): string {
		$text = HDS_Encoding::scrub_utf8( $text );
		$text = str_replace( array( "\r\n", "\r", "\u{2028}", "\u{2029}", "\f", "\v", "\u{0085}" ), "\n", $text );
		$text = (string) preg_replace( self::STRIP_PATTERN, '', $text );
		// Every horizontal space (tab, NBSP, thin spaces...) becomes one space.
		$text = (string) preg_replace( '/[\t\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000} ]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Remove Hebrew points and cantillation marks.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	public static function remove_nikud( string $text ): string {
		return (string) preg_replace( self::NIKUD_PATTERN, '', $text );
	}

	/**
	 * Unify quotes (״ ׳ “ ” ‘ ’ ...) and dashes.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	public static function normalize_punctuation( string $text ): string {
		return strtr( $text, self::PUNCTUATION );
	}

	/**
	 * Remove known export boilerplate (see BOILERPLATE).
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	public static function strip_boilerplate( string $text ): string {
		$patterns = self::BOILERPLATE;
		if ( function_exists( 'apply_filters' ) ) {
			$patterns = (array) apply_filters( 'hds_boilerplate_patterns', $patterns );
		}
		if ( ! $patterns ) {
			return $text;
		}
		$result = preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
		return is_string( $result ) ? $result : $text;
	}

	/**
	 * Full normalization for stored chunks and for queries.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	public static function normalize( string $text ): string {
		$text = self::clean_extracted( $text );
		$text = self::strip_boilerplate( $text );
		$text = strtr( $text, self::PRESENTATION_FORMS );
		$text = self::remove_nikud( $text );
		$text = self::normalize_punctuation( $text );
		$text = (string) preg_replace( '/ {2,}/', ' ', $text );
		$text = (string) preg_replace( '/ *\n */', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Normalize a user query: single line, lowercase, normalized.
	 *
	 * @param string $query Raw query.
	 * @return string
	 */
	public static function normalize_query( string $query ): string {
		$query = self::normalize( $query );
		$query = (string) preg_replace( '/\s+/u', ' ', $query );
		return trim( mb_strtolower( $query, 'UTF-8' ) );
	}

	/**
	 * Split text into lowercase word tokens. Keeps internal quotes,
	 * apostrophes and dashes so עו"ד, ג'ורג' and 57879-06-22 stay whole,
	 * and dots/slashes between digits so 1.5, 12/03/2024 and case numbers
	 * like 8069-74/25 are single tokens.
	 *
	 * @param string $text UTF-8 text.
	 * @return string[]
	 */
	public static function tokenize( string $text ): array {
		$text = mb_strtolower( self::normalize( $text ), 'UTF-8' );
		if ( ! preg_match_all( '/[\p{L}\p{N}]+(?:[\'"\-][\p{L}\p{N}]+|(?<=\p{N})[.\/]\p{N}+)*\'?/u', $text, $m ) ) {
			return array();
		}
		return $m[0];
	}
}
