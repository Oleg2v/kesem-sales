<?php
/**
 * Character-set helpers. Own single-byte tables so conversion does not
 * depend on how iconv was compiled on a shared host.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encoding conversion and detection.
 */
final class HDS_Encoding {

	const UTF8     = 'UTF-8';
	const UTF16LE  = 'UTF-16LE';
	const UTF16BE  = 'UTF-16BE';
	const CP1255   = 'Windows-1255';
	const CP1252   = 'Windows-1252';

	/**
	 * Windows-1255 (Hebrew), bytes 0x80-0xFF. 0 = undefined (dropped).
	 * 0xCA is mapped to U+05BA as in Microsoft's current table.
	 */
	const TABLE_1255 = array(
		0x80 => 0x20AC, 0x81 => 0, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021,
		0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0, 0x8B => 0x2039, 0x8C => 0, 0x8D => 0, 0x8E => 0, 0x8F => 0,
		0x90 => 0, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
		0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0, 0x9B => 0x203A, 0x9C => 0, 0x9D => 0, 0x9E => 0, 0x9F => 0,
		0xA0 => 0x00A0, 0xA1 => 0x00A1, 0xA2 => 0x00A2, 0xA3 => 0x00A3, 0xA4 => 0x20AA, 0xA5 => 0x00A5, 0xA6 => 0x00A6, 0xA7 => 0x00A7,
		0xA8 => 0x00A8, 0xA9 => 0x00A9, 0xAA => 0x00D7, 0xAB => 0x00AB, 0xAC => 0x00AC, 0xAD => 0x00AD, 0xAE => 0x00AE, 0xAF => 0x00AF,
		0xB0 => 0x00B0, 0xB1 => 0x00B1, 0xB2 => 0x00B2, 0xB3 => 0x00B3, 0xB4 => 0x00B4, 0xB5 => 0x00B5, 0xB6 => 0x00B6, 0xB7 => 0x00B7,
		0xB8 => 0x00B8, 0xB9 => 0x00B9, 0xBA => 0x00F7, 0xBB => 0x00BB, 0xBC => 0x00BC, 0xBD => 0x00BD, 0xBE => 0x00BE, 0xBF => 0x00BF,
		0xC0 => 0x05B0, 0xC1 => 0x05B1, 0xC2 => 0x05B2, 0xC3 => 0x05B3, 0xC4 => 0x05B4, 0xC5 => 0x05B5, 0xC6 => 0x05B6, 0xC7 => 0x05B7,
		0xC8 => 0x05B8, 0xC9 => 0x05B9, 0xCA => 0x05BA, 0xCB => 0x05BB, 0xCC => 0x05BC, 0xCD => 0x05BD, 0xCE => 0x05BE, 0xCF => 0x05BF,
		0xD0 => 0x05C0, 0xD1 => 0x05C1, 0xD2 => 0x05C2, 0xD3 => 0x05C3, 0xD4 => 0x05F0, 0xD5 => 0x05F1, 0xD6 => 0x05F2, 0xD7 => 0x05F3,
		0xD8 => 0x05F4, 0xD9 => 0, 0xDA => 0, 0xDB => 0, 0xDC => 0, 0xDD => 0, 0xDE => 0, 0xDF => 0,
		0xE0 => 0x05D0, 0xE1 => 0x05D1, 0xE2 => 0x05D2, 0xE3 => 0x05D3, 0xE4 => 0x05D4, 0xE5 => 0x05D5, 0xE6 => 0x05D6, 0xE7 => 0x05D7,
		0xE8 => 0x05D8, 0xE9 => 0x05D9, 0xEA => 0x05DA, 0xEB => 0x05DB, 0xEC => 0x05DC, 0xED => 0x05DD, 0xEE => 0x05DE, 0xEF => 0x05DF,
		0xF0 => 0x05E0, 0xF1 => 0x05E1, 0xF2 => 0x05E2, 0xF3 => 0x05E3, 0xF4 => 0x05E4, 0xF5 => 0x05E5, 0xF6 => 0x05E6, 0xF7 => 0x05E7,
		0xF8 => 0x05E8, 0xF9 => 0x05E9, 0xFA => 0x05EA, 0xFB => 0, 0xFC => 0, 0xFD => 0x200E, 0xFE => 0x200F, 0xFF => 0,
	);

