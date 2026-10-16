<?php
/**
 * Tests for HDS_Bidi (visual -> logical Hebrew).
 *
 * Visual-order expectations were produced with the independent UBA
 * implementation in tests/fixtures/tools/bidi_visual.py.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers HDS_Bidi
 */
class BidiTest extends TestCase {

	protected function setUp(): void {
		hds_test_reset();
	}

	/**
	 * Logical line => visual line (mirrored brackets, as stored by legacy generators).
	 *
	 * @return array<string, array{string, string}>
	 */
	public function visual_lines(): array {
		return array(
			'plain hebrew'             => array( 'בית משפט', 'טפשמ תיב' ),
			'case number and parens'   => array(
				'ת"א 57879-06-22 ליפשיץ ואח\' נ\' מיזם נדל"ן (תמ"א 38) סוקולוב',
				'בולוקוס (38 א"מת) ן"לדנ םזימ \'נ \'חאו ץישפיל 57879-06-22 א"ת',
			),
			'date'                     => array( 'ניתן ביום 12/03/2024 בהעדר הצדדים.', '.םידדצה רדעהב 12/03/2024 םויב ןתינ' ),
			'percent and decimal'      => array(
				'הרווח הצפוי בשיעור של 17%, זאת לאחר הפחתה של 1.5 מיליון ש"ח.',
				'.ח"ש ןוילימ 1.5 לש התחפה רחאל תאז ,17% לש רועישב יופצה חוורה',
			),
			'gershayim abbreviation'   => array( 'עו"ד בנימין זלמנוביץ', 'ץיבונמלז ןימינב ד"וע' ),
			'latin words with number'  => array( 'הקובץ נשמר בתוכנת Microsoft Word 2010 ונשלח.', '.חלשנו Microsoft Word 2010 תנכותב רמשנ ץבוקה' ),
			'law citation'             => array(
				'חוק התכנון והבניה, תשכ"ה-1965: סע\' 19(ב)(9) לתוספת השלישית',
				'תישילשה תפסותל (9)(ב)19 \'עס :1965-ה"כשת ,הינבהו ןונכתה קוח',
			),
			'numbered list item'       => array( '1. נטע ליפשיץ', 'ץישפיל עטנ .1' ),
			'all bracket kinds'        => array( 'בדו"ח [נספח א] ו-{הערה} <קישור>', '<רושיק> {הרעה}-ו [א חפסנ] ח"ודב' ),
			'thousands separators'     => array( 'סכום של 1,700,000 ש"ח', 'ח"ש 1,700,000 לש םוכס' ),
			'curly quotes'             => array( 'הודעה “חשובה” ו‘נוספת’', '’תפסונ‘ו ”הבושח“ העדוה' ),
		);
	}

	/**
	 * @dataProvider visual_lines
	 */
	public function test_visual_to_logical( string $logical, string $visual ): void {
		$this->assertSame( $logical, HDS_Bidi::visual_to_logical( $visual ) );
	}

	public function test_nikud_points_stay_on_their_letters(): void {
		// The bidi algorithm reverses code points, so in visual order the
		// points precede their letters; converting back restores them.
		$logical = 'בְּרֵאשִׁית בָּרָא אֱלֹהִים אֵת הַשָּׁמַיִם וְאֵת הָאָרֶץ';
		$visual  = implode( '', array_reverse( preg_split( '//u', $logical, -1, PREG_SPLIT_NO_EMPTY ) ) );
		$this->assertSame( $logical, HDS_Bidi::visual_to_logical( $visual ) );
		$this->assertSame( 'בראשית ברא אלהים את השמים ואת הארץ', HDS_Normalizer::remove_nikud( HDS_Bidi::fix_text( $visual ) ) );
	}

	public function test_visual_to_logical_without_mirroring_for_logical_bracket_codes(): void {
		// LibreOffice/Word keep the logical code points: ")" is drawn on the left.
		$this->assertSame( '(תמ"א 38) סוקולוב', HDS_Bidi::visual_to_logical( 'בולוקוס )38 א"מת(', false ) );
		// The same input with mirroring gives the wrong orientation.
		$this->assertSame( ')תמ"א 38( סוקולוב', HDS_Bidi::visual_to_logical( 'בולוקוס )38 א"מת(', true ) );
	}

	public function test_visual_to_logical_keeps_spaces_and_empty_line(): void {
		$this->assertSame( '', HDS_Bidi::visual_to_logical( '' ) );
		$this->assertSame( '  ', HDS_Bidi::visual_to_logical( '  ' ) );
		$this->assertSame( 'בית  משפט ', HDS_Bidi::visual_to_logical( ' טפשמ  תיב' ) );
	}

	public function test_numbers_and_latin_are_never_reversed(): void {
		foreach ( array( '06-22', '57879-06-22', '12/03/2024', '1.5', '1,000', '17%', '38', 'abc', 'www.nevo.co.il', '8069-74/25' ) as $token ) {
			$this->assertSame( 'מספר ' . $token . ' כאן', HDS_Bidi::visual_to_logical( 'ןאכ ' . $token . ' רפסמ' ), $token );
		}
	}

