<?php
/**
 * Tests for HDS_Docx_Extractor.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers HDS_Docx_Extractor
 */
class DocxExtractorTest extends TestCase {

	const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

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
	// Real documents (LibreOffice conversions of the .doc fixtures).
	// ------------------------------------------------------------------

	public function test_short_document(): void {
		$text = HDS_Extractor_Factory::extract( HDS_FIXTURES_DIR . '/generated/SH-22-06-57879-590.docx' );
		$this->assertCoverage( self::reference( 'SH-22-06-57879-590' ), $text, 0.9 );
		$this->assertPhrases(
			$text,
			array(
				'ניתן תוקף של פסק דין להסכמת הצדדים',
				'בית משפט השלום בפתח תקווה',
				'ת"א 57879-06-22 ליפשיץ ואח\' נ\' מיזם נדל"ן (תמ"א 38) סוקולוב כ"ס בע"מ ואח\'',
				'העתק החלטה מפרוטוקול שניתנה בבית משפט מיום 06 אוקטובר 2026, כ"ה תשרי תשפ"ז',
			)
		);
	}

	public function test_long_document(): void {
		$text = HDS_Extractor_Factory::extract( HDS_FIXTURES_DIR . '/generated/ARAR-25-09-8059.docx' );
		$this->assertCoverage( self::reference( 'ARAR-25-09-8059' ), $text, 0.9 );
		$this->assertPhrases(
			$text,
			array(
				'ועדת הערר לפיצויים והיטל השבחה',
				'בדו"ח זה הוערך הרווח הצפוי במיזם בשיעור של 17%, זאת בהתחשב בעלויות מימוש התוכנית',
				'חוק התכנון והבניה, תשכ"ה-1965: סע\' 19(ב)(9) לתוספת השלישית',
				'בד בבד עם קידומו של ההליך התכנוני, התקשרה דמרי בהסכמים עם בעלי הזכויות במתחם',
				'התוכנית קודמה על ידי י.ח. דמרי בניה ופיתוח בע"מ',
			)
		);
	}

	public function test_nikud_quotes_table_and_breaks(): void {
		$raw = ( new HDS_Docx_Extractor() )->extract( HDS_FIXTURES_DIR . '/generated/nikud-quotes.docx' );
		$this->assertSame(
			"בְּרֵאשִׁית בָּרָא אֱלֹהִים אֵת הַשָּׁמַיִם וְאֵת הָאָרֶץ\n"
			. "עו\u{05F4}ד ישראל ישראלי ג\u{05F3}ורג\u{05F3} בע\u{05F4}מ\n"
			. "הוא אמר: “שלום” ו‘להתראות’ – \"בסדר\" ו'כן'\n"
			. "תל\u{05BE}אביב\u{05BE}יפו\n"
			. "שורה ראשונה\nשורה שנייה\t57879-06-22\n"
			. "תא א1\tתא א2\n"
			. "תא ב1\tתא ב2\n"
			. 'סוף המסמך',
			$raw
		);

		$normalized = HDS_Normalizer::normalize( HDS_Extractor_Factory::extract( HDS_FIXTURES_DIR . '/generated/nikud-quotes.docx' ) );
		$this->assertStringContainsString( 'בראשית ברא אלהים את השמים ואת הארץ', $normalized );
		$this->assertStringContainsString( 'עו"ד ישראל ישראלי ג\'ורג\' בע"מ', $normalized );
		$this->assertStringContainsString( 'הוא אמר: "שלום" ו\'להתראות\' - "בסדר" ו\'כן\'', $normalized );
		$this->assertStringContainsString( 'תל-אביב-יפו', $normalized );
		$this->assertStringContainsString( 'תא א1 תא א2', $normalized );
		$this->assertContains( '57879-06-22', HDS_Normalizer::tokenize( $normalized ) );
	}

	// ------------------------------------------------------------------
	// Damaged files.
	// ------------------------------------------------------------------

