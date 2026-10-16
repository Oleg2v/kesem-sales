<?php
/**
 * Common interface of all text extractors.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts plain UTF-8 text from one file format.
 */
interface HDS_Extractor_Interface {

	/**
	 * Extract the text of a file.
	 *
	 * Returns UTF-8 text in logical (reading) order, paragraphs separated by
	 * "\n". Output does not have to be cleaned: the factory runs
	 * HDS_Normalizer::clean_extracted() on it.
	 *
	 * @param string $path Absolute path to a readable file.
	 * @return string
	 * @throws HDS_Extraction_Exception When the file cannot be parsed.
	 */
	public function extract( string $path ): string;
}
