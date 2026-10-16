<?php
/**
 * Text extractor for Word 97-2003 binary documents (.doc, [MS-DOC]), with
 * a best-effort fallback for Word 6/95 files. Pure PHP.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Word .doc extractor.
 *
 * Reads the FIB from the WordDocument stream, the piece table (CLX) from
 * the table stream, and assembles the main text, footnotes and endnotes in
 * logical order. Field instructions are dropped (their displayed result is
 * kept); table cell marks become tabs and row ends become line breaks.
 */
final class HDS_Doc_Extractor implements HDS_Extractor_Interface {

	const W_IDENT         = 0xA5EC;
	const NFIB_WORD97     = 0x00C0;
	const F_ENCRYPTED     = 0x0100;
	const F_WHICH_TBL_STM = 0x0200;
	const FC_COMPRESSED   = 0x40000000;
	const FC_MASK         = 0x3FFFFFFF;
	const LANG_HEBREW     = 0x0D;
	const CODEPAGE_HEBREW = 1255;
	const FKP_SIZE        = 512;

	// FibRgLw97 indexes.
	const LW_CCP_TEXT = 3;
	const LW_CCP_FTN  = 4;
	const LW_CCP_HDD  = 5;
	const LW_CCP_MCR  = 6;
	const LW_CCP_ATN  = 7;
	const LW_CCP_EDN  = 8;

	// FibRgFcLcbBlob indexes of 32-bit values (pair #n => fc at 2n, lcb at 2n+1).
	const FC_PLCF_BTE_PAPX = 26;
	const FC_CLX           = 66;

	// Paragraph sprms used to tell table row ends from cell ends.
	const SPRM_P_F_TTP       = 0x2417;
	const SPRM_P_F_INNER_TTP = 0x244C;
	const SPRM_P_HUGE_PAPX   = 0x6646;
	const SPRM_T_DEF_TABLE   = 0xD608;
	const SPRM_T_DEF_TABLE10 = 0xD606;
	const SPRM_P_CHG_TABS    = 0xC615;

	/**
	 * Bytes of compressed text sampled for the Hebrew/Western decision.
	 */
	const SAMPLE_BYTES = 65536;

	/**
	 * Maximum nesting depth of fields (Word allows far less).
	 */
	const MAX_FIELD_DEPTH = 64;

	/**
	 * Budget (bytes) for scanning paragraph properties in one document.
	 */
	const SPRM_SCAN_BUDGET = 4194304;

	/**
	 * Cached FKP pages kept at most (the cache is reset beyond that).
	 */
	const MAX_CACHED_FKPS = 4096;

	/**
	 * Compound file being read.
	 *
	 * @var HDS_CFB_Reader|null
	 */
	private $cfb = null;

	/**
	 * WordDocument stream.
	 *
	 * @var string
	 */
	private $word = '';

	/**
	 * Table stream (0Table or 1Table).
	 *
	 * @var string
	 */
	private $table = '';

	/**
	 * Data stream (lazy; false when unavailable).
	 *
	 * @var string|false|null
	 */
	private $data = null;

	/**
	 * Parsed FIB values of the current document.
	 *
	 * @var array<string, int>
	 */
	private $fib = array();

	/**
	 * Paragraph property bin table (lazy; false when unavailable):
	 * {fcs: int[], pns: int[]}.
	 *
	 * @var array<string, int[]>|false|null
	 */
	private $bte = null;

	/**
	 * Parsed PAPX FKP pages by page number (null when invalid).
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private $fkps = array();

	/**
	 * Remaining property-scan budget for the current document.
	 *
	 * @var int
	 */
	private $scan_budget = 0;

	/**
	 * Single-byte map for control characters (built once).
	 *
	 * @var array<string, string>|null
	 */
	private static $controls = null;

	/**
	 * Extract the text of a .doc file.
	 *
	 * @param string $path Absolute path.
	 * @return string UTF-8 text, paragraphs separated by "\n".
	 * @throws HDS_Extraction_Exception On any failure.
	 */
	public function extract( string $path ): string {
		return $this->extract_from_reader( HDS_CFB_Reader::from_file( $path ) );
	}

	/**
	 * Extract the text from an already opened compound file.
	 *
	 * @param HDS_CFB_Reader $cfb Compound file.
	 * @return string UTF-8 text, paragraphs separated by "\n".
	 * @throws HDS_Extraction_Exception On any failure.
	 */
	public function extract_from_reader( HDS_CFB_Reader $cfb ): string {
		$this->reset( $cfb );
		try {
			return $this->run();
		} finally {
			$this->reset( null );
		}
	}