	public function test_package_without_document_xml_is_corrupt(): void {
		$this->assertExtractionError( HDS_FIXTURES_DIR . '/broken/no-document-xml.docx', HDS_Extraction_Exception::CORRUPT );
	}

	public function test_garbage_with_zip_header_is_corrupt(): void {
		$this->assertExtractionError( HDS_FIXTURES_DIR . '/broken/garbage.docx', HDS_Extraction_Exception::CORRUPT );
	}

	public function test_invalid_xml_is_corrupt(): void {
		$path = $this->docx( array( 'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="' . self::W . '"><w:body><w:p><w:r><w:t>Unclosed text' ) );
		$this->assertExtractionError( $path, HDS_Extraction_Exception::CORRUPT );
	}

	public function test_empty_document_part_is_corrupt(): void {
		$this->assertExtractionError( $this->docx( array( 'word/document.xml' => '' ) ), HDS_Extraction_Exception::CORRUPT );
	}

	public function test_document_without_text_is_no_text(): void {
		$this->assertExtractionError( $this->docx( array( 'word/document.xml' => self::document( '<w:p/><w:p><w:r><w:t xml:space="preserve">   </w:t></w:r></w:p>' ) ) ), HDS_Extraction_Exception::NO_TEXT );
	}

	public function test_oversized_part_is_rejected_before_reading(): void {
		// A sparse 51 MB file of zeros compresses to almost nothing (zip bomb).
		$big = tempnam( sys_get_temp_dir(), 'hds-big-' );
		$this->temp[] = $big;
		$handle = fopen( $big, 'wb' );
		ftruncate( $handle, 51 * 1024 * 1024 );
		fclose( $handle );

		$path = $this->new_path();
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFile( $big, 'word/document.xml' );
		$zip->close();
		$this->assertLessThan( 1024 * 1024, filesize( $path ) );
		$this->assertExtractionError( $path, HDS_Extraction_Exception::TOO_LARGE );
	}

	// ------------------------------------------------------------------
	// XML security.
	// ------------------------------------------------------------------

	public function test_external_entity_is_never_resolved(): void {
		$secret = tempnam( sys_get_temp_dir(), 'hds-secret-' );
		$this->temp[] = $secret;
		file_put_contents( $secret, 'TOP-SECRET-VALUE' );
		$xml  = '<?xml version="1.0"?><!DOCTYPE w:document [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
			. '<w:document xmlns:w="' . self::W . '"><w:body><w:p><w:r><w:t>&xxe; visible text</w:t></w:r></w:p></w:body></w:document>';
		$path = $this->docx( array( 'word/document.xml' => $xml ) );
		try {
			$text = HDS_Extractor_Factory::extract( $path );
			$this->assertStringNotContainsString( 'TOP-SECRET-VALUE', $text );
		} catch ( HDS_Extraction_Exception $e ) {
			$this->assertSame( HDS_Extraction_Exception::CORRUPT, $e->get_error_code() );
			$this->assertStringNotContainsString( 'TOP-SECRET-VALUE', $e->getMessage() );
		}
	}

	public function test_entity_expansion_bomb_is_rejected(): void {
		$entities = '<!ENTITY a "aaaaaaaaaa">';
		for ( $i = 1; $i <= 9; $i++ ) {
			$entities .= '<!ENTITY ' . chr( 97 + $i ) . ' "' . str_repeat( '&' . chr( 96 + $i ) . ';', 10 ) . '">';
		}
		$xml  = '<?xml version="1.0"?><!DOCTYPE lolz [' . $entities . ']><w:document xmlns:w="' . self::W . '"><w:body><w:p><w:r><w:t>&j;</w:t></w:r></w:p></w:body></w:document>';
		$path = $this->docx( array( 'word/document.xml' => $xml ) );
		$this->assertExtractionError( $path, HDS_Extraction_Exception::CORRUPT );
	}

	// ------------------------------------------------------------------
	// WordprocessingML features.
	// ------------------------------------------------------------------

