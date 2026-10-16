<?php
/**
 * PDF text extraction (bundled, namespace-prefixed smalot/pdfparser).
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use HDS_Vendor\Smalot\PdfParser\Element\ElementArray;
use HDS_Vendor\Smalot\PdfParser\Element\ElementXRef;

/**
 * Extracts the text layer of a PDF.
 *
 * smalot/pdfparser returns text in content-stream order. For Hebrew that
 * order is unreliable: modern generators (LibreOffice, Word, cairo) write the
 * glyph runs of a line in logical order, each run with its glyphs in visual
 * order, and legacy generators write whole lines in visual order. So every
 * page is rebuilt from the positions of its glyph runs: a small text-state
 * machine tracks the text matrix (with exact glyph advances from the font
 * widths), runs are grouped into lines by baseline and sorted left to right,
 * which yields every line in visual order whatever the generator.
 * HDS_Bidi::fix_text() then restores logical order; it decides per document,
 * so a PDF whose text is already logical is left alone.
 */
final class HDS_Pdf_Extractor implements HDS_Extractor_Interface {

	/**
	 * Max size of one decompressed stream (bytes); larger streams are
	 * truncated by the parser instead of exhausting memory.
	 */
	const DECODE_MEMORY_LIMIT = 33554432; // 32 MB.

	/**
	 * A PDF with fewer letters/digits than this (while it has pages) is
	 * considered scanned.
	 */
	const MIN_TEXT_CHARS = 20;

	/**
	 * Rough peak memory of the parser relative to the file size, used to
	 * refuse files that would exceed memory_limit (a fatal error cannot be
	 * caught and would leave the document stuck).
	 */
	const MEMORY_FACTOR = 8;

	/**
	 * Admin message for scanned PDFs (contract wording).
	 */
	const SCANNED_MESSAGE = 'Scanned PDF: no text layer found. OCR is not supported.';

	/**
	 * Average glyph advance in em when the font has no usable widths.
	 */
	const ESTIMATED_EM_PER_CHAR = 0.5;

	/**
	 * Width of a space in em when the font has no usable widths.
	 */
	const ESTIMATED_EM_PER_SPACE = 0.25;

	/**
	 * Font::decodeText() turns a TJ adjustment below
	 * (-50 x factor / 4) thousandths of an em into a space: 16 means gaps
	 * wider than 0.2 em are word breaks, letter-spacing is not.
	 */
	const DECODE_FONT_FACTOR = 16.0;

	/**
	 * Font metrics cache (spl_object_id => metrics|null), per extraction.
	 *
	 * @var array<int, array|null>
	 */
	private $metrics = array();

	/**
	 * Extract the text of a PDF file.
	 *
	 * @param string $path Absolute path to a readable file.
	 * @return string Text in logical order, pages and lines separated by "\n".
	 * @throws HDS_Extraction_Exception On encrypted, damaged or scanned PDFs.
	 */
	public function extract( string $path ): string {
		self::load_library();

		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Checked below.
		if ( false === $size ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNREADABLE, 'The file is missing or not readable.' );
		}
		if ( ! self::fits_in_memory( (int) $size ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::TOO_LARGE, 'The PDF is too large to process within the server memory limit.' );
		}

		// The library emits PHP notices/warnings on odd files; keep them out
		// of logs and AJAX responses while it runs (restored in finally).
		set_error_handler( array( __CLASS__, 'silence_library_notices' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Restored below.
		try {
			$text = $this->extract_text( $path );
		} finally {
			restore_error_handler();
			$this->metrics = array();
		}

		$text = HDS_Bidi::fix_text( $text );

		// Re-join numbers wrapped at a hyphen or slash ("8069-\n74/25") and
		// Hebrew prefixes wrapped before a number ("כ-\n158.1").
		return (string) preg_replace( '/(?<=[\d\x{05D0}-\x{05EA}])([\-\/])\n(?=\d)/u', '$1', $text );
	}

	/**
	 * Error handler active while the library runs: swallows notices,
	 * warnings and deprecations (never document content), lets anything
	 * else through to the regular handler.
	 *
	 * @param int $errno Error level.
	 * @return bool
	 */
	public static function silence_library_notices( $errno ) {
		return in_array( (int) $errno, array( E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED ), true );
	}

	/**
	 * Parse the file and return the raw (visual-order) text of all pages.
	 *
	 * @param string $path File path.
	 * @return string
	 * @throws HDS_Extraction_Exception On any failure.
	 */
	private function extract_text( string $path ): string {
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( false === $data ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNREADABLE, 'The file is missing or not readable.' );
		}
		$encrypted = self::has_encrypt_dictionary( $data );

		$config = new \HDS_Vendor\Smalot\PdfParser\Config();
		$config->setRetainImageContent( false );
		$config->setDecodeMemoryLimit( self::DECODE_MEMORY_LIMIT );

		try {
			$parser   = new \HDS_Vendor\Smalot\PdfParser\Parser( array(), $config );
			$document = $parser->parseContent( $data );
			unset( $data );
			$pages = $document->getPages();
		} catch ( \Throwable $e ) {
			if ( $encrypted || false !== stripos( $e->getMessage(), 'secured' ) ) {
				throw new HDS_Extraction_Exception( HDS_Extraction_Exception::ENCRYPTED, 'The PDF is password-protected or encrypted. Remove the protection and upload it again.' );
			}
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The PDF could not be parsed (it may be damaged).', $e );
		}

		if ( $encrypted ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::ENCRYPTED, 'The PDF is password-protected or encrypted. Remove the protection and upload it again.' );
		}
		if ( ! is_array( $pages ) || ! $pages ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The PDF has no pages (it may be damaged).' );
		}

