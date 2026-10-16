<?php
/**
 * Test-only builder of Compound File Binary containers and minimal Word
 * 97 / Word 95 documents, used to exercise HDS_CFB_Reader and
 * HDS_Doc_Extractor without binary fixtures.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CFB writer (v3 with 512-byte sectors by default, v4 optional).
 *
 * Streams smaller than 4096 bytes are stored in the mini stream, larger
 * ones in regular sectors. After build(), layout() describes where every
 * structure was placed so tests can corrupt specific bytes.
 */
final class HDS_Test_CFB_Writer {

	const ENDOFCHAIN = 0xFFFFFFFE;
	const FREESECT   = 0xFFFFFFFF;
	const FATSECT    = 0xFFFFFFFD;
	const DIFSECT    = 0xFFFFFFFC;
	const NOSTREAM   = 0xFFFFFFFF;

	const FC_COMPRESSED = 0x40000000;
	const TEXT_START    = 1024;

	/**
	 * Sector shift (9 or 12).
	 *
	 * @var int
	 */
	private $shift;

	/**
	 * Directory nodes: {name, type, children[], data, parent}.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $nodes = array();

	/**
	 * Write 0xDEADBEEF into the (ignored) high half of v3 stream sizes.
	 *
	 * @var bool
	 */
	public $garbage_size_high = false;

	/**
	 * Layout of the last build().
	 *
	 * @var array<string, mixed>
	 */
	private $layout = array();

	/**
	 * Constructor.
	 *
	 * @param int $shift Sector shift: 9 (v3, 512-byte sectors) or 12 (v4, 4096).
	 */
	public function __construct( int $shift = 9 ) {
		$this->shift   = $shift;
		$this->nodes[] = array(
			'name'     => 'Root Entry',
			'type'     => 5,
			'children' => array(),
			'data'     => '',
		);
	}

	/**
	 * Add a stream. "A/B/Name" creates storages A and A/B.
	 *
	 * @param string $path Stream path.
	 * @param string $data Contents.
	 * @return self
	 */
	public function add_stream( string $path, string $data ): self {
		$parts  = explode( '/', $path );
		$name   = array_pop( $parts );
		$parent = 0;
		foreach ( $parts as $storage ) {
			$found = null;
			foreach ( $this->nodes[ $parent ]['children'] as $child ) {
				if ( 1 === $this->nodes[ $child ]['type'] && $this->nodes[ $child ]['name'] === $storage ) {
					$found = $child;
				}
			}
			if ( null === $found ) {
				$found                                  = count( $this->nodes );
				$this->nodes[]                          = array(
					'name'     => $storage,
					'type'     => 1,
					'children' => array(),
					'data'     => '',
				);
				$this->nodes[ $parent ]['children'][] = $found;
			}
			$parent = $found;
		}
		$id                                     = count( $this->nodes );
		$this->nodes[]                          = array(
			'name'     => $name,
			'type'     => 2,
			'children' => array(),
			'data'     => $data,
		);
		$this->nodes[ $parent ]['children'][] = $id;
		return $this;
	}

	/**
	 * Layout of the last build: sector_size, sector_count, fat_sectors,
	 * difat_sectors, dir_sectors, dir_start, mini_fat_sectors,
	 * mini_stream_start, streams (path => {id, start, size, mini, sectors}).
	 *
	 * @return array<string, mixed>
	 */
	public function layout(): array {
		return $this->layout;
	}

	/**
	 * File offset of a regular sector.
	 *
	 * @param int $sector Sector id.
	 * @return int
	 */
	public function sector_offset( int $sector ): int {
		return ( $sector + 1 ) << $this->shift;
	}

	/**
	 * File offset of the FAT entry for a sector.
	 *
	 * @param int $sector Sector id.
	 * @return int
	 */
	public function fat_entry_offset( int $sector ): int {
		$per = ( 1 << $this->shift ) >> 2;
		$fat = $this->layout['fat_sectors'][ intdiv( $sector, $per ) ];
		return $this->sector_offset( $fat ) + 4 * ( $sector % $per );
	}

	/**
	 * File offset of a directory entry.
	 *
	 * @param int $id Entry id.
	 * @return int
	 */
	public function dir_entry_offset( int $id ): int {
		$per = ( 1 << $this->shift ) >> 7;
		return $this->sector_offset( $this->layout['dir_sectors'][ intdiv( $id, $per ) ] ) + 128 * ( $id % $per );
	}

