<?php
/**
 * Word 2007+ (.docx) text extraction.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts the text of a .docx (Office Open XML WordprocessingML) file:
 * the main document, then footnotes and endnotes. Headers, footers and
 * comments are skipped.
 *
 * Security: the XML is streamed with XMLReader using LIBXML_NONET and
 * without entity substitution or DTD loading; parts that contain a DTD at
 * all are rejected (OOXML never uses one), which also rules out entity
 * expansion attacks. Every part's uncompressed size is checked before it
 * is read (zip-bomb guard).
 */
final class HDS_Docx_Extractor implements HDS_Extractor_Interface {

	/**
	 * Largest uncompressed XML part that will be read (bytes).
	 */
	const MAX_ENTRY_BYTES = 52428800; // 50 MB.

	/**
	 * Largest relationship part that will be read (bytes).
	 */
	const MAX_RELS_BYTES = 1048576; // 1 MB.

	/**
	 * WordprocessingML namespaces (transitional and strict).
	 */
	const W_NAMESPACES = array(
		'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
		'http://purl.oclc.org/ooxml/wordprocessingml/main',
	);

	/**
	 * Markup-compatibility namespace (mc:AlternateContent).
	 */
	const MC_NAMESPACE = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

	/**
	 * w:* elements whose whole subtree carries no document text: properties,
	 * field instructions, deleted text, the old location of moved text.
	 */
	const SKIP_ELEMENTS = array(
		'pPr'       => true,
		'rPr'       => true,
		'sectPr'    => true,
		'tblPr'     => true,
		'tblGrid'   => true,
		'trPr'      => true,
		'tcPr'      => true,
		'instrText' => true,
		'delText'   => true,
		'del'       => true,
		'moveFrom'  => true,
		'commentReference' => true,
		'footnoteReference' => true,
		'endnoteReference'  => true,
	);

	/**
	 * Output buffer.
	 *
	 * @var string
	 */
	private $out = '';

	/**
	 * Separator waiting to be written before the next content ("", "\n"
	 * or "\t"), so a table cell can replace its last paragraph break.
	 *
	 * @var string
	 */
	private $pending = '';

	/**
	 * Extract the text of a .docx file.
	 *
	 * @param string $path Absolute path to a readable file.
	 * @return string
	 * @throws HDS_Extraction_Exception On damaged, oversized or non-Word files.
	 */
	public function extract( string $path ): string {
		if ( ! class_exists( 'ZipArchive' ) || ! class_exists( 'XMLReader' ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::UNSUPPORTED, 'The PHP zip and xmlreader extensions are required to read .docx files.' );
		}

		$zip    = new ZipArchive();
		$flags  = defined( 'ZipArchive::RDONLY' ) ? ZipArchive::RDONLY : 0;
		$status = $zip->open( $path, $flags );
		if ( true !== $status ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The .docx file is damaged (not a valid ZIP archive).' );
		}

		try {
			$parts = $this->locate_parts( $zip );
			if ( null === $parts['document'] ) {
				throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The .docx file is damaged or is not a Word document (word/document.xml is missing).' );
			}

			$this->out     = '';
			$this->pending = '';

			$this->parse_part( $this->read_entry( $zip, $parts['document'], self::MAX_ENTRY_BYTES ), false );
			foreach ( array( 'footnotes', 'endnotes' ) as $notes ) {
				if ( null !== $parts[ $notes ] ) {
					$this->flush_pending();
					$this->pending = "\n";
					$this->parse_part( $this->read_entry( $zip, $parts[ $notes ], self::MAX_ENTRY_BYTES ), true );
				}
			}
		} finally {
			$zip->close();
		}

		$text          = $this->out;
		$this->out     = '';
		$this->pending = '';
		return $text;
	}

	/**
	 * Find the main document, footnotes and endnotes parts via the package
	 * relationships, falling back to the conventional names.
	 *
	 * @param ZipArchive $zip Open archive.
	 * @return array{document: ?string, footnotes: ?string, endnotes: ?string}
	 */
	private function locate_parts( ZipArchive $zip ): array {
		$parts = array(
			'document'  => null,
			'footnotes' => null,
			'endnotes'  => null,
		);

		$main = null;
		$rels = $this->relationships( $zip, '_rels/.rels', '' );
		if ( isset( $rels['officeDocument'] ) ) {
			$main = $rels['officeDocument'];
		}
		if ( null === $main || false === $zip->locateName( $main ) ) {
			$main = 'word/document.xml';
		}
		if ( false === $zip->locateName( $main ) ) {
			return $parts;
		}
		$parts['document'] = $main;

		$dir      = false === strpos( $main, '/' ) ? '' : substr( $main, 0, strrpos( $main, '/' ) + 1 );
		$doc_rels = $this->relationships( $zip, $dir . '_rels/' . basename( $main ) . '.rels', $dir );
		foreach ( array( 'footnotes', 'endnotes' ) as $type ) {
			$candidate = isset( $doc_rels[ $type ] ) ? $doc_rels[ $type ] : $dir . $type . '.xml';
			if ( false !== $zip->locateName( $candidate ) ) {
				$parts[ $type ] = $candidate;
			}
		}
		return $parts;
	}

