<?php
/**
 * Reader for OLE2 Compound File Binary containers ([MS-CFB]), the storage
 * format of Word 97-2003 .doc files (and of encrypted OOXML packages).
 *
 * Pure PHP, no extensions beyond the core. Designed for hostile input:
 * every sector id is bounds-checked, every chain is loop-checked and
 * bounded by the number of sectors in the file, and no allocation is ever
 * driven by a size field read from the file without capping it first.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compound File Binary reader.
 *
 * Streams are addressed by entry name (case-insensitive); when several
 * entries share a name (an embedded document inside ObjectPool, for
 * example) the shallowest one wins, so "WordDocument" is always the one
 * directly under the root storage.
 */
final class HDS_CFB_Reader {

	const SIGNATURE          = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
	const HEADER_SIZE        = 512;
	const MAXREGSECT         = 0xFFFFFFFA;
	const DIFSECT            = 0xFFFFFFFC;
	const FATSECT            = 0xFFFFFFFD;
	const ENDOFCHAIN         = 0xFFFFFFFE;
	const FREESECT           = 0xFFFFFFFF;
	const NOSTREAM           = 0xFFFFFFFF;
	const TYPE_EMPTY         = 0;
	const TYPE_STORAGE       = 1;
	const TYPE_STREAM        = 2;
	const TYPE_ROOT          = 5;
	const ENTRY_SIZE         = 128;
	const MINI_SECTOR_SHIFT  = 6;
	const MINI_SECTOR_SIZE   = 64;
	const MINI_STREAM_CUTOFF = 4096;

	/**
	 * Upper bound for directory entries (a Word file has a few dozen).
	 */
	const MAX_ENTRIES = 65536;

	/**
	 * Whole file contents (string mode) or '' (file mode).
	 *
	 * @var string
	 */
	private $data = '';

	/**
	 * Open file handle (file mode) or null (string mode).
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Size of the file in bytes.
	 *
	 * @var int
	 */
	private $file_size = 0;

	/**
	 * Major format version (3 or 4).
	 *
	 * @var int
	 */
	private $version = 3;

	/**
	 * Sector shift (9 => 512-byte sectors, 12 => 4096-byte sectors).
	 *
	 * @var int
	 */
	private $sector_shift = 9;

	/**
	 * Sector size in bytes.
	 *
	 * @var int
	 */
	private $sector_size = 512;

	/**
	 * Number of (possibly partial) sectors after the header.
	 *
	 * @var int
	 */
	private $sector_count = 0;

	/**
	 * File allocation table: sector id => next sector id.
	 *
	 * @var int[]
	 */
	private $fat = array();

	/**
	 * First sector of the directory chain.
	 *
	 * @var int
	 */
	private $dir_start = self::ENDOFCHAIN;

	/**
	 * Stream entry ids, shallowest first (cache).
	 *
	 * @var int[]|null
	 */
	private $stream_ids = null;

	/**
	 * First sector of the mini FAT chain.
	 *
	 * @var int
	 */
	private $mini_fat_start = self::ENDOFCHAIN;

	/**
	 * Mini FAT (lazy): mini sector id => next mini sector id.
	 *
	 * @var int[]|null
	 */
	private $mini_fat = null;

	/**
	 * Mini stream container (lazy): the root entry's stream.
	 *
	 * @var string|null
	 */
	private $mini_stream = null;

	/**
	 * Directory entries by id; null for unused/invalid slots. Each entry:
	 * {name, type, left, right, child, start, size, depth}.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private $entries = array();

	/**
	 * Parse a compound file held in memory.
	 *
	 * @param string        $data   File contents. Ignored when $handle is given.
	 * @param resource|null $handle Internal (used by from_file()): an open, seekable
	 *                              read handle; the reader then loads only the
	 *                              sectors it needs and closes the handle when destroyed.
	 * @throws HDS_Extraction_Exception CORRUPT on any structural problem.
	 */
	public function __construct( string $data, $handle = null ) {
		if ( is_resource( $handle ) ) {
			$stat            = fstat( $handle );
			$this->handle    = $handle;
			$this->file_size = is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : 0;
		} else {
			$this->data      = $data;
			$this->file_size = strlen( $data );
		}
		$this->parse_header_and_fat();
		$this->parse_directory();
	}