	/**
	 * Clear per-document state.
	 *
	 * @param HDS_CFB_Reader|null $cfb Compound file or null.
	 * @return void
	 */
	private function reset( $cfb ) {
		$this->cfb   = $cfb;
		$this->word  = '';
		$this->table = '';
		$this->data  = null;
		$this->fib   = array();
		$this->bte   = null;
		$this->fkps  = array();

		$this->scan_budget = self::SPRM_SCAN_BUDGET;
	}

	/**
	 * Extraction pipeline.
	 *
	 * @return string
	 * @throws HDS_Extraction_Exception On any failure.
	 */
	private function run(): string {
		$cfb = $this->cfb;
		if ( $cfb->has_stream( 'EncryptedPackage' ) || $cfb->has_stream( 'EncryptionInfo' ) ) {
			throw $this->encrypted();
		}
		if ( ! $cfb->has_stream( 'WordDocument' ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNSUPPORTED, 'This OLE file is not a Word document (it may be an Excel or PowerPoint file with a Word extension).' );
		}

		$this->word = $cfb->get_stream( 'WordDocument' );
		if ( strlen( $this->word ) < 32 ) {
			throw $this->corrupt( 'The Word document header (FIB) is truncated.' );
		}
		$ident = self::u16( $this->word, 0 );
		$nfib  = self::u16( $this->word, 2 );
		$lid   = self::u16( $this->word, 6 );
		$flags = self::u16( $this->word, 10 );

		if ( self::W_IDENT !== $ident ) {
			throw $this->corrupt( 'The WordDocument stream has an invalid header (not a Word 97-2003 document).' );
		}
		if ( $flags & self::F_ENCRYPTED ) {
			throw $this->encrypted();
		}
		if ( $this->is_legacy( $nfib ) ) {
			return $this->extract_legacy( $lid );
		}

		$this->fib = $this->parse_fib( $lid );

		$table_name = ( $flags & self::F_WHICH_TBL_STM ) ? '1Table' : '0Table';
		if ( ! $cfb->has_stream( $table_name ) ) {
			throw $this->corrupt( sprintf( 'The Word table stream (%s) is missing.', $table_name ) );
		}
		$this->table = $cfb->get_stream( $table_name );

		$pieces = $this->parse_piece_table( $this->fib['fc_clx'], $this->fib['lcb_clx'] );
		$last   = $pieces[ count( $pieces ) - 1 ]['cp_end'];

		$text_end = $this->fib['ccp_text'];
		$ftn_end  = $text_end + $this->fib['ccp_ftn'];
		$edn_from = $ftn_end + $this->fib['ccp_hdd'] + $this->fib['ccp_mcr'] + $this->fib['ccp_atn'];
		$ranges   = array(
			array( 0, $text_end ),                              // Main document.
			array( $text_end, $ftn_end ),                       // Footnotes.
			array( $edn_from, $edn_from + $this->fib['ccp_edn'] ), // Endnotes.
		);

		$stories = array();
		$sample  = '';
		$budget  = 2 * strlen( $this->word ) + 4096;
		foreach ( $ranges as $range ) {
			$segments = $this->collect_segments( $pieces, min( $range[0], $last ), min( $range[1], $last ), $budget );
			foreach ( $segments as $segment ) {
				if ( $segment['compressed'] && strlen( $sample ) < self::SAMPLE_BYTES ) {
					$sample .= substr( $segment['bytes'], 0, self::SAMPLE_BYTES - strlen( $sample ) );
				}
			}
			$stories[] = $segments;
		}

		// MS-DOC: compressed text is Windows-1252. Hebrew documents written by
		// some older tools store Windows-1255 bytes there instead.
		$hebrew = '' !== $sample && $this->is_hebrew_document( $lid, $this->fib['lid_fe'] ) && HDS_Encoding::looks_like_cp1255( $sample );

		$texts = array();
		foreach ( $stories as $segments ) {
			$text = $this->clean_story( $this->decode_segments( $segments, $hebrew ) );
			if ( '' !== trim( $text ) ) {
				$texts[] = $text;
			}
		}
		return implode( "\n", $texts );
	}

	/**
	 * Is this a pre-Word 97 (Word 6/95) FIB?
	 *
	 * @param int $nfib FibBase.nFib.
	 * @return bool
	 */
	private function is_legacy( int $nfib ): bool {
		if ( $nfib >= self::NFIB_WORD97 ) {
			return false;
		}
		// Some writers put an old nFib on a Word 97 structure: trust the structure.
		$csw  = self::u16( $this->word, 32 );
		$cslw = 14 === $csw ? self::u16( $this->word, 62 ) : -1;
		return ! ( 14 === $csw && 22 === $cslw );
	}

