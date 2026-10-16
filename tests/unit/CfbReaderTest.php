<?php
/**
 * Tests for HDS_CFB_Reader.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Compound File Binary reader tests.
 */
class CfbReaderTest extends TestCase {

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

	/**
	 * Deterministic pseudo-random bytes.
	 *
	 * @param int $length Length.
	 * @param int $seed   Seed.
	 * @return string
	 */
	private static function bytes( int $length, int $seed ): string {
		mt_srand( $seed );
		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= chr( mt_rand( 0, 255 ) );
		}
		return $out;
	}

	/**
	 * Streams used by the round-trip tests.
	 *
	 * @return array<string, string>
	 */
	private static function sample_streams(): array {
		return array(
			'Small'     => self::bytes( 100, 1 ),
			'Exact4095' => self::bytes( 4095, 2 ),
			'Exact4096' => self::bytes( 4096, 3 ),
			'Big'       => self::bytes( 20000, 4 ),
			'Empty'     => '',
			'Tiny'      => 'x',
		);
	}

	/**
	 * Writer with the sample streams.
	 *
	 * @param int $shift Sector shift.
	 * @return HDS_Test_CFB_Writer
	 */
	private function sample_writer( int $shift = 9 ): HDS_Test_CFB_Writer {
		$writer = new HDS_Test_CFB_Writer( $shift );
		foreach ( self::sample_streams() as $name => $data ) {
			$writer->add_stream( $name, $data );
		}
		return $writer;
	}

	/**
	 * Write bytes to a temporary file.
	 *
	 * @param string $bytes Contents.
	 * @return string Path.
	 */
	private function tmp_file( string $bytes ): string {
		$path              = sys_get_temp_dir() . '/hds-cfb-' . getmypid() . '-' . count( $this->tmp_files ) . '-' . mt_rand() . '.bin';
		$this->tmp_files[] = $path;
		file_put_contents( $path, $bytes );
		return $path;
	}

	/**
	 * Overwrite a little-endian 32-bit value.
	 *
	 * @param string $bin    File bytes.
	 * @param int    $offset Offset.
	 * @param int    $value  Value.
	 * @return string
	 */
	private static function patch32( string $bin, int $offset, int $value ): string {
		return substr_replace( $bin, pack( 'V', $value ), $offset, 4 );
	}

	/**
	 * Assert that parsing and reading every stream throws CORRUPT quickly.
	 *
	 * @param string $bin File bytes.
	 * @return HDS_Extraction_Exception
	 */
	private function assert_corrupt( string $bin ): HDS_Extraction_Exception {
		$start = microtime( true );
		try {
			$reader = new HDS_CFB_Reader( $bin );
			foreach ( $reader->stream_names() as $name ) {
				$reader->get_stream( $name );
			}
			$this->fail( 'Expected a CORRUPT exception.' );
		} catch ( HDS_Extraction_Exception $e ) {
			$this->assertSame( HDS_Extraction_Exception::CORRUPT, $e->get_error_code(), $e->getMessage() );
			$this->assertNotSame( '', $e->getMessage() );
			$this->assertLessThan( 2.0, microtime( true ) - $start );
			return $e;
		}
	}

	public function test_round_trip_v3_mini_and_regular_streams(): void {
		$writer = $this->sample_writer();
		$bin    = $writer->build();
		$layout = $writer->layout();

		// Streams < 4096 bytes live in the mini stream, the others in regular sectors.
		$this->assertTrue( $layout['streams']['Small']['mini'] );
		$this->assertTrue( $layout['streams']['Exact4095']['mini'] );
		$this->assertTrue( $layout['streams']['Tiny']['mini'] );
		$this->assertFalse( $layout['streams']['Exact4096']['mini'] );
		$this->assertFalse( $layout['streams']['Big']['mini'] );

		$from_string = new HDS_CFB_Reader( $bin );
		$from_file   = HDS_CFB_Reader::from_file( $this->tmp_file( $bin ) );
		$this->assertSame( 3, $from_string->version() );
		$this->assertSame( 512, $from_string->sector_size() );
		foreach ( self::sample_streams() as $name => $data ) {
			$this->assertTrue( $from_string->has_stream( $name ), $name );
			$this->assertSame( $data, $from_string->get_stream( $name ), $name );
			$this->assertSame( $data, $from_file->get_stream( $name ), $name );
		}
		$names = $from_string->stream_names();
		sort( $names );
		$expected = array_keys( self::sample_streams() );
		sort( $expected );
		$this->assertSame( $expected, $names );
	}

	public function test_round_trip_v4_4096_byte_sectors(): void {
		$bin    = $this->sample_writer( 12 )->build();
		$reader = new HDS_CFB_Reader( $bin );
		$this->assertSame( 4, $reader->version() );
		$this->assertSame( 4096, $reader->sector_size() );
		foreach ( self::sample_streams() as $name => $data ) {
			$this->assertSame( $data, $reader->get_stream( $name ), $name );
		}
		$this->assertSame( self::sample_streams()['Big'], HDS_CFB_Reader::from_file( $this->tmp_file( $bin ) )->get_stream( 'Big' ) );
	}

	public function test_names_are_case_insensitive_and_the_shallowest_stream_wins(): void {
		$writer = new HDS_Test_CFB_Writer();
		$writer->add_stream( 'ObjectPool/_1234/WordDocument', 'embedded' );
		$writer->add_stream( 'WordDocument', 'main document' );
		$writer->add_stream( "\x05SummaryInformation", 'summary' );
		$reader = new HDS_CFB_Reader( $writer->build() );

		$this->assertSame( 'main document', $reader->get_stream( 'WordDocument' ) );
		$this->assertSame( 'main document', $reader->get_stream( 'worddocument' ) );
		$this->assertSame( 'main document', $reader->get_stream( 'WORDDOCUMENT' ) );
		$this->assertTrue( $reader->has_stream( 'SummaryInformation' ) );
		$this->assertSame( 'summary', $reader->get_stream( "\x05SummaryInformation" ) );
		$this->assertSame( 'summary', $reader->get_stream( 'summaryinformation' ) );
		$this->assertFalse( $reader->has_stream( 'ObjectPool' ) ); // A storage, not a stream.
		$this->assertFalse( $reader->has_stream( 'Missing' ) );
		$this->assertSame( 'WordDocument', $reader->stream_names()[0] );
		$this->assertContains( "\x05SummaryInformation", $reader->stream_names() );

		try {
			$reader->get_stream( 'Missing' );
			$this->fail( 'Expected CORRUPT for a missing stream.' );
		} catch ( HDS_Extraction_Exception $e ) {
			$this->assertSame( HDS_Extraction_Exception::CORRUPT, $e->get_error_code() );
		}
	}

	public function test_difat_sector_chain_beyond_109_fat_sectors(): void {
		// 7.3 MB needs > 109 FAT sectors with 512-byte sectors.
		$data   = str_repeat( self::bytes( 4093, 5 ), 1800 );
		$writer = new HDS_Test_CFB_Writer();
		$writer->add_stream( 'Data', $data );
		$writer->add_stream( 'Small', 'small stream' );
		$bin    = $writer->build();
		$layout = $writer->layout();
		$this->assertGreaterThan( 109, count( $layout['fat_sectors'] ) );
		$this->assertNotEmpty( $layout['difat_sectors'] );

		$reader = new HDS_CFB_Reader( $bin );
		$this->assertSame( md5( $data ), md5( $reader->get_stream( 'Data' ) ) );
		$this->assertSame( 'small stream', $reader->get_stream( 'Small' ) );

		$reader = HDS_CFB_Reader::from_file( $this->tmp_file( $bin ) );
		$this->assertSame( md5( $data ), md5( $reader->get_stream( 'Data' ) ) );

		// A DIFAT sector pointing to itself is a loop (once more FAT sectors are declared than it holds).
		$difat = $layout['difat_sectors'][0];
		$last  = $writer->sector_offset( $difat ) + 512 - 4;
		$loop  = self::patch32( $bin, $last, $difat );
		$loop  = self::patch32( $loop, 44, count( $layout['fat_sectors'] ) + 200 );
		$this->assertStringContainsString( 'Loop', $this->assert_corrupt( $loop )->getMessage() );
	}

	public function test_unused_difat_slots_are_ignored(): void {
		$writer = $this->sample_writer();
		$bin    = $writer->build();
		$this->assertCount( 1, $writer->layout()['fat_sectors'] );
		// Junk after the declared FAT sector and an unused DIFAT chain start.
		$bin    = self::patch32( $bin, 76 + 4 * 108, 0x12345678 );
		$bin    = self::patch32( $bin, 68, 999999 );
		$reader = new HDS_CFB_Reader( $bin );
		$this->assertSame( self::sample_streams()['Big'], $reader->get_stream( 'Big' ) );

		// The same DIFAT start is fatal when the header says the chain is needed.
		$this->assert_corrupt( self::patch32( $bin, 44, 2 ) );
	}

	public function test_v3_ignores_the_high_half_of_stream_sizes(): void {
		$writer                    = $this->sample_writer();
		$writer->garbage_size_high = true;
		$reader                    = new HDS_CFB_Reader( $writer->build() );
		foreach ( self::sample_streams() as $name => $data ) {
			$this->assertSame( $data, $reader->get_stream( $name ), $name );
		}
	}

	public function test_v4_stream_size_beyond_the_file_is_corrupt(): void {
		$writer = $this->sample_writer( 12 );
		$bin    = $writer->build();
		$id     = $writer->layout()['streams']['Big']['id'];
		$this->assert_corrupt( self::patch32( $bin, $writer->dir_entry_offset( $id ) + 124, 1 ) );
	}

	public function test_stream_size_larger_than_the_file_is_corrupt(): void {
		$writer = $this->sample_writer();
		$bin    = $writer->build();
		$id     = $writer->layout()['streams']['Big']['id'];
		$this->assert_corrupt( self::patch32( $bin, $writer->dir_entry_offset( $id ) + 120, 0x7FFFFFF0 ) );
	}

	/**
	 * Header-level corruption.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public function header_corruptions(): array {
		return array(
			'bad signature'      => array( fn( $b ) => "\xD0\xCF\x11\xE0\x00\x00\x00\x00" . substr( $b, 8 ) ),
			'too small'          => array( fn( $b ) => substr( $b, 0, 300 ) ),
			'header only'        => array( fn( $b ) => substr( $b, 0, 512 ) ),
			'byte order'         => array( fn( $b ) => substr_replace( $b, "\xFF\xFE", 28, 2 ) ),
			'major version'      => array( fn( $b ) => substr_replace( $b, "\x05\x00", 26, 2 ) ),
			'sector shift'       => array( fn( $b ) => substr_replace( $b, "\x0A\x00", 30, 2 ) ),
			'mini sector shift'  => array( fn( $b ) => substr_replace( $b, "\x07\x00", 32, 2 ) ),
			'mini stream cutoff' => array( fn( $b ) => self::patch32( $b, 56, 8192 ) ),
			'directory past EOF' => array( fn( $b ) => self::patch32( $b, 48, 999999 ) ),
			'FAT sector past EOF' => array( fn( $b ) => self::patch32( $b, 76, 999999 ) ),
			'no FAT sectors'     => array( fn( $b ) => substr_replace( $b, str_repeat( "\xFF", 436 ), 76, 436 ) ),
			'FAT count too big'  => array( fn( $b ) => self::patch32( $b, 44, 0x7FFFFFFF ) ),
			'DIFAT past EOF'     => array( fn( $b ) => self::patch32( self::patch32( $b, 68, 999999 ), 44, 5 ) ),
			'truncated in half'  => array( fn( $b ) => substr( $b, 0, intdiv( strlen( $b ), 2 ) ) ),
		);
	}

	/**
	 * Header-level corruption must give CORRUPT.
	 *
	 * @dataProvider header_corruptions
	 * @param callable $mutate Mutation.
	 */
	public function test_header_corruption_is_corrupt( callable $mutate ): void {
		$this->assert_corrupt( $mutate( $this->sample_writer()->build() ) );
	}

	public function test_fat_chain_loop_is_detected(): void {
		$writer  = $this->sample_writer();
		$bin     = $writer->build();
		$sectors = $writer->layout()['streams']['Big']['sectors'];
		$e       = $this->assert_corrupt( self::patch32( $bin, $writer->fat_entry_offset( $sectors[2] ), $sectors[0] ) );
		$this->assertStringContainsString( 'Loop', $e->getMessage() );
	}

	public function test_directory_chain_loop_does_not_hang(): void {
		$writer = $this->sample_writer();
		$bin    = $writer->build();
		$dir    = $writer->layout()['dir_sectors'];
		$this->assert_corrupt( self::patch32( $bin, $writer->fat_entry_offset( end( $dir ) ), $dir[0] ) );
	}

	public function test_sector_id_out_of_range_is_corrupt(): void {
		$writer  = $this->sample_writer();
		$bin     = $writer->build();
		$sectors = $writer->layout()['streams']['Big']['sectors'];
		$e       = $this->assert_corrupt( self::patch32( $bin, $writer->fat_entry_offset( $sectors[1] ), 0x00FFFFFF ) );
		$this->assertStringContainsString( 'out of range', $e->getMessage() );
		$this->assert_corrupt( self::patch32( $bin, $writer->fat_entry_offset( $sectors[1] ), 0xFFFFFFFF ) ); // FREESECT.
		$this->assert_corrupt( self::patch32( $bin, $writer->fat_entry_offset( $sectors[1] ), 0xFFFFFFFE ) ); // Chain ends early.
	}

	public function test_mini_fat_corruption_is_corrupt(): void {
		$writer = $this->sample_writer();
		$bin    = $writer->build();
		$layout = $writer->layout();
		$first  = $layout['streams']['Small']['start'];
		$offset = $writer->sector_offset( $layout['mini_fat_sectors'][0] ) + 4 * $first;
		$this->assert_corrupt( self::patch32( $bin, $offset, $first ) );   // Loop.
		$this->assert_corrupt( self::patch32( $bin, $offset, 50000 ) );    // Out of range.

		// Mini FAT chain start past EOF.
		$this->assert_corrupt( self::patch32( $bin, 60, 999999 ) );
	}

	public function test_missing_root_entry_is_corrupt(): void {
		$writer = $this->sample_writer();
		$bin    = $writer->build();
		$e      = $this->assert_corrupt( substr_replace( $bin, "\x01", $writer->dir_entry_offset( 0 ) + 66, 1 ) );
		$this->assertStringContainsString( 'root', $e->getMessage() );
	}

	public function test_unreadable_file(): void {
		try {
			HDS_CFB_Reader::from_file( sys_get_temp_dir() . '/hds-does-not-exist-' . getmypid() . '.doc' );
			$this->fail( 'Expected UNREADABLE.' );
		} catch ( HDS_Extraction_Exception $e ) {
			$this->assertSame( HDS_Extraction_Exception::UNREADABLE, $e->get_error_code() );
		}
	}

	public function test_real_fixture_streams(): void {
		$reader = HDS_CFB_Reader::from_file( HDS_FIXTURES_DIR . '/ARAR-25-09-8059.doc' );
		$this->assertSame( 3, $reader->version() );
		foreach ( array( 'WordDocument', '1Table', 'Data', "\x05SummaryInformation", "\x05DocumentSummaryInformation", "\x01CompObj" ) as $name ) {
			$this->assertTrue( $reader->has_stream( $name ), $name );
		}
		$word = $reader->get_stream( 'WordDocument' );
		$this->assertSame( 134291, strlen( $word ) );
		$this->assertSame( "\xEC\xA5", substr( $word, 0, 2 ) );
		$this->assertSame( 114, strlen( $reader->get_stream( "\x01CompObj" ) ) ); // Mini stream.

		$from_string = new HDS_CFB_Reader( (string) file_get_contents( HDS_FIXTURES_DIR . '/ARAR-25-09-8059.doc' ) );
		$this->assertSame( md5( $word ), md5( $from_string->get_stream( 'WordDocument' ) ) );
	}

	public function test_fuzzed_files_only_throw_extraction_exceptions(): void {
		$base   = $this->sample_writer()->build();
		$length = strlen( $base );
		mt_srand( 424242 );
		$parsed = 0;
		$failed = 0;
		$start  = microtime( true );
		for ( $i = 0; $i < 400; $i++ ) {
			$bin   = $base;
			$edits = mt_rand( 1, 6 );
			for ( $k = 0; $k < $edits; $k++ ) {
				// Bias towards the header, FAT and directory (first and last sectors).
				$pos         = 0 === $k % 2 ? mt_rand( 0, min( 1535, $length - 1 ) ) : mt_rand( 0, $length - 1 );
				$bin[ $pos ] = chr( mt_rand( 0, 255 ) );
			}
			if ( 0 === $i % 10 ) {
				$bin = substr( $bin, 0, mt_rand( 0, $length ) );
			}
			try {
				$reader = new HDS_CFB_Reader( $bin );
				foreach ( $reader->stream_names() as $name ) {
					$reader->get_stream( $name );
				}
				$parsed++;
			} catch ( HDS_Extraction_Exception $e ) {
				$this->assertNotSame( '', $e->getMessage() );
				$failed++;
			}
		}
		$this->assertSame( 400, $parsed + $failed );
		$this->assertGreaterThan( 0, $failed );
		$this->assertLessThan( 10.0, microtime( true ) - $start );
	}
}
