<?php
/**
 * Extraction error with a machine-readable code.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by extractors. The message is shown to the site admin (English),
 * so it never contains document text.
 */
class HDS_Extraction_Exception extends Exception {

	const UNREADABLE  = 'unreadable';   // Missing file or no read permission.
	const CORRUPT     = 'corrupt';      // Broken or truncated file.
	const ENCRYPTED   = 'encrypted';    // Password-protected document.
	const UNSUPPORTED = 'unsupported';  // Unsupported variant (RTF/HTML saved as .doc, unknown type...).
	const TOO_LARGE   = 'too_large';    // Exceeds a safety limit (zip bomb, huge stream).
	const NO_TEXT     = 'no_text';      // Parsed fine but contains no text.
	const SCANNED_PDF = 'scanned_pdf';  // PDF without a text layer.

	/**
	 * Machine-readable error code (one of the constants).
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Constructor.
	 *
	 * @param string          $error_code One of the class constants.
	 * @param string          $message    Admin-facing message, no document content.
	 * @param \Throwable|null $previous   Previous exception.
	 */
	public function __construct( string $error_code, string $message, $previous = null ) {
		parent::__construct( $message, 0, $previous instanceof \Throwable ? $previous : null );
		$this->error_code = $error_code;
	}

	/**
	 * Get the machine-readable code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}
}