	/**
	 * Read a relationships part: relationship type suffix => target path
	 * inside the archive. Unreadable or suspicious parts yield [].
	 *
	 * @param ZipArchive $zip  Archive.
	 * @param string     $name Rels part name.
	 * @param string     $base Directory targets are relative to ('' or 'word/').
	 * @return array<string, string>
	 */
	private function relationships( ZipArchive $zip, string $name, string $base ): array {
		if ( false === $zip->locateName( $name ) ) {
			return array();
		}
		try {
			$xml = $this->read_entry( $zip, $name, self::MAX_RELS_BYTES );
		} catch ( HDS_Extraction_Exception $e ) {
			return array();
		}

		$result   = array();
		$reader   = new XMLReader();
		$previous = libxml_use_internal_errors( true );
		try {
			if ( ! $reader->XML( $xml, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
				return array();
			}
			while ( @$reader->read() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Errors are collected by libxml.
				if ( XMLReader::ELEMENT !== $reader->nodeType || 'Relationship' !== $reader->localName ) {
					continue;
				}
				$type   = (string) $reader->getAttribute( 'Type' );
				$target = (string) $reader->getAttribute( 'Target' );
				$mode   = (string) $reader->getAttribute( 'TargetMode' );
				if ( '' === $type || '' === $target || 'External' === $mode ) {
					continue;
				}
				$key    = substr( $type, strrpos( $type, '/' ) + 1 );
				$target = '/' === $target[0] ? ltrim( $target, '/' ) : $base . $target;
				$target = $this->normalize_part_name( $target );
				if ( null !== $target && ! isset( $result[ $key ] ) ) {
					$result[ $key ] = $target;
				}
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		return $result;
	}

	/**
	 * Resolve "." and ".." segments of a part name; null if it escapes the
	 * package root.
	 *
	 * @param string $name Part name without leading slash.
	 * @return string|null
	 */
	private function normalize_part_name( string $name ) {
		$segments = array();
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( ! $segments ) {
					return null;
				}
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		return $segments ? implode( '/', $segments ) : null;
	}

	/**
	 * Read one archive entry after checking its uncompressed size.
	 *
	 * @param ZipArchive $zip   Archive.
	 * @param string     $name  Entry name.
	 * @param int        $limit Max uncompressed bytes.
	 * @return string
	 * @throws HDS_Extraction_Exception TOO_LARGE or CORRUPT.
	 */
	private function read_entry( ZipArchive $zip, string $name, int $limit ): string {
		$stat = $zip->statName( $name );
		if ( false === $stat ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The .docx file is damaged (a required part cannot be read).' );
		}
		if ( (int) $stat['size'] > $limit ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::TOO_LARGE, 'The .docx file is too large to process (a part exceeds the safety limit).' );
		}
		if ( 0 === (int) $stat['size'] ) {
			return '';
		}
		// Reads exactly the declared (already checked) number of bytes, so a
		// lying header cannot inflate more than that; libzip verifies the CRC.
		$data = $zip->getFromName( $name, (int) $stat['size'] );
		if ( false === $data || strlen( $data ) > $limit ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The .docx file is damaged (a required part cannot be decompressed).' );
		}
		return $data;
	}

	/**
	 * Stream one WordprocessingML part into the output buffer.
	 *
	 * @param string $xml   Part XML.
	 * @param bool   $notes Whether this is footnotes/endnotes (skips separators).
	 * @return void
	 * @throws HDS_Extraction_Exception CORRUPT on malformed XML or a DTD.
	 */
	private function parse_part( string $xml, bool $notes ) {
		if ( '' === $xml ) {
			if ( $notes ) {
				return;
			}
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The .docx file is damaged (empty document part).' );
		}
		if ( false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The .docx file contains unexpected XML declarations and was rejected.' );
		}

		$reader   = new XMLReader();
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$failed = false;
		try {
			if ( ! $reader->XML( $xml, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
				$failed = true;
			} else {
				$this->walk( $reader, $notes );
				foreach ( libxml_get_errors() as $error ) {
					if ( $error->level >= LIBXML_ERR_ERROR ) {
						$failed = true;
						break;
					}
				}
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( $failed ) {
			throw new HDS_Extraction_Exception( HDS_Extraction_Exception::CORRUPT, 'The .docx file is damaged (invalid XML).' );
		}
	}

	/**
	 * Walk the XML and write text, paragraph breaks and table separators.
	 *
	 * @param XMLReader $reader Positioned before the root.
	 * @param bool      $notes  Skip footnote/endnote separators.
	 * @return void
	 */
	private function walk( XMLReader $reader, bool $notes ) {
		$ok = @$reader->read(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Errors are collected by libxml.
		while ( $ok ) {
			$type = $reader->nodeType;
			if ( XMLReader::ELEMENT === $type ) {
				$ns    = $reader->namespaceURI;
				$local = $reader->localName;

				if ( self::MC_NAMESPACE === $ns && 'Fallback' === $local ) {
					// Same content as mc:Choice (e.g. VML copy of a text box).
					$ok = @$reader->next(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					continue;
				}
				if ( in_array( $ns, self::W_NAMESPACES, true ) ) {
					if ( isset( self::SKIP_ELEMENTS[ $local ] ) ) {
						$ok = @$reader->next(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						continue;
					}
					if ( $notes && ( 'footnote' === $local || 'endnote' === $local ) ) {
						$note_type = (string) $reader->getAttribute( 'w:type' );
						if ( '' === $note_type ) {
							$note_type = (string) $reader->getAttributeNs( 'type', (string) $ns );
						}
						if ( '' !== $note_type && 'normal' !== $note_type ) {
							$ok = @$reader->next(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							continue;
						}
					}
					switch ( $local ) {
						case 't':
							$this->add( $reader->isEmptyElement ? '' : $reader->readString() );
							$ok = @$reader->next(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							continue 2;
						case 'tab':
						case 'ptab':
							$this->add_raw( "\t" );
							break;
						case 'br':
						case 'cr':
							$this->add_raw( "\n" );
							break;
						case 'noBreakHyphen':
							$this->add( '-' );
							break;
						case 'p':
							if ( $reader->isEmptyElement ) {
								$this->end_paragraph();
							}
							break;
						case 'tc':
							if ( $reader->isEmptyElement ) {
								$this->end_cell();
							}
							break;
						case 'tr':
							if ( $reader->isEmptyElement ) {
								$this->end_row();
							}
							break;
					}
				}
			} elseif ( XMLReader::END_ELEMENT === $type && in_array( $reader->namespaceURI, self::W_NAMESPACES, true ) ) {
				switch ( $reader->localName ) {
					case 'p':
						$this->end_paragraph();
						break;
					case 'tc':
						$this->end_cell();
						break;
					case 'tr':
						$this->end_row();
						break;
				}
			}
			$ok = @$reader->read(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Append text (writes the pending separator first).
	 *
	 * @param string $text Text.
	 * @return void
	 */
	private function add( string $text ) {
		if ( '' === $text ) {
			return;
		}
		$this->out    .= $this->pending . $text;
		$this->pending = '';
	}

	/**
	 * Append a literal tab or line break.
	 *
	 * @param string $char "\t" or "\n".
	 * @return void
	 */
	private function add_raw( string $char ) {
		$this->out    .= $this->pending . $char;
		$this->pending = '';
	}

	/**
	 * Write any pending separator.
	 *
	 * @return void
	 */
	private function flush_pending() {
		$this->out    .= $this->pending;
		$this->pending = '';
	}

	/**
	 * End of a paragraph: "\n" (deferred, so a cell can replace it).
	 *
	 * @return void
	 */
	private function end_paragraph() {
		$this->flush_pending();
		$this->pending = "\n";
	}

	/**
	 * End of a table cell: cells are separated by "\t".
	 *
	 * @return void
	 */
	private function end_cell() {
		if ( "\n" !== $this->pending ) {
			$this->flush_pending();
		}
		$this->pending = "\t";
	}

	/**
	 * End of a table row: "\n".
	 *
	 * @return void
	 */
	private function end_row() {
		if ( "\t" !== $this->pending ) {
			$this->flush_pending();
		}
		$this->pending = "\n";
	}
}
