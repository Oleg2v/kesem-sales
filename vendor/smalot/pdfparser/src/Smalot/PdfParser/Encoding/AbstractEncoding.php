<?php

namespace HDS_Vendor\Smalot\PdfParser\Encoding;

// Modified for Smart Document Search: namespace prefixed with HDS_Vendor\ by tools/prefix-vendor.php.
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractEncoding
{
    abstract public function getTranslations(): array;
}
