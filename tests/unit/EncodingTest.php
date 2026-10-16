<?php
/**
 * Tests for HDS_Encoding (foundation).
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers HDS_Encoding
 */
class EncodingTest extends TestCase {

	protected function setUp(): void {
		hds_test_reset();
	}

	public function test_cp1255_hebrew_letters_e0_to_fa(): void {
		for ( $byte = 0xE0; $byte <= 0xFA; $byte++ ) {
			$this->assertSame( mb_chr( 0x05D0 + $byte - 0xE0, 'UTF-8' ), HDS_Encoding::cp1255_to_utf8( chr( $byte ) ), sprintf( '0x%02X', $byte ) );
		}
		$this->assertSame( 'שלום עולם', HDS_Encoding::cp1255_to_utf8( "\xF9\xEC\xE5\xED \xF2\xE5\xEC\xED" ) );
	}

	public function test_cp1255_special_characters(): void {
		$expected = array(
			0x80 => "\u{20AC}", // Euro.
			0x96 => "\u{2013}", // En dash.
			0x93 => "\u{201C}",
			0x94 => "\u{201D}",
			0xA4 => "\u{20AA}", // New sheqel sign.
			0xAA => "\u{00D7}",
			0xBA => "\u{00F7}",
			0xC0 => "\u{05B0}", // Sheva.
			0xC9 => "\u{05B9}", // Holam.
			0xCA => "\u{05BA}", // Holam haser (Microsoft's current table).
			0xCC => "\u{05BC}", // Dagesh.
			0xCE => "\u{05BE}", // Maqaf.
			0xD3 => "\u{05C3}", // Sof pasuq.
			0xD4 => "\u{05F0}",
			0xD7 => "\u{05F3}", // Geresh.
			0xD8 => "\u{05F4}", // Gershayim.
			0xFD => "\u{200E}", // LRM.
			0xFE => "\u{200F}", // RLM.
		);
		foreach ( $expected as $byte => $char ) {
			$this->assertSame( $char, HDS_Encoding::cp1255_to_utf8( chr( $byte ) ), sprintf( '0x%02X', $byte ) );
		}
	}

	public function test_cp1255_undefined_bytes_are_dropped(): void {
		foreach ( array( 0x81, 0x8A, 0x8C, 0x8D, 0x8E, 0x8F, 0x90, 0x9A, 0x9C, 0x9D, 0x9E, 0x9F, 0xD9, 0xDA, 0xDB, 0xDC, 0xDD, 0xDE, 0xDF, 0xFB, 0xFC, 0xFF ) as $byte ) {
			$this->assertSame( 'ab', HDS_Encoding::cp1255_to_utf8( 'a' . chr( $byte ) . 'b' ), sprintf( '0x%02X', $byte ) );
		}
	}

	public function test_cp1255_ascii_is_unchanged(): void {
		$ascii = implode( '', array_map( 'chr', range( 0, 127 ) ) );
		$this->assertSame( $ascii, HDS_Encoding::cp1255_to_utf8( $ascii ) );
	}

