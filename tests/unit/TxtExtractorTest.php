<?php
/**
 * Tests for HDS_Txt_Extractor.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers HDS_Txt_Extractor
 */
class TxtExtractorTest extends TestCase {

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

	/**
	 * @return array<string, array{string}>
	 */
	public function documents(): array {
		return array(
			'short' => array( 'SH-22-06-57879-590' ),
			'long'  => array( 'ARAR-25-09-8059' ),
		);
	}

	/**
	 * @dataProvider documents
	 */
	public function test_utf8_and_cp1255_give_the_reference_text( string $name ): void {
		$expected = HDS_Normalizer::clean_extracted( self::reference( $name ) );
		$this->assertSame( $expected, HDS_Extractor_Factory::extract( HDS_FIXTURES_DIR . '/generated/' . $name . '.utf8.txt' ) );
		$this->assertSame( $expected, HDS_Extractor_Factory::extract( HDS_FIXTURES_DIR . '/generated/' . $name . '.cp1255.txt' ) );
	}

	public function test_fixture_encodings_are_what_they_claim(): void {
		$utf8   = (string) file_get_contents( HDS_FIXTURES_DIR . '/generated/SH-22-06-57879-590.utf8.txt' );
		$cp1255 = (string) file_get_contents( HDS_FIXTURES_DIR . '/generated/SH-22-06-57879-590.cp1255.txt' );
		$this->assertNotSame( "\xEF\xBB\xBF", substr( $utf8, 0, 3 ) );
		$this->assertTrue( mb_check_encoding( $utf8, 'UTF-8' ) );
		$this->assertFalse( mb_check_encoding( $cp1255, 'UTF-8' ) );
		$this->assertSame( HDS_Encoding::CP1255, HDS_Encoding::detect_and_convert( $cp1255 )['encoding'] );
		$this->assertSame( mb_strlen( $utf8, 'UTF-8' ), strlen( $cp1255 ) ); // Lossless: one byte per character.
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function encodings(): array {
		$text = "בית משפט השלום – ת\"א 57879-06-22\r\nעו״ד ישראלי, ₪ 1,500\r\n";
		return array(
			'utf-8 bom'          => array( "\xEF\xBB\xBF" . $text ),
			'utf-16le bom'       => array( "\xFF\xFE" . mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ),
			'utf-16be bom'       => array( "\xFE\xFF" . mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' ) ),
			'utf-16le no bom'    => array( mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ),
			'utf-16be no bom'    => array( mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' ) ),
			'windows-1255'       => array( (string) iconv( 'UTF-8', 'WINDOWS-1255', $text ) ),
			'utf-8 without bom'  => array( $text ),
		);
	}

	/**
	 * @dataProvider encodings
	 */
	public function test_encodings_and_line_endings( string $bytes ): void {
		$this->assertSame( "בית משפט השלום – ת\"א 57879-06-22\nעו״ד ישראלי, ₪ 1,500", HDS_Extractor_Factory::extract( $this->file( $bytes ) ) );
	}

	public function test_bomless_utf16_with_ascii_only(): void {
		$this->assertSame( "Plain English notes\nline 2", HDS_Extractor_Factory::extract( $this->file( mb_convert_encoding( "Plain English notes\nline 2", 'UTF-16LE', 'UTF-8' ) ) ) );
		$this->assertSame( 'Big endian notes', HDS_Extractor_Factory::extract( $this->file( mb_convert_encoding( 'Big endian notes', 'UTF-16BE', 'UTF-8' ) ) ) );
	}

	public function test_western_windows_1252(): void {
		$this->assertSame( 'Café crème, naïve – “quoted”', HDS_Extractor_Factory::extract( $this->file( "Caf\xE9 cr\xE8me, na\xEFve \x96 \x93quoted\x94" ) ) );
	}

	public function test_invalid_utf8_after_bom_is_scrubbed(): void {
		// The broken lead byte becomes U+FFFD (then removed); "(" survives.
		$this->assertSame( 'ok (text', HDS_Extractor_Factory::extract( $this->file( "\xEF\xBB\xBFok \xC3\x28text" ) ) );
	}

	public function test_empty_file_is_no_text(): void {
		$this->assertExtractionError( HDS_FIXTURES_DIR . '/broken/empty.txt', HDS_Extraction_Exception::NO_TEXT );
		$this->assertExtractionError( $this->file( "  \r\n\t \n" ), HDS_Extraction_Exception::NO_TEXT );
	}

	public function test_binary_file_is_rejected(): void {
		$this->assertExtractionError( HDS_FIXTURES_DIR . '/broken/binary.txt', HDS_Extraction_Exception::CORRUPT );
		// A (compressed, binary) PDF renamed to .txt is not read as text either.
		$this->assertExtractionError( $this->file( (string) file_get_contents( HDS_FIXTURES_DIR . '/generated/ARAR-25-09-8059.pdf' ) ), HDS_Extraction_Exception::CORRUPT );
	}

	public function test_extractor_returns_raw_text_for_the_factory_to_clean(): void {
		$this->assertSame( "a\r\nb\t c", ( new HDS_Txt_Extractor() )->extract( $this->file( "a\r\nb\t c" ) ) );
	}

	/**
	 * Write a temporary .txt file.
	 *
	 * @param string $bytes Contents.
	 * @return string
	 */
	private function file( string $bytes ): string {
		$path = tempnam( sys_get_temp_dir(), 'hds-txt-' );
		unlink( $path );
		$path        .= '.txt';
		$this->temp[] = $path;
		file_put_contents( $path, $bytes );
		return $path;
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
	 * Assert an extraction error code.
	 *
	 * @param string $path File.
	 * @param string $code Expected code.
	 * @return void
	 */
	private function assertExtractionError( string $path, string $code ): void {
		try {
			HDS_Extractor_Factory::extract( $path );
		} catch ( HDS_Extraction_Exception $e ) {
			$this->assertSame( $code, $e->get_error_code(), $e->getMessage() );
			return;
		}
		$this->fail( 'No exception for ' . basename( $path ) );
	}
}