	/**
	 * Build the compound file.
	 *
	 * @return string
	 */
	public function build(): string {
		$ss      = 1 << $this->shift;
		$per_fat = $ss >> 2;
		$fat     = array();
		$next    = 0;
		$layout  = array(
			'sector_size' => $ss,
			'streams'     => array(),
		);

		$alloc = function ( int $count ) use ( &$fat, &$next ): array {
			$ids = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$ids[]            = $next;
				$fat[ $next ]     = $i + 1 < $count ? $next + 1 : self::ENDOFCHAIN;
				$next++;
			}
			return $ids;
		};

		// Mini stream for small streams, regular sectors for big ones.
		$mini_stream = '';
		$mini_fat    = array();
		$big         = array();
		$starts      = array();
		foreach ( $this->nodes as $id => $node ) {
			if ( 2 !== $node['type'] ) {
				continue;
			}
			$size = strlen( $node['data'] );
			if ( 0 === $size ) {
				$starts[ $id ] = array( self::ENDOFCHAIN, false, array() );
			} elseif ( $size < 4096 ) {
				$first = count( $mini_fat );
				$count = intdiv( $size + 63, 64 );
				$ids   = array();
				for ( $i = 0; $i < $count; $i++ ) {
					$ids[]      = $first + $i;
					$mini_fat[] = $i + 1 < $count ? $first + $i + 1 : self::ENDOFCHAIN;
				}
				$mini_stream  .= str_pad( $node['data'], 64 * $count, "\0" );
				$starts[ $id ] = array( $first, true, $ids );
			} else {
				$big[] = $id;
			}
		}

		$sectors = array(); // Sector id => contents.
		foreach ( $big as $id ) {
			$data  = $this->nodes[ $id ]['data'];
			$count = intdiv( strlen( $data ) + $ss - 1, $ss );
			$ids   = $alloc( $count );
			foreach ( $ids as $k => $sid ) {
				$sectors[ $sid ] = substr( $data, $k * $ss, $ss );
			}
			$starts[ $id ] = array( $ids[0], false, $ids );
		}

		$mini_start = self::ENDOFCHAIN;
		if ( '' !== $mini_stream ) {
			$ids = $alloc( intdiv( strlen( $mini_stream ) + $ss - 1, $ss ) );
			foreach ( $ids as $k => $sid ) {
				$sectors[ $sid ] = substr( $mini_stream, $k * $ss, $ss );
			}
			$mini_start = $ids[0];
		}

		$mini_fat_ids = array();
		if ( $mini_fat ) {
			$bytes        = pack( 'V*', ...$mini_fat );
			$mini_fat_ids = $alloc( intdiv( strlen( $bytes ) + $ss - 1, $ss ) );
			foreach ( $mini_fat_ids as $k => $sid ) {
				$sectors[ $sid ] = str_pad( substr( $bytes, $k * $ss, $ss ), $ss, "\xFF" );
			}
		}

		$directory = $this->directory( $starts, '' === $mini_stream ? self::ENDOFCHAIN : $mini_start, strlen( $mini_stream ) );
		$dir_ids   = $alloc( intdiv( strlen( $directory ) + $ss - 1, $ss ) );
		foreach ( $dir_ids as $k => $sid ) {
			$sectors[ $sid ] = substr( $directory, $k * $ss, $ss );
		}

		// FAT + DIFAT sizes depend on each other.
		$data_sectors = $next;
		$fat_count    = 0;
		$difat_count  = 0;
		do {
			$total     = $data_sectors + $fat_count + $difat_count;
			$new_fat   = intdiv( $total + $per_fat - 1, $per_fat );
			$new_difat = $new_fat > 109 ? intdiv( $new_fat - 109 + $per_fat - 2, $per_fat - 1 ) : 0;
			$stable    = $new_fat === $fat_count && $new_difat === $difat_count;
			$fat_count   = $new_fat;
			$difat_count = $new_difat;
		} while ( ! $stable );

		$fat_ids = array();
		for ( $i = 0; $i < $fat_count; $i++ ) {
			$fat_ids[]    = $next;
			$fat[ $next ] = self::FATSECT;
			$next++;
		}
		$difat_ids = array();
		for ( $i = 0; $i < $difat_count; $i++ ) {
			$difat_ids[]  = $next;
			$fat[ $next ] = self::DIFSECT;
			$next++;
		}