	/**
	 * Close the file handle in file mode.
	 */
	public function __destruct() {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local file opened by from_file().
		}
		$this->handle = null;
	}

	/**
	 * Open a compound file from disk. Only the sectors that are needed are
	 * read, so large embedded images never have to fit in memory.
	 *
	 * @param string $path File path.
	 * @return self
	 * @throws HDS_Extraction_Exception UNREADABLE when the file cannot be opened, CORRUPT when it is damaged.
	 */
	public static function from_file( string $path ): self {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNREADABLE, 'The file is missing or not readable.' );
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local file, binary read.
		if ( false === $handle ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNREADABLE, 'The file could not be opened.' );
		}
		try {
			return new self( '', $handle );
		} catch ( \Throwable $e ) {
			// The destructor does not run when the constructor throws.
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local file.
			throw $e;
		}
	}

	/**
	 * Is there a stream with this name (case-insensitive, at any depth)?
	 *
	 * @param string $name Entry name, e.g. "WordDocument" or "\x05SummaryInformation".
	 * @return bool
	 */
	public function has_stream( string $name ): bool {
		return null !== $this->find_stream( $name );
	}

	/**
	 * Read a stream by name (case-insensitive, at any depth; the shallowest
	 * match wins).
	 *
	 * @param string $name Entry name.
	 * @return string Stream contents.
	 * @throws HDS_Extraction_Exception CORRUPT when the stream is missing or damaged.
	 */
	public function get_stream( string $name ): string {
		$id = $this->find_stream( $name );
		if ( null === $id ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, sprintf( 'The "%s" stream is missing from the compound file.', $this->printable( $name ) ) );
		}
		return $this->read_entry( $id );
	}

	/**
	 * Names of all streams, shallowest first.
	 *
	 * @return string[]
	 */
	public function stream_names(): array {
		$names = array();
		foreach ( $this->sorted_stream_ids() as $id ) {
			$names[] = $this->entries[ $id ]['name'];
		}
		return $names;
	}

	/**
	 * Major version of the compound file format (3 or 4).
	 *
	 * @return int
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Sector size in bytes (512 or 4096).
	 *
	 * @return int
	 */
	public function sector_size(): int {
		return $this->sector_size;
	}

	/**
	 * Parse the header, the DIFAT and the FAT.
	 *
	 * @return void
	 * @throws HDS_Extraction_Exception CORRUPT.
	 */
	private function parse_header_and_fat() {
		$header = $this->read( 0, self::HEADER_SIZE );
		if ( strlen( $header ) < self::HEADER_SIZE ) {
			throw $this->corrupt( 'The file is too small to be an OLE2 compound document.' );
		}
		if ( 0 !== strncmp( $header, self::SIGNATURE, 8 ) ) {
			throw $this->corrupt( 'Not an OLE2 compound document (bad signature).' );
		}

		$h = unpack( 'vminor/vmajor/vbyte_order/vsector_shift/vmini_shift', substr( $header, 24, 10 ) );
		$f = unpack( 'Vdir_sectors/Vfat_sectors/Vdir_start/Vtransaction/Vmini_cutoff/Vmini_fat_start/Vmini_fat_sectors/Vdifat_start/Vdifat_sectors', substr( $header, 40, 36 ) );

		if ( 0xFFFE !== $h['byte_order'] ) {
			throw $this->corrupt( 'Invalid compound file header (byte order mark).' );
		}
		if ( 3 !== $h['major'] && 4 !== $h['major'] ) {
			throw $this->corrupt( 'Unsupported compound file version.' );
		}
		if ( 9 !== $h['sector_shift'] && 12 !== $h['sector_shift'] ) {
			throw $this->corrupt( 'Invalid compound file header (sector size).' );
		}
		if ( self::MINI_SECTOR_SHIFT !== $h['mini_shift'] ) {
			throw $this->corrupt( 'Invalid compound file header (mini sector size).' );
		}
		if ( self::MINI_STREAM_CUTOFF !== $f['mini_cutoff'] ) {
			throw $this->corrupt( 'Invalid compound file header (mini stream cutoff).' );
		}

		$this->version      = (int) $h['major'];
		$this->sector_shift = (int) $h['sector_shift'];
		$this->sector_size  = 1 << $this->sector_shift;

		// Sector #0 starts right after the header sector (512 or 4096 bytes).
		// A short final sector is tolerated (some writers trim the padding).
		$this->sector_count = $this->file_size > $this->sector_size
			? intdiv( $this->file_size - 1, $this->sector_size )
			: 0;
		if ( 0 === $this->sector_count ) {
			throw $this->corrupt( 'The compound file contains no sectors.' );
		}
		if ( $f['fat_sectors'] > $this->sector_count ) {
			throw $this->corrupt( 'Invalid compound file header (FAT sector count).' );
		}

		$this->mini_fat_start = (int) $f['mini_fat_start'];
		$this->dir_start      = (int) $f['dir_start'];

		// DIFAT: 109 FAT sector ids in the header, then a chain of DIFAT sectors.
		// Only the number of FAT sectors the header declares is used, so junk in
		// unused DIFAT slots (left by some writers) is ignored.
		$wanted         = $f['fat_sectors'] > 0 ? (int) $f['fat_sectors'] : $this->sector_count;
		$fat_sector_ids = array();
		$this->collect_difat_ids( array_values( unpack( 'V109', substr( $header, 76, 436 ) ) ), $fat_sector_ids, $wanted );

		$per_sector = ( $this->sector_size >> 2 ) - 1;
		$next       = (int) $f['difat_start'];
		$seen       = array();
		while ( count( $fat_sector_ids ) < $wanted && self::ENDOFCHAIN !== $next && self::FREESECT !== $next ) {
			if ( ! $this->is_valid_sector( $next ) ) {
				throw $this->corrupt( 'DIFAT sector id out of range.' );
			}
			if ( isset( $seen[ $next ] ) ) {
				throw $this->corrupt( 'Loop in the DIFAT sector chain.' );
			}
			$seen[ $next ] = true;
			$ids           = $this->unpack_ids( $this->read_sectors( array( $next ) ) );
			$this->collect_difat_ids( array_slice( $ids, 0, $per_sector ), $fat_sector_ids, $wanted );
			$next = (int) $ids[ $per_sector ];
		}

		if ( empty( $fat_sector_ids ) ) {
			throw $this->corrupt( 'The compound file has no allocation table.' );
		}
		if ( count( $fat_sector_ids ) > $this->sector_count ) {
			throw $this->corrupt( 'The allocation table is larger than the file.' );
		}

		$fat = $this->unpack_ids( $this->read_sectors( $fat_sector_ids ) );
		if ( count( $fat ) > $this->sector_count ) {
			$fat = array_slice( $fat, 0, $this->sector_count );
		}
		$this->fat = $fat;
	}

	/**
	 * Append the valid FAT sector ids from a DIFAT array.
	 *
	 * @param int[] $ids    Raw DIFAT entries.
	 * @param int[] $out    Collected FAT sector ids (by reference).
	 * @param int   $wanted Number of FAT sectors to collect at most.
	 * @return void
	 * @throws HDS_Extraction_Exception CORRUPT on an out-of-range id.
	 */
	private function collect_difat_ids( array $ids, array &$out, int $wanted ) {
		foreach ( $ids as $id ) {
			if ( count( $out ) >= $wanted ) {
				return;
			}
			$id = (int) $id;
			if ( $id > self::MAXREGSECT ) {
				continue; // FREESECT / ENDOFCHAIN padding.
			}
			if ( ! $this->is_valid_sector( $id ) ) {
				throw $this->corrupt( 'FAT sector id out of range (the file is truncated or damaged).' );
			}
			$out[] = $id;
		}
	}

	/**
	 * Parse the directory and compute the depth of every reachable entry.
	 *
	 * @return void
	 * @throws HDS_Extraction_Exception CORRUPT.
	 */
	private function parse_directory() {
		// The header's directory sector count is only meaningful for v4; follow the chain instead.
		$chain     = $this->follow_chain( $this->dir_start, $this->fat, count( $this->fat ), -1, 'directory' );
		$directory = $this->read_sectors( $chain );
		$count     = intdiv( strlen( $directory ), self::ENTRY_SIZE );
		if ( $count > self::MAX_ENTRIES ) {
			throw $this->corrupt( 'The compound file directory is too large.' );
		}

		for ( $i = 0; $i < $count; $i++ ) {
			$raw  = substr( $directory, $i * self::ENTRY_SIZE, self::ENTRY_SIZE );
			$type = ord( $raw[66] );
			if ( self::TYPE_STORAGE !== $type && self::TYPE_STREAM !== $type && self::TYPE_ROOT !== $type ) {
				$this->entries[ $i ] = null;
				continue;
			}
			$v        = unpack( 'vname_len', substr( $raw, 64, 2 ) );
			$name_len = min( (int) $v['name_len'], 64 );
			$name     = $name_len >= 2 ? HDS_Encoding::utf16le_to_utf8( substr( $raw, 0, $name_len - 2 ) ) : '';
			$name     = rtrim( $name, "\0" );
			$p        = unpack( 'Vleft/Vright/Vchild', substr( $raw, 68, 12 ) );
			$s        = unpack( 'Vstart/Vsize_low/Vsize_high', substr( $raw, 116, 12 ) );

			// v3 files: the high 32 bits of the size are undefined and must be ignored.
			$size = (int) $s['size_low'];
			if ( 4 === $this->version && $s['size_high'] > 0 ) {
				$size = PHP_INT_MAX; // Capped to the file size when read.
			}

			$this->entries[ $i ] = array(
				'name'  => $name,
				'type'  => $type,
				'left'  => (int) $p['left'],
				'right' => (int) $p['right'],
				'child' => (int) $p['child'],
				'start' => (int) $s['start'],
				'size'  => $size,
				'depth' => PHP_INT_MAX,
			);
		}

		if ( empty( $this->entries[0] ) || self::TYPE_ROOT !== $this->entries[0]['type'] ) {
			throw $this->corrupt( 'The compound file has no root directory entry.' );
		}

		// Walk the storage trees from the root; the visited set guarantees termination.
		$this->entries[0]['depth'] = 0;
		$visited                   = array( 0 => true );
		$storages                  = array( 0 );
		while ( $storages ) {
			$storage = array_pop( $storages );
			$depth   = $this->entries[ $storage ]['depth'] + 1;
			$nodes   = array( $this->entries[ $storage ]['child'] );
			while ( $nodes ) {
				$id = array_pop( $nodes );
				if ( self::NOSTREAM === $id || ! isset( $this->entries[ $id ] ) || isset( $visited[ $id ] ) ) {
					continue; // Dangling or repeated pointers are ignored, not followed.
				}
				$visited[ $id ]                = true;
				$this->entries[ $id ]['depth'] = $depth;
				$nodes[]                       = $this->entries[ $id ]['left'];
				$nodes[]                       = $this->entries[ $id ]['right'];
				if ( self::TYPE_STORAGE === $this->entries[ $id ]['type'] ) {
					$storages[] = $id;
				}
			}
		}
	}

	/**
	 * Stream entry ids sorted by depth (unreachable last), then id.
	 *
	 * @return int[]
	 */
	private function sorted_stream_ids(): array {
		if ( null !== $this->stream_ids ) {
			return $this->stream_ids;
		}
		$ids = array();
		foreach ( $this->entries as $id => $entry ) {
			if ( null !== $entry && self::TYPE_STREAM === $entry['type'] ) {
				$ids[ $id ] = $entry['depth'];
			}
		}
		// Stable on PHP 8; on 7.4 ties are broken explicitly by the id.
		uksort(
			$ids,
			function ( $a, $b ) use ( $ids ) {
				if ( $ids[ $a ] !== $ids[ $b ] ) {
					return $ids[ $a ] < $ids[ $b ] ? -1 : 1;
				}
				return $a - $b;
			}
		);
		$this->stream_ids = array_keys( $ids );
		return $this->stream_ids;
	}

	/**
	 * Find the best stream entry for a name.
	 *
	 * @param string $name Entry name.
	 * @return int|null Entry id.
	 */
	private function find_stream( string $name ) {
		$wanted = $this->fold( $name );
		$loose  = $this->fold( ltrim( $name, "\x01..\x1F" ) );
		$match  = null;
		foreach ( $this->sorted_stream_ids() as $id ) {
			$candidate = $this->fold( $this->entries[ $id ]['name'] );
			if ( $candidate === $wanted ) {
				return $id;
			}
			// "SummaryInformation" also finds "\x05SummaryInformation".
			if ( null === $match && '' !== $loose && $this->fold( ltrim( $this->entries[ $id ]['name'], "\x01..\x1F" ) ) === $loose ) {
				$match = $id;
			}
		}
		return $match;
	}

	/**
	 * Case-fold an entry name for comparison.
	 *
	 * @param string $name UTF-8 name.
	 * @return string
	 */
	private function fold( string $name ): string {
		return mb_strtoupper( $name, 'UTF-8' );
	}

	/**
	 * Read the contents of a stream entry.
	 *
	 * @param int $id Entry id.
	 * @return string
	 * @throws HDS_Extraction_Exception CORRUPT.
	 */
	private function read_entry( int $id ): string {
		$entry = $this->entries[ $id ];
		if ( $entry['size'] <= 0 ) {
			return '';
		}
		// The location is decided by the declared size; the amount read is
		// capped to what the file can hold.
		if ( $entry['size'] < self::MINI_STREAM_CUTOFF ) {
			return $this->read_mini_stream( (int) $entry['start'], (int) $entry['size'] );
		}
		return $this->read_regular_stream( (int) $entry['start'], min( $entry['size'], $this->file_size ), 'stream' );
	}

	/**
	 * Read a stream stored in regular sectors.
	 *
	 * @param int    $start First sector id.
	 * @param int    $size  Size in bytes (already capped to the file size).
	 * @param string $what  Label for error messages.
	 * @return string
	 * @throws HDS_Extraction_Exception CORRUPT.
	 */
	private function read_regular_stream( int $start, int $size, string $what ): string {
		$needed = intdiv( $size + $this->sector_size - 1, $this->sector_size );
		if ( $needed > $this->sector_count ) {
			throw $this->corrupt( sprintf( 'A %s is larger than the file (truncated or damaged).', $what ) );
		}
		$chain = $this->follow_chain( $start, $this->fat, count( $this->fat ), $needed, $what );
		if ( count( $chain ) < $needed ) {
			throw $this->corrupt( sprintf( 'A %s is truncated (its sector chain ends early).', $what ) );
		}
		$data = $this->read_sectors( $chain );
		if ( strlen( $data ) < $size ) {
			throw $this->corrupt( sprintf( 'A %s is truncated (the file ends early).', $what ) );
		}
		return strlen( $data ) > $size ? substr( $data, 0, $size ) : $data;
	}

	/**
	 * Read a small stream stored in the mini stream.
	 *
	 * @param int $start First mini sector id.
	 * @param int $size  Size in bytes (< 4096).
	 * @return string
	 * @throws HDS_Extraction_Exception CORRUPT.
	 */
	private function read_mini_stream( int $start, int $size ): string {
		$this->load_mini_stream();
		$limit  = min( count( $this->mini_fat ), intdiv( strlen( $this->mini_stream ), self::MINI_SECTOR_SIZE ) );
		$needed = intdiv( $size + self::MINI_SECTOR_SIZE - 1, self::MINI_SECTOR_SIZE );
		$chain  = $this->follow_chain( $start, $this->mini_fat, $limit, $needed, 'mini stream' );
		if ( count( $chain ) < $needed ) {
			throw $this->corrupt( 'A small stream is truncated (its mini sector chain ends early).' );
		}

		$parts = array();
		$count = count( $chain );
		for ( $i = 0; $i < $count; ) {
			// Coalesce runs of consecutive mini sectors into one substr().
			$first = $chain[ $i ];
			$run   = 1;
			while ( $i + $run < $count && $chain[ $i + $run ] === $first + $run ) {
				$run++;
			}
			$parts[] = substr( $this->mini_stream, $first << self::MINI_SECTOR_SHIFT, $run << self::MINI_SECTOR_SHIFT );
			$i      += $run;
		}
		$data = implode( '', $parts );
		if ( strlen( $data ) < $size ) {
			throw $this->corrupt( 'A small stream is truncated.' );
		}
		return substr( $data, 0, $size );
	}

	/**
	 * Load the mini FAT and the mini stream container on first use.
	 *
	 * @return void
	 * @throws HDS_Extraction_Exception CORRUPT.
	 */
	private function load_mini_stream() {
		if ( null !== $this->mini_stream ) {
			return;
		}
		$root  = $this->entries[0];
		$size  = min( $root['size'], $this->sector_count * $this->sector_size );
		$chain = $this->follow_chain( $this->mini_fat_start, $this->fat, count( $this->fat ), -1, 'mini FAT' );
		$table = $this->read_sectors( $chain );

		$this->mini_fat    = $this->unpack_ids( $table );
		$this->mini_stream = $size > 0 ? $this->read_regular_stream( (int) $root['start'], $size, 'mini stream container' ) : '';
	}

	/**
	 * Follow a sector chain.
	 *
	 * @param int    $start Start id.
	 * @param int[]  $table Allocation table (FAT or mini FAT).
	 * @param int    $limit Number of valid ids (ids >= limit are out of range).
	 * @param int    $max   Stop after this many sectors (-1: until ENDOFCHAIN).
	 * @param string $what  Label for error messages.
	 * @return int[]
	 * @throws HDS_Extraction_Exception CORRUPT on loops and out-of-range ids.
	 */
	private function follow_chain( int $start, array $table, int $limit, int $max, string $what ): array {
		$chain = array();
		$seen  = array();
		$id    = $start;
		while ( -1 === $max || count( $chain ) < $max ) {
			if ( self::ENDOFCHAIN === $id ) {
				break;
			}
			if ( $id < 0 || $id >= $limit || ! isset( $table[ $id ] ) ) {
				throw $this->corrupt( sprintf( 'Sector id out of range in the %s chain (the file is truncated or damaged).', $what ) );
			}
			if ( isset( $seen[ $id ] ) ) {
				throw $this->corrupt( sprintf( 'Loop detected in the %s sector chain.', $what ) );
			}
			$seen[ $id ] = true;
			$chain[]     = $id;
			$id          = (int) $table[ $id ];
		}
		return $chain;
	}

	/**
	 * Read regular sectors and concatenate them. Consecutive ids are read
	 * with a single call. Only the file's final sector may be short.
	 *
	 * @param int[] $ids Sector ids (already validated against the sector count).
	 * @return string
	 * @throws HDS_Extraction_Exception CORRUPT when the file ends early.
	 */
	private function read_sectors( array $ids ): string {
		$parts = array();
		$count = count( $ids );
		for ( $i = 0; $i < $count; ) {
			$first = (int) $ids[ $i ];
			$run   = 1;
			while ( $i + $run < $count && (int) $ids[ $i + $run ] === $first + $run ) {
				$run++;
			}
			if ( ! $this->is_valid_sector( $first ) || ! $this->is_valid_sector( $first + $run - 1 ) ) {
				throw $this->corrupt( 'Sector id out of range (the file is truncated or damaged).' );
			}
			$offset   = ( $first + 1 ) << $this->sector_shift;
			$length   = $run << $this->sector_shift;
			$chunk    = $this->read( $offset, $length );
			$expected = min( $length, $this->file_size - $offset );
			if ( strlen( $chunk ) !== $expected ) {
				throw $this->corrupt( 'The file could not be read completely.' );
			}
			if ( $expected < $length ) {
				// Short final sector: pad so that offsets stay sector-aligned.
				$chunk = str_pad( $chunk, $length, "\0" );
			}
			$parts[] = $chunk;
			$i      += $run;
		}
		return implode( '', $parts );
	}

	/**
	 * Unpack little-endian 32-bit sector ids (0-indexed).
	 *
	 * @param string $bytes Raw table bytes (a trailing partial id is ignored).
	 * @return int[]
	 */
	private function unpack_ids( string $bytes ): array {
		$usable = strlen( $bytes ) & ~3;
		if ( 0 === $usable ) {
			return array();
		}
		return array_values( unpack( 'V*', substr( $bytes, 0, $usable ) ) );
	}

	/**
	 * Is this a regular sector id inside the file?
	 *
	 * @param int $id Sector id.
	 * @return bool
	 */
	private function is_valid_sector( int $id ): bool {
		return $id >= 0 && $id <= self::MAXREGSECT && $id < $this->sector_count;
	}

	/**
	 * Read raw bytes from the file.
	 *
	 * @param int $offset Byte offset.
	 * @param int $length Number of bytes.
	 * @return string Fewer bytes at the end of the file.
	 */
	private function read( int $offset, int $length ): string {
		if ( $length <= 0 || $offset < 0 || $offset >= $this->file_size ) {
			return '';
		}
		$length = min( $length, $this->file_size - $offset );
		if ( null === $this->handle ) {
			return (string) substr( $this->data, $offset, $length );
		}
		if ( 0 !== fseek( $this->handle, $offset ) ) {
			return '';
		}
		$parts = array();
		while ( $length > 0 && ! feof( $this->handle ) ) {
			$chunk = fread( $this->handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Binary read from a local file.
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$parts[] = $chunk;
			$length -= strlen( $chunk );
		}
		return implode( '', $parts );
	}

	/**
	 * Make a stream name safe for an error message.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private function printable( string $name ): string {
		return (string) preg_replace( '/[^\x20-\x7E]/', '', $name );
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
}
