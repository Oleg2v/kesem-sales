<?php
/**
 * Tests for HDS_Normalizer (foundation).
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers HDS_Normalizer
 */
class NormalizerTest extends TestCase {

	protected function setUp(): void {
		hds_test_reset();
	}

	public function test_remove_nikud_keeps_letters_and_punctuation(): void {
		$this->assertSame( 'בראשית ברא אלהים את השמים ואת הארץ', HDS_Normalizer::remove_nikud( 'בְּרֵאשִׁית בָּרָא אֱלֹהִים אֵת הַשָּׁמַיִם וְאֵת הָאָרֶץ' ) );
		// Cantillation marks (U+0591-U+05AF), meteg, qamats qatan.
		$this->assertSame( 'שלום', HDS_Normalizer::remove_nikud( "ש\u{0591}ָל\u{05BD}וֹ\u{05C7}ם\u{05AF}" ) );
		// Maqaf, paseq, sof pasuq and nun hafukha are punctuation, not points.
		$this->assertSame( "תל\u{05BE}אביב\u{05C0}\u{05C3}\u{05C6}", HDS_Normalizer::remove_nikud( "תֵּל\u{05BE}אָבִיב\u{05C0}\u{05C3}\u{05C6}" ) );
		$this->assertSame( 'Latin 123', HDS_Normalizer::remove_nikud( 'Latin 123' ) );
	}

	public function test_normalize_punctuation(): void {
		$this->assertSame( 'עו"ד', HDS_Normalizer::normalize_punctuation( "עו\u{05F4}ד" ) );
		$this->assertSame( "ג'ורג'", HDS_Normalizer::normalize_punctuation( "ג\u{05F3}ורג\u{05F3}" ) );
		$this->assertSame( '"quoted" \'single\' "low" "angle"', HDS_Normalizer::normalize_punctuation( "\u{201C}quoted\u{201D} \u{2018}single\u{2019} \u{201E}low\u{201C} \u{00AB}angle\u{00BB}" ) );
		$this->assertSame( "'back' 'prime' \"dprime\"", HDS_Normalizer::normalize_punctuation( "`back\u{00B4} \u{2032}prime\u{2032} \u{2033}dprime\u{2033}" ) );
		$this->assertSame( 'תל-אביב - א-ב-ג-ד', HDS_Normalizer::normalize_punctuation( "תל\u{05BE}אביב \u{2013} א\u{2014}ב\u{2212}ג\u{2011}ד" ) );
		$this->assertSame( '| : נ', HDS_Normalizer::normalize_punctuation( "\u{05C0} \u{05C3} \u{05C6}" ) );
	}

	public function test_clean_extracted_strips_bidi_and_control_characters(): void {
		$dirty = "\u{FEFF}\u{200F}שלום\u{200E} \u{202B}עולם\u{202C}\u{2067}x\u{2069}\x01\x7F\u{0085}soft\u{00AD}hyphen\u{E001}\u{FFFD}";
		$this->assertSame( "שלום עולםx\nsofthyphen", HDS_Normalizer::clean_extracted( $dirty ) );
	}

	public function test_clean_extracted_whitespace(): void {
		$this->assertSame( "a b c\nd\n\ne", HDS_Normalizer::clean_extracted( "  a\t\u{00A0}b\u{2003}\u{202F}c  \r\nd\r\r\r\r\ne\f" ) );
		$this->assertSame( "line\nline", HDS_Normalizer::clean_extracted( "line\u{2028}line" ) );
		$this->assertSame( "x\ny", HDS_Normalizer::clean_extracted( "x\vy" ) );
		$this->assertSame( '', HDS_Normalizer::clean_extracted( " \n\t " ) );
	}

	public function test_clean_extracted_scrubs_invalid_utf8(): void {
		$clean = HDS_Normalizer::clean_extracted( "ok\xFF\xFEtext" );
		$this->assertTrue( mb_check_encoding( $clean, 'UTF-8' ) );
		$this->assertSame( 'oktext', $clean );
	}

	public function test_clean_extracted_keeps_text_as_written(): void {
		$text = "בְּרֵאשִׁית “ציטוט” עו\u{05F4}ד";
		$this->assertSame( $text, HDS_Normalizer::clean_extracted( $text ) );
	}

	public function test_normalize(): void {
		$this->assertSame( 'בראשית "ציטוט" עו"ד - תל-אביב', HDS_Normalizer::normalize( "  בְּרֵאשִׁית  “ציטוט”   עו\u{05F4}ד \u{2013} תל\u{05BE}אביב \u{200F}" ) );
		// Presentation forms become base letters.
		$this->assertSame( 'שלום אלה ב', HDS_Normalizer::normalize( "\u{FB2A}לום \u{FB4F}ה \u{FB31}" ) );
		$this->assertSame( 'יי', HDS_Normalizer::normalize( "\u{FB1D}\u{FB39}" ) );
		$this->assertSame( "שורה\n\nשורה", HDS_Normalizer::normalize( "שורה\n\n\n\nשורה" ) );
	}

	public function test_normalize_query(): void {
		$this->assertSame( 'hello world שלום', HDS_Normalizer::normalize_query( "  Hello\nWORLD\t  שָלום " ) );
		$this->assertSame( 'מה דין עו"ד?', HDS_Normalizer::normalize_query( "מה דין עו\u{05F4}ד?" ) );
		$this->assertSame( '', HDS_Normalizer::normalize_query( " \u{200F} " ) );
	}

	public function test_tokenize(): void {
		$tokens = HDS_Normalizer::tokenize( "עו\u{05F4}ד, ת\"א 57879-06-22 (ג\u{05F3}ורג\u{05F3}) בע\"מ: Hello WORLD! תל\u{05BE}אביב בְּרֵאשִׁית" );
		$this->assertSame( array( 'עו"ד', 'ת"א', '57879-06-22', 'ג\'ורג\'', 'בע"מ', 'hello', 'world', 'תל-אביב', 'בראשית' ), $tokens );
		$this->assertSame( array(), HDS_Normalizer::tokenize( ' - , ! ? "' ) );
		$this->assertSame( array( 'סע\'', '19', 'ב', '9' ), HDS_Normalizer::tokenize( "סע' 19(ב)(9)" ) );
		// Surrounding quotes are not part of a word; a prefix with maqaf/hyphen is.
		$this->assertSame( array( 'ציטוט', 'ו-1965' ), HDS_Normalizer::tokenize( '"ציטוט" ו-1965' ) );
		// Only quotes, apostrophes and hyphens join: decimals and dates split.
		$this->assertSame( array( '1.5', '12/03/2024', '17' ), HDS_Normalizer::tokenize( '1.5 12/03/2024 17%' ) );
		$this->assertSame( array( 'בערר', '8069-74/25', 'ת"א', '57879-06-22' ), HDS_Normalizer::tokenize( 'בערר 8069-74/25. ת"א 57879-06-22.' ) );
		$this->assertSame( array( 'סוף', 'משפט', 'התחלה' ), HDS_Normalizer::tokenize( 'סוף משפט.התחלה' ) );
	}
}