		$fat_entries = array_fill( 0, $fat_count * $per_fat, self::FREESECT );
		foreach ( $fat as $sid => $value ) {
			$fat_entries[ $sid ] = $value;
		}
		$fat_bytes = pack( 'V*', ...$fat_entries );
		foreach ( $fat_ids as $k => $sid ) {
			$sectors[ $sid ] = substr( $fat_bytes, $k * $ss, $ss );
		}

		$header_difat = array_pad( array_slice( $fat_ids, 0, 109 ), 109, self::FREESECT );
		$rest         = array_slice( $fat_ids, 109 );
		foreach ( $difat_ids as $k => $sid ) {
			$entries         = array_pad( array_slice( $rest, $k * ( $per_fat - 1 ), $per_fat - 1 ), $per_fat - 1, self::FREESECT );
			$entries[]       = isset( $difat_ids[ $k + 1 ] ) ? $difat_ids[ $k + 1 ] : self::ENDOFCHAIN;
			$sectors[ $sid ] = pack( 'V*', ...$entries );
		}

		$header  = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat( "\0", 16 );
		$header .= pack( 'vvvvv', 0x3E, 12 === $this->shift ? 4 : 3, 0xFFFE, $this->shift, 6 );
		$header .= str_repeat( "\0", 6 );
		$header .= pack(
			'VVVVVVVVV',
			12 === $this->shift ? count( $dir_ids ) : 0,
			$fat_count,
			$dir_ids[0],
			0,
			4096,
			$mini_fat_ids ? $mini_fat_ids[0] : self::ENDOFCHAIN,
			count( $mini_fat_ids ),
			$difat_ids ? $difat_ids[0] : self::ENDOFCHAIN,
			$difat_count
		);
		$header .= pack( 'V*', ...$header_difat );
		$header  = str_pad( $header, $ss, "\0" );

		$parts = array( $header );
		for ( $sid = 0; $sid < $next; $sid++ ) {
			$parts[] = str_pad( isset( $sectors[ $sid ] ) ? $sectors[ $sid ] : '', $ss, "\0" );
		}

		foreach ( $this->nodes as $id => $node ) {
			if ( 2 === $node['type'] ) {
				$layout['streams'][ $this->path( $id ) ] = array(
					'id'      => $id,
					'start'   => $starts[ $id ][0],
					'size'    => strlen( $node['data'] ),
					'mini'    => $starts[ $id ][1],
					'sectors' => $starts[ $id ][2],
				);
			}
		}
		$layout['sector_count']      = $next;
		$layout['fat_sectors']       = $fat_ids;
		$layout['difat_sectors']     = $difat_ids;
		$layout['dir_sectors']       = $dir_ids;
		$layout['dir_start']         = $dir_ids[0];
		$layout['mini_fat_sectors']  = $mini_fat_ids;
		$layout['mini_stream_start'] = $mini_start;
		$this->layout                = $layout;