	/**
	 * Parse the Word 97+ FIB (FibBase, FibRgW97, FibRgLw97, FibRgFcLcbBlob).
	 *
	 * @param int $lid FibBase.lid.
	 * @return array<string, int>
	 * @throws HDS_Extraction_Exception CORRUPT.
	 */
	private function parse_fib( int $lid ): array {
		$word = $this->word;
		$len  = strlen( $word );

		$csw   = self::u16( $word, 32 );
		$rg_w  = 34;
		$pos   = $rg_w + 2 * max( $csw, 0 );
		$cslw  = self::u16( $word, $pos );
		$rg_lw = $pos + 2;
		if ( $csw < 0 || $cslw < 11 ) {
			throw $this->corrupt( 'The Word document header (FIB) is damaged.' );
		}
		$pos = $rg_lw + 4 * $cslw;
		$cb  = self::u16( $word, $pos );
		$rg_fc_lcb = $pos + 2;
		if ( $cb < 34 || $rg_fc_lcb + 8 * $cb > $len ) {
			throw $this->corrupt( 'The Word document header (FIB) is truncated or has no piece table.' );
		}

		$fib = array(
			'lid'      => $lid,
			'lid_fe'   => $csw >= 14 ? self::u16( $word, $rg_w + 26 ) : 0,
			'fc_clx'   => self::u32( $word, $rg_fc_lcb + 4 * self::FC_CLX ),
			'lcb_clx'  => self::u32( $word, $rg_fc_lcb + 4 * ( self::FC_CLX + 1 ) ),
			'fc_papx'  => self::u32( $word, $rg_fc_lcb + 4 * self::FC_PLCF_BTE_PAPX ),
			'lcb_papx' => self::u32( $word, $rg_fc_lcb + 4 * ( self::FC_PLCF_BTE_PAPX + 1 ) ),
		);
		$ccps = array(
			'ccp_text' => self::LW_CCP_TEXT,
			'ccp_ftn'  => self::LW_CCP_FTN,
			'ccp_hdd'  => self::LW_CCP_HDD,
			'ccp_mcr'  => self::LW_CCP_MCR,
			'ccp_atn'  => self::LW_CCP_ATN,
			'ccp_edn'  => self::LW_CCP_EDN,
		);
		foreach ( $ccps as $key => $index ) {
			$value = self::u32( $word, $rg_lw + 4 * $index );
			if ( $value < 0 || $value > 0x7FFFFFFF ) {
				throw $this->corrupt( 'The Word document header (FIB) has invalid character counts.' );
			}
			$fib[ $key ] = $value;
		}
		return $fib;
	}

	/**
	 * Parse the CLX: skip the Prc entries and read the PlcPcd.
	 *
	 * @param int $fc  Offset in the table stream.
	 * @param int $lcb Size in bytes.
	 * @return array<int, array<string, mixed>> Pieces {cp_start, cp_end, fc, compressed}.
	 * @throws HDS_Extraction_Exception CORRUPT.
	 */
	private function parse_piece_table( int $fc, int $lcb ): array {
		$table_len = strlen( $this->table );
		if ( $lcb <= 0 ) {
			throw $this->corrupt( 'The Word document has no piece table (the text cannot be located).' );
		}
		if ( $fc < 0 || $fc > $table_len || $lcb > $table_len - $fc ) {
			throw $this->corrupt( 'The Word piece table lies outside the table stream (truncated or damaged file).' );
		}
		$clx = substr( $this->table, $fc, $lcb );

		$pos = 0;
		while ( $pos < $lcb && 0x01 === ord( $clx[ $pos ] ) ) {
			$cb_grpprl = self::u16( $clx, $pos + 1 );
			if ( $cb_grpprl < 0 ) {
				throw $this->corrupt( 'The Word piece table is damaged.' );
			}
			$pos += 3 + $cb_grpprl;
		}
		if ( $pos + 5 > $lcb || 0x02 !== ord( $clx[ $pos ] ) ) {
			throw $this->corrupt( 'The Word piece table is damaged (no text pieces found).' );
		}
		$plc_len = self::u32( $clx, $pos + 1 );
		$start   = $pos + 5;
		if ( $plc_len < 16 || $plc_len > $lcb - $start || 0 !== ( $plc_len - 4 ) % 12 ) {
			throw $this->corrupt( 'The Word piece table is damaged (invalid size).' );
		}

		$n      = intdiv( $plc_len - 4, 12 );
		$cps    = array_values( unpack( 'V' . ( $n + 1 ), substr( $clx, $start, 4 * ( $n + 1 ) ) ) );
		$pcds   = $start + 4 * ( $n + 1 );
		$pieces = array();
		for ( $i = 0; $i < $n; $i++ ) {
			if ( $cps[ $i ] > $cps[ $i + 1 ] || $cps[ $i + 1 ] > 0x7FFFFFFF ) {
				throw $this->corrupt( 'The Word piece table is damaged (character positions out of order).' );
			}
			$raw        = self::u32( $clx, $pcds + 8 * $i + 2 );
			$compressed = 0 !== ( $raw & self::FC_COMPRESSED );
			$pieces[]   = array(
				'cp_start'   => (int) $cps[ $i ],
				'cp_end'     => (int) $cps[ $i + 1 ],
				'fc'         => $compressed ? ( $raw & self::FC_MASK ) >> 1 : ( $raw & self::FC_MASK ),
				'compressed' => $compressed,
			);
		}
		return $pieces;
	}

