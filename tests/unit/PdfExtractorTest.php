<?php
/**
 * Tests for HDS_Pdf_Extractor and the vendored pdfparser.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers HDS_Pdf_Extractor
 */
class PdfExtractorTest extends TestCase {

	const SH   = 'SH-22-06-57879-590';
	const ARAR = 'ARAR-25-09-8059';

	/**
	 * Distinctive ARAR sentences (logical order).
	 */
	const ARAR_PHRASES = array(
		'ועדת הערר לפיצויים והיטל השבחה',
		'בדו"ח זה הוערך הרווח הצפוי במיזם בשיעור של 17%, זאת בהתחשב בעלויות מימוש התוכנית',
		'חוק התכנון והבניה, תשכ"ה-1965: סע\' 19(ב)(9) לתוספת השלישית',
		'בד בבד עם קידומו של ההליך התכנוני, התקשרה דמרי בהסכמים עם בעלי הזכויות במתחם',
		'התוכנית קודמה על ידי י.ח. דמרי בניה ופיתוח בע"מ',
	);

	/**
	 * Temporary files.
	 *
	 * @var string[]
	 */
	private $temp = array();

	protected function setUp(): void {
		hds_test_reset();
	}

	protected function tearDown(): void {
		foreach ( $this->temp as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp = array();
	}

	// ------------------------------------------------------------------
	// Real documents.
	// ------------------------------------------------------------------

	public function test_libreoffice_pdf_short_document_is_logical_hebrew(): void {
		$text = HDS_Extractor_Factory::extract( self::generated( self::SH . '.pdf' ) );
		$this->assertCoverage( self::reference( self::SH ), $text, 0.9 );
		$this->assertPhrases(
			$text,
			array(
				'ניתן תוקף של פסק דין להסכמת הצדדים',
				'בית משפט השלום בפתח תקווה',
				'ת"א 57879-06-22 ליפשיץ ואח\' נ\' מיזם נדל"ן (תמ"א 38) סוקולוב',
				'החזר אגרה בהתאם לתקנות',
			)
		);
	}

	public function test_libreoffice_pdf_long_document_is_logical_hebrew(): void {
		$text = HDS_Extractor_Factory::extract( self::generated( self::ARAR . '.pdf' ) );
		$this->assertCoverage( self::reference( self::ARAR ), $text, 0.9 );
		$this->assertPhrases( $text, self::ARAR_PHRASES );
		// A number wrapped at its hyphen is joined again.
		$this->assertStringContainsString( 'העוררים בערר 8069-74/25:', self::flat( $text ) );
	}

	public function test_cairo_written_pdf_is_logical_hebrew(): void {
		// Type0/Identity-H fonts, lowercase hex strings and TJ arrays that move
		// the pen backwards (each right-to-left run placed from its right edge).
		$path = self::generated( self::SH . '.cairo.pdf' );
		if ( ! is_file( $path ) ) {
			$this->markTestSkipped( 'Optional fixture (pdftocairo + pypdf) not generated.' );
		}
		$text = HDS_Extractor_Factory::extract( $path );
		$this->assertCoverage( self::reference( self::SH ), $text, 0.95 );
		$this->assertStringNotContainsString( '<>', $text );
		$this->assertPhrases(
			$text,
			array(
				'ניתן תוקף של פסק דין להסכמת הצדדים',
				'בית משפט השלום בפתח תקווה',
				'ת"א 57879-06-22 ליפשיץ ואח\' נ\' מיזם נדל"ן (תמ"א 38) סוקולוב',
				'1. נטע ליפשיץ',
				'13. שרון כהן',
			)
		);
	}

	public function test_naive_parser_output_is_reversed_so_the_fix_is_needed(): void {
		$this->load_vendor();
		foreach ( array( self::SH . '.pdf', self::SH . '.visual.pdf' ) as $file ) {
			$parser = new \HDS_Vendor\Smalot\PdfParser\Parser();
			$naive  = self::flat( $parser->parseFile( self::generated( $file ) )->getText() );
			$this->assertStringContainsString( 'הווקת חתפב םולשה טפשמ תיב', $naive, $file );
			$this->assertStringNotContainsString( 'בית משפט השלום בפתח תקווה', $naive, $file );
		}
	}

	public function test_visual_order_pdf_is_converted_to_logical(): void {
		$text = HDS_Extractor_Factory::extract( self::generated( self::SH . '.visual.pdf' ) );
		$this->assertCoverage( self::reference( self::SH ), $text, 0.95 );
		$this->assertPhrases(
			$text,
			array(
				'ניתן תוקף של פסק דין להסכמת הצדדים',
				'בית משפט השלום בפתח תקווה',
				// Mirrored brackets in the visual PDF are restored.
				'ת"א 57879-06-22 ליפשיץ ואח\' נ\' מיזם נדל"ן (תמ"א 38) סוקולוב כ"ס בע"מ ואח\'',
				'1. נטע ליפשיץ',
				'בעניין עריכה ושינויים במסמכי פסיקה, חקיקה ועוד באתר נבו – הקש כאן',
			)
		);
	}

	public function test_extractor_output_without_factory_cleaning(): void {
		$text = ( new HDS_Pdf_Extractor() )->extract( self::generated( self::SH . '.visual.pdf' ) );
		$this->assertStringStartsWith( 'בית משפט השלום בפתח תקווה', $text );
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
	}

	// ------------------------------------------------------------------
	// Error handling.
	// ------------------------------------------------------------------

	public function test_scanned_pdf(): void {
		$this->assertExtractionError( self::generated( 'scanned.pdf' ), HDS_Extraction_Exception::SCANNED_PDF, 'Scanned PDF: no text layer found. OCR is not supported.' );
	}

	public function test_encrypted_pdf(): void {
		$this->assertExtractionError( self::broken( 'encrypted.pdf' ), HDS_Extraction_Exception::ENCRYPTED );
	}

	public function test_truncated_pdf(): void {
		$this->assertExtractionError( self::broken( 'truncated.pdf' ), HDS_Extraction_Exception::CORRUPT );
	}

	public function test_garbage_after_header_is_corrupt(): void {
		$path = $this->temp_file( "%PDF-1.7\n" . str_repeat( "\x8F\x00garbage\xFF", 200 ) );
		$this->assertExtractionError( $path, HDS_Extraction_Exception::CORRUPT );
	}

	public function test_page_without_text_is_scanned(): void {
		$path = $this->temp_file( self::pdf( "q 10 0 0 10 50 50 cm 0 g 0 0 1 1 re f Q\nBT /F1 12 Tf 72 700 Td (   ) Tj ET" ) );
		$this->assertExtractionError( $path, HDS_Extraction_Exception::SCANNED_PDF );
	}

	public function test_unreadable_page_structure_is_unsupported(): void {
		// A comment inside a page dictionary stored in an object stream (as
		// cairo writes it) defeats the parser: the page loses its entries.
		$readable = $this->temp_file( self::object_stream_pdf( false ) );
		$this->assertSame( 'Hello World with enough letters here', HDS_Extractor_Factory::extract( $readable ) );
		$broken = $this->temp_file( self::object_stream_pdf( true ) );
		$this->assertExtractionError( $broken, HDS_Extraction_Exception::UNSUPPORTED );
	}

	public function test_no_exception_leaks_and_no_content_in_messages(): void {
		foreach ( array( self::broken( 'truncated.pdf' ), self::broken( 'encrypted.pdf' ), self::generated( 'scanned.pdf' ) ) as $path ) {
			try {
				HDS_Extractor_Factory::extract( $path );
				$this->fail( 'Expected an exception for ' . $path );
			} catch ( HDS_Extraction_Exception $e ) {
				$this->assertDoesNotMatchRegularExpression( '/[\x{0590}-\x{05FF}]/u', $e->getMessage() );
			}
		}
	}

	// ------------------------------------------------------------------
	// Layout reconstruction on hand-made PDFs (Latin, standard font).
	// ------------------------------------------------------------------

	public function test_runs_are_ordered_by_position_not_stream_order(): void {
		$content = "BT /F1 12 Tf 160 700 Td (World) Tj ET\n"
			. "BT /F1 12 Tf 72 700 Td (Hello) Tj ET\n"
			. "BT /F1 12 Tf 72 670 Td (second line of the page) Tj ET\n"
			. "BT /F1 12 Tf 72 685 Td (between both lines) Tj ET\n"
			. "BT /F1 12 Tf 72 600 Td (after a paragraph gap) Tj ET";
		$text    = $this->extract_pdf( $content );
		$this->assertSame( "Hello World\nbetween both lines\nsecond line of the page\n\nafter a paragraph gap", $text );
	}

	public function test_tj_adjustments_word_gaps_and_letter_spacing(): void {
		$content = "BT /F1 12 Tf 72 700 Td [(Hello) -400 (World) -250 (again)] TJ ET\n"
			. "BT /F1 12 Tf 72 680 Td [(S) -60 (p) -60 (a) -60 (c) -60 (e) -60 (d)] TJ ET";
		$this->assertSame( "Hello World again\nSpaced", $this->extract_pdf( $content ) );
	}

	public function test_backward_moves_inside_tj_are_reordered(): void {
		// The pen jumps left: the second string is drawn left of the first.
		$content = "BT /F1 12 Tf 300 700 Td [(right side words) 12000 (left side words)] TJ ET";
		$this->assertSame( 'left side words right side words', $this->extract_pdf( $content ) );
	}

	public function test_tokenizer_handles_hex_escapes_comments_and_inline_images(): void {
		$content = "% a comment with (parentheses and <hex>\n"
			. "q BI /W 2 /H 2 /BPC 8 /CS /G ID \x00\xFF) Tj (fake\xFE\x01 EI Q\n"
			. "BT /F1 12 Tf 72 700 Td <48656c6c6f20> Tj (\\(paren\\) in C:\\\\dir) Tj ET\n"
			. "BT /F1 12 Tf 72 680 Td (nested (balanced) parens) Tj ET";
		$text    = $this->extract_pdf( $content );
		$this->assertSame( "Hello (paren) in C:\\dir\nnested (balanced) parens", $text );
		$this->assertStringNotContainsString( 'fake', $text );
	}

	public function test_text_in_form_xobject_is_extracted(): void {
		$objects    = self::objects( "BT /F1 12 Tf 72 700 Td (Page text first) Tj ET\nq 1 0 0 1 72 685 cm /Fm1 Do Q" );
		$form       = 'BT /F1 12 Tf 0 0 Td (Stamp inside a form) Tj ET';
		$objects[3] = str_replace( '/Font << /F1 5 0 R >>', '/Font << /F1 5 0 R >> /XObject << /Fm1 6 0 R >>', $objects[3] );
		$objects[6] = '<< /Type /XObject /Subtype /Form /BBox [0 0 300 50] /Resources << /Font << /F1 5 0 R >> >> /Length ' . strlen( $form ) . " >>\nstream\n" . $form . "\nendstream";
		$path       = $this->temp_file( self::build( $objects ) );
		$this->assertSame( "Page text first\nStamp inside a form", HDS_Extractor_Factory::extract( $path ) );
	}

	public function test_hebrew_runs_written_right_to_left_become_logical(): void {
		// Like LibreOffice: one run per word, emitted right to left, each
		// run's glyphs in visual (drawing) order. Font codes map to Hebrew
		// through a ToUnicode CMap.
		$map = array(
			'A' => 'ב',
			'B' => 'י',
			'C' => 'ת',
			'D' => 'מ',
			'E' => 'ש',
			'F' => 'פ',
			'G' => 'ט',
			'H' => 'ל',
			'I' => 'ו',
			'J' => 'ם',
			'K' => 'ע',
		);
		$enc = static function ( string $visual ) use ( $map ): string {
			$codes = array_flip( $map );
			$out   = '';
			foreach ( preg_split( '//u', $visual, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
				$out .= ' ' === $char ? ' ' : $codes[ $char ];
			}
			return $out;
		};
		$content = 'BT /F1 12 Tf 400 700 Td (' . $enc( 'תיב' ) . ") Tj ET\n"
			. 'BT /F1 12 Tf 340 700 Td (' . $enc( 'טפשמ' ) . ") Tj ET\n"
			. 'BT /F1 12 Tf 400 685 Td (' . $enc( 'םולש' ) . ") Tj ET\n"
			. 'BT /F1 12 Tf 340 685 Td (' . $enc( 'םלוע' ) . ") Tj ET\n"
			. 'BT /F1 12 Tf 250 670 Td (' . $enc( 'םולש םלוע טפשמ תיב' ) . ') Tj ET';
		$objects = self::objects( $content );
		$cmap    = self::to_unicode( $map );
		$objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 6 0 R >>';
		$objects[6] = '<< /Length ' . strlen( $cmap ) . " >>\nstream\n" . $cmap . "\nendstream";
		$path       = $this->temp_file( self::build( $objects ) );
		$this->assertSame( "בית משפט\nשלום עולם\nבית משפט עולם שלום", HDS_Extractor_Factory::extract( $path ) );
	}

	// ------------------------------------------------------------------
	// Vendored library.
	// ------------------------------------------------------------------

	public function test_vendor_is_prefixed_and_guarded(): void {
		$root  = HDS_PLUGIN_DIR . 'vendor/smalot/pdfparser/src';
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		$count = 0;
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$count++;
			$code = (string) file_get_contents( $file->getPathname() );
			$rest = str_replace( array( 'HDS_Vendor\\\\Smalot', 'HDS_Vendor\\Smalot' ), '', $code );
			$this->assertDoesNotMatchRegularExpression( '/Smalot\\\\+PdfParser/', $rest, $file->getPathname() );
			$this->assertMatchesRegularExpression( '/^namespace HDS_Vendor\\\\Smalot\\\\PdfParser[^;]*;\s*\/\/[^\n]*\nif \( ! \\\\defined\( \'ABSPATH\' \) \) \{/m', $code, $file->getPathname() );
			$this->assertStringNotContainsString( 'Symfony\\', $code );
		}
		$this->assertSame( 46, $count );
		$this->assertFileExists( HDS_PLUGIN_DIR . 'vendor/smalot/pdfparser/LICENSE.txt' );
		$this->assertFileExists( HDS_PLUGIN_DIR . 'vendor/README.md' );
	}

	public function test_vendor_autoloader(): void {
		$this->load_vendor();
		$this->assertTrue( function_exists( 'hds_vendor_autoload' ) );
		$this->assertTrue( class_exists( 'HDS_Vendor\Smalot\PdfParser\Font\FontType0' ) );
		// Dynamic class names built by the library resolve to the prefixed copy.
		$this->assertTrue( class_exists( '\HDS_Vendor\Smalot\PdfParser\Encoding\WinAnsiEncoding' ) );
		// Path traversal and foreign classes are ignored without errors.
		hds_vendor_autoload( 'HDS_Vendor\Smalot\PdfParser\..\..\..\..\tests\bootstrap' );
		hds_vendor_autoload( 'HDS_Vendor\Smalot\PdfParser\Font/../Parser' );
		hds_vendor_autoload( 'Smalot\PdfParser\Parser' );
		$this->assertFalse( class_exists( 'HDS_Vendor\Smalot\PdfParser\DoesNotExist' ) );
		// Including the autoloader twice registers it once.
		$before = count( spl_autoload_functions() );
		require HDS_PLUGIN_DIR . 'vendor/autoload.php';
		$this->assertSame( $before, count( spl_autoload_functions() ) );
	}

	public function test_prefix_tool_refuses_web_sapi_and_is_documented(): void {
		$code = (string) file_get_contents( HDS_PLUGIN_DIR . 'tools/prefix-vendor.php' );
		$this->assertStringContainsString( "'cli' !== PHP_SAPI", $code );
		$this->assertStringContainsString( 'HDS_Vendor', $code );
	}

	public function test_memory_limit_parser(): void {
		$method = new ReflectionMethod( 'HDS_Pdf_Extractor', 'bytes' );
		$method->setAccessible( true );
		$this->assertSame( 268435456, $method->invoke( null, '256M' ) );
		$this->assertSame( 1073741824, $method->invoke( null, '1G' ) );
		$this->assertSame( 524288, $method->invoke( null, '512k' ) );
		$this->assertSame( 1000, $method->invoke( null, '1000' ) );
		$this->assertSame( -1, $method->invoke( null, '-1' ) );
		$this->assertSame( -1, $method->invoke( null, '' ) );
	}

	public function test_library_notices_are_silenced_only_during_extraction(): void {
		$this->assertTrue( HDS_Pdf_Extractor::silence_library_notices( E_WARNING ) );
		$this->assertTrue( HDS_Pdf_Extractor::silence_library_notices( E_DEPRECATED ) );
		$this->assertFalse( HDS_Pdf_Extractor::silence_library_notices( E_USER_ERROR ) );

		$handler = static function () {
			return true;
		};
		set_error_handler( $handler );
		HDS_Extractor_Factory::extract( self::generated( self::SH . '.pdf' ) );
		$current = set_error_handler( null );
		restore_error_handler();
		restore_error_handler();
		$this->assertSame( $handler, $current );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Extract a one-page hand-made PDF through the factory.
	 *
	 * @param string $content Page content stream.
	 * @return string
	 */
	private function extract_pdf( string $content ): string {
		return HDS_Extractor_Factory::extract( $this->temp_file( self::pdf( $content ) ) );
	}

	/**
	 * Objects of a one-page PDF with Helvetica as /F1.
	 *
	 * @param string $content Content stream.
	 * @return array<int, string>
	 */
	private static function objects( string $content ): array {
		return array(
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
			4 => '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream",
			5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		);
	}

	/**
	 * Serialize PDF objects with a valid cross-reference table.
	 *
	 * @param array<int, string> $objects Object number => body.
	 * @return string
	 */
	private static function build( array $objects ): string {
		ksort( $objects );
		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $number => $body ) {
			$offsets[ $number ] = strlen( $out );
			$out               .= $number . " 0 obj\n" . $body . "\nendobj\n";
		}
		$size = max( array_keys( $objects ) ) + 1;
		$xref = strlen( $out );
		$out .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
		for ( $i = 1; $i < $size; $i++ ) {
			$out .= isset( $offsets[ $i ] ) ? sprintf( "%010d 00000 n \n", $offsets[ $i ] ) : "0000000000 65535 f \n";
		}
		return $out . "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}

	/**
	 * One-page PDF.
	 *
	 * @param string $content Content stream.
	 * @return string
	 */
	private static function pdf( string $content ): string {
		return self::build( self::objects( $content ) );
	}

	/**
	 * PDF 1.5 with the catalog, pages and page objects in an object stream
	 * and a cross-reference stream.
	 *
	 * @param bool $comment Put a comment inside the page dictionary.
	 * @return string
	 */
	private static function object_stream_pdf( bool $comment ): string {
		$content = 'BT /F1 12 Tf 72 700 Td (Hello World with enough letters here) Tj ET';
		$inner   = array(
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>',
			3 => '<< /Type /Page' . ( $comment ? " % 1\n" : ' ' ) . '/Parent 2 0 R /MediaBox [ 0 0 595 842 ] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
		);
		$header  = '';
		$body    = '';
		foreach ( $inner as $number => $object ) {
			$header .= $number . ' ' . strlen( $body ) . ' ';
			$body   .= $object . "\n";
		}
		$header .= "\n";
		$stream  = $header . $body;

		$out     = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		$offsets[4] = strlen( $out );
		$out       .= "4 0 obj\n<< /Length " . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream\nendobj\n";
		$offsets[5] = strlen( $out );
		$out       .= "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
		$offsets[6] = strlen( $out );
		$out       .= "6 0 obj\n<< /Type /ObjStm /N 3 /First " . strlen( $header ) . ' /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n";
		$offsets[7] = strlen( $out );

		$rows = pack( 'CNn', 0, 0, 65535 );
		foreach ( array( 0, 1, 2 ) as $index ) {
			$rows .= pack( 'CNn', 2, 6, $index );
		}
		foreach ( array( 4, 5, 6, 7 ) as $number ) {
			$rows .= pack( 'CNn', 1, $offsets[ $number ], 0 );
		}
		return $out . "7 0 obj\n<< /Type /XRef /Size 8 /W [1 4 2] /Root 1 0 R /Length " . strlen( $rows ) . " >>\nstream\n" . $rows . "\nendstream\nendobj\nstartxref\n" . $offsets[7] . "\n%%EOF\n";
	}

	/**
	 * ToUnicode CMap for single-byte codes.
	 *
	 * @param array<string, string> $map Byte => UTF-8 character.
	 * @return string
	 */
	private static function to_unicode( array $map ): string {
		$lines = array( '<20> <0020>' );
		foreach ( $map as $byte => $char ) {
			$lines[] = sprintf( '<%02X> <%04X>', ord( $byte ), mb_ord( $char, 'UTF-8' ) );
		}
		return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
			. "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
			. "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
			. "1 begincodespacerange\n<00> <FF>\nendcodespacerange\n"
			. count( $lines ) . " beginbfchar\n" . implode( "\n", $lines ) . "\nendbfchar\n"
			. "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
	}

	/**
	 * Write a temporary file.
	 *
	 * @param string $data Contents.
	 * @return string Path (.pdf).
	 */
	private function temp_file( string $data ): string {
		$path = tempnam( sys_get_temp_dir(), 'hds-pdf-' );
		unlink( $path );
		$path .= '.pdf';
		file_put_contents( $path, $data );
		$this->temp[] = $path;
		return $path;
	}

	/**
	 * Load the vendored autoloader.
	 *
	 * @return void
	 */
	private function load_vendor(): void {
		require_once HDS_PLUGIN_DIR . 'vendor/autoload.php';
	}

	/**
	 * Assert an extraction error code (and optionally the message).
	 *
	 * @param string      $path    File.
	 * @param string      $code    Expected code.
	 * @param string|null $message Expected message.
	 * @return void
	 */
	private function assertExtractionError( string $path, string $code, $message = null ): void {
		try {
			HDS_Extractor_Factory::extract( $path );
		} catch ( HDS_Extraction_Exception $e ) {
			$this->assertSame( $code, $e->get_error_code(), $e->getMessage() );
			if ( null !== $message ) {
				$this->assertSame( $message, $e->getMessage() );
			}
			return;
		}
		$this->fail( 'No exception for ' . basename( $path ) );
	}

	/**
	 * Path of a generated fixture.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function generated( string $name ): string {
		return HDS_FIXTURES_DIR . '/generated/' . $name;
	}

	/**
	 * Path of a broken fixture.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function broken( string $name ): string {
		return HDS_FIXTURES_DIR . '/broken/' . $name;
	}

	/**
	 * Reference text of a document.
	 *
	 * @param string $name Document name.
	 * @return string
	 */
	private static function reference( string $name ): string {
		return (string) file_get_contents( HDS_FIXTURES_DIR . '/reference/' . $name . '.txt' );
	}

	/**
	 * Normalized single-line text for phrase comparison.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function flat( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', HDS_Normalizer::normalize( $text ) ) );
	}

	/**
	 * At least $ratio of the reference tokens (multiset) are present.
	 *
	 * @param string $reference Reference text.
	 * @param string $text      Extracted text.
	 * @param float  $ratio     Minimum share.
	 * @return void
	 */
	private function assertCoverage( string $reference, string $text, float $ratio ): void {
		$expected  = HDS_Normalizer::tokenize( $reference );
		$available = array_count_values( HDS_Normalizer::tokenize( $text ) );
		$found     = 0;
		foreach ( $expected as $token ) {
			if ( ! empty( $available[ $token ] ) ) {
				$available[ $token ]--;
				$found++;
			}
		}
		$this->assertGreaterThanOrEqual( $ratio, $found / max( 1, count( $expected ) ), 'Token coverage too low' );
	}

	/**
	 * Every phrase occurs contiguously, in reading order (and not reversed).
	 *
	 * @param string   $text    Extracted text.
	 * @param string[] $phrases Phrases.
	 * @return void
	 */
	private function assertPhrases( string $text, array $phrases ): void {
		$flat = self::flat( $text );
		foreach ( $phrases as $phrase ) {
			$this->assertStringContainsString( self::flat( $phrase ), $flat );
		}
	}
}