	/**
	 * Windows-1252 (Western), bytes 0x80-0x9F; 0xA0-0xFF equal Latin-1.
	 * Also the mapping MS-DOC prescribes for "compressed" text pieces.
	 */
	const TABLE_1252_C1 = array(
		0x80 => 0x20AC, 0x81 => 0, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021,
		0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152, 0x8D => 0, 0x8E => 0x017D, 0x8F => 0,
		0x90 => 0, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
		0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153, 0x9D => 0, 0x9E => 0x017E, 0x9F => 0x0178,
	);

	/**
	 * Built strtr() maps, keyed by encoding name.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $maps = array();

	/**
	 * Convert Windows-1255 bytes to UTF-8.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function cp1255_to_utf8( string $bytes ): string {
		return strtr( $bytes, self::map( self::CP1255 ) );
	}

	/**
	 * Convert Windows-1252 bytes to UTF-8.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function cp1252_to_utf8( string $bytes ): string {
		return strtr( $bytes, self::map( self::CP1252 ) );
	}

	/**
	 * Convert UTF-16LE bytes to UTF-8. A dangling odd byte is dropped.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function utf16le_to_utf8( string $bytes ): string {
		if ( strlen( $bytes ) % 2 ) {
			$bytes = substr( $bytes, 0, -1 );
		}
		return '' === $bytes ? '' : (string) mb_convert_encoding( $bytes, 'UTF-8', 'UTF-16LE' );
	}

	/**
	 * Convert UTF-16BE bytes to UTF-8. A dangling odd byte is dropped.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function utf16be_to_utf8( string $bytes ): string {
		if ( strlen( $bytes ) % 2 ) {
			$bytes = substr( $bytes, 0, -1 );
		}
		return '' === $bytes ? '' : (string) mb_convert_encoding( $bytes, 'UTF-8', 'UTF-16BE' );
	}

	/**
	 * Encode one Unicode code point as UTF-8.
	 *
	 * @param int $code_point Code point.
	 * @return string
	 */
	public static function chr( int $code_point ): string {
		$char = mb_chr( $code_point, 'UTF-8' );
		return false === $char ? '' : $char;
	}