	/**
	 * Raw bytes of the pieces covering a CP range, in CP order.
	 *
	 * Pieces of a valid document never overlap, so all text together cannot
	 * be larger than the WordDocument stream; $budget enforces that (with
	 * slack) before anything is copied, which bounds memory on crafted files.
	 *
	 * @param array<int, array<string, mixed>> $pieces  Pieces.
	 * @param int                              $cp_from First CP.
	 * @param int                              $cp_to   CP after the last one.
	 * @param int                              $budget  Remaining bytes allowed (by reference).
	 * @return array<int, array<string, mixed>> Segments {fc, compressed, bytes}.
	 * @throws HDS_Extraction_Exception CORRUPT when a piece points outside the stream or pieces overlap.
	 */
	private function collect_segments( array $pieces, int $cp_from, int $cp_to, int &$budget ): array {
		$segments = array();
		if ( $cp_to <= $cp_from ) {
			return $segments;
		}
		$word_len = strlen( $this->word );
		foreach ( $pieces as $piece ) {
			$from = max( $cp_from, $piece['cp_start'] );
			$to   = min( $cp_to, $piece['cp_end'] );
			if ( $to <= $from ) {
				continue;
			}
			$width  = $piece['compressed'] ? 1 : 2;
			$offset = $piece['fc'] + ( $from - $piece['cp_start'] ) * $width;
			$length = ( $to - $from ) * $width;
			if ( $offset > $word_len || $length > $word_len - $offset ) {
				throw $this->corrupt( 'A text piece points outside the WordDocument stream (truncated or damaged file).' );
			}
			$budget -= $length;
			if ( $budget < 0 ) {
				throw $this->corrupt( 'The Word piece table is inconsistent (text pieces overlap).' );
			}
			$segments[] = array(
				'fc'         => $offset,
				'compressed' => $piece['compressed'],
				'bytes'      => substr( $this->word, $offset, $length ),
			);
		}
		return $segments;
	}

	/**
	 * Decode segments to UTF-8, turning table marks into tabs/line breaks.
	 *
	 * @param array<int, array<string, mixed>> $segments Segments.
	 * @param bool                             $hebrew   Decode compressed text as Windows-1255.
	 * @return string
	 */
	private function decode_segments( array $segments, bool $hebrew ): string {
		$parts = array();
		foreach ( $segments as $segment ) {
			$bytes = $this->mark_table_ends( $segment['bytes'], $segment['fc'], $segment['compressed'] );
			if ( ! $segment['compressed'] ) {
				$parts[] = HDS_Encoding::utf16le_to_utf8( $bytes );
			} elseif ( $hebrew ) {
				$parts[] = HDS_Encoding::cp1255_to_utf8( $bytes );
			} else {
				$parts[] = HDS_Encoding::cp1252_to_utf8( $bytes );
			}
		}
		return implode( '', $parts );
	}

	/**
	 * Replace 0x07 marks: row ends become "\n", cell ends "\t".
	 *
	 * @param string $bytes      Raw piece bytes.
	 * @param int    $fc         Stream offset of the first byte.
	 * @param bool   $compressed 8-bit (true) or UTF-16LE (false).
	 * @return string
	 */
	private function mark_table_ends( string $bytes, int $fc, bool $compressed ): string {
		$needle = $compressed ? "\x07" : "\x07\x00";
		$pos    = strpos( $bytes, $needle );
		while ( false !== $pos ) {
			if ( ! $compressed && 0 !== $pos % 2 ) {
				$pos = strpos( $bytes, $needle, $pos + 1 );
				continue;
			}
			$bytes[ $pos ] = $this->is_row_end( $fc + $pos ) ? "\n" : "\t";
			$pos           = strpos( $bytes, $needle, $pos + strlen( $needle ) );
		}
		return $bytes;
	}