		return implode( '', $parts );
	}

	/**
	 * Serialize the directory (balanced red-black-compatible trees, all black).
	 *
	 * @param array<int, array> $starts      Stream id => array( start, mini, sectors ).
	 * @param int               $mini_start  First sector of the mini stream.
	 * @param int               $mini_length Mini stream size.
	 * @return string
	 */
	private function directory( array $starts, int $mini_start, int $mini_length ): string {
		$left  = array();
		$right = array();
		$child = array();
		foreach ( $this->nodes as $id => $node ) {
			$left[ $id ]  = self::NOSTREAM;
			$right[ $id ] = self::NOSTREAM;
			$child[ $id ] = self::NOSTREAM;
		}
		foreach ( $this->nodes as $id => $node ) {
			$kids = $node['children'];
			usort(
				$kids,
				function ( $a, $b ) {
					$na = $this->nodes[ $a ]['name'];
					$nb = $this->nodes[ $b ]['name'];
					if ( strlen( $na ) !== strlen( $nb ) ) {
						return strlen( $na ) - strlen( $nb );
					}
					return strcmp( strtoupper( $na ), strtoupper( $nb ) );
				}
			);
			$child[ $id ] = $this->tree( $kids, $left, $right );
		}

		$out = '';
		foreach ( $this->nodes as $id => $node ) {
			$name = mb_convert_encoding( $node['name'], 'UTF-16LE', 'UTF-8' );
			if ( 5 === $node['type'] ) {
				$start = $mini_start;
				$size  = $mini_length;
			} elseif ( 2 === $node['type'] ) {
				$start = $starts[ $id ][0];
				$size  = strlen( $node['data'] );
			} else {
				$start = 0;
				$size  = 0;
			}
			$high = ( $this->garbage_size_high && 9 === $this->shift && 2 === $node['type'] ) ? 0xDEADBEEF : 0;
			$out .= str_pad( $name . "\0\0", 64, "\0" );
			$out .= pack( 'vCC', strlen( $name ) + 2, $node['type'], 1 );
			$out .= pack( 'VVV', $left[ $id ], $right[ $id ], $child[ $id ] );
			$out .= str_repeat( "\0", 16 + 4 + 16 );
			$out .= pack( 'VVV', $start, $size, $high );
		}
		$per   = ( 1 << $this->shift ) >> 7;
		$count = count( $this->nodes );
		while ( 0 !== $count % $per ) {
			$out .= str_repeat( "\0", 66 ) . "\0\0" . pack( 'VVV', self::NOSTREAM, self::NOSTREAM, self::NOSTREAM ) . str_repeat( "\0", 48 );
			$count++;
		}
		return $out;
	}

	/**
	 * Build a balanced binary tree over sorted ids.
	 *
	 * @param int[] $ids   Sorted ids.
	 * @param int[] $left  Left pointers (by reference).
	 * @param int[] $right Right pointers (by reference).
	 * @return int Root id or NOSTREAM.
	 */
	private function tree( array $ids, array &$left, array &$right ): int {
		if ( ! $ids ) {
			return self::NOSTREAM;
		}
		$mid          = intdiv( count( $ids ), 2 );
		$root         = $ids[ $mid ];
		$left[ $root ]  = $this->tree( array_slice( $ids, 0, $mid ), $left, $right );
		$right[ $root ] = $this->tree( array_slice( $ids, $mid + 1 ), $left, $right );
		return $root;
	}

	/**
	 * Path of a node.
	 *
	 * @param int $id Node id.
	 * @return string
	 */
	private function path( int $id ): string {
		foreach ( $this->nodes as $pid => $node ) {
			if ( in_array( $id, $node['children'], true ) ) {
				return 0 === $pid ? $this->nodes[ $id ]['name'] : $this->path( $pid ) . '/' . $this->nodes[ $id ]['name'];
			}
		}
		return $this->nodes[ $id ]['name'];
	}

	/**
	 * Encode UTF-8 text into a single-byte Windows code page.
	 *
	 * @param string $text     UTF-8 text.
	 * @param int    $codepage 1255 or 1252.
	 * @return string
	 */
	public static function encode_8bit( string $text, int $codepage ): string {
		$map = array();
		for ( $b = 0x80; $b <= 0xFF; $b++ ) {
			if ( 1255 === $codepage ) {
				$cp = HDS_Encoding::TABLE_1255[ $b ];
			} else {
				$cp = $b < 0xA0 ? HDS_Encoding::TABLE_1252_C1[ $b ] : $b;
			}
			if ( $cp ) {
				$map[ HDS_Encoding::chr( $cp ) ] = chr( $b );
			}
		}
		$out  = strtr( $text, $map );
		$back = 1255 === $codepage ? HDS_Encoding::cp1255_to_utf8( $out ) : HDS_Encoding::cp1252_to_utf8( $out );
		if ( $back !== $text ) {
			// A character outside the code page would silently pass through as UTF-8.
			throw new InvalidArgumentException( 'Text not representable in code page ' . $codepage );
		}
		return $out;
	}

	/**
	 * Build the WordDocument and table streams of a Word 97 document.
	 *
	 * Options:
	 * - pieces: list of {text (UTF-8), compressed (bool), codepage (1255|1252)} or {raw, compressed}.
	 * - ccp: {text, ftn, hdd, mcr, atn, edn, txbx, hdrtxbx}; text defaults to all characters.
	 * - lid (0x0409), lid_fe (= lid), nfib (0xC1), flags (extra FibBase flag bits),
	 *   table ('1Table' | '0Table'), prc (bool: add a Prc before the Pcdt),
	 *   clx (raw CLX override), row_ends (CPs of 0x07 marks that end a table row),
	 *   cells (CPs of 0x07 marks that end a cell; given explicit in-table PAPX),
	 *   huge_papx (bool: store the row-end properties in the Data stream).
	 *
	 * @param array<string, mixed> $o Options.
	 * @return array<string, string> Stream name => contents.
	 */
	public static function word_streams( array $o ): array {
		$pieces = isset( $o['pieces'] ) ? $o['pieces'] : array();
		$lid    = isset( $o['lid'] ) ? $o['lid'] : 0x0409;
		$table  = isset( $o['table'] ) ? $o['table'] : '1Table';

		// Text pieces.
		$text_bytes = '';
		$cps        = array( 0 );
		$pcd_fcs    = array();
		$char_fc    = array(); // CP => array( fc, width ).
		$cp         = 0;
		foreach ( $pieces as $piece ) {
			$compressed = ! empty( $piece['compressed'] );
			if ( isset( $piece['raw'] ) ) {
				$bytes = $piece['raw'];
			} elseif ( $compressed ) {
				$bytes = self::encode_8bit( $piece['text'], isset( $piece['codepage'] ) ? $piece['codepage'] : 1252 );
			} else {
				$bytes = mb_convert_encoding( $piece['text'], 'UTF-16LE', 'UTF-8' );
			}
			if ( ! $compressed && 0 !== strlen( $text_bytes ) % 2 ) {
				$text_bytes .= "\0";
			}
			$fc        = self::TEXT_START + strlen( $text_bytes );
			$width     = $compressed ? 1 : 2;
			$chars     = intdiv( strlen( $bytes ), $width );
			$pcd_fcs[] = $compressed ? ( ( $fc * 2 ) | self::FC_COMPRESSED ) : $fc;
			for ( $i = 0; $i < $chars; $i++ ) {
				$char_fc[ $cp + $i ] = array( $fc + $i * $width, $width );
			}
			$text_bytes .= $bytes;
			$cp         += $chars;
			$cps[]       = $cp;
		}
		$text_end = self::TEXT_START + strlen( $text_bytes );

		$ccp = array_merge(
			array(
				'text'    => $cp,
				'ftn'     => 0,
				'hdd'     => 0,
				'mcr'     => 0,
				'atn'     => 0,
				'edn'     => 0,
				'txbx'    => 0,
				'hdrtxbx' => 0,
			),
			isset( $o['ccp'] ) ? $o['ccp'] : array()
		);

		// Paragraph properties: runs over [TEXT_START, text_end).
		$table_stream = '';
		$data_stream  = '';
		$fkp_pages    = array();
		$bte          = '';
		$marks        = array();
		foreach ( isset( $o['row_ends'] ) ? $o['row_ends'] : array() as $mark_cp ) {
			$marks[ $mark_cp ] = 'ttp';
		}
		foreach ( isset( $o['cells'] ) ? $o['cells'] : array() as $mark_cp ) {
			$marks[ $mark_cp ] = 'cell';
		}
		ksort( $marks );
		if ( $marks ) {
			$runs = array(); // array( fc_start, fc_end, kind ).
			$pos  = self::TEXT_START;
			foreach ( $marks as $mark_cp => $kind ) {
				list( $fc, $width ) = $char_fc[ $mark_cp ];
				if ( $fc > $pos ) {
					$runs[] = array( $pos, $fc, 'none' );
				}
				$runs[] = array( $fc, $fc + $width, $kind );
				$pos    = $fc + $width;
			}
			if ( $text_end > $pos ) {
				$runs[] = array( $pos, $text_end, 'none' );
			}

			$ttp_grpprl = self::ttp_grpprl();
			if ( ! empty( $o['huge_papx'] ) ) {
				$data_stream = str_repeat( "\0", 100 ) . pack( 'v', strlen( $ttp_grpprl ) ) . $ttp_grpprl;
				$ttp_grpprl  = pack( 'vV', 0x6646, 100 );
			}
			$cell_grpprl = pack( 'vC', 0x2416, 1 ); // sprmPFInTable.

			$first_pn = intdiv( $text_end + 511, 512 );
			$bte_fcs  = array();
			$bte_pns  = array();
			foreach ( array_chunk( $runs, 20 ) as $k => $chunk ) {
				$fkp_pages[] = self::papx_fkp( $chunk, $ttp_grpprl, $cell_grpprl );
				$bte_fcs[]   = $chunk[0][0];
				$bte_pns[]   = $first_pn + $k;
			}
			$last      = end( $runs );
			$bte_fcs[] = $last[1];
			$bte       = pack( 'V*', ...$bte_fcs ) . pack( 'V*', ...$bte_pns );
		}

		// CLX.
		if ( isset( $o['clx'] ) ) {
			$clx = $o['clx'];
		} else {
			$plc = pack( 'V*', ...$cps );
			foreach ( $pcd_fcs as $fc ) {
				$plc .= pack( 'vVv', 0, $fc, 0 );
			}
			$clx = '';
			if ( ! empty( $o['prc'] ) ) {
				$clx .= "\x01" . pack( 'v', 4 ) . pack( 'vCC', 0x2416, 1, 0 );
			}
			$clx .= "\x02" . pack( 'V', strlen( $plc ) ) . $plc;
		}
		$fc_clx        = 16; // Leave a little room at the start of the table stream.
		$table_stream  = str_repeat( "\0", $fc_clx ) . $clx;
		$fc_bte        = strlen( $table_stream );
		$table_stream .= $bte;

		// WordDocument: FIB, text, FKP pages.
		$flags = ( isset( $o['flags'] ) ? (int) $o['flags'] : 0 ) | ( '1Table' === $table ? 0x0200 : 0 );
		$fib   = pack( 'vvvvvv', 0xA5EC, isset( $o['nfib'] ) ? $o['nfib'] : 0xC1, 0, $lid, 0, $flags );
		$fib  .= pack( 'vVCCvvVV', 0xBF, 0, 0, 0, 0, 0, 0, 0 );
		$rg_w  = array_fill( 0, 14, 0 );
		$rg_w[13] = isset( $o['lid_fe'] ) ? $o['lid_fe'] : $lid;
		$fib  .= pack( 'v', 14 ) . pack( 'v*', ...$rg_w );
		$rg_lw = array_fill( 0, 22, 0 );
		$rg_lw[3]  = $ccp['text'];
		$rg_lw[4]  = $ccp['ftn'];
		$rg_lw[5]  = $ccp['hdd'];
		$rg_lw[6]  = $ccp['mcr'];
		$rg_lw[7]  = $ccp['atn'];
		$rg_lw[8]  = $ccp['edn'];
		$rg_lw[9]  = $ccp['txbx'];
		$rg_lw[10] = $ccp['hdrtxbx'];
		$fib      .= pack( 'v', 22 ) . pack( 'V*', ...$rg_lw );
		$rg_fc     = array_fill( 0, 93 * 2, 0 );
		$rg_fc[66] = $fc_clx;
		$rg_fc[67] = strlen( $clx );
		if ( '' !== $bte ) {
			$rg_fc[26] = $fc_bte;
			$rg_fc[27] = strlen( $bte );
		}
		$fib .= pack( 'v', 93 ) . pack( 'V*', ...$rg_fc ) . pack( 'v', 0 );

		$word = str_pad( $fib, self::TEXT_START, "\0" ) . $text_bytes;
		if ( $fkp_pages ) {
			$word = str_pad( $word, $first_pn * 512, "\0" ) . implode( '', $fkp_pages );
		}

		$streams = array(
			'WordDocument' => $word,
			$table         => $table_stream,
		);
		if ( '' !== $data_stream ) {
			$streams['Data'] = $data_stream;
		}
		return $streams;
	}

	/**
	 * Grpprl of a table row end: sprmPFInTable, a sprmTDefTable for two
	 * cells (variable-length operand that must be skipped), sprmPFTtp.
	 *
	 * @return string
	 */
	private static function ttp_grpprl(): string {
		$itc_mac = 2;
		$rest    = chr( $itc_mac ) . pack( 'v*', 0, 2000, 4000 ) . str_repeat( "\0", 20 * $itc_mac );
		$grpprl  = pack( 'vC', 0x2416, 1 );
		$grpprl .= pack( 'v', 0xD608 ) . pack( 'v', strlen( $rest ) + 1 ) . $rest;
		$grpprl .= pack( 'vC', 0x2417, 1 );
		return $grpprl;
	}

	/**
	 * Build one PAPX FKP page.
	 *
	 * @param array<int, array> $runs        Runs array( fc_start, fc_end, kind ).
	 * @param string            $ttp_grpprl  Grpprl for row ends.
	 * @param string            $cell_grpprl Grpprl for cell ends.
	 * @return string 512 bytes.
	 */
	private static function papx_fkp( array $runs, string $ttp_grpprl, string $cell_grpprl ): string {
		$crun = count( $runs );
		$page = str_repeat( "\0", 512 );
		$fcs  = array();
		foreach ( $runs as $run ) {
			$fcs[] = $run[0];
		}
		$fcs[] = $runs[ $crun - 1 ][1];
		$page  = substr_replace( $page, pack( 'V*', ...$fcs ), 0, 4 * ( $crun + 1 ) );

		$free    = 511; // PAPX data grows down from the end.
		$offsets = array();
		foreach ( array( 'ttp' => $ttp_grpprl, 'cell' => $cell_grpprl ) as $kind => $grpprl ) {
			$body = "\0\0" . $grpprl; // istd 0.
			$len  = strlen( $body );
			$papx = 1 === $len % 2 ? chr( ( $len + 1 ) >> 1 ) . $body : "\0" . chr( $len >> 1 ) . $body;
			$free = ( $free - strlen( $papx ) ) & ~1;
			$page = substr_replace( $page, $papx, $free, strlen( $papx ) );
			$offsets[ $kind ] = $free >> 1;
		}
		foreach ( $runs as $j => $run ) {
			$b    = isset( $offsets[ $run[2] ] ) ? $offsets[ $run[2] ] : 0;
			$page = substr_replace( $page, chr( $b ), 4 * ( $crun + 1 ) + 13 * $j, 1 );
		}
		$page[511] = chr( $crun );
		return $page;
	}

	/**
	 * Build a complete Word 97 .doc file.
	 *
	 * @param array<string, mixed>  $o     Options for word_streams().
	 * @param array<string, string> $extra Extra streams (path => contents).
	 * @return string
	 */
	public static function word_file( array $o, array $extra = array() ): string {
		$writer = new self();
		foreach ( self::word_streams( $o ) + $extra as $name => $data ) {
			$writer->add_stream( $name, $data );
		}
		return $writer->build();
	}

	/**
	 * A "\x05SummaryInformation" property set holding only the codepage.
	 *
	 * @param int $codepage Codepage (e.g. 1255).
	 * @return string
	 */
	public static function summary_information( int $codepage ): string {
		$fmtid = "\xE0\x85\x9F\xF2\xF9\x4F\x68\x10\xAB\x91\x08\x00\x2B\x27\xB3\xD9";
		$head  = pack( 'vvV', 0xFFFE, 0, 0x00020006 ) . str_repeat( "\0", 16 ) . pack( 'V', 1 ) . $fmtid . pack( 'V', 48 );
		$value = pack( 'vvvv', 2, 0, $codepage, 0 ); // VT_I2.
		$set   = pack( 'VV', 16 + strlen( $value ), 1 ) . pack( 'VV', 1, 16 ) . $value;
		return $head . $set;
	}

	/**
	 * Build a Word 6/95 style .doc file (8-bit text between fcMin and fcMac).
	 *
	 * @param string $bytes 8-bit text bytes.
	 * @param int    $lid   Language id.
	 * @param int    $nfib  FIB version (0x65 Word 6, 0x68 Word 95).
	 * @param int    $flags FibBase flags.
	 * @return string
	 */
	public static function word95_file( string $bytes, int $lid, int $nfib = 0x65, int $flags = 0 ): string {
		$fc_min = 0x300;
		$fib    = pack( 'vvvvvv', 0xA5EC, $nfib, 0, $lid, 0, $flags );
		$fib    = str_pad( $fib, 0x18, "\0" ) . pack( 'VV', $fc_min, $fc_min + strlen( $bytes ) );
		$fib   .= pack( 'V', $fc_min + strlen( $bytes ) ); // cbMac.
		$word   = str_pad( $fib, $fc_min, "\0" ) . $bytes;
		$writer = new self();
		$writer->add_stream( 'WordDocument', $word );
		return $writer->build();
	}
}