		$texts      = array();
		$failed     = 0;
		$unreadable = 0;
		foreach ( $pages as $page ) {
			if ( ! $page->has( 'Parent' ) && ! $page->has( 'Contents' ) ) {
				$unreadable++;
			}
			$text = $this->page_text( $page );
			if ( null === $text ) {
				$failed++;
				$text = '';
			}
			$texts[] = $text;
		}
		if ( count( $pages ) === $failed ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The PDF could not be parsed (it may be damaged).' );
		}

		$text = HDS_Encoding::scrub_utf8( implode( "\n", $texts ) );
		if ( (int) preg_match_all( '/[\p{L}\p{N}]/u', $text ) < self::MIN_TEXT_CHARS ) {
			if ( count( $pages ) === $unreadable ) {
				// The page dictionaries themselves could not be read: not a scan.
				throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNSUPPORTED, 'The structure of this PDF is not supported by the built-in reader. Re-save it as PDF (e.g. "Print to PDF") and upload it again.' );
			}
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::SCANNED_PDF, self::SCANNED_MESSAGE );
		}
		return $text;
	}

	/**
	 * Load the bundled library.
	 *
	 * @return void
	 * @throws HDS_Extraction_Exception When the library is missing.
	 */
	private static function load_library() {
		if ( ! class_exists( '\HDS_Vendor\Smalot\PdfParser\Parser', false ) ) {
			$autoload = HDS_PLUGIN_DIR . 'vendor/autoload.php';
			if ( ! is_readable( $autoload ) ) {
				throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNSUPPORTED, 'The PDF library is missing from the plugin (vendor/). Reinstall the plugin.' );
			}
			require_once $autoload;
		}
	}

	/**
	 * Will parsing a file of this size likely stay within memory_limit?
	 *
	 * @param int $size File size in bytes.
	 * @return bool
	 */
	private static function fits_in_memory( int $size ): bool {
		$limit = self::bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return true;
		}
		return memory_get_usage( true ) + self::MEMORY_FACTOR * $size < $limit;
	}

	/**
	 * Parse a php.ini size ("256M", "1G", "-1").
	 *
	 * @param string $value Value.
	 * @return int Bytes; -1 when unlimited or unknown.
	 */
	private static function bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value || '-1' === $value || ! preg_match( '/^(\d+)\s*([kmg]?)/i', $value, $m ) ) {
			return -1;
		}
		$bytes = (int) $m[1];
		switch ( strtolower( $m[2] ) ) {
			case 'g':
				$bytes *= 1024;
				// Fall through.
			case 'm':
				$bytes *= 1024;
				// Fall through.
			case 'k':
				$bytes *= 1024;
		}
		return $bytes;
	}

	/**
	 * Does the file declare an /Encrypt dictionary? Trailers and
	 * cross-reference stream dictionaries sit at the end of the file (and,
	 * when linearized, near the start), so only those regions are searched:
	 * text inside the document cannot cause a false positive.
	 *
	 * @param string $data Raw PDF bytes.
	 * @return bool
	 */
	private static function has_encrypt_dictionary( string $data ): bool {
		$length  = strlen( $data );
		$regions = $length <= 131072 ? array( $data ) : array( substr( $data, 0, 65536 ), substr( $data, -65536 ) );
		foreach ( $regions as $region ) {
			if ( false !== strpos( $region, '/Encrypt' ) && preg_match( '/\/Encrypt\s*(?:\d+\s+\d+\s+R|<<)/', $region ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Text of one page, lines in visual order, or null when the page
	 * cannot be read at all.
	 *
	 * @param object $page HDS_Vendor\Smalot\PdfParser\Page.
	 * @return string|null
	 */
	private function page_text( $page ) {
		try {
			$text = $this->positional_text( $page );
			if ( null !== $text ) {
				return $text;
			}
		} catch ( \Throwable $e ) {
			// Fall back to the stream-order text below.
			unset( $e );
		}
		try {
			return (string) $page->getText();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Rebuild a page's text from the positions of its glyph runs.
	 *
	 * Returns null when the page has no decodable text this way, so the
	 * caller falls back to the parser's stream-order text.
	 *
	 * @param object $page HDS_Vendor\Smalot\PdfParser\Page.
	 * @return string|null
	 */
	private function positional_text( $page ) {
		$contents = self::resolve( $page->get( 'Contents' ) );
		$data     = '';
		if ( $contents instanceof ElementArray ) {
			foreach ( $contents->getContent() as $part ) {
				$part = self::resolve( $part );
				if ( is_object( $part ) && method_exists( $part, 'getContent' ) ) {
					$data .= (string) $part->getContent() . "\n";
				}
			}
		} elseif ( is_object( $contents ) && method_exists( $contents, 'getHeader' ) ) {
			$data = (string) $contents->getContent();
		}
		if ( '' === $data ) {
			return null;
		}

		$runs    = array();
		$visited = array();
		$this->walk( $page, $page, self::parse_content( $data ), array( 1.0, 0.0, 0.0, 1.0, 0.0, 0.0 ), $runs, $visited, 0 );
		$text = $runs ? implode( "\n", $this->lines( $runs ) ) : '';
		return preg_match( '/[\p{L}\p{N}]/u', $text ) ? $text : null;
	}

	/**
	 * Tokenize a content stream into commands:
	 * [ 'o' => operator, 't' => type of a string operand ('(' or '<'),
	 *   'c' => operands (numbers/names joined by spaces, the raw string, or
	 *   for TJ a list of [ 't' => '(' | '<' | 'n', 'c' => raw ]) ].
	 *
	 * Own tokenizer (PDF 7.2/7.8): literal strings with nesting and
	 * escapes, hex strings, names, numbers, arrays, dictionaries, comments
	 * and inline images (BI ... ID <binary> EI) are skipped correctly.
	 *
	 * @param string $data Decoded content stream.
	 * @return array[]
	 */
	private static function parse_content( string $data ): array {
		$commands = array();
		$operands = array();
		$array    = null; // Items of an open [ ... ] array.
		$length   = strlen( $data );
		$offset   = 0;
		$token    = '/\G(?:([\x00\x09\x0A\x0C\x0D\x20]+)|(%[^\r\n]*)|(<<)|(>>)|<([0-9A-Fa-f\x00\x09\x0A\x0C\x0D\x20]*)>|(\[)|(\])|\/([^\x00\x09\x0A\x0C\x0D\x20\/\[\]()<>{}%]*)|([+\-]?(?:\d+\.?\d*|\.\d+))(?![^\x00\x09\x0A\x0C\x0D\x20\/\[\]()<>{}%])|([^\x00\x09\x0A\x0C\x0D\x20\/\[\]()<>{}%]+)|(.))/s';
		$dict     = 0;

		while ( $offset < $length ) {
			if ( '(' === $data[ $offset ] ) {
				$end = self::literal_end( $data, $offset );
				$raw = substr( $data, $offset + 1, $end - $offset - 1 );
				$offset = $end + 1;
				if ( $dict > 0 ) {
					continue;
				}
				$item = array(
					't' => '(',
					'c' => $raw,
				);
				if ( null !== $array ) {
					$array[] = $item;
				} else {
					$operands[] = $item;
				}
				continue;
			}
			if ( ! preg_match( $token, $data, $m, 0, $offset ) ) {
				break;
			}
			$offset += strlen( $m[0] );
			if ( isset( $m[3] ) && '' !== $m[3] ) {
				$dict++;
				continue;
			}
			if ( isset( $m[4] ) && '' !== $m[4] ) {
				$dict = max( 0, $dict - 1 );
				continue;
			}
			if ( $dict > 0 || ( isset( $m[1] ) && '' !== $m[1] ) || ( isset( $m[2] ) && '' !== $m[2] ) ) {
				continue; // Inside a dictionary, whitespace or a comment.
			}
			if ( isset( $m[5] ) && '<' === $m[0][0] ) {
				$item = array(
					't' => '<',
					'c' => (string) preg_replace( '/[^0-9A-Fa-f]/', '', $m[5] ),
				);
			} elseif ( isset( $m[6] ) && '' !== $m[6] ) {
				$array = array();
				continue;
			} elseif ( isset( $m[7] ) && '' !== $m[7] ) {
				if ( null !== $array ) {
					$operands[] = array(
						't' => '[',
						'c' => $array,
					);
				}
				$array = null;
				continue;
			} elseif ( isset( $m[8] ) && '/' === $m[0][0] ) {
				$item = array(
					't' => '/',
					'c' => $m[8],
				);
			} elseif ( isset( $m[9] ) && '' !== $m[9] ) {
				$item = array(
					't' => 'n',
					'c' => $m[9],
				);
			} elseif ( isset( $m[10] ) && '' !== $m[10] ) {
				if ( null !== $array ) {
					continue; // Stray keyword inside an array.
				}
				$operator = $m[10];
				if ( 'ID' === $operator ) {
					// Inline image data: skip to the EI operator.
					if ( preg_match( '/[\x00\x09\x0A\x0C\x0D\x20]EI(?=[\x00\x09\x0A\x0C\x0D\x20]|$)/', $data, $ei, PREG_OFFSET_CAPTURE, $offset + 1 ) ) {
						$offset = $ei[0][1] + strlen( $ei[0][0] );
					} else {
						$offset = $length;
					}
					$operands = array();
					continue;
				}
				$commands[] = self::command( $operator, $operands );
				$operands   = array();
				continue;
			} else {
				continue; // Stray delimiter.
			}
			if ( null !== $array ) {
				$array[] = $item;
			} else {
				$operands[] = $item;
			}
		}
		return $commands;
	}

	/**
	 * Index of the ")" closing the literal string that starts at $start.
	 *
	 * @param string $data  Content.
	 * @param int    $start Offset of "(".
	 * @return int Offset of the closing parenthesis (or the last byte).
	 */
	private static function literal_end( string $data, int $start ): int {
		$length = strlen( $data );
		$depth  = 0;
		for ( $i = $start; $i < $length; $i++ ) {
			$char = $data[ $i ];
			if ( '\\' === $char ) {
				$i++;
			} elseif ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return $length - 1;
	}

	/**
	 * Build a command in the format walk() expects.
	 *
	 * @param string  $operator Operator.
	 * @param array[] $operands Operands.
	 * @return array
	 */
	private static function command( string $operator, array $operands ): array {
		$command = array(
			'o' => $operator,
			't' => '',
			'c' => '',
		);
		if ( 'TJ' === $operator ) {
			$items = array();
			foreach ( $operands as $operand ) {
				if ( '[' === $operand['t'] ) {
					$items = $operand['c'];
				}
			}
			$command['c'] = $items;
			return $command;
		}
		$plain = array();
		foreach ( $operands as $operand ) {
			if ( '(' === $operand['t'] || '<' === $operand['t'] ) {
				$command['t'] = $operand['t'];
				$command['c'] = $operand['c'];
			} elseif ( 'n' === $operand['t'] || '/' === $operand['t'] ) {
				$plain[] = $operand['c'];
			}
		}
		if ( '' === $command['t'] ) {
			$command['c'] = implode( ' ', $plain );
		} elseif ( '"' === $operator ) {
			$command['n'] = $plain;
		}
		return $command;
	}

	/**
	 * Interpret a content stream with a small text-state machine (q/Q/cm,
	 * BT, Tf, Tc, Tw, Tz, TL, Td, TD, Tm, T*, Tj, TJ, ', ", Do) and collect
	 * positioned runs. Each run is a string drawn left to right, so its
	 * characters are in visual order; a TJ array is split where it moves
	 * the pen backwards (some generators place several runs with one TJ).
	 *
	 * @param object  $page      The page (font/XObject fallback).
	 * @param object  $resources Page or form XObject owning the stream.
	 * @param array   $commands  Commands from parse_content().
	 * @param float[] $ctm       Current transformation matrix.
	 * @param array[] $runs      Collected runs (by reference).
	 * @param array   $visited   Form XObjects already entered (loop guard).
	 * @param int     $depth     Form nesting depth.
	 * @return void
	 */
	private function walk( $page, $resources, array $commands, array $ctm, array &$runs, array &$visited, int $depth ) {
		$identity = array( 1.0, 0.0, 0.0, 1.0, 0.0, 0.0 );
		$stack    = array();
		$tm       = $identity;
		$tlm      = $identity;
		$state    = array(
			'font'    => null,
			'size'    => 1.0,
			'tc'      => 0.0,
			'tw'      => 0.0,
			'th'      => 1.0,
			'leading' => 0.0,
		);
		$fonts    = array();

		foreach ( $commands as $command ) {
			if ( ! is_array( $command ) ) {
				continue;
			}
			$op      = isset( $command['o'] ) ? (string) $command['o'] : '';
			$operand = isset( $command['c'] ) && is_string( $command['c'] ) ? $command['c'] : '';
			switch ( $op ) {
				case 'q':
					// The text state (font, spacing, leading) is part of the graphics state.
					$stack[] = array( $ctm, $state );
					break;
				case 'Q':
					if ( $stack ) {
						list( $ctm, $state ) = array_pop( $stack );
					}
					break;
				case 'cm':
					$m = self::numbers( $operand, 6 );
					if ( null !== $m ) {
						$ctm = self::multiply( $m, $ctm );
					}
					break;
				case 'BT':
					$tm  = $identity;
					$tlm = $identity;
					break;
				case 'Tf':
					$parts = preg_split( '/\s+/', trim( $operand ) );
					if ( is_array( $parts ) && count( $parts ) >= 2 && is_numeric( end( $parts ) ) ) {
						$state['size'] = abs( (float) end( $parts ) );
						$font_id       = ltrim( (string) $parts[ count( $parts ) - 2 ], '/' );
						if ( ! array_key_exists( $font_id, $fonts ) ) {
							$font = $resources->getFont( $font_id );
							if ( null === $font && $resources !== $page ) {
								$font = $page->getFont( $font_id );
							}
							$fonts[ $font_id ] = $font;
						}
						$state['font'] = $fonts[ $font_id ];
					}
					break;
				case 'Tc':
				case 'Tw':
				case 'Tz':
				case 'TL':
					$value = trim( $operand );
					if ( is_numeric( $value ) ) {
						$keys                  = array(
							'Tc' => 'tc',
							'Tw' => 'tw',
							'Tz' => 'th',
							'TL' => 'leading',
						);
						$state[ $keys[ $op ] ] = 'Tz' === $op ? (float) $value / 100 : (float) $value;
					}
					break;
				case 'Td':
				case 'TD':
					$m = self::numbers( $operand, 2 );
					if ( null !== $m ) {
						if ( 'TD' === $op ) {
							$state['leading'] = -$m[1];
						}
						$tlm = self::multiply( array( 1.0, 0.0, 0.0, 1.0, $m[0], $m[1] ), $tlm );
						$tm  = $tlm;
					}
					break;
				case 'Tm':
					$m = self::numbers( $operand, 6 );
					if ( null !== $m ) {
						$tlm = $m;
						$tm  = $m;
					}
					break;
				case 'T*':
					$tlm = self::multiply( array( 1.0, 0.0, 0.0, 1.0, 0.0, -$state['leading'] ), $tlm );
					$tm  = $tlm;
					break;
				case '"':
					if ( isset( $command['n'][0], $command['n'][1] ) && is_numeric( $command['n'][0] ) && is_numeric( $command['n'][1] ) ) {
						$state['tw'] = (float) $command['n'][0];
						$state['tc'] = (float) $command['n'][1];
					}
					// Fall through: same as ' with the new spacing.
				case "'":
					$tlm = self::multiply( array( 1.0, 0.0, 0.0, 1.0, 0.0, -$state['leading'] ), $tlm );
					$tm  = $tlm;
					$tm  = $this->show( $resources, $page, $command, $tm, $ctm, $state, $runs );
					break;
				case 'Tj':
				case 'TJ':
					$tm = $this->show( $resources, $page, $command, $tm, $ctm, $state, $runs );
					break;
				case 'Do':
					$this->enter_form( $page, $resources, $operand, $ctm, $runs, $visited, $depth );
					break;
			}
		}
	}

	/**
	 * Execute a form XObject's content (text in forms: stamps, headers).
	 *
	 * @param object  $page      Page.
	 * @param object  $resources Current resource owner.
	 * @param string  $operand   XObject name.
	 * @param float[] $ctm       Current transformation matrix.
	 * @param array[] $runs      Runs (by reference).
	 * @param array   $visited   Loop guard (by reference).
	 * @param int     $depth     Current depth.
	 * @return void
	 */
	private function enter_form( $page, $resources, string $operand, array $ctm, array &$runs, array &$visited, int $depth ) {
		$name = trim( $operand, " \t\n\r/" );
		if ( '' === $name || $depth >= 4 ) {
			return;
		}
		$form = $resources->getXObject( $name );
		if ( null === $form && $resources !== $page ) {
			$form = $page->getXObject( $name );
		}
		if ( ! $form instanceof \HDS_Vendor\Smalot\PdfParser\XObject\Form ) {
			return;
		}
		$id = spl_object_id( $form );
		if ( isset( $visited[ $id ] ) ) {
			return;
		}
		$visited[ $id ] = true;

		$matrix = array( 1.0, 0.0, 0.0, 1.0, 0.0, 0.0 );
		$values = self::resolve( $form->get( 'Matrix' ) );
		if ( $values instanceof ElementArray ) {
			$numbers = array_map( array( __CLASS__, 'number' ), array_values( $values->getContent() ) );
			if ( 6 === count( $numbers ) && ! in_array( null, $numbers, true ) ) {
				$matrix = $numbers;
			}
		}
		$this->walk( $page, $form, self::parse_content( (string) $form->getContent() ), self::multiply( $matrix, $ctm ), $runs, $visited, $depth + 1 );
		unset( $visited[ $id ] );
	}

	/**
	 * Show a string (Tj, ') or an array (TJ): add its runs and return the
	 * text matrix moved past it.
	 *
	 * @param object  $resources Resource owner (fonts for the fallback decode).
	 * @param object  $page      Page.
	 * @param array   $command   Raw command.
	 * @param float[] $tm        Text matrix.
	 * @param float[] $ctm       Current transformation matrix.
	 * @param array   $state     Text state.
	 * @param array[] $runs      Runs (by reference).
	 * @return float[] New text matrix.
	 */
	private function show( $resources, $page, array $command, array $tm, array $ctm, array $state, array &$runs ): array {
		$font = $state['font'];
		if ( null === $font ) {
			$all  = $resources->getFonts();
			$font = $all ? reset( $all ) : null;
			if ( null === $font ) {
				return $tm;
			}
		}
		$metrics = $this->font_metrics( $font );
		$size    = $state['size'] > 0 ? $state['size'] : 1.0;

		// Split into segments at backward moves of the pen.
		$segments = array();
		$current  = array(
			'elements' => array(),
			'offset'   => 0.0,
			'width'    => 0.0,
			'known'    => true,
		);
		$pen      = 0.0;
		foreach ( self::string_operands( $command ) as $element ) {
			if ( 'n' === $element['t'] ) {
				$shift = -(float) $element['c'] / 1000 * $size * $state['th'];
				if ( (float) $element['c'] > 250 ) {
					if ( $current['elements'] ) {
						$segments[] = $current;
					}
					$pen    += $shift;
					$current = array(
						'elements' => array(),
						'offset'   => $pen,
						'width'    => 0.0,
						'known'    => true,
					);
					continue;
				}
				$pen += $shift;
				if ( $current['elements'] ) {
					$current['elements'][] = $element;
					$current['width']     += $shift;
				} else {
					$current['offset'] = $pen;
				}
				continue;
			}
			if ( '' === trim( (string) $element['c'], " \t\r\n\0" ) && '<' === $element['t'] ) {
				continue; // Empty hex string: nothing drawn.
			}
			$advance               = $this->string_advance( $element, $metrics, $state, $size );
			$current['elements'][] = $element;
			$current['width']     += $advance['width'];
			$current['known']      = $current['known'] && $advance['known'];
			$pen                  += $advance['width'];
		}
		if ( $current['elements'] ) {
			$segments[] = $current;
		}

		foreach ( $segments as $segment ) {
			$text = self::decode( $font, $segment['elements'], $resources );
			if ( '' === $text ) {
				continue;
			}
			$width     = $segment['width'];
			$estimated = ! $segment['known'];
			if ( null === $metrics ) {
				$spaces    = substr_count( $text, ' ' );
				$width     = ( ( mb_strlen( $text, 'UTF-8' ) - $spaces ) * self::ESTIMATED_EM_PER_CHAR + $spaces * self::ESTIMATED_EM_PER_SPACE ) * $size * $state['th'];
				$estimated = true;
			}
			$origin = self::multiply( array( 1.0, 0.0, 0.0, 1.0, $segment['offset'], 0.0 ), $tm );
			$run    = self::make_run( self::multiply( $origin, $ctm ), $text, $size, $width, $estimated, count( $runs ) );
			if ( null !== $run ) {
				$runs[] = $run;
			}
			if ( null === $metrics ) {
				$pen = max( $pen, $segment['offset'] + $width );
			}
		}
		return self::multiply( array( 1.0, 0.0, 0.0, 1.0, $pen, 0.0 ), $tm );
	}

	/**
	 * Decode string elements to UTF-8 with the font (and, like the parser,
	 * retry other fonts of the page when the result contains control
	 * characters, a sign of a wrong font).
	 *
	 * @param object $font      Font.
	 * @param array  $elements  String/number elements.
	 * @param object $resources Resource owner.
	 * @return string
	 */
	private static function decode( $font, array $elements, $resources ): string {
		$text = (string) $font->decodeText( $elements, self::DECODE_FONT_FACTOR );
		if ( preg_match( '/[\x00-\x08\x0E-\x1F\x7F]/', $text ) ) {
			foreach ( $resources->getFonts() as $other ) {
				if ( $other === $font ) {
					continue;
				}
				$retry = (string) $other->decodeText( $elements, self::DECODE_FONT_FACTOR );
				if ( ! preg_match( '/[\x00-\x08\x0E-\x1F\x7F]/', $retry ) ) {
					$text = $retry;
					break;
				}
			}
		}
		return str_replace( array( "\r", "\n", "\t" ), array( '', '', ' ' ), $text );
	}

	/**
	 * String and number operands of a text-showing command, as
	 * [ 't' => '(' | '<' | 'n', 'c' => raw content ].
	 *
	 * @param array $command Raw command.
	 * @return array[]
	 */
	private static function string_operands( array $command ): array {
		$content = isset( $command['c'] ) ? $command['c'] : '';
		if ( 'TJ' === $command['o'] ) {
			$operands = array();
			foreach ( is_array( $content ) ? $content : array() as $element ) {
				if ( is_array( $element ) && isset( $element['t'], $element['c'] ) && is_string( $element['c'] ) ) {
					$operands[] = array(
						't' => (string) $element['t'],
						'c' => $element['c'],
					);
				}
			}
			return $operands;
		}
		if ( is_string( $content ) && isset( $command['t'] ) && ( '(' === $command['t'] || '<' === $command['t'] ) ) {
			return array(
				array(
					't' => (string) $command['t'],
					'c' => $content,
				),
			);
		}
		return array();
	}

	/**
	 * Advance of one string element in text space (PDF 9.4.4), from the
	 * raw character codes and the font's glyph widths.
	 *
	 * @param array      $element String element.
	 * @param array|null $metrics Font metrics or null (then 0, known=false).
	 * @param array      $state   Text state.
	 * @param float      $size    Font size.
	 * @return array{width: float, known: bool}
	 */
	private function string_advance( array $element, $metrics, array $state, float $size ): array {
		if ( null === $metrics ) {
			return array(
				'width' => 0.0,
				'known' => false,
			);
		}
		$bytes = '<' === $element['t'] ? self::hex_bytes( (string) $element['c'] ) : self::literal_bytes( (string) $element['c'] );
		$step  = $metrics['bytes'];
		$count = strlen( $bytes );
		$width = 0.0;
		$known = true;
		for ( $i = 0; $i + $step <= $count; $i += $step ) {
			$code = 1 === $step ? ord( $bytes[ $i ] ) : ( ord( $bytes[ $i ] ) << 8 ) | ord( $bytes[ $i + 1 ] );
			if ( isset( $metrics['widths'][ $code ] ) ) {
				$glyph = $metrics['widths'][ $code ];
			} elseif ( null !== $metrics['default'] ) {
				$glyph = $metrics['default'];
			} else {
				$glyph = 1000 * self::ESTIMATED_EM_PER_CHAR;
				$known = false;
			}
			$spacing = $state['tc'] + ( 1 === $step && 32 === $code ? $state['tw'] : 0.0 );
			$width  += ( $glyph * $metrics['scale'] * $size + $spacing ) * $state['th'];
		}
		return array(
			'width' => $width,
			'known' => $known,
		);
	}

	/**
	 * Glyph widths of a font: simple fonts (Widths/FirstChar, one byte per
	 * code) and Type0/CID fonts (W/DW, two bytes per code).
	 *
	 * @param object $font HDS_Vendor\Smalot\PdfParser\Font.
	 * @return array{bytes: int, widths: array<int, float>, default: float|null, scale: float}|null
	 */
	private function font_metrics( $font ) {
		$key = spl_object_id( $font );
		if ( array_key_exists( $key, $this->metrics ) ) {
			return $this->metrics[ $key ];
		}
		$metrics = null;
		try {
			$subtype = self::name( $font->get( 'Subtype' ) );
			if ( 'Type0' === $subtype ) {
				$metrics = self::cid_metrics( $font );
			} else {
				$widths = self::resolve( $font->get( 'Widths' ) );
				if ( $widths instanceof ElementArray ) {
					$first = (int) self::number( $font->get( 'FirstChar' ) );
					$map   = array();
					foreach ( array_values( $widths->getContent() ) as $offset => $value ) {
						$number = self::number( $value );
						if ( null !== $number ) {
							$map[ $first + $offset ] = $number;
						}
					}
					$descriptor = self::resolve( $font->get( 'FontDescriptor' ) );
					$missing    = is_object( $descriptor ) && method_exists( $descriptor, 'get' ) ? self::number( $descriptor->get( 'MissingWidth' ) ) : null;
					$scale      = 0.001;
					if ( 'Type3' === $subtype ) {
						$matrix = self::resolve( $font->get( 'FontMatrix' ) );
						$values = $matrix instanceof ElementArray ? array_values( $matrix->getContent() ) : array();
						$scale  = isset( $values[0] ) && null !== self::number( $values[0] ) ? (float) self::number( $values[0] ) : 0.001;
					}
					$metrics = array(
						'bytes'   => 1,
						'widths'  => $map,
						'default' => $missing ? $missing : null,
						'scale'   => $scale,
					);
				}
			}
		} catch ( \Throwable $e ) {
			$metrics = null;
		}
		$this->metrics[ $key ] = $metrics;
		return $metrics;
	}

	/**
	 * Widths of a Type0 font from its descendant CIDFont (W array, DW).
	 *
	 * @param object $font Type0 font.
	 * @return array|null
	 */
	private static function cid_metrics( $font ) {
		$descendants = self::resolve( $font->get( 'DescendantFonts' ) );
		if ( ! $descendants instanceof ElementArray ) {
			return null;
		}
		$list    = array_values( $descendants->getContent() );
		$cidfont = isset( $list[0] ) ? self::resolve( $list[0] ) : null;
		if ( ! is_object( $cidfont ) || ! method_exists( $cidfont, 'get' ) ) {
			return null;
		}
		$default = self::number( $cidfont->get( 'DW' ) );
		$widths  = array();
		$w       = self::resolve( $cidfont->get( 'W' ) );
		$items   = $w instanceof ElementArray ? array_values( $w->getContent() ) : array();
		$count   = count( $items );
		$total   = 0;
		for ( $i = 0; $i < $count && $total < 65536; ) {
			$start = self::number( $items[ $i ] );
			$next  = isset( $items[ $i + 1 ] ) ? self::resolve( $items[ $i + 1 ] ) : null;
			if ( null === $start ) {
				break;
			}
			if ( $next instanceof ElementArray ) {
				foreach ( array_values( $next->getContent() ) as $offset => $value ) {
					$number = self::number( $value );
					if ( null !== $number ) {
						$widths[ (int) $start + $offset ] = $number;
						$total++;
					}
				}
				$i += 2;
				continue;
			}
			$last  = null === $next ? null : self::number( $next );
			$value = isset( $items[ $i + 2 ] ) ? self::number( $items[ $i + 2 ] ) : null;
			if ( null === $last || null === $value ) {
				break;
			}
			for ( $cid = (int) $start; $cid <= (int) $last && $total < 65536; $cid++, $total++ ) {
				$widths[ $cid ] = $value;
			}
			$i += 3;
		}
		return array(
			'bytes'   => 2,
			'widths'  => $widths,
			'default' => null !== $default ? $default : 1000.0,
			'scale'   => 0.001,
		);
	}

	/**
	 * Resolve an indirect reference element to its object.
	 *
	 * @param mixed $element Element.
	 * @return mixed
	 */
	private static function resolve( $element ) {
		if ( $element instanceof ElementXRef ) {
			return $element->getObject();
		}
		return $element;
	}

	/**
	 * Numeric value of an element (or null).
	 *
	 * @param mixed $element Element or scalar.
	 * @return float|null
	 */
	private static function number( $element ) {
		$element = self::resolve( $element );
		if ( is_object( $element ) && method_exists( $element, 'getContent' ) ) {
			$element = $element->getContent();
		}
		return is_numeric( $element ) ? (float) $element : null;
	}

	/**
	 * Name value of an element ('' when missing).
	 *
	 * @param mixed $element Element.
	 * @return string
	 */
	private static function name( $element ): string {
		$element = self::resolve( $element );
		if ( is_object( $element ) && method_exists( $element, 'getContent' ) ) {
			$element = $element->getContent();
		}
		return is_scalar( $element ) ? ltrim( (string) $element, '/' ) : '';
	}

	/**
	 * Bytes of a hex string operand.
	 *
	 * @param string $hex Hex digits (whitespace allowed).
	 * @return string
	 */
	private static function hex_bytes( string $hex ): string {
		$hex = (string) preg_replace( '/[^0-9A-Fa-f]/', '', $hex );
		if ( strlen( $hex ) % 2 ) {
			$hex .= '0';
		}
		return '' === $hex ? '' : (string) hex2bin( $hex );
	}

	/**
	 * Bytes of a literal string operand (escape sequences resolved).
	 *
	 * @param string $literal Content between the parentheses.
	 * @return string
	 */
	private static function literal_bytes( string $literal ): string {
		return (string) preg_replace_callback(
			'/\\\\(?:([0-7]{1,3})|(\r\n|\r|\n)|(.))/s',
			static function ( array $m ) {
				if ( isset( $m[1] ) && '' !== $m[1] ) {
					return chr( octdec( $m[1] ) & 0xFF );
				}
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					return '';
				}
				$escapes = array(
					'n' => "\n",
					'r' => "\r",
					't' => "\t",
					'b' => "\x08",
					'f' => "\f",
				);
				return isset( $escapes[ $m[3] ] ) ? $escapes[ $m[3] ] : $m[3];
			},
			$literal
		);
	}

	/**
	 * Parse exactly $count numbers.
	 *
	 * @param string $operands Operands.
	 * @param int    $count    Expected count.
	 * @return float[]|null
	 */
	private static function numbers( string $operands, int $count ) {
		$parts = preg_split( '/\s+/', trim( $operands ) );
		if ( ! is_array( $parts ) || count( $parts ) !== $count ) {
			return null;
		}
		$numbers = array();
		foreach ( $parts as $part ) {
			if ( ! is_numeric( $part ) ) {
				return null;
			}
			$numbers[] = (float) $part;
		}
		return $numbers;
	}

	/**
	 * Product of two PDF matrices [a b c d e f] (row-vector convention).
	 *
	 * @param float[] $m First.
	 * @param float[] $n Second.
	 * @return float[]
	 */
	private static function multiply( array $m, array $n ): array {
		return array(
			$m[0] * $n[0] + $m[1] * $n[2],
			$m[0] * $n[1] + $m[1] * $n[3],
			$m[2] * $n[0] + $m[3] * $n[2],
			$m[2] * $n[1] + $m[3] * $n[3],
			$m[4] * $n[0] + $m[5] * $n[2] + $n[4],
			$m[4] * $n[1] + $m[5] * $n[3] + $n[5],
		);
	}

	/**
	 * Reverse a UTF-8 string by characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function reverse( string $text ): string {
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $chars ) ? implode( '', array_reverse( $chars ) ) : $text;
	}

	/**
	 * Build a positioned run, or null for empty/degenerate runs.
	 *
	 * Coordinates are projected onto the run's own baseline direction, so
	 * rotated text (stamps, margin notes) forms lines too:
	 * t = position along the baseline, s = position across it.
	 *
	 * @param float[] $matrix    Text matrix x CTM at the run's origin.
	 * @param string  $text      Text of the run.
	 * @param float   $font_size Font size from Tf.
	 * @param float   $width     Advance in text space.
	 * @param bool    $estimated Whether the width is an estimate.
	 * @param int     $index     Stream order.
	 * @return array|null
	 */
	private static function make_run( array $matrix, string $text, float $font_size, float $width, bool $estimated, int $index ) {
		list( $a, $b, $c, $d, $e, $f ) = $matrix;
		$scale_x = sqrt( $a * $a + $b * $b );
		$scale_y = sqrt( $c * $c + $d * $d );
		if ( '' === $text || $scale_x < 1e-9 ) {
			return null;
		}
		$ux   = $a / $scale_x;
		$uy   = $b / $scale_x;
		$size = $font_size > 1e-6 ? $font_size : 1.0;
		return array(
			'text'      => $text,
			'index'     => $index,
			'angle'     => (int) round( rad2deg( atan2( $uy, $ux ) ) ),
			't'         => $e * $ux + $f * $uy,
			's'         => $f * $ux - $e * $uy,
			'height'    => $size * ( $scale_y > 1e-9 ? $scale_y : $scale_x ),
			'width'     => max( 0.0, $width * $scale_x ),
			'estimated' => $estimated,
		);
	}

	/**
	 * Group runs into lines and render each line left to right.
	 *
	 * @param array[] $runs Runs from make_run().
	 * @return string[] Lines, top to bottom (horizontal text first).
	 */
	private function lines( array $runs ): array {
		$by_angle = array();
		foreach ( $runs as $run ) {
			$by_angle[ $run['angle'] ][] = $run;
		}
		// Horizontal text first, then other orientations.
		uksort(
			$by_angle,
			static function ( $x, $y ) {
				return array( 0 !== $x, $x ) <=> array( 0 !== $y, $y );
			}
		);

		$lines = array();
		foreach ( $by_angle as $group ) {
			usort(
				$group,
				static function ( array $x, array $y ) {
					return array( $y['s'], $x['index'] ) <=> array( $x['s'], $y['index'] );
				}
			);

			$current    = array();
			$line_s     = 0.0;
			$line_h     = 0.0;
			$previous_s = null;
			foreach ( $group as $run ) {
				if ( $current && $line_s - $run['s'] > 0.45 * max( $line_h, $run['height'] ) ) {
					if ( null !== $previous_s && $previous_s - $line_s > 2.2 * $line_h ) {
						$lines[] = '';
					}
					$lines[]    = $this->render_line( $current );
					$previous_s = $line_s;
					$current    = array();
				}
				if ( ! $current ) {
					$line_s = $run['s'];
					$line_h = $run['height'];
				}
				$line_h    = max( $line_h, $run['height'] );
				$current[] = $run;
			}
			if ( $current ) {
				if ( null !== $previous_s && $previous_s - $line_s > 2.2 * $line_h ) {
					$lines[] = '';
				}
				$lines[] = $this->render_line( $current );
			}
		}
		return $lines;
	}

	/**
	 * Concatenate the runs of one line in left-to-right order, inserting a
	 * space where the gap between two runs is wide and no space glyph is
	 * present.
	 *
	 * @param array[] $runs Runs of one line.
	 * @return string Visual-order line.
	 */
	private function render_line( array $runs ): string {
		usort(
			$runs,
			static function ( array $x, array $y ) {
				return array( $x['t'], $x['index'] ) <=> array( $y['t'], $y['index'] );
			}
		);

		// Some generators (LibreOffice) draw the space at a bidi-run boundary
		// on top of the neighbouring run; such a space is not a word gap.
		$solid = array_filter(
			$runs,
			static function ( array $run ) {
				return '' !== trim( $run['text'] );
			}
		);
		$runs  = array_values(
			array_filter(
				$runs,
				static function ( array $run ) use ( $solid ) {
					if ( '' !== trim( $run['text'] ) || $run['width'] <= 0 ) {
						return true;
					}
					foreach ( $solid as $other ) {
						$overlap = min( $run['t'] + $run['width'], $other['t'] + $other['width'] ) - max( $run['t'], $other['t'] );
						if ( $overlap > 0.5 * $run['width'] ) {
							return false;
						}
					}
					return true;
				}
			)
		);

		// Generators that write space glyphs need fewer synthetic spaces: a
		// small gap there is justification at a bidi-run boundary ("17%," must
		// stay together); a gap wider than ~1/3 em is a missing space glyph or
		// a table column. Without space glyphs, 0.2 em separates words.
		$has_spaces = false;
		foreach ( $runs as $run ) {
			// A leading space may be the parser's guess; others are glyphs.
			if ( false !== strpos( ltrim( $run['text'] ), ' ' ) || '' === trim( $run['text'] ) ) {
				$has_spaces = true;
				break;
			}
		}
		$factor = $has_spaces ? 0.35 : 0.2;

		$line        = '';
		$end         = null;
		$uncertainty = 0.0;
		foreach ( $runs as $run ) {
			if ( null !== $end && '' !== $line ) {
				// An estimated width can be off by ~15 %: demand a wider gap then.
				$threshold = $factor * $run['height'] + $uncertainty;
				if ( $run['t'] - $end > $threshold && ' ' !== substr( $line, -1 ) && ' ' !== substr( $run['text'], 0, 1 ) ) {
					$line .= ' ';
				}
			}
			$line   .= $run['text'];
			$run_end = $run['t'] + $run['width'];
			if ( null === $end || $run_end >= $end ) {
				$end         = $run_end;
				$uncertainty = $run['estimated'] ? 0.15 * $run['width'] : 0.0;
			}
		}
		return rtrim( $line );
	}
}