	/**
	 * Is the 0x07 at this stream offset a table row end (TTP paragraph)?
	 *
	 * @param int $fc Stream offset of the character.
	 * @return bool
	 */
	private function is_row_end( int $fc ): bool {
		if ( null === $this->bte ) {
			$this->bte = $this->load_bte();
		}
		if ( false === $this->bte ) {
			return false;
		}
		$i = self::find_run( $this->bte['fcs'], $fc );
		if ( $i < 0 ) {
			return false;
		}
		$pn  = $this->bte['pns'][ $i ];
		$fkp = $this->fkp( $pn );
		if ( null === $fkp ) {
			return false;
		}
		$j = self::find_run( $fkp['fcs'], $fc );
		if ( $j < 0 ) {
			return false;
		}
		if ( ! isset( $fkp['ttp'][ $j ] ) ) {
			$grpprl                        = $this->papx_grpprl( $fkp['page'], $fkp['offsets'][ $j ] );
			$this->fkps[ $pn ]['ttp'][ $j ] = '' !== $grpprl && $this->grpprl_is_ttp( $grpprl, true );
		}
		return $this->fkps[ $pn ]['ttp'][ $j ];
	}

	/**
	 * Load the PlcBtePapx (paragraph FKP page numbers by stream offset).
	 *
	 * @return array<string, int[]>|false
	 */
	private function load_bte() {
		$fc  = $this->fib['fc_papx'];
		$lcb = $this->fib['lcb_papx'];
		if ( $lcb < 12 || 0 !== ( $lcb - 4 ) % 8 || $fc < 0 || $fc > strlen( $this->table ) - $lcb ) {
			return false;
		}
		$n    = intdiv( $lcb - 4, 8 );
		$fcs  = array_values( unpack( 'V' . ( $n + 1 ), substr( $this->table, $fc, 4 * ( $n + 1 ) ) ) );
		$pns  = array_values( unpack( 'V' . $n, substr( $this->table, $fc + 4 * ( $n + 1 ), 4 * $n ) ) );
		$mask = 0x3FFFFF;
		foreach ( $pns as $k => $pn ) {
			$pns[ $k ] = $pn & $mask;
		}
		return array(
			'fcs' => $fcs,
			'pns' => $pns,
		);
	}

	/**
	 * Parse a PAPX FKP page.
	 *
	 * @param int $pn Page number in the WordDocument stream.
	 * @return array<string, mixed>|null {page, fcs, offsets}
	 */
	private function fkp( int $pn ) {
		if ( array_key_exists( $pn, $this->fkps ) ) {
			return $this->fkps[ $pn ];
		}
		if ( count( $this->fkps ) >= self::MAX_CACHED_FKPS ) {
			$this->fkps = array();
		}
		$this->fkps[ $pn ] = null;
		$offset            = $pn * self::FKP_SIZE;
		if ( $offset + self::FKP_SIZE > strlen( $this->word ) ) {
			return null;
		}
		$page = substr( $this->word, $offset, self::FKP_SIZE );
		$crun = ord( $page[ self::FKP_SIZE - 1 ] );
		$bx   = 4 * ( $crun + 1 );
		if ( 0 === $crun || $bx + 13 * $crun > self::FKP_SIZE - 1 ) {
			return null;
		}
		$offsets = array();
		for ( $j = 0; $j < $crun; $j++ ) {
			$offsets[] = ord( $page[ $bx + 13 * $j ] );
		}
		$this->fkps[ $pn ] = array(
			'page'    => $page,
			'fcs'     => array_values( unpack( 'V' . ( $crun + 1 ), $page ) ),
			'offsets' => $offsets,
			'ttp'     => array(),
		);
		return $this->fkps[ $pn ];
	}

	/**
	 * The grpprl of a PapxInFkp (without the istd).
	 *
	 * @param string $page     FKP page.
	 * @param int    $b_offset Word offset of the PapxInFkp (0 = no properties).
	 * @return string
	 */
	private function papx_grpprl( string $page, int $b_offset ): string {
		$o   = 2 * $b_offset;
		$end = self::FKP_SIZE - 1;
		if ( 0 === $b_offset || $o + 1 >= $end ) {
			return '';
		}
		$cb = ord( $page[ $o ] );
		if ( 0 === $cb ) {
			$length = 2 * ord( $page[ $o + 1 ] );
			$start  = $o + 2;
		} else {
			$length = 2 * $cb - 1;
			$start  = $o + 1;
		}
		if ( $length <= 2 || $start + $length > $end ) {
			return '';
		}
		return substr( $page, $start + 2, $length - 2 );
	}

