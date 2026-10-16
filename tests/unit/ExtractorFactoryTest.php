<?php
/**
 * Tests for HDS_Extractor_Factory (foundation) with the real extractors.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers HDS_Extractor_Factory
 */
class ExtractorFactoryTest extends TestCase {

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

	public function test_supported_extensions_and_extractor_classes(): void {
		$this->assertSame( array( 'doc', 'docx', 'pdf', 'txt' ), HDS_Extractor_Factory::supported_extensions() );
		$this->assertInstanceOf( HDS_Pdf_Extractor::class, HDS_Extractor_Factory::for_extension( 'PDF' ) );
		$this->assertInstanceOf( HDS_Docx_Extractor::class, HDS_Extractor_Factory::for_extension( 'docx' ) );
		$this->assertInstanceOf( HDS_Txt_Extractor::class, HDS_Extractor_Factory::for_extension( 'Txt' ) );
		$this->assertInstanceOf( HDS_Extractor_Interface::class, HDS_Extractor_Factory::for_extension( 'doc' ) );
		$this->expectException( HDS_Extraction_Exception::class );
		HDS_Extractor_Factory::for_extension( 'odt' );
	}

	public function test_docx_renamed_to_doc_works(): void {
		$path = $this->copy_fixture( '/generated/SH-22-06-57879-590.docx', 'doc' );
		$this->assertSame( 'docx', HDS_Extractor_Factory::detect_format( $path, 'doc' ) );
		$this->assertStringContainsString( 'ניתן תוקף של פסק דין להסכמת הצדדים', HDS_Extractor_Factory::extract( $path ) );
	}

