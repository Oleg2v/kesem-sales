<?php

namespace HDS_Vendor\Smalot\PdfParser\Encoding;

// Modified for Smart Document Search: namespace prefixed with HDS_Vendor\ by tools/prefix-vendor.php.
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class EncodingLocator
{
    protected static $encodings;

    public static function getEncoding(string $encodingClassName): AbstractEncoding
    {
        if (!isset(self::$encodings[$encodingClassName])) {
            self::$encodings[$encodingClassName] = new $encodingClassName();
        }

        return self::$encodings[$encodingClassName];
    }
}