	/**
	 * Does a grpprl mark a table row end (sprmPFTtp / sprmPFInnerTtp)?
	 *
	 * @param string $grpprl     Property modifiers.
	 * @param bool   $allow_huge Follow sprmPHugePapx into the Data stream.
	 * @return bool
	 */
	private function grpprl_is_ttp( string $grpprl, bool $allow_huge ): bool {
		$len                = strlen( $grpprl );
		$pos                = 0;
		$this->scan_budget -= $len;
		if ( $this->scan_budget < 0 ) {
			return false; // Crafted file: stop analysing table layout, cells stay tabs.
		}
		while ( $pos + 2 <= $len ) {
			$sprm = ord( $grpprl[ $pos ] ) | ( ord( $grpprl[ $pos + 1 ] ) << 8 );
			$op   = $pos + 2;
			$size = $this->operand_size( $sprm, $grpprl, $op );
			if ( $size < 1 || $op + $size > $len ) {
				return false;
			}
			if ( ( self::SPRM_P_F_TTP === $sprm || self::SPRM_P_F_INNER_TTP === $sprm ) && 0 !== ord( $grpprl[ $op ] ) ) {
				return true;
			}
			if ( self::SPRM_P_HUGE_PAPX === $sprm && $allow_huge ) {
				$huge = $this->huge_grpprl( self::u32( $grpprl, $op ) );
				if ( '' !== $huge && $this->grpprl_is_ttp( $huge, false ) ) {
					return true;
				}
			}
			$pos = $op + $size;
		}
		return false;
	}

	/**
	 * Operand size of a Prl ([MS-DOC] 2.2.5.1, Sprm.spra).
	 *
	 * @param int    $sprm   Sprm.
	 * @param string $grpprl Property modifiers.
	 * @param int    $op     Offset of the operand.
	 * @return int Size in bytes, or -1 when it cannot be determined.
	 */
	private function operand_size( int $sprm, string $grpprl, int $op ): int {
		$len = strlen( $grpprl );
		switch ( $sprm >> 13 ) {
			case 0:
			case 1:
				return 1;
			case 2:
			case 4:
			case 5:
				return 2;
			case 3:
				return 4;
			case 7:
				return 3;
		}
		if ( $op >= $len ) {
			return -1;
		}
		if ( self::SPRM_T_DEF_TABLE === $sprm || self::SPRM_T_DEF_TABLE10 === $sprm ) {
			$cb = self::u16( $grpprl, $op );
			return $cb < 1 ? -1 : $cb + 1;
		}
		$cb = ord( $grpprl[ $op ] );
		if ( self::SPRM_P_CHG_TABS === $sprm && 255 === $cb ) {
			$p = $op + 1;
			if ( $p >= $len ) {
				return -1;
			}
			$p += 1 + 4 * ord( $grpprl[ $p ] );
			if ( $p >= $len ) {
				return -1;
			}
			$p += 1 + 3 * ord( $grpprl[ $p ] );
			return $p - $op;
		}
		return 1 + $cb;
	}

	/**
	 * Read a grpprl stored in the Data stream (sprmPHugePapx).
	 *
	 * @param int $offset Offset in the Data stream.
	 * @return string
	 */
	private function huge_grpprl( int $offset ): string {
		if ( null === $this->data ) {
			$this->data = false;
			if ( $this->cfb->has_stream( 'Data' ) ) {
				try {
					$this->data = $this->cfb->get_stream( 'Data' );
				} catch ( HDS_Extraction_Exception $e ) {
					$this->data = false; // Formatting detail only; the text is still usable.
				}
			}
		}
		if ( false === $this->data ) {
			return '';
		}
		$cb = self::u16( $this->data, $offset );
		if ( $cb <= 0 || $offset + 2 + $cb > strlen( $this->data ) ) {
			return '';
		}
		return substr( $this->data, $offset + 2, $cb );
	}

	/**
	 * Remove field instructions and map Word's special characters.
	 *
	 * Fields are 0x13 instruction [0x14 result] 0x15 and can nest. Only the
	 * result is kept; a field without a separator is dropped entirely, and
	 * unmatched markers are ignored.
	 *
	 * @param string $text Decoded story text.
	 * @return string
	 */
	private function clean_story( string $text ): string {
		return strtr( self::strip_fields( $text ), self::control_map() );
	}

