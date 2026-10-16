<?php
/**
 * Picks an extractor for a file and runs the common pipeline.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extractor factory.
 */
final class HDS_Extractor_Factory {

	/**
	 * Bump when extraction output changes, so documents get re-indexed.
	 */
	const VERSION = '1';

	/**
	 * Hard upper bound for a file the extractors will open (bytes).
	 */
	const MAX_FILE_BYTES = 104857600; // 100 MB.

	/**
	 * Extension => extractor class.
	 */
	const EXTRACTORS = array(
		'doc'  => 'HDS_Doc_Extractor',
		'docx' => 'HDS_Docx_Extractor',
		'pdf'  => 'HDS_Pdf_Extractor',
		'txt'  => 'HDS_Txt_Extractor',
	);

	/**
	 * Supported file extensions (lowercase, no dot).
	 *
	 * @return string[]
	 */
	public static function supported_extensions(): array {
		return array_keys( self::EXTRACTORS );
	}

	/**
	 * Get the extractor for an extension.
	 *
	 * @param string $ext Extension without dot.
	 * @return HDS_Extractor_Interface
	 * @throws HDS_Extraction_Exception For unsupported extensions.
	 */
	public static function for_extension( string $ext ): HDS_Extractor_Interface {
		$ext = strtolower( $ext );
		if ( ! isset( self::EXTRACTORS[ $ext ] ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNSUPPORTED, sprintf( 'Unsupported file type ".%s".', $ext ) );
		}
		$class = self::EXTRACTORS[ $ext ];
		return new $class();
	}

	/**
	 * Determine the real format of a file from its first bytes. Handles the
	 * common case of a .docx renamed to .doc (and vice versa) and rejects
	 * RTF/HTML files that carry a Word extension with a clear message.
	 *
	 * @param string $path File path.
	 * @param string $ext  Extension from the file name.
	 * @return string Extension of the extractor to use.
	 * @throws HDS_Extraction_Exception When the content does not match any supported format.
	 */
	public static function detect_format( string $path, string $ext ): string {
		$ext  = strtolower( $ext );
		$head = (string) file_get_contents( $path, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$text = ltrim( 0 === strncmp( $head, "\xEF\xBB\xBF", 3 ) ? substr( $head, 3 ) : $head );

		if ( 0 === strncmp( $head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8 ) ) {
			if ( 'doc' === $ext || 'docx' === $ext ) {
				return 'doc';
			}
		} elseif ( 0 === strncmp( $head, "PK\x03\x04", 4 ) ) {
			if ( 'doc' === $ext || 'docx' === $ext ) {
				return 'docx';
			}
		} elseif ( false !== strpos( $head, '%PDF-' ) ) {
			if ( 'pdf' === $ext ) {
				return 'pdf';
			}
		} elseif ( 0 === strncmp( $text, '{\\rtf', 5 ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNSUPPORTED, 'This is an RTF file saved with a Word extension. Re-save it in Word as .docx and upload again.' );
		} elseif ( preg_match( '/^(?:<!doctype html|<html|<\?xml[^>]*>\s*<html)/i', $text ) && 'txt' !== $ext ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNSUPPORTED, 'This is an HTML file saved with a Word extension. Re-save it in Word as .docx and upload again.' );
		}

		if ( 'txt' === $ext ) {
			return 'txt';
		}
		if ( isset( self::EXTRACTORS[ $ext ] ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, sprintf( 'The file content does not match the ".%s" format (damaged or wrong extension).', $ext ) );
		}
		throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNSUPPORTED, sprintf( 'Unsupported file type ".%s".', $ext ) );
	}

	/**
	 * Extract and clean the text of a file.
	 *
	 * @param string      $path Absolute file path.
	 * @param string|null $ext  Extension; defaults to the one in $path.
	 * @return string Cleaned UTF-8 text (HDS_Normalizer::clean_extracted()).
	 * @throws HDS_Extraction_Exception On any failure; never other exception types.
	 */
	public static function extract( string $path, $ext = null ): string {
		if ( null === $ext ) {
			$ext = (string) pathinfo( $path, PATHINFO_EXTENSION );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNREADABLE, 'The file is missing or not readable.' );
		}
		$size = (int) filesize( $path );
		if ( 0 === $size ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::NO_TEXT, 'The file is empty.' );
		}
		if ( $size > self::MAX_FILE_BYTES ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::TOO_LARGE, 'The file is too large to process.' );
		}

		$format = self::detect_format( $path, $ext );

		try {
			$text = self::for_extension( $format )->extract( $path );
		} catch ( HDS_Extraction_Exception $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The file could not be parsed (it may be damaged).', $e );
		}

		$text = HDS_Normalizer::clean_extracted( $text );
		if ( '' === $text || ! preg_match( '/[\p{L}\p{N}]/u', $text ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::NO_TEXT, 'No text was found in the file.' );
		}
		return $text;
	}
}
