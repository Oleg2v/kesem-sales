<?php

declare(strict_types=1);

namespace HDS_Vendor\Smalot\PdfParser\Exception;

// Modified for Smart Document Search: namespace prefixed with HDS_Vendor\ by tools/prefix-vendor.php.
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This Exception is thrown when no PDF data was given.
 */
class EmptyPdfException extends \Exception
{
}