	/**
	 * Drop field instructions, keep field results.
	 *
	 * A second pass runs only when a field begin has no matching end: such
	 * markers are then ignored so that they cannot hide the rest of the text.
	 *
	 * @param string $text UTF-8 text with 0x13/0x14/0x15 markers.
	 * @return string
	 */
	private static function strip_fields( string $text ): string {
		if ( false === strpbrk( $text, "\x13\x14\x15" ) ) {
			return $text;
		}
		$unmatched = array();
		$result    = self::strip_fields_pass( $text, array(), $unmatched );
		if ( $unmatched ) {
			$ignored = array_fill_keys( $unmatched, true );
			$result  = self::strip_fields_pass( $text, $ignored, $unmatched );
		}
		return $result;
	}

	/**
	 * One pass of the field state machine.
	 *
	 * @param string           $text      Text.
	 * @param array<int, bool> $ignored   Offsets of field begin markers to ignore.
	 * @param int[]            $unmatched Out: offsets of begin markers never closed.
	 * @return string
	 */
	private static function strip_fields_pass( string $text, array $ignored, array &$unmatched ): string {
		$len    = strlen( $text );
		$out    = '';
		$start  = 0;     // Start of the pending visible run.
		$stack  = array(); // Open fields: array( begin offset, in result part ).
		$hidden = 0;     // Open fields still in their instruction part.
		$at     = strcspn( $text, "\x13\x14\x15" );
		while ( $at < $len ) {
			if ( 0 === $hidden && $at > $start ) {
				$out .= substr( $text, $start, $at - $start );
			}
			$start = $at + 1;
			$mark  = $text[ $at ];
			if ( "\x13" === $mark ) {
				if ( ! isset( $ignored[ $at ] ) && count( $stack ) < self::MAX_FIELD_DEPTH ) {
					$stack[] = array( $at, false );
					$hidden++;
				}
			} elseif ( "\x14" === $mark ) {
				$top = count( $stack ) - 1;
				if ( $top >= 0 && ! $stack[ $top ][1] ) {
					$stack[ $top ][1] = true;
					$hidden--;
				}
			} elseif ( $stack ) {
				$field = array_pop( $stack );
				if ( ! $field[1] ) {
					$hidden--;
				}
			}
			$at = $start + strcspn( $text, "\x13\x14\x15", $start );
		}
		if ( 0 === $hidden && $start < $len ) {
			$out .= substr( $text, $start );
		}
		$unmatched = array();
		foreach ( $stack as $field ) {
			$unmatched[] = $field[0];
		}
		return $out;
	}

	/**
	 * Map for Word's special characters (all C0 controls).
	 *
	 * @return array<string, string>
	 */
	private static function control_map(): array {
		if ( null === self::$controls ) {
			$map = array();
			for ( $c = 0; $c < 0x20; $c++ ) {
				$map[ chr( $c ) ] = '';
			}
			$map["\t"]   = "\t";
			$map["\n"]   = "\n";
			$map["\r"]   = "\n"; // Paragraph end.
			$map["\x0B"] = "\n"; // Line break (Shift+Enter).
			$map["\x0C"] = "\n"; // Page or section break.
			$map["\x0E"] = "\n"; // Column break.
			$map["\x07"] = "\t"; // Cell mark not resolved by mark_table_ends().
			$map["\x1E"] = '-';  // Non-breaking hyphen.
			$map["\x1F"] = '';   // Optional hyphen.
			// 0x01 picture, 0x02 footnote mark, 0x03/0x04 separators, 0x05 comment
			// mark, 0x08 drawing anchor, 0x13-0x15 stray field markers: dropped.
			self::$controls = $map;
		}
		return self::$controls;
	}

	/**
	 * Word 6/95 fallback: 8-bit text between FIB.fcMin and FIB.fcMac.
	 *
	 * @param int $lid FibBase.lid.
	 * @return string
	 * @throws HDS_Extraction_Exception CORRUPT.
	 */
	private function extract_legacy( int $lid ): string {
		$len    = strlen( $this->word );
		$fc_min = self::u32( $this->word, 0x18 );
		$fc_mac = self::u32( $this->word, 0x1C );
		if ( $fc_min < 32 || $fc_mac < $fc_min || $fc_mac > $len ) {
			throw $this->corrupt( 'The Word 6/95 document header is damaged (text range).' );
		}
		$bytes  = (string) substr( $this->word, $fc_min, $fc_mac - $fc_min );
		$hebrew = $this->is_hebrew_document( $lid, 0 ) && HDS_Encoding::looks_like_cp1255( $bytes );
		$bytes  = str_replace( "\x07", "\t", $bytes );
		$text   = $hebrew ? HDS_Encoding::cp1255_to_utf8( $bytes ) : HDS_Encoding::cp1252_to_utf8( $bytes );
		return $this->clean_story( $text );
	}