	public function test_cp1255_table_agrees_with_iconv(): void {
		if ( false === @iconv( 'WINDOWS-1255', 'UTF-8', 'a' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->markTestSkipped( 'iconv without Windows-1255.' );
		}
		for ( $byte = 0x80; $byte <= 0xFF; $byte++ ) {
			$reference = @iconv( 'WINDOWS-1255', 'UTF-8', chr( $byte ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $reference || 0xCA === $byte ) {
				continue; // Undefined in iconv's table (0xCA is defined by Microsoft).
			}
			$this->assertSame( $reference, HDS_Encoding::cp1255_to_utf8( chr( $byte ) ), sprintf( '0x%02X', $byte ) );
		}
	}

	public function test_cp1252(): void {
		$this->assertSame( "\u{20AC} \u{0160} \u{0178} é ü ß", HDS_Encoding::cp1252_to_utf8( "\x80 \x8A \x9F \xE9 \xFC \xDF" ) );
		$this->assertSame( 'ab', HDS_Encoding::cp1252_to_utf8( "a\x81\x8D\x8F\x90\x9Db" ) );
	}

	public function test_utf16_conversions(): void {
		$this->assertSame( 'שלום a', HDS_Encoding::utf16le_to_utf8( "\xE9\x05\xDC\x05\xD5\x05\xDD\x05\x20\x00\x61\x00" ) );
		$this->assertSame( 'שלום a', HDS_Encoding::utf16be_to_utf8( "\x05\xE9\x05\xDC\x05\xD5\x05\xDD\x00\x20\x00\x61" ) );
		// A dangling odd byte is dropped.
		$this->assertSame( 'a', HDS_Encoding::utf16le_to_utf8( "\x61\x00\x62" ) );
		$this->assertSame( '', HDS_Encoding::utf16le_to_utf8( '' ) );
		$this->assertSame( '', HDS_Encoding::utf16be_to_utf8( "\x00" ) );
	}

	public function test_chr(): void {
		$this->assertSame( 'א', HDS_Encoding::chr( 0x05D0 ) );
		$this->assertSame( '₪', HDS_Encoding::chr( 0x20AA ) );
		$this->assertSame( 'A', HDS_Encoding::chr( 65 ) );
	}

	public function test_looks_like_cp1255(): void {
		$this->assertTrue( HDS_Encoding::looks_like_cp1255( "\xF9\xEC\xE5\xED \xF2\xE5\xEC\xED" ) );
		$this->assertTrue( HDS_Encoding::looks_like_cp1255( 'Only ASCII text' ) ); // Inconclusive: Hebrew site default.
		$this->assertFalse( HDS_Encoding::looks_like_cp1255( "Caf\xE9 cr\xE8me br\xFBl\xE9e na\xEFve" ) );
		$this->assertTrue( HDS_Encoding::looks_like_cp1255( (string) file_get_contents( HDS_FIXTURES_DIR . '/generated/ARAR-25-09-8059.cp1255.txt' ) ) );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public function detections(): array {
		$hebrew = 'שלום עולם 2026';
		return array(
			'utf-8 bom'       => array( "\xEF\xBB\xBF" . $hebrew, HDS_Encoding::UTF8, $hebrew ),
			'utf-16le bom'    => array( "\xFF\xFE" . mb_convert_encoding( $hebrew, 'UTF-16LE', 'UTF-8' ), HDS_Encoding::UTF16LE, $hebrew ),
			'utf-16be bom'    => array( "\xFE\xFF" . mb_convert_encoding( $hebrew, 'UTF-16BE', 'UTF-8' ), HDS_Encoding::UTF16BE, $hebrew ),
			'utf-8'           => array( $hebrew, HDS_Encoding::UTF8, $hebrew ),
			'utf-16le no bom' => array( mb_convert_encoding( $hebrew, 'UTF-16LE', 'UTF-8' ), HDS_Encoding::UTF16LE, $hebrew ),
			'utf-16be no bom' => array( mb_convert_encoding( $hebrew, 'UTF-16BE', 'UTF-8' ), HDS_Encoding::UTF16BE, $hebrew ),
			'windows-1255'    => array( "\xF9\xEC\xE5\xED \xF2\xE5\xEC\xED 2026", HDS_Encoding::CP1255, $hebrew ),
			'windows-1252'    => array( "Caf\xE9 cr\xE8me", HDS_Encoding::CP1252, 'Café crème' ),
			'empty'           => array( '', HDS_Encoding::UTF8, '' ),
		);
	}

	/**
	 * @dataProvider detections
	 */
	public function test_detect_and_convert( string $bytes, string $encoding, string $text ): void {
		$result = HDS_Encoding::detect_and_convert( $bytes );
		$this->assertSame( $encoding, $result['encoding'] );
		$this->assertSame( $text, $result['text'] );
	}

	public function test_detect_real_fixtures(): void {
		$utf8   = HDS_Encoding::detect_and_convert( (string) file_get_contents( HDS_FIXTURES_DIR . '/generated/ARAR-25-09-8059.utf8.txt' ) );
		$cp1255 = HDS_Encoding::detect_and_convert( (string) file_get_contents( HDS_FIXTURES_DIR . '/generated/ARAR-25-09-8059.cp1255.txt' ) );
		$this->assertSame( HDS_Encoding::UTF8, $utf8['encoding'] );
		$this->assertSame( HDS_Encoding::CP1255, $cp1255['encoding'] );
		$this->assertSame( $utf8['text'], $cp1255['text'] );
	}

	public function test_scrub_utf8(): void {
		$this->assertSame( 'valid שלום', HDS_Encoding::scrub_utf8( 'valid שלום' ) );
		$this->assertSame( "a\u{FFFD}(b", HDS_Encoding::scrub_utf8( "a\xC3(b" ) );
		$this->assertTrue( mb_check_encoding( HDS_Encoding::scrub_utf8( "\xFF\xFE\xF0\x9F" ), 'UTF-8' ) );
		// The global substitute character is restored.
		$before = mb_substitute_character();
		HDS_Encoding::scrub_utf8( "\xFF" );
		$this->assertSame( $before, mb_substitute_character() );
	}

	public function test_bom_with_invalid_utf8_is_scrubbed(): void {
		$result = HDS_Encoding::detect_and_convert( "\xEF\xBB\xBFok\xFF" );
		$this->assertSame( HDS_Encoding::UTF8, $result['encoding'] );
		$this->assertSame( "ok\u{FFFD}", $result['text'] );
	}
}