	public function test_runs_fields_revisions_and_alternate_content(): void {
		$body = '<w:p><w:pPr><w:tabs><w:tab w:val="left" w:pos="720"/></w:tabs><w:rPr><w:b/></w:rPr></w:pPr>'
			. '<w:r><w:t>Start</w:t></w:r><w:r><w:tab/><w:t xml:space="preserve"> after tab</w:t></w:r></w:p>'
			. '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> HYPERLINK "http://x" </w:instrText></w:r>'
			. '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>field result</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>'
			. '<w:fldSimple w:instr="PAGE"><w:r><w:t xml:space="preserve"> 7</w:t></w:r></w:fldSimple></w:p>'
			. '<w:p><w:del w:id="1" w:author="a"><w:r><w:delText>deleted words</w:delText></w:r></w:del>'
			. '<w:ins w:id="2" w:author="a"><w:r><w:t>inserted words</w:t></w:r></w:ins>'
			. '<w:moveFrom w:id="3" w:author="a"><w:r><w:t>moved away</w:t></w:r></w:moveFrom></w:p>'
			. '<w:p><w:r><w:t>line one</w:t><w:br/><w:t>line two</w:t><w:cr/><w:t>a</w:t><w:noBreakHyphen/><w:t>b</w:t><w:softHyphen/><w:t>c</w:t></w:r></w:p>'
			. '<w:p><w:r><mc:AlternateContent><mc:Choice Requires="wps"><w:drawing><wps:txbx><w:txbxContent><w:p><w:r><w:t>text box once</w:t></w:r></w:p></w:txbxContent></wps:txbx></w:drawing></mc:Choice>'
			. '<mc:Fallback><w:pict><v:textbox><w:txbxContent><w:p><w:r><w:t>text box once</w:t></w:r></w:p></w:txbxContent></v:textbox></w:pict></mc:Fallback></mc:AlternateContent></w:r></w:p>'
			. '<w:sectPr><w:pgSz w:w="11906"/></w:sectPr>';
		$text = ( new HDS_Docx_Extractor() )->extract( $this->docx( array( 'word/document.xml' => self::document( $body ) ) ) );
		// The text box paragraph sits inside the outer paragraph: one extra
		// (harmless) line break at the end.
		$this->assertSame( "Start\t after tab\nfield result 7\ninserted words\nline one\nline two\na-bc\ntext box once", rtrim( $text ) );
	}

	public function test_tables_and_multi_paragraph_cells(): void {
		$cell = static function ( string ...$paragraphs ): string {
			$xml = '<w:tc><w:tcPr><w:tcW w:w="100"/></w:tcPr>';
			foreach ( $paragraphs as $paragraph ) {
				$xml .= '<w:p><w:r><w:t>' . $paragraph . '</w:t></w:r></w:p>';
			}
			return $xml . '</w:tc>';
		};
		$body = '<w:p><w:r><w:t>Before</w:t></w:r></w:p>'
			. '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol/><w:gridCol/></w:tblGrid>'
			. '<w:tr><w:trPr/>' . $cell( 'תובעים' ) . $cell( '1. נטע ליפשיץ', '2. ויטלי ליפקין' ) . '</w:tr>'
			. '<w:tr>' . $cell( '' ) . $cell( 'נגד' ) . '</w:tr>'
			. '</w:tbl><w:p><w:r><w:t>After</w:t></w:r></w:p>';
		$text = ( new HDS_Docx_Extractor() )->extract( $this->docx( array( 'word/document.xml' => self::document( $body ) ) ) );
		$this->assertSame( "Before\nתובעים\t1. נטע ליפשיץ\n2. ויטלי ליפקין\n\tנגד\nAfter", $text );
	}