	/**
	 * Is the document Hebrew (FIB language ids or SummaryInformation codepage)?
	 *
	 * @param int $lid    FibBase.lid.
	 * @param int $lid_fe FibRgW97.lidFE.
	 * @return bool
	 */
	private function is_hebrew_document( int $lid, int $lid_fe ): bool {
		if ( self::LANG_HEBREW === ( $lid & 0x3FF ) || self::LANG_HEBREW === ( $lid_fe & 0x3FF ) ) {
			return true;
		}
		return self::CODEPAGE_HEBREW === $this->summary_codepage();
	}

	/**
	 * Codepage property (PID 1) of the SummaryInformation property set.
	 *
	 * @return int Codepage, 0 when unknown.
	 */
	private function summary_codepage(): int {
		$name = "\x05SummaryInformation";
		if ( ! $this->cfb->has_stream( $name ) ) {
			return 0;
		}
		try {
			$stream = $this->cfb->get_stream( $name );
		} catch ( HDS_Extraction_Exception $e ) {
			return 0;
		}
		// PropertySetStream: byte order, version, system id, CLSID, count, then FMTID + offset.
		if ( 0xFFFE !== self::u16( $stream, 0 ) || self::u32( $stream, 24 ) < 1 ) {
			return 0;
		}
		$set   = self::u32( $stream, 44 );
		$count = self::u32( $stream, $set + 4 );
		if ( $set < 48 || $count < 1 ) {
			return 0;
		}
		$count = min( $count, 1024 );
		for ( $i = 0; $i < $count; $i++ ) {
			$pid = self::u32( $stream, $set + 8 + 8 * $i );
			if ( $pid < 0 ) {
				break;
			}
			if ( 1 === $pid ) {
				$offset = $set + self::u32( $stream, $set + 12 + 8 * $i );
				// TypedPropertyValue: VT_I2 (0x0002), padding, then the 16-bit value.
				return 2 === self::u16( $stream, $offset ) ? max( 0, self::u16( $stream, $offset + 4 ) ) : 0;
			}
		}
		return 0;
	}

	/**
	 * Index of the run [fcs[i], fcs[i+1]) containing $fc (binary search).
	 *
	 * @param int[] $fcs Ascending boundaries.
	 * @param int   $fc  Offset.
	 * @return int Run index or -1.
	 */
	private static function find_run( array $fcs, int $fc ): int {
		$lo = 0;
		$hi = count( $fcs ) - 2;
		while ( $lo <= $hi ) {
			$mid = ( $lo + $hi ) >> 1;
			if ( $fc < $fcs[ $mid ] ) {
				$hi = $mid - 1;
			} elseif ( $fc >= $fcs[ $mid + 1 ] ) {
				$lo = $mid + 1;
			} else {
				return $mid;
			}
		}
		return -1;
	}

	/**
	 * Little-endian unsigned 16-bit value.
	 *
	 * @param string $s   Bytes.
	 * @param int    $off Offset.
	 * @return int Value, or -1 when out of bounds.
	 */
	private static function u16( string $s, int $off ): int {
		if ( $off < 0 || $off + 2 > strlen( $s ) ) {
			return -1;
		}
		return ord( $s[ $off ] ) | ( ord( $s[ $off + 1 ] ) << 8 );
	}

	/**
	 * Little-endian unsigned 32-bit value.
	 *
	 * @param string $s   Bytes.
	 * @param int    $off Offset.
	 * @return int Value, or -1 when out of bounds.
	 */
	private static function u32( string $s, int $off ): int {
		if ( $off < 0 || $off + 4 > strlen( $s ) ) {
			return -1;
		}
		$v = unpack( 'V', substr( $s, $off, 4 ) );
		return (int) $v[1];
	}

	/**
	 * Build a CORRUPT exception.
	 *
	 * @param string $message English message (no document content).
	 * @return HDS_Extraction_Exception
	 */
	private function corrupt( string $message ): HDS_Extraction_Exception {
		return new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, $message );
	}

	/**
	 * Build an ENCRYPTED exception.
	 *
	 * @return HDS_Extraction_Exception
	 */
	private function encrypted(): HDS_Extraction_Exception {
		return new HDS_Extraction_Exception( HDS_Extraction_Exception::ENCRYPTED, 'The document is password-protected. Remove the password in Word, save it and upload it again.' );
	}
}
