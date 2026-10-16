<?php
/**
 * Plain-text (.txt) extraction with encoding detection.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a text file in UTF-8 (with or without BOM), UTF-16LE/BE or a
 * Windows-1255/1252 legacy encoding and returns UTF-8.
 */
final class HDS_Txt_Extractor implements HDS_Extractor_Interface {

	/**
	 * Largest text file that will be read (bytes). Plain text this large is
	 * already far beyond any real document.
	 */
	const MAX_BYTES = 52428800; // 50 MB.

	/**
	 * Extract the text of a .txt file.
	 *
	 * @param string $path Absolute path to a readable file.
	 * @return string UTF-8 text.
	 * @throws HDS_Extraction_Exception When the file cannot be read or is binary.
	 */
	public function extract( string $path ): string {
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Checked below.
		if ( false === $size ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNREADABLE, 'The file is missing or not readable.' );
		}
		if ( $size > self::MAX_BYTES ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::TOO_LARGE, 'The text file is too large to process.' );
		}
		$bytes = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Local file.
		if ( false === $bytes ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNREADABLE, 'The file is missing or not readable.' );
		}
		if ( '' === $bytes ) {
			return '';
		}

		$result = HDS_Encoding::detect_and_convert( $bytes );
		$text   = (string) $result['text'];

		// BOM-less UTF-16 with only ASCII-range characters is also valid
		// UTF-8 (with NULs), so detect_and_convert() reports UTF-8.
		if ( HDS_Encoding::UTF8 === $result['encoding'] && false !== strpos( $text, "\0" ) ) {
			$utf16 = self::sniff_ascii_utf16( $bytes );
			if ( HDS_Encoding::UTF16LE === $utf16 ) {
				$text = HDS_Encoding::utf16le_to_utf8( $bytes );
			} elseif ( HDS_Encoding::UTF16BE === $utf16 ) {
				$text = HDS_Encoding::utf16be_to_utf8( $bytes );
			}
		}

		if ( self::looks_binary( $text ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The file content does not match the ".txt" format (binary data or wrong extension).' );
		}
		return $text;
	}

	/**
	 * Is this BOM-less UTF-16 text whose characters are all below U+0100
	 * (every second byte NUL)?
	 *
	 * @param string $bytes Raw bytes.
	 * @return string|null HDS_Encoding::UTF16LE, UTF16BE or null.
	 */
	private static function sniff_ascii_utf16( string $bytes ) {
		$sample = substr( $bytes, 0, 8192 );
		$pairs  = intdiv( strlen( $sample ), 2 );
		if ( $pairs < 2 ) {
			return null;
		}
		$high_le = 0;
		$high_be = 0;
		for ( $i = 0; $i < $pairs; $i++ ) {
			$first  = $sample[ 2 * $i ];
			$second = $sample[ 2 * $i + 1 ];
			if ( "\0" === $second && "\0" !== $first ) {
				$high_le++;
			} elseif ( "\0" === $first && "\0" !== $second ) {
				$high_be++;
			}
		}
		if ( $high_le >= 0.9 * $pairs ) {
			return HDS_Encoding::UTF16LE;
		}
		if ( $high_be >= 0.9 * $pairs ) {
			return HDS_Encoding::UTF16BE;
		}
		return null;
	}

	/**
	 * Binary data renamed to .txt: after decoding, real text has (almost)
	 * no control characters other than tab, line breaks, vertical tab and
	 * form feed, while binary data has them everywhere (NUL bytes survive
	 * decoding only when the file was not UTF-16 text).
	 *
	 * @param string $text Decoded text.
	 * @return bool
	 */
	private static function looks_binary( string $text ): bool {
		$sample   = substr( $text, 0, 65536 );
		$length   = strlen( $sample );
		$controls = $length - strlen( (string) preg_replace( '/[\x00-\x08\x0E-\x1F]/', '', $sample ) );
		return substr_count( $sample, "\0" ) > 4 || $controls > max( 8, 0.02 * $length );
	}
}