	public function test_footnotes_and_endnotes_follow_the_body(): void {
		$notes = static function ( string $kind ): string {
			return '<?xml version="1.0" encoding="UTF-8"?><w:' . $kind . 's xmlns:w="' . self::W . '">'
				. '<w:' . $kind . ' w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:' . $kind . '>'
				. '<w:' . $kind . ' w:type="continuationSeparator" w:id="0"><w:p><w:r><w:t>never</w:t></w:r></w:p></w:' . $kind . '>'
				. '<w:' . $kind . ' w:id="1"><w:p><w:r><w:t>' . $kind . ' text</w:t></w:r></w:p></w:' . $kind . '>'
				. '</w:' . $kind . 's>';
		};
		$body = '<w:p><w:r><w:t>Body</w:t></w:r><w:r><w:footnoteReference w:id="1"/></w:r></w:p>';
		$path = $this->docx(
			array(
				'word/document.xml'  => self::document( $body ),
				'word/footnotes.xml' => $notes( 'footnote' ),
				'word/endnotes.xml'  => $notes( 'endnote' ),
			)
		);
		// Notes are separated from the body (and from each other) by an empty line.
		$this->assertSame( "Body\n\nfootnote text\n\nendnote text", HDS_Extractor_Factory::extract( $path ) );
	}

	public function test_main_part_found_through_relationships(): void {
		$rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="/custom/main.xml"/></Relationships>';
		$doc_rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="../notes/fn.xml"/>'
			. '<Relationship Id="rId8" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="http://example.com" TargetMode="External"/></Relationships>';
		$fn = '<?xml version="1.0"?><w:footnotes xmlns:w="' . self::W . '"><w:footnote w:id="1"><w:p><w:r><w:t>relocated note</w:t></w:r></w:p></w:footnote></w:footnotes>';
		$path = $this->docx(
			array(
				'_rels/.rels'                => $rels,
				'custom/main.xml'            => self::document( '<w:p><w:r><w:t>Main part elsewhere</w:t></w:r></w:p>' ),
				'custom/_rels/main.xml.rels' => $doc_rels,
				'notes/fn.xml'               => $fn,
				'word/document.xml'          => self::document( '<w:p><w:r><w:t>decoy</w:t></w:r></w:p>' ),
			)
		);
		$this->assertSame( "Main part elsewhere\n\nrelocated note", HDS_Extractor_Factory::extract( $path ) );
	}

	public function test_strict_ooxml_namespace(): void {
		$xml  = '<?xml version="1.0"?><w:document xmlns:w="http://purl.oclc.org/ooxml/wordprocessingml/main"><w:body>'
			. '<w:p><w:r><w:t>שלום מסמך Strict</w:t></w:r></w:p></w:body></w:document>';
		$path = $this->docx( array( 'word/document.xml' => $xml ) );
		$this->assertSame( 'שלום מסמך Strict', HDS_Extractor_Factory::extract( $path ) );
	}

	public function test_docx_renamed_to_doc_is_detected(): void {
		$path = $this->new_path( 'doc' );
		copy( HDS_FIXTURES_DIR . '/generated/SH-22-06-57879-590.docx', $path );
		$this->assertStringContainsString( 'ניתן תוקף של פסק דין להסכמת הצדדים', HDS_Extractor_Factory::extract( $path ) );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Wrap body XML in a w:document with the usual namespaces.
	 *
	 * @param string $body Body XML.
	 * @return string
	 */
	private static function document( string $body ): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="' . self::W . '"'
			. ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
			. ' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
			. ' xmlns:v="urn:schemas-microsoft-com:vml" mc:Ignorable="wps">'
			. '<w:body>' . $body . '</w:body></w:document>';
	}

	/**
	 * Create a .docx (ZIP) with the given entries.
	 *
	 * @param array<string, string> $entries Name => content.
	 * @return string Path.
	 */
	private function docx( array $entries ): string {
		$path = $this->new_path();
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( ! isset( $entries['[Content_Types].xml'] ) ) {
			$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>' );
		}
		foreach ( $entries as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();
		return $path;
	}

	/**
	 * New temporary path.
	 *
	 * @param string $ext Extension.
	 * @return string
	 */
	private function new_path( string $ext = 'docx' ): string {
		$path = tempnam( sys_get_temp_dir(), 'hds-docx-' );
		unlink( $path );
		$path        .= '.' . $ext;
		$this->temp[] = $path;
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
	 * Normalized single-line text.
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
	 * Every phrase occurs contiguously, in reading order.
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