	public function test_hebrew_hyphen_year(): void {
		$this->assertSame( 'תשכ"ה-1965', HDS_Bidi::visual_to_logical( '1965-ה"כשת' ) );
		$this->assertSame( 'ל-1,700,000 ש"ח', HDS_Bidi::visual_to_logical( 'ח"ש 1,700,000-ל' ) );
	}

	public function test_number_wrapped_at_hyphen_keeps_its_hyphen(): void {
		// Paragraph-level bidi (LibreOffice): "8069-74/25" broken after the hyphen.
		$this->assertSame( 'העוררים בערר 8069-', HDS_Bidi::visual_to_logical( '8069- ררעב םיררועה' ) );
		// Line-level bidi puts the hyphen on the left instead.
		$this->assertSame( 'העוררים בערר 8069-', HDS_Bidi::visual_to_logical( '-8069 ררעב םיררועה' ) );
	}

	public function test_is_visual_order(): void {
		$this->assertTrue( HDS_Bidi::is_visual_order( 'ןידה קספ לש ףקות ןתינ' . "\n" . 'הווקת חתפב םולשה טפשמ תיב' ) );
		$this->assertFalse( HDS_Bidi::is_visual_order( 'ניתן תוקף של פסק הדין' . "\n" . 'בית משפט השלום בפתח תקווה' ) );
		$this->assertFalse( HDS_Bidi::is_visual_order( '' ) );
		$this->assertFalse( HDS_Bidi::is_visual_order( "Hello world\n57879-06-22" ) );
		// Too little evidence either way: leave it alone.
		$this->assertFalse( HDS_Bidi::is_visual_order( 'לש' ) );
	}

	public function test_order_evidence_counts_final_letters_and_common_words(): void {
		$logical = HDS_Bidi::order_evidence( 'משפט של שלום' );
		$visual  = HDS_Bidi::order_evidence( 'םולש לש טפשמ' );
		$this->assertGreaterThan( 0, $logical['logical'] );
		$this->assertSame( 0, $logical['visual'] );
		$this->assertSame( $logical['logical'], $visual['visual'] );
		$this->assertSame( $logical['visual'], $visual['logical'] );
		$this->assertSame(
			array(
				'visual'  => 0,
				'logical' => 0,
			),
			HDS_Bidi::order_evidence( 'Latin only 123' )
		);
	}

	public function test_fix_text_converts_visual_document_line_by_line(): void {
		$visual   = "טפשמ תיב\n\nןיד קספ\nHello world.\n.1\nןידה קספ לש ףקות ןתינ";
		$expected = "בית משפט\n\nפסק דין\nHello world.\n1.\nניתן תוקף של פסק הדין";
		$this->assertSame( $expected, HDS_Bidi::fix_text( $visual ) );
	}

	public function test_fix_text_leaves_logical_text_untouched(): void {
		foreach ( array( 'SH-22-06-57879-590', 'ARAR-25-09-8059' ) as $name ) {
			$text = (string) file_get_contents( HDS_FIXTURES_DIR . '/reference/' . $name . '.txt' );
			$this->assertSame( $text, HDS_Bidi::fix_text( $text ), $name );
		}
		$this->assertSame( "Plain English text.\nSecond line (1)", HDS_Bidi::fix_text( "Plain English text.\nSecond line (1)" ) );
	}

	public function test_fix_text_detects_mirroring_per_document(): void {
		// Mirrored storage (legacy generator).
		$mirrored = "בולוקוס (38 א\"מת) ן\"לדנ םזימ\nםיפיעסל (א)3 ףיעס";
		$this->assertSame( "מיזם נדל\"ן (תמ\"א 38) סוקולוב\nסעיף 3(א) לסעיפים", HDS_Bidi::fix_text( $mirrored ) );
		// Logical code points at visual positions (LibreOffice, Word).
		$plain = "בולוקוס )38 א\"מת( ן\"לדנ םזימ\nםיפיעסל )א(3 ףיעס";
		$this->assertSame( "מיזם נדל\"ן (תמ\"א 38) סוקולוב\nסעיף 3(א) לסעיפים", HDS_Bidi::fix_text( $plain ) );
	}

	/**
	 * Every line of both real documents survives logical -> visual (Python
	 * UBA) -> logical (PHP) unchanged.
	 */
	public function test_round_trip_of_real_documents(): void {
		foreach ( array( 'SH-22-06-57879-590', 'ARAR-25-09-8059' ) as $name ) {
			$reference = explode( "\n", (string) file_get_contents( HDS_FIXTURES_DIR . '/reference/' . $name . '.txt' ) );
			$visual    = (string) file_get_contents( HDS_FIXTURES_DIR . '/generated/' . $name . '.visual.txt' );
			$this->assertTrue( HDS_Bidi::is_visual_order( $visual ), $name );
			$fixed = explode( "\n", HDS_Bidi::fix_text( $visual ) );
			$this->assertCount( count( $reference ), $fixed, $name );
			foreach ( $reference as $i => $line ) {
				$this->assertSame( trim( $line ), $fixed[ $i ], $name . ' line ' . ( $i + 1 ) );
			}
		}
	}
}