	public function test_word97_renamed_to_docx_works(): void {
		$path = $this->copy_fixture( '/SH-22-06-57879-590.doc', 'docx' );
		$this->assertSame( 'doc', HDS_Extractor_Factory::detect_format( $path, 'docx' ) );
		$this->assertStringContainsString( 'ניתן תוקף של פסק דין להסכמת הצדדים', HDS_Extractor_Factory::extract( $path ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function unsupported_word_variants(): array {
		return array(
			'rtf as doc'            => array( "{\\rtf1\\ansi\\ansicpg1255 {\\fonttbl} שלום}", 'doc' ),
			'rtf with whitespace'   => array( "\r\n  {\\rtf1\\ansi hello}", 'docx' ),
			'html as doc'           => array( '<html><body><p>שלום עולם</p></body></html>', 'doc' ),
			'html doctype as doc'   => array( "<!DOCTYPE html>\n<html lang=\"he\"><body>טקסט</body></html>", 'doc' ),
			'word html with xml pi' => array( '<?xml version="1.0"?>' . "\n" . '<html xmlns:w="urn:schemas-microsoft-com:office:word"><body>x</body></html>', 'docx' ),
			'rtf as pdf'            => array( '{\\rtf1 not a pdf}', 'pdf' ),
		);
	}

	/**
	 * @dataProvider unsupported_word_variants
	 */
	public function test_rtf_and_html_with_word_extension_are_unsupported( string $content, string $ext ): void {
		$this->assertExtractionError( $this->file( $content, $ext ), HDS_Extraction_Exception::UNSUPPORTED );
	}

	public function test_html_saved_as_txt_is_read_as_text(): void {
		$this->assertSame( '<p>שלום</p>', HDS_Extractor_Factory::extract( $this->file( '<p>שלום</p>', 'txt' ) ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function wrong_magic(): array {
		return array(
			'text as pdf'   => array( 'just some text, not a PDF at all', 'pdf' ),
			'text as doc'   => array( 'plain text pretending to be Word', 'doc' ),
			'text as docx'  => array( 'plain text pretending to be Word', 'docx' ),
			'pdf as docx'   => array( "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n", 'docx' ),
			'zip as pdf'    => array( "PK\x03\x04" . str_repeat( "\0", 40 ), 'pdf' ),
		);
	}

	/**
	 * @dataProvider wrong_magic
	 */
	public function test_wrong_magic_is_corrupt( string $content, string $ext ): void {
		$this->assertExtractionError( $this->file( $content, $ext ), HDS_Extraction_Exception::CORRUPT );
	}

	public function test_missing_file_is_unreadable(): void {
		$this->assertExtractionError( HDS_FIXTURES_DIR . '/does-not-exist.pdf', HDS_Extraction_Exception::UNREADABLE );
		$this->assertExtractionError( HDS_FIXTURES_DIR, HDS_Extraction_Exception::UNREADABLE );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function unsupported_extensions(): array {
		return array(
			'odt'  => array( 'odt' ),
			'rtf'  => array( 'rtf' ),
			'xlsx' => array( 'xlsx' ),
			'none' => array( '' ),
		);
	}

	/**
	 * @dataProvider unsupported_extensions
	 */
	public function test_unsupported_extension( string $ext ): void {
		$path = $this->file( "PK\x03\x04 some content", 'bin' );
		$this->assertExtractionError( $path, HDS_Extraction_Exception::UNSUPPORTED, $ext );
	}

	public function test_explicit_extension_overrides_the_file_name(): void {
		$path = $this->copy_fixture( '/generated/SH-22-06-57879-590.utf8.txt', 'upload' );
		$this->assertStringStartsWith( 'בית משפט השלום בפתח תקווה', HDS_Extractor_Factory::extract( $path, 'txt' ) );
		$this->assertStringStartsWith( 'בית משפט השלום בפתח תקווה', HDS_Extractor_Factory::extract( $path, 'TXT' ) );
	}

	public function test_empty_and_textless_files(): void {
		$this->assertExtractionError( $this->file( '', 'pdf' ), HDS_Extraction_Exception::NO_TEXT );
		$this->assertExtractionError( $this->file( '', 'docx' ), HDS_Extraction_Exception::NO_TEXT );
		$this->assertExtractionError( $this->file( " \n\t – , . ! \u{200F}", 'txt' ), HDS_Extraction_Exception::NO_TEXT );
	}

	public function test_file_over_the_hard_limit_is_too_large(): void {
		$path   = $this->file( '', 'txt' );
		$handle = fopen( $path, 'wb' );
		ftruncate( $handle, HDS_Extractor_Factory::MAX_FILE_BYTES + 1 ); // Sparse: no disk use.
		fclose( $handle );
		$this->assertExtractionError( $path, HDS_Extraction_Exception::TOO_LARGE );
	}

	public function test_output_is_cleaned(): void {
		$text = HDS_Extractor_Factory::extract( $this->file( "\u{FEFF}  שורה\u{200F} א\r\n\r\n\r\n\r\nשורה\tב  ", 'txt' ) );
		$this->assertSame( "שורה א\n\nשורה ב", $text );
	}

	public function test_every_generated_fixture_extracts_or_fails_cleanly(): void {
		$expected = array(
			'scanned.pdf' => HDS_Extraction_Exception::SCANNED_PDF,
		);
		foreach ( glob( HDS_FIXTURES_DIR . '/generated/*.{docx,pdf,txt}', GLOB_BRACE ) as $path ) {
			$name = basename( $path );
			try {
				$text = HDS_Extractor_Factory::extract( $path );
				$this->assertArrayNotHasKey( $name, $expected );
				$this->assertMatchesRegularExpression( '/[\x{05D0}-\x{05EA}]{3}/u', $text, $name );
			} catch ( HDS_Extraction_Exception $e ) {
				$this->assertArrayHasKey( $name, $expected, $name . ': ' . $e->getMessage() );
				$this->assertSame( $expected[ $name ], $e->get_error_code() );
			}
		}
	}

	/**
	 * Copy a fixture to a temporary file with another extension.
	 *
	 * @param string $relative Path under tests/fixtures.
	 * @param string $ext      New extension.
	 * @return string
	 */
	private function copy_fixture( string $relative, string $ext ): string {
		$path = $this->new_path( $ext );
		copy( HDS_FIXTURES_DIR . $relative, $path );
		return $path;
	}

	/**
	 * Write a temporary file.
	 *
	 * @param string $content Content.
	 * @param string $ext     Extension.
	 * @return string
	 */
	private function file( string $content, string $ext ): string {
		$path = $this->new_path( $ext );
		file_put_contents( $path, $content );
		return $path;
	}

	/**
	 * New temporary path with an extension.
	 *
	 * @param string $ext Extension ('' for none).
	 * @return string
	 */
	private function new_path( string $ext ): string {
		$path = tempnam( sys_get_temp_dir(), 'hds-factory-' );
		unlink( $path );
		if ( '' !== $ext ) {
			$path .= '.' . $ext;
		}
		$this->temp[] = $path;
		return $path;
	}

	/**
	 * Assert an extraction error code.
	 *
	 * @param string      $path File.
	 * @param string      $code Expected code.
	 * @param string|null $ext  Extension argument for extract().
	 * @return void
	 */
	private function assertExtractionError( string $path, string $code, $ext = null ): void {
		try {
			HDS_Extractor_Factory::extract( $path, $ext );
		} catch ( HDS_Extraction_Exception $e ) {
			$this->assertSame( $code, $e->get_error_code(), $e->getMessage() );
			$this->assertNotSame( '', $e->getMessage() );
			return;
		}
		$this->fail( 'No exception for ' . basename( $path ) );
	}
}