	/**
	 * Heuristic: are the high bytes of an 8-bit string Hebrew (Windows-1255)
	 * rather than accented Latin (Windows-1252)?
	 *
	 * In Hebrew text the letters 0xE0-0xFA come in runs (whole words); in
	 * Western text accented letters are isolated between ASCII letters.
	 *
	 * @param string $bytes Raw bytes.
	 * @return bool
	 */
	public static function looks_like_cp1255( string $bytes ): bool {
		$sample  = substr( $bytes, 0, 65536 );
		$len     = strlen( $sample );
		$hebrew  = 0;
		$latin   = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$b = ord( $sample[ $i ] );
			if ( $b < 0xC0 ) {
				continue;
			}
			$prev = $i > 0 ? ord( $sample[ $i - 1 ] ) : 0x20;
			$next = $i + 1 < $len ? ord( $sample[ $i + 1 ] ) : 0x20;
			$prev_ascii_letter = ( $prev >= 0x41 && $prev <= 0x5A ) || ( $prev >= 0x61 && $prev <= 0x7A );
			$next_ascii_letter = ( $next >= 0x41 && $next <= 0x5A ) || ( $next >= 0x61 && $next <= 0x7A );
			if ( $b >= 0xE0 && $b <= 0xFA && ( ( $prev >= 0xE0 && $prev <= 0xFA ) || ( $next >= 0xE0 && $next <= 0xFA ) ) ) {
				$hebrew++;
			} elseif ( $prev_ascii_letter || $next_ascii_letter ) {
				$latin++;
			}
		}
		if ( 0 === $hebrew && 0 === $latin ) {
			// Nothing conclusive: the site is Hebrew, prefer Windows-1255.
			return true;
		}
		return $hebrew >= $latin;
	}

	/**
	 * Detect the encoding of a text file and convert it to UTF-8.
	 *
	 * Order: BOM, valid UTF-8, BOM-less UTF-16, then Windows-1255 or
	 * Windows-1252 by heuristic.
	 *
	 * @param string $bytes Raw file contents.
	 * @return array{text: string, encoding: string}
	 */
	public static function detect_and_convert( string $bytes ): array {
		if ( 0 === strncmp( $bytes, "\xEF\xBB\xBF", 3 ) ) {
			return array(
				'text'     => self::scrub_utf8( substr( $bytes, 3 ) ),
				'encoding' => self::UTF8,
			);
		}
		if ( 0 === strncmp( $bytes, "\xFF\xFE", 2 ) ) {
			return array(
				'text'     => self::utf16le_to_utf8( substr( $bytes, 2 ) ),
				'encoding' => self::UTF16LE,
			);
		}
		if ( 0 === strncmp( $bytes, "\xFE\xFF", 2 ) ) {
			return array(
				'text'     => self::utf16be_to_utf8( substr( $bytes, 2 ) ),
				'encoding' => self::UTF16BE,
			);
		}
		// NUL bytes never occur in real UTF-8 text but do in BOM-less UTF-16.
		if ( false === strpos( substr( $bytes, 0, 8192 ), "\0" ) && mb_check_encoding( $bytes, 'UTF-8' ) ) {
			return array(
				'text'     => $bytes,
				'encoding' => self::UTF8,
			);
		}
		$utf16 = self::sniff_utf16( $bytes );
		if ( null !== $utf16 ) {
			return array(
				'text'     => self::UTF16LE === $utf16 ? self::utf16le_to_utf8( $bytes ) : self::utf16be_to_utf8( $bytes ),
				'encoding' => $utf16,
			);
		}
		if ( self::looks_like_cp1255( $bytes ) ) {
			return array(
				'text'     => self::cp1255_to_utf8( $bytes ),
				'encoding' => self::CP1255,
			);
		}
		return array(
			'text'     => self::cp1252_to_utf8( $bytes ),
			'encoding' => self::CP1252,
		);
	}

	/**
	 * Replace invalid UTF-8 sequences.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function scrub_utf8( string $text ): string {
		if ( mb_check_encoding( $text, 'UTF-8' ) ) {
			return $text;
		}
		$substitute = mb_substitute_character();
		mb_substitute_character( 0xFFFD );
		$clean = (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		mb_substitute_character( $substitute );
		return $clean;
	}

	/**
	 * Detect BOM-less UTF-16 by the pattern of high bytes (0x00 for ASCII,
	 * 0x05 for Hebrew).
	 *
	 * @param string $bytes Raw bytes.
	 * @return string|null UTF16LE, UTF16BE or null.
	 */
	private static function sniff_utf16( string $bytes ) {
		$sample = substr( $bytes, 0, 8192 );
		$pairs  = intdiv( strlen( $sample ), 2 );
		if ( $pairs < 2 || false === strpos( $sample, "\0" ) ) {
			return null;
		}
		$le = 0;
		$be = 0;
		for ( $i = 0; $i < $pairs; $i++ ) {
			$first  = ord( $sample[ 2 * $i ] );
			$second = ord( $sample[ 2 * $i + 1 ] );
			if ( 0x00 === $second || 0x05 === $second ) {
				$le++;
			}
			if ( 0x00 === $first || 0x05 === $first ) {
				$be++;
			}
		}
		if ( $le >= 0.8 * $pairs && $le > $be ) {
			return self::UTF16LE;
		}
		if ( $be >= 0.8 * $pairs && $be > $le ) {
			return self::UTF16BE;
		}
		return null;
	}

	/**
	 * Build the strtr() map for a single-byte encoding.
	 *
	 * @param string $encoding CP1255 or CP1252.
	 * @return array<string, string>
	 */
	private static function map( string $encoding ): array {
		if ( isset( self::$maps[ $encoding ] ) ) {
			return self::$maps[ $encoding ];
		}
		$map = array();
		for ( $b = 0x80; $b <= 0xFF; $b++ ) {
			if ( self::CP1255 === $encoding ) {
				$cp = self::TABLE_1255[ $b ];
			} else {
				$cp = $b < 0xA0 ? self::TABLE_1252_C1[ $b ] : $b;
			}
			$map[ chr( $b ) ] = $cp ? self::chr( $cp ) : '';
		}
		self::$maps[ $encoding ] = $map;
		return $map;
	}
}
