<?php
/**
 * Tests for HDS_Doc_Extractor (Word 97-2003 .doc), on the real nevo.co.il
 * fixtures and on synthetic documents built with HDS_Test_CFB_Writer.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Word .doc extractor tests.
 */
class DocExtractorTest extends TestCase {

	const SH   = 'SH-22-06-57879-590';
	const ARAR = 'ARAR-25-09-8059';

	/**
	 * Temporary files to delete.
	 *
	 * @var string[]
	 */
	private $tmp_files = array();

	protected function setUp(): void {
		hds_test_reset();
	}

	protected function tearDown(): void {
		foreach ( $this->tmp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->tmp_files = array();
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Write bytes to a temporary file with an extension.
	 *
	 * @param string $bytes Contents.
	 * @param string $ext   Extension.
	 * @return string Path.
	 */
	private function tmp_file( string $bytes, string $ext = 'doc' ): string {
		$path              = sys_get_temp_dir() . '/hds-doc-' . getmypid() . '-' . count( $this->tmp_files ) . '-' . mt_rand() . '.' . $ext;
		$this->tmp_files[] = $path;
		file_put_contents( $path, $bytes );
		return $path;
	}

	/**
	 * Raw extractor output for a synthetic document.
	 *
	 * @param array<string, mixed>  $options Options for HDS_Test_CFB_Writer::word_streams().
	 * @param array<string, string> $extra   Extra streams.
	 * @return string
	 */
	private static function extract_synthetic( array $options, array $extra = array() ): string {
		$reader = new HDS_CFB_Reader( HDS_Test_CFB_Writer::word_file( $options, $extra ) );
		return ( new HDS_Doc_Extractor() )->extract_from_reader( $reader );
	}

	/**
	 * Run the factory and return the exception it throws.
	 *
	 * @param string $path File path.
	 * @return HDS_Extraction_Exception
	 */
	private function factory_error( string $path ): HDS_Extraction_Exception {
		$start = microtime( true );
		try {
			HDS_Extractor_Factory::extract( $path );
		} catch ( HDS_Extraction_Exception $e ) {
			$this->assertLessThan( 2.0, microtime( true ) - $start, 'Must fail fast.' );
			$this->assertNotSame( '', $e->getMessage() );
			return $e;
		}
		$this->fail( 'Expected an HDS_Extraction_Exception.' );
	}

	/**
	 * Normalized, whitespace-collapsed text (the comparison basis).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function flat( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', HDS_Normalizer::normalize( $text ) ) );
	}

	/**
	 * Whitespace tokens of the flattened text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function tokens( string $text ): array {
		$flat = self::flat( $text );
		return '' === $flat ? array() : explode( ' ', $flat );
	}

	/**
	 * Length of the longest common subsequence of two token lists (Myers'
	 * O((N+M)D) greedy algorithm; fast for near-identical texts).
	 *
	 * @param string[] $a Tokens.
	 * @param string[] $b Tokens.
	 * @return int
	 */
	private static function lcs_length( array $a, array $b ): int {
		$n   = count( $a );
		$m   = count( $b );
		$max = $n + $m;
		$v   = array( 1 => 0 );
		for ( $d = 0; $d <= $max; $d++ ) {
			for ( $k = -$d; $k <= $d; $k += 2 ) {
				if ( -$d === $k || ( $d !== $k && $v[ $k - 1 ] < $v[ $k + 1 ] ) ) {
					$x = $v[ $k + 1 ];
				} else {
					$x = $v[ $k - 1 ] + 1;
				}
				$y = $x - $k;
				while ( $x < $n && $y < $m && $a[ $x ] === $b[ $y ] ) {
					$x++;
					$y++;
				}
				$v[ $k ] = $x;
				if ( $x >= $n && $y >= $m ) {
					return intdiv( $n + $m - $d, 2 );
				}
			}
		}
		return 0;
	}

	/**
	 * Similarity metrics of extracted text against a reference.
	 *
	 * @param string $ours      Extracted text.
	 * @param string $reference Reference text.
	 * @return array{ratio: float, recall: float, precision: float}
	 */
	private static function similarity( string $ours, string $reference ): array {
		$a   = self::tokens( $ours );
		$b   = self::tokens( $reference );
		$lcs = self::lcs_length( $a, $b );
		return array(
			'ratio'     => ( 2.0 * $lcs ) / max( 1, count( $a ) + count( $b ) ), // Like difflib's ratio().
			'recall'    => $lcs / max( 1, count( $b ) ),
			'precision' => $lcs / max( 1, count( $a ) ),
		);
	}

	/**
	 * LibreOffice prints automatic list numbers ("    12. ") that are list
	 * formatting, not characters of the document; drop them for the strict check.
	 *
	 * @param string $reference Reference text.
	 * @return string
	 */
	private static function strip_list_labels( string $reference ): string {
		return (string) preg_replace( '/^[ \t]+\d+(?:\.\d+)*\.(?=\s|$)/mu', '', $reference );
	}

	/**
	 * Common assertions on a real fixture.
	 *
	 * @param string   $name    Fixture base name.
	 * @param string[] $phrases Phrases that must appear, in this order.
	 * @return string Extracted text.
	 */
	private function assert_real_fixture( string $name, array $phrases ): string {
		$text      = HDS_Extractor_Factory::extract( HDS_FIXTURES_DIR . '/' . $name . '.doc' );
		$reference = (string) file_get_contents( HDS_FIXTURES_DIR . '/reference/' . $name . '.txt' );

		// (a) Readable Hebrew.
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
		$this->assertStringNotContainsString( "\u{FFFD}", $text );
		$this->assertSame( 0, preg_match( '/[\x00-\x09\x0B-\x1F\x7F]/', $text ), 'No control characters except "\n".' );
		$letters = preg_match_all( '/\p{L}/u', $text );
		$hebrew  = preg_match_all( '/\p{Hebrew}/u', $text );
		$this->assertGreaterThan( 0.6, $hebrew / max( 1, $letters ), 'Hebrew letter ratio.' );

		// (b) Token similarity to the LibreOffice reference.
		$raw = self::similarity( $text, $reference );
		$this->assertGreaterThanOrEqual( 0.95, $raw['ratio'], 'Similarity ratio vs reference: ' . json_encode( $raw ) );
		$strict = self::similarity( $text, self::strip_list_labels( $reference ) );
		$this->assertGreaterThanOrEqual( 0.995, $strict['recall'], 'Recall without list labels: ' . json_encode( $strict ) );
		$this->assertGreaterThanOrEqual( 0.995, $strict['precision'], 'Precision without list labels: ' . json_encode( $strict ) );

		// (c) Contiguous phrases in logical order.
		$flat = self::flat( $text );
		$last = -1;
		foreach ( $phrases as $phrase ) {
			$pos = mb_strpos( $flat, self::flat( $phrase ) );
			$this->assertNotFalse( $pos, 'Phrase missing: ' . $phrase );
			$this->assertGreaterThan( $last, $pos, 'Phrase out of order: ' . $phrase );
			$last = $pos;
		}

		// (d) No field-code junk.
		foreach ( array( 'HYPERLINK', 'PAGE', 'MERGEFORMAT', 'nevo.co.il/' ) as $junk ) {
			$this->assertStringNotContainsString( $junk, $text );
		}
		return $text;
	}

	// ------------------------------------------------------------------
	// Real documents.
	// ------------------------------------------------------------------

	public function test_real_short_judgment(): void {
		$text = $this->assert_real_fixture(
			self::SH,
			array(
				'בית משפט השלום בפתח תקווה',
				'ת"א 57879-06-22',
				'כבוד השופטת לימור חלד-רון',
				'ניתן תוקף של פסק דין להסכמת הצדדים',
				'החזר אגרה בהתאם לתקנות',
			)
		);
		// Exactly the reference text, word for word.
		$similarity = self::similarity( $text, (string) file_get_contents( HDS_FIXTURES_DIR . '/reference/' . self::SH . '.txt' ) );
		$this->assertSame( 1.0, $similarity['ratio'] );
		// Header story ("תא (פ"ת)") and the text box are skipped.
		$this->assertStringNotContainsString( 'תא (פ"ת)', $text );
		$this->assertStringStartsWith( 'בית משפט השלום בפתח תקווה', $text );
	}

	public function test_real_long_decision(): void {
		$start = microtime( true );
		$text  = HDS_Extractor_Factory::extract( HDS_FIXTURES_DIR . '/' . self::ARAR . '.doc' );
		$this->assertLessThan( 1.0, microtime( true ) - $start, 'ARAR must extract in under 1 s.' );
		$this->assertGreaterThan( 40000, mb_strlen( $text ) );

		$this->assert_real_fixture(
			self::ARAR,
			array(
				'ועדת הערר לפיצויים והיטל השבחה',
				'חוק התכנון והבניה, תשכ"ה-1965: סע\' 19(ב)(9) לתוספת השלישית',
				'לפנינו עררים על שומות מכריעות שעניינן היטל השבחה החל בקשר למקרקעין הידועים כחלקות 30-25 בגוש 7944',
				'עוד טוענת הוועדה המקומית כי יש לייחס משקל מרכזי לשתי עסקאות מכר שנערכו בדירות המצויות במתחם לאחר אישור התוכנית.',
				'לפנינו שומות מכריעות מפורטות ומנומקות, המבוססות על מסד נתונים סדור וברור.',
				'בכפוף לאמור לעיל, העררים מתקבלים בחלקם.',
				'הוחלט פה אחד כאמור בחוות דעתו של יו"ר הוועדה, עו"ד סגלוביץ\'.',
				'ניתנה היום, כ"ד תשרי ה\'תשפ"ז, 5 אוקטובר 2026.',
			)
		);
	}

	public function test_real_table_rows_become_lines(): void {
		$text = ( new HDS_Doc_Extractor() )->extract( HDS_FIXTURES_DIR . '/' . self::ARAR . '.doc' );
		// Two cells of one row are separated by a tab; the row ends with a line break.
		$this->assertStringContainsString( "מחוז מרכז\tערר נת/8059-8064/0925\nנת/8069-8074/0925\t\n", $text );
		$this->assertStringContainsString( "העוררים בערר 8069-74/25:\tי.ח. דמרי בניה ופיתוח בע\"מ ואח'\t\n", $text );
	}

	// ------------------------------------------------------------------
	// Encodings of 8-bit ("compressed") pieces.
	// ------------------------------------------------------------------

	public function test_compressed_hebrew_piece_in_hebrew_document(): void {
		$text = self::extract_synthetic(
			array(
				'lid'    => 0x040D,
				'pieces' => array(
					array(
						'text'       => "פסק דין: ניתן תוקף להסכם הפשרה.\r",
						'compressed' => true,
						'codepage'   => 1255,
					),
				),
			)
		);
		$this->assertSame( "פסק דין: ניתן תוקף להסכם הפשרה.\n", $text );
	}

	public function test_compressed_hebrew_with_summary_information_codepage(): void {
		$options = array(
			'lid'    => 0x0409,
			'pieces' => array(
				array(
					'text'       => "בית משפט השלום\r",
					'compressed' => true,
					'codepage'   => 1255,
				),
			),
		);
		$this->assertSame(
			"בית משפט השלום\n",
			self::extract_synthetic( $options, array( "\x05SummaryInformation" => HDS_Test_CFB_Writer::summary_information( 1255 ) ) )
		);
		// Without any Hebrew marker MS-DOC's Windows-1252 rule applies.
		$this->assertSame( 0, preg_match( '/\p{Hebrew}/u', self::extract_synthetic( $options ) ) );
		$this->assertSame( 0, preg_match( '/\p{Hebrew}/u', self::extract_synthetic( $options, array( "\x05SummaryInformation" => HDS_Test_CFB_Writer::summary_information( 1252 ) ) ) ) );
	}

	public function test_compressed_western_piece_in_english_document(): void {
		$text = self::extract_synthetic(
			array(
				'lid'    => 0x0409,
				'pieces' => array(
					array(
						'text'       => "Café – “quotes” € ‘single’\r",
						'compressed' => true,
						'codepage'   => 1252,
					),
				),
			)
		);
		$this->assertSame( "Café – “quotes” € ‘single’\n", $text );
	}

	public function test_western_bytes_in_hebrew_document_stay_western(): void {
		$text = self::extract_synthetic(
			array(
				'lid'    => 0x040D,
				'pieces' => array(
					array(
						'text'       => "Café crème brûlée à la française\r",
						'compressed' => true,
						'codepage'   => 1252,
					),
				),
			)
		);
		$this->assertSame( "Café crème brûlée à la française\n", $text );
	}

	public function test_mixed_compressed_and_unicode_pieces_in_a_large_stream(): void {
		$long    = str_repeat( 'שורה ארוכה בעברית עם מילים רבות. ', 120 ) . "\r";
		$options = array(
			'lid'    => 0x040D,
			'prc'    => true,
			'pieces' => array(
				array(
					'text'       => 'Hello ',
					'compressed' => true,
				),
				array( 'text' => 'שלום ' ),
				array(
					'text'       => "world\r",
					'compressed' => true,
				),
				array( 'text' => $long ),
				array(
					'text'       => "סוף המסמך\r",
					'compressed' => true,
					'codepage'   => 1255,
				),
			),
		);
		$writer = new HDS_Test_CFB_Writer();
		foreach ( HDS_Test_CFB_Writer::word_streams( $options ) as $name => $data ) {
			$writer->add_stream( $name, $data );
		}
		$bin = $writer->build();
		$this->assertFalse( $writer->layout()['streams']['WordDocument']['mini'], 'WordDocument > 4096 bytes uses regular sectors.' );
		$this->assertTrue( $writer->layout()['streams']['1Table']['mini'], 'The table stream uses the mini stream.' );

		$text = ( new HDS_Doc_Extractor() )->extract_from_reader( new HDS_CFB_Reader( $bin ) );
		$this->assertSame( "Hello שלום world\n" . str_replace( "\r", "\n", $long ) . "סוף המסמך\n", $text );

		// Same through the factory pipeline from a file.
		$clean = HDS_Extractor_Factory::extract( $this->tmp_file( $bin ) );
		$this->assertStringStartsWith( "Hello שלום world\nשורה ארוכה", $clean );
		$this->assertStringEndsWith( 'סוף המסמך', $clean );
	}

	public function test_table_stream_zero_is_used_when_flag_is_clear(): void {
		$text = self::extract_synthetic(
			array(
				'table'  => '0Table',
				'pieces' => array( array( 'text' => "Text in a 0Table document\r" ) ),
			)
		);
		$this->assertSame( "Text in a 0Table document\n", $text );
	}

	// ------------------------------------------------------------------
	// Fields, tables, stories, special characters.
	// ------------------------------------------------------------------

	public function test_fields_tables_footnotes_and_special_characters(): void {
		$main = "Intro \x13 HYPERLINK \"http://example.com/x\" \\t \"_blank\" \x01\x14Example site\x15 here.\r"
			. "Nested \x13 IF \x13 MERGEFIELD amount \x14125\x15 > 100 \x14Large\x15 done.\r"
			. "Result with field \x13 REF bm \x14ref \x13 PAGE \x142\x15 end\x15 ok.\r"
			. "Dropped \x13 PAGE \\* MERGEFORMAT \x15field.\r"
			. "Stray \x15end and \x14sep.\r"
			. "Cell A\x07Cell B\x07\x07Cell C\x07Cell D\x07\x07"
			. "Line\x0Bbreak\x0Cpage non\x1Ebreaking opt\x1Fional pic\x01ture note\x02 obj\x08ect com\x05ment\r"
			. "Unclosed \x13 field begin is ignored, text stays.\r";
		$footnotes = "\x02 Footnote text.\r";
		$headers   = "HEADER STORY\r";
		$comments  = "COMMENT STORY\r";
		$endnotes  = "\x02 Endnote text.\r";
		$textboxes = "TEXTBOX STORY\r";

		$cell_start = mb_strpos( $main, 'Cell A' );
		$options    = array(
			'pieces'   => array( array( 'text' => $main . $footnotes . $headers . $comments . $endnotes . $textboxes . "\r" ) ),
			'ccp'      => array(
				'text' => mb_strlen( $main ),
				'ftn'  => mb_strlen( $footnotes ),
				'hdd'  => mb_strlen( $headers ),
				'atn'  => mb_strlen( $comments ),
				'edn'  => mb_strlen( $endnotes ),
				'txbx' => mb_strlen( $textboxes ),
			),
			'cells'    => array( $cell_start + 6, $cell_start + 13, $cell_start + 21, $cell_start + 28 ),
			'row_ends' => array( $cell_start + 14, $cell_start + 29 ),
		);
		$text = self::extract_synthetic( $options );

		$expected = "Intro Example site here.\n"
			. "Nested Large done.\n"
			. "Result with field ref 2 end ok.\n"
			. "Dropped field.\n"
			. "Stray end and sep.\n"
			. "Cell A\tCell B\t\nCell C\tCell D\t\n"
			. "Line\nbreak\npage non-breaking optional picture note object comment\n"
			. "Unclosed  field begin is ignored, text stays.\n"
			. "\n Footnote text.\n"
			. "\n Endnote text.\n";
		$this->assertSame( $expected, $text );
		foreach ( array( 'HEADER', 'COMMENT', 'TEXTBOX', 'HYPERLINK', 'MERGEFIELD', 'PAGE', 'http' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $text );
		}

		// Huge PAPX (properties in the Data stream) and compressed text give the same result.
		$options['huge_papx']               = true;
		$options['pieces'][0]['compressed'] = true;
		$this->assertSame( $expected, self::extract_synthetic( $options ) );
	}

	public function test_cell_marks_without_paragraph_properties_become_tabs(): void {
		$text = self::extract_synthetic( array( 'pieces' => array( array( 'text' => "A\x07B\x07\x07After\r" ) ) ) );
		$this->assertSame( "A\tB\t\tAfter\n", $text );
	}

	public function test_word97_structure_with_an_old_nfib_is_still_parsed_as_word97(): void {
		$text = self::extract_synthetic(
			array(
				'nfib'   => 0x65,
				'pieces' => array( array( 'text' => "Structure wins\r" ) ),
			)
		);
		$this->assertSame( "Structure wins\n", $text );
	}

	// ------------------------------------------------------------------
	// Word 6/95 fallback.
	// ------------------------------------------------------------------

	public function test_word95_hebrew_fallback(): void {
		$bytes = HDS_Test_CFB_Writer::encode_8bit( "פסק דין\rניתן היום \x13 PAGE \x141\x15 בהעדר הצדדים.\r", 1255 );
		$path  = $this->tmp_file( HDS_Test_CFB_Writer::word95_file( $bytes, 0x040D, 0x65 ) );
		$this->assertSame( "פסק דין\nניתן היום 1 בהעדר הצדדים.", HDS_Extractor_Factory::extract( $path ) );
	}

	public function test_word95_western_fallback(): void {
		$bytes = HDS_Test_CFB_Writer::encode_8bit( "Café table\x07cell\x07\x07\r", 1252 );
		$text  = ( new HDS_Doc_Extractor() )->extract_from_reader( new HDS_CFB_Reader( HDS_Test_CFB_Writer::word95_file( $bytes, 0x0409, 0x68 ) ) );
		$this->assertSame( "Café table\tcell\t\t\n", $text );
	}

	public function test_word95_bad_text_range_is_corrupt(): void {
		$bin = HDS_Test_CFB_Writer::word95_file( 'abc', 0x0409 );
		$this->expectException( HDS_Extraction_Exception::class );
		// fcMac beyond the end of the stream.
		$reader = new HDS_CFB_Reader( $bin );
		$word   = $reader->get_stream( 'WordDocument' );
		$writer = new HDS_Test_CFB_Writer();
		$writer->add_stream( 'WordDocument', substr_replace( $word, pack( 'V', 999999 ), 0x1C, 4 ) );
		( new HDS_Doc_Extractor() )->extract_from_reader( new HDS_CFB_Reader( $writer->build() ) );
	}

	// ------------------------------------------------------------------
	// Encryption and unsupported content.
	// ------------------------------------------------------------------

	public function test_encrypted_flag_is_reported(): void {
		$bin = HDS_Test_CFB_Writer::word_file(
			array(
				'flags'  => 0x0100,
				'pieces' => array( array( 'text' => "Secret\r" ) ),
			)
		);
		$this->assertSame( HDS_Extraction_Exception::ENCRYPTED, $this->factory_error( $this->tmp_file( $bin ) )->get_error_code() );

		$bin95 = HDS_Test_CFB_Writer::word95_file( "Secret\r", 0x0409, 0x65, 0x0100 );
		$this->assertSame( HDS_Extraction_Exception::ENCRYPTED, $this->factory_error( $this->tmp_file( $bin95 ) )->get_error_code() );
	}

	public function test_encrypted_ooxml_package_is_reported(): void {
		$writer = new HDS_Test_CFB_Writer();
		$writer->add_stream( 'EncryptionInfo', str_repeat( "\x04", 200 ) );
		$writer->add_stream( 'EncryptedPackage', str_repeat( "\x9A", 9000 ) );
		$bin = $writer->build();
		foreach ( array( 'doc', 'docx' ) as $ext ) {
			$e = $this->factory_error( $this->tmp_file( $bin, $ext ) );
			$this->assertSame( HDS_Extraction_Exception::ENCRYPTED, $e->get_error_code(), $ext );
		}
	}

	public function test_ole_file_without_word_document_is_unsupported(): void {
		$writer = new HDS_Test_CFB_Writer();
		$writer->add_stream( 'Workbook', str_repeat( 'x', 5000 ) );
		$e = $this->factory_error( $this->tmp_file( $writer->build() ) );
		$this->assertSame( HDS_Extraction_Exception::UNSUPPORTED, $e->get_error_code() );
	}

	public function test_empty_document_has_no_text(): void {
		$bin = HDS_Test_CFB_Writer::word_file( array( 'pieces' => array( array( 'text' => "\r\x07\x0C" ) ) ) );
		$this->assertSame( HDS_Extraction_Exception::NO_TEXT, $this->factory_error( $this->tmp_file( $bin ) )->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Damaged Word structures.
	// ------------------------------------------------------------------

	/**
	 * Damaged Word structures that must be CORRUPT.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public function word_corruptions(): array {
		return array(
			'bad wIdent'        => array( fn( $s ) => array( 'WordDocument' => substr_replace( $s['WordDocument'], "\x00\x00", 0, 2 ) ) + $s ),
			'truncated FIB'     => array( fn( $s ) => array( 'WordDocument' => substr( $s['WordDocument'], 0, 20 ) ) + $s ),
			'FIB cut in RgLw'   => array( fn( $s ) => array( 'WordDocument' => substr( $s['WordDocument'], 0, 100 ) ) + $s ),
			'missing 1Table'    => array( fn( $s ) => array( 'WordDocument' => $s['WordDocument'] ) ),
			'empty CLX'         => array( fn( $s ) => array( 'WordDocument' => self::set_fib( $s['WordDocument'], 67, 0 ) ) + $s ),
			'CLX past end'      => array( fn( $s ) => array( 'WordDocument' => self::set_fib( $s['WordDocument'], 66, 999999 ) ) + $s ),
			'CLX garbage'       => array( fn( $s ) => array( '1Table' => substr_replace( $s['1Table'], "\x07\x07\x07\x07\x07", 16, 5 ) ) + $s ),
			'PlcPcd bad size'   => array( fn( $s ) => array( '1Table' => substr_replace( $s['1Table'], pack( 'V', 13 ), 17, 4 ) ) + $s ),
			'piece outside'     => array( fn( $s ) => array( '1Table' => substr_replace( $s['1Table'], pack( 'V', 9999999 ), 16 + 5 + 8 + 2, 4 ) ) + $s ),
			'CPs out of order'  => array( fn( $s ) => array( '1Table' => substr_replace( $s['1Table'], pack( 'V', 0x7FFFFFFF ), 16 + 5, 4 ) ) + $s ),
			'negative ccpText'  => array( fn( $s ) => array( 'WordDocument' => substr_replace( $s['WordDocument'], pack( 'V', 0xFFFFFFF0 ), 64 + 12, 4 ) ) + $s ),
		);
	}

	/**
	 * Overwrite a 32-bit value of FibRgFcLcbBlob.
	 *
	 * @param string $word  WordDocument stream.
	 * @param int    $index Index of the 32-bit value.
	 * @param int    $value Value.
	 * @return string
	 */
	private static function set_fib( string $word, int $index, int $value ): string {
		return substr_replace( $word, pack( 'V', $value ), 154 + 4 * $index, 4 );
	}

	/**
	 * Damaged Word structures give CORRUPT.
	 *
	 * @dataProvider word_corruptions
	 * @param callable $mutate Mutation of the stream map.
	 */
	public function test_damaged_word_structures_are_corrupt( callable $mutate ): void {
		$streams = HDS_Test_CFB_Writer::word_streams( array( 'pieces' => array( array( 'text' => "Some text here\r" ) ) ) );
		$writer  = new HDS_Test_CFB_Writer();
		foreach ( $mutate( $streams ) as $name => $data ) {
			$writer->add_stream( $name, $data );
		}
		$e = $this->factory_error( $this->tmp_file( $writer->build() ) );
		$this->assertSame( HDS_Extraction_Exception::CORRUPT, $e->get_error_code(), $e->getMessage() );
	}

	public function test_overlapping_pieces_are_rejected(): void {
		// 400 pieces all pointing at the same 2000 characters.
		$text   = str_repeat( 'abcdefghij', 200 );
		$fc     = HDS_Test_CFB_Writer::TEXT_START;
		$cps    = array();
		$pcds   = '';
		$pieces = 400;
		for ( $i = 0; $i <= $pieces; $i++ ) {
			$cps[] = $i * 2000;
		}
		for ( $i = 0; $i < $pieces; $i++ ) {
			$pcds .= pack( 'vVv', 0, $fc, 0 );
		}
		$plc = pack( 'V*', ...$cps ) . $pcds;
		$bin = HDS_Test_CFB_Writer::word_file(
			array(
				'pieces' => array( array( 'text' => $text ) ),
				'ccp'    => array( 'text' => $pieces * 2000 ),
				'clx'    => "\x02" . pack( 'V', strlen( $plc ) ) . $plc,
			)
		);
		$e = $this->factory_error( $this->tmp_file( $bin ) );
		$this->assertSame( HDS_Extraction_Exception::CORRUPT, $e->get_error_code() );
	}

	/**
	 * Container-level corruption of a Word file (requirements g, h, i).
	 *
	 * @return array<string, array{0: string}>
	 */
	public function container_corruptions(): array {
		return array(
			'FAT chain loop'           => array( 'loop' ),
			'sector id out of range'   => array( 'range' ),
			'directory past EOF'       => array( 'dir' ),
			'mini sector out of range' => array( 'mini' ),
		);
	}

	/**
	 * Container corruption gives CORRUPT, fast.
	 *
	 * @dataProvider container_corruptions
	 * @param string $kind Kind of damage.
	 */
	public function test_damaged_container_is_corrupt( string $kind ): void {
		$long   = str_repeat( 'טקסט ארוך לבדיקה. ', 200 ) . "\r";
		$writer = new HDS_Test_CFB_Writer();
		foreach ( HDS_Test_CFB_Writer::word_streams( array( 'pieces' => array( array( 'text' => $long ) ) ) ) as $name => $data ) {
			$writer->add_stream( $name, $data );
		}
		$bin    = $writer->build();
		$layout = $writer->layout();
		$word   = $layout['streams']['WordDocument']['sectors'];
		$this->assertGreaterThan( 3, count( $word ) );

		switch ( $kind ) {
			case 'loop':
				$bin = substr_replace( $bin, pack( 'V', $word[0] ), $writer->fat_entry_offset( $word[3] ), 4 );
				break;
			case 'range':
				$bin = substr_replace( $bin, pack( 'V', 0x0ABCDEF0 ), $writer->fat_entry_offset( $word[1] ), 4 );
				break;
			case 'dir':
				$bin = substr_replace( $bin, pack( 'V', 70000 ), 48, 4 );
				break;
			case 'mini':
				$this->assertTrue( $layout['streams']['1Table']['mini'] );
				$entry = $writer->dir_entry_offset( $layout['streams']['1Table']['id'] );
				$bin   = substr_replace( $bin, pack( 'V', 5000 ), $entry + 116, 4 );
				break;
		}
		$e = $this->factory_error( $this->tmp_file( $bin ) );
		$this->assertSame( HDS_Extraction_Exception::CORRUPT, $e->get_error_code(), $e->getMessage() );
	}

	// ------------------------------------------------------------------
	// Broken files on disk.
	// ------------------------------------------------------------------

	/**
	 * Broken fixture files and the expected error code.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function broken_files(): array {
		return array(
			'truncated'           => array( 'truncated.doc', HDS_Extraction_Exception::CORRUPT ),
			'garbage with header' => array( 'garbage-with-header.doc', HDS_Extraction_Exception::CORRUPT ),
			'random bytes'        => array( 'random.doc', HDS_Extraction_Exception::CORRUPT ),
			'empty'               => array( 'empty.doc', HDS_Extraction_Exception::NO_TEXT ),
		);
	}

	/**
	 * Broken files give a clean HDS_Extraction_Exception.
	 *
	 * @dataProvider broken_files
	 * @param string $file Fixture name.
	 * @param string $code Expected code.
	 */
	public function test_broken_files( string $file, string $code ): void {
		$path = HDS_FIXTURES_DIR . '/broken/' . $file;
		$this->assertFileExists( $path );
		$this->assertSame( $code, $this->factory_error( $path )->get_error_code() );
	}

	public function test_truncated_real_document_at_many_lengths(): void {
		$bytes = (string) file_get_contents( HDS_FIXTURES_DIR . '/' . self::SH . '.doc' );
		$start = microtime( true );
		foreach ( array( 511, 512, 513, 1024, 4096, 20000, 40000, 60000, strlen( $bytes ) - 1 ) as $length ) {
			try {
				$text = ( new HDS_Doc_Extractor() )->extract_from_reader( new HDS_CFB_Reader( substr( $bytes, 0, $length ) ) );
				$this->assertIsString( $text );
			} catch ( HDS_Extraction_Exception $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->assertLessThan( 2.0, microtime( true ) - $start );
	}

	public function test_fuzzed_word_structures_only_throw_extraction_exceptions(): void {
		$table = "שלום \x13 HYPERLINK \"x\" \x14קישור\x15 A\x07B\x07\x07";
		$cell  = mb_strpos( $table, "A\x07" );
		$base  = HDS_Test_CFB_Writer::word_streams(
			array(
				'lid'      => 0x040D,
				'prc'      => true,
				'pieces'   => array(
					array( 'text' => $table ),
					array(
						'text'       => "Western text\r",
						'compressed' => true,
					),
				),
				'cells'    => array( $cell + 1, $cell + 3 ),
				'row_ends' => array( $cell + 4 ),
			)
		);
		mt_srand( 777 );
		$ok     = 0;
		$failed = 0;
		$start  = microtime( true );
		for ( $i = 0; $i < 300; $i++ ) {
			$streams = $base;
			$target  = 0 === $i % 3 ? '1Table' : 'WordDocument';
			$data    = $streams[ $target ];
			$edits   = mt_rand( 1, 4 );
			for ( $k = 0; $k < $edits; $k++ ) {
				// The FIB, the CLX and the FKP page are the interesting areas.
				$region       = 'WordDocument' === $target && 0 === $k % 2 ? mt_rand( 0, 899 ) : mt_rand( 0, strlen( $data ) - 1 );
				$data[ $region ] = chr( mt_rand( 0, 255 ) );
			}
			$streams[ $target ] = $data;
			$writer             = new HDS_Test_CFB_Writer();
			foreach ( $streams as $name => $contents ) {
				$writer->add_stream( $name, $contents );
			}
			try {
				$text = ( new HDS_Doc_Extractor() )->extract_from_reader( new HDS_CFB_Reader( $writer->build() ) );
				$this->assertTrue( mb_check_encoding( HDS_Normalizer::clean_extracted( $text ), 'UTF-8' ) );
				$ok++;
			} catch ( HDS_Extraction_Exception $e ) {
				$this->assertNotSame( '', $e->getMessage() );
				$failed++;
			}
		}
		$this->assertSame( 300, $ok + $failed );
		$this->assertLessThan( 10.0, microtime( true ) - $start );
	}
}
