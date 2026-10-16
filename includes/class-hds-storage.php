<?php
/**
 * Private file storage for uploaded documents (uploads/hds-docs/).
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage.
 *
 * Documents are stored in wp-content/uploads/hds-docs/ under a sanitized
 * name with a random suffix. The directory (and its cache/ subdirectory) is
 * closed to direct web access with .htaccess (Apache 2.2 and 2.4), web.config
 * (IIS) and an index.php; on nginx the README documents a location rule.
 * Uploads are checked for upload errors, size, extension, magic bytes and
 * (when fileinfo is available) MIME type before they are moved.
 */
final class HDS_Storage {

	/**
	 * Directory name inside the uploads base directory.
	 */
	const DIR_NAME = 'hds-docs';

	/**
	 * Cache subdirectory name.
	 */
	const CACHE_DIR_NAME = 'cache';

	/**
	 * Longest stored file name in bytes.
	 */
	const MAX_NAME_BYTES = 180;

	/**
	 * Apache 2.2 + 2.4 deny-all.
	 */
	const HTACCESS = "# Smart Document Search: no direct access to stored documents.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

	/**
	 * Directory index placeholder.
	 */
	const INDEX_PHP = "<?php // Silence is golden.\n";

	/**
	 * IIS: request filtering rejects every file extension, no directory listing.
	 */
	const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<directoryBrowse enabled=\"false\" />\n\t\t<security>\n\t\t\t<requestFiltering>\n\t\t\t\t<fileExtensions allowUnlisted=\"false\" />\n\t\t\t</requestFiltering>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n";

	/**
	 * Absolute path of the storage directory (trailing slash).
	 *
	 * @return string
	 */
	public static function dir(): string {
		return trailingslashit( self::uploads_basedir() ) . self::DIR_NAME . '/';
	}

	/**
	 * Absolute path of the cache directory (trailing slash).
	 *
	 * @return string
	 */
	public static function cache_dir(): string {
		return self::dir() . self::CACHE_DIR_NAME . '/';
	}

	/**
	 * Create the directories and (re)write any missing or altered protection
	 * file. Cheap enough to call before every write.
	 *
	 * @return bool True when both directories exist with all protection files.
	 */
	public static function ensure_protected(): bool {
		$ok    = true;
		$files = array(
			'.htaccess'  => self::HTACCESS,
			'index.php'  => self::INDEX_PHP,
			'web.config' => self::WEB_CONFIG,
		);
		foreach ( array( self::dir(), self::cache_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				$ok = false;
				continue;
			}
			foreach ( $files as $name => $content ) {
				$path = $dir . $name;
				if ( is_file( $path ) && ! is_link( $path ) && (string) @file_get_contents( $path ) === $content ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
					continue;
				}
				if ( is_link( $path ) ) {
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				if ( false === @file_put_contents( $path, $content, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					$ok = false;
				}
			}
		}
		return $ok;
	}

	/**
	 * Absolute path of a stored document.
	 *
	 * @param string $file_name Stored file name (no directory parts).
	 * @return string '' when the name is not a valid stored document name.
	 */
	public static function path( string $file_name ): string {
		if ( ! self::is_valid_name( $file_name ) ) {
			return '';
		}
		return self::dir() . $file_name;
	}

	/**
	 * Acceptable fileinfo MIME types per extension ('*' at the end = prefix).
	 *
	 * @return array<string, string[]>
	 */
	public static function allowed_mimes(): array {
		return array(
			'doc'  => array(
				'application/msword',
				'application/x-ole-storage',
				'application/cdfv2',
				'application/cdfv2-*',
				'application/vnd.ms-office',
				'application/vnd.ms-word',
				'application/encrypted',
				'application/octet-stream',
			),
			'docx' => array(
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/zip',
				'application/x-zip-compressed',
				'application/octet-stream',
			),
			'pdf'  => array(
				'application/pdf',
				'application/x-pdf',
			),
			'txt'  => array(
				'text/plain',
				'text/x-*',
				'text/*',
				'application/octet-stream',
			),
		);
	}

	/**
	 * Check a file's first bytes against its extension.
	 *
	 * .doc and .docx accept both Word containers (OLE2 and a zip with
	 * word/document.xml): a renamed .docx/.doc is handled by the extractor
	 * factory, and an encrypted .docx is an OLE2 file.
	 *
	 * @param string $path File path.
	 * @param string $ext  Extension (lowercase, no dot).
	 * @return bool
	 */
	public static function check_magic( string $path, string $ext ): bool {
		$ext = strtolower( $ext );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$head = (string) file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( '' === $head ) {
			return false;
		}
		switch ( $ext ) {
			case 'doc':
			case 'docx':
				return self::is_ole( $head ) || ( 0 === strncmp( $head, "PK\x03\x04", 4 ) && self::zip_has_document( $path ) );
			case 'pdf':
				return false !== strpos( substr( $head, 0, 1024 ), '%PDF-' );
			case 'txt':
				return self::looks_like_text( $head );
			default:
				return false;
		}
	}

	/**
	 * Validate an uploaded file and move it into the storage directory.
	 *
	 * @param array $file      One normalized $_FILES entry {name, type, tmp_name, error, size}.
	 * @param int   $max_bytes Largest accepted size in bytes.
	 * @return array|WP_Error {file_name, original_name, ext, file_size, file_hash, title} or an error (English message).
	 */
	public static function store_upload( array $file, int $max_bytes ) {
		$original = self::client_name( isset( $file['name'] ) && is_string( $file['name'] ) ? $file['name'] : '' );
		$label    = '' !== $original ? $original : __( 'The file', 'smart-document-search' );
		$error    = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'hds_upload_error', self::upload_error_message( $error, $label, $max_bytes ) );
		}
		if ( '' === $original ) {
			return new WP_Error( 'hds_upload_name', __( 'The uploaded file has no name.', 'smart-document-search' ) );
		}

		$tmp = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( '' === $tmp || ! is_file( $tmp ) ) {
			return new WP_Error( 'hds_upload_missing', sprintf( /* translators: %s: file name. */ __( '%s: the upload did not complete. Please try again.', 'smart-document-search' ), $label ) );
		}
		$uploaded = is_uploaded_file( $tmp );
		/**
		 * For unit tests only: treat a local file that is not an HTTP upload
		 * as one (it is then copied instead of moved). Only consulted when
		 * is_uploaded_file() rejected the file; must return exactly true.
		 *
		 * @param bool   $allow Default false.
		 * @param string $tmp   Temporary file path.
		 */
		if ( ! $uploaded && true !== apply_filters( 'hds_is_uploaded_file', false, $tmp ) ) {
			return new WP_Error( 'hds_upload_invalid', sprintf( /* translators: %s: file name. */ __( '%s: this is not a valid uploaded file.', 'smart-document-search' ), $label ) );
		}

		$ext = self::extension( $original );
		if ( ! in_array( $ext, HDS_Extractor_Factory::supported_extensions(), true ) ) {
			return new WP_Error(
				'hds_upload_type',
				sprintf(
					/* translators: 1: file name, 2: list of extensions. */
					__( '%1$s: this file type is not supported. Allowed types: %2$s.', 'smart-document-search' ),
					$label,
					'.' . implode( ', .', HDS_Extractor_Factory::supported_extensions() )
				)
			);
		}

		clearstatcache( true, $tmp );
		$size = (int) filesize( $tmp );
		if ( $size <= 0 ) {
			return new WP_Error( 'hds_upload_empty', sprintf( /* translators: %s: file name. */ __( '%s: the file is empty.', 'smart-document-search' ), $label ) );
		}
		if ( $max_bytes > 0 && $size > $max_bytes ) {
			return new WP_Error(
				'hds_upload_size',
				sprintf(
					/* translators: 1: file name, 2: size limit. */
					__( '%1$s: the file is larger than the %2$s limit.', 'smart-document-search' ),
					$label,
					size_format( $max_bytes )
				)
			);
		}

		if ( ! self::check_magic( $tmp, $ext ) ) {
			return new WP_Error( 'hds_upload_content', self::content_error_message( $tmp, $ext, $label ) );
		}

		$mime = self::detect_mime( $tmp );
		if ( '' !== $mime && ! self::mime_allowed( $mime, $ext ) ) {
			return new WP_Error(
				'hds_upload_mime',
				sprintf(
					/* translators: 1: file name, 2: extension. */
					__( '%1$s: the file content does not match the .%2$s format.', 'smart-document-search' ),
					$label,
					$ext
				)
			);
		}

		$hash = hash_file( 'sha256', $tmp );
		if ( ! is_string( $hash ) || 64 !== strlen( $hash ) ) {
			return new WP_Error( 'hds_upload_read', sprintf( /* translators: %s: file name. */ __( '%s: the uploaded file could not be read.', 'smart-document-search' ), $label ) );
		}

		if ( ! self::ensure_protected() && ! is_dir( self::dir() ) ) {
			return new WP_Error( 'hds_storage', __( 'The storage folder (wp-content/uploads/hds-docs) could not be created. Check the folder permissions.', 'smart-document-search' ) );
		}

		$base = substr( $original, 0, -( strlen( $ext ) + 1 ) );
		$dest = '';
		$name = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$name = self::stored_name( $base, $ext );
			$dest = self::dir() . $name;
			if ( ! file_exists( $dest ) ) {
				break;
			}
		}
		if ( file_exists( $dest ) ) {
			return new WP_Error( 'hds_storage', __( 'Could not save the uploaded file. Please try again.', 'smart-document-search' ) );
		}

		$saved = $uploaded ? @move_uploaded_file( $tmp, $dest ) : @copy( $tmp, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $saved || ! is_file( $dest ) ) {
			return new WP_Error( 'hds_storage', __( 'Could not save the uploaded file. Check the permissions of wp-content/uploads.', 'smart-document-search' ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$title = sanitize_text_field( $base );
		if ( '' === $title ) {
			$title = sanitize_text_field( $original );
		}

		return array(
			'file_name'     => $name,
			'original_name' => self::truncate( sanitize_text_field( $original ), 255 ),
			'ext'           => $ext,
			'file_size'     => $size,
			'file_hash'     => $hash,
			'title'         => self::truncate( $title, 255 ),
		);
	}

	/**
	 * Delete a stored document file (protection files can not be deleted).
	 *
	 * @param string $file_name Stored file name.
	 * @return void
	 */
	public static function delete_file( string $file_name ): void {
		$path = self::path( $file_name );
		if ( '' !== $path && ( is_link( $path ) || is_file( $path ) ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Delete the whole storage directory. Refuses to act unless the directory
	 * resolves to <uploads basedir>/hds-docs.
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		$base = realpath( self::uploads_basedir() );
		$dir  = realpath( self::dir() );
		if ( false === $base || false === $dir || ! is_dir( $dir ) || is_link( untrailingslashit( self::dir() ) ) ) {
			return;
		}
		if ( rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . self::DIR_NAME !== $dir ) {
			return;
		}
		self::remove_tree( $dir );
	}

	/**
	 * Uploads base directory of the current site.
	 *
	 * @return string
	 */
	private static function uploads_basedir(): string {
		$uploads = wp_upload_dir( null, false );
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			return (string) $uploads['basedir'];
		}
		return ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/uploads';
	}

	/**
	 * Recursively delete a directory without following symbolic links.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function remove_tree( string $dir ): void {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_link( $path ) || ! is_dir( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			} else {
				self::remove_tree( $path );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Whether a name is a valid stored document name.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	private static function is_valid_name( string $name ): bool {
		if ( '' === $name || strlen( $name ) > 255 || '.' === $name[0] ) {
			return false;
		}
		if ( strpbrk( $name, "/\\\0:" ) !== false || false !== strpos( $name, '..' ) ) {
			return false;
		}
		return in_array( self::extension( $name ), HDS_Extractor_Factory::supported_extensions(), true );
	}

	/**
	 * Lowercase extension of a file name ('' when none).
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function extension( string $name ): string {
		$dot = strrpos( $name, '.' );
		if ( false === $dot || 0 === $dot || strlen( $name ) - 1 === $dot ) {
			return '';
		}
		return strtolower( substr( $name, $dot + 1 ) );
	}

	/**
	 * Client file name without any path components, control characters or
	 * invalid UTF-8 (basename() is not multibyte safe in every locale).
	 *
	 * @param string $name Client file name.
	 * @return string
	 */
	private static function client_name( string $name ): string {
		$name = str_replace( '\\', '/', $name );
		$pos  = strrpos( $name, '/' );
		if ( false !== $pos ) {
			$name = substr( $name, $pos + 1 );
		}
		if ( ! mb_check_encoding( $name, 'UTF-8' ) ) {
			$name = (string) mb_convert_encoding( $name, 'UTF-8', 'UTF-8' );
		}
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $name );
		$name = trim( $name, " \t.\x0B" );
		if ( mb_strlen( $name, 'UTF-8' ) > 255 ) {
			// Keep the extension; shorten the base name.
			$ext  = self::extension( $name );
			$tail = '' !== $ext && strlen( $ext ) <= 10 ? '.' . $ext : '';
			$base = '' !== $tail ? substr( $name, 0, -strlen( $tail ) ) : $name;
			$name = rtrim( self::truncate( $base, 255 - strlen( $tail ) ) ) . $tail;
		}
		return $name;
	}

	/**
	 * Unique stored name: sanitize_file_name( base ) . '-' . 8 random chars . '.' . ext,
	 * at most MAX_NAME_BYTES bytes.
	 *
	 * @param string $base Original name without extension.
	 * @param string $ext  Extension.
	 * @return string
	 */
	private static function stored_name( string $base, string $ext ): string {
		$base = sanitize_file_name( $base );
		// No dots: a stored name never has more than one extension (Apache multi-extension handlers).
		$base = str_replace( array( '/', '\\', "\0", '.' ), array( '', '', '', '-' ), $base );
		$base = trim( (string) preg_replace( '/-{2,}/', '-', $base ), '.-_ ' );
		if ( '' === $base ) {
			$base = 'document';
		}
		$suffix = strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', wp_generate_password( 8, false ) ) );
		while ( strlen( $suffix ) < 8 ) {
			$suffix .= dechex( random_int( 0, 15 ) );
		}
		$suffix = substr( $suffix, 0, 8 );
		$room   = self::MAX_NAME_BYTES - strlen( '-' . $suffix . '.' . $ext );
		if ( strlen( $base ) > $room ) {
			$base = rtrim( mb_strcut( $base, 0, $room, 'UTF-8' ), '.-_ ' );
			if ( '' === $base ) {
				$base = 'document';
			}
		}
		return $base . '-' . $suffix . '.' . $ext;
	}

	/**
	 * Truncate to a number of characters.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Characters.
	 * @return string
	 */
	private static function truncate( string $text, int $chars ): string {
		return mb_strlen( $text, 'UTF-8' ) > $chars ? mb_substr( $text, 0, $chars, 'UTF-8' ) : $text;
	}

	/**
	 * Whether the bytes start with the OLE2 (Compound File) signature.
	 *
	 * @param string $head First bytes.
	 * @return bool
	 */
	private static function is_ole( string $head ): bool {
		return 0 === strncmp( $head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8 );
	}

	/**
	 * Whether a zip file contains word/document.xml.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private static function zip_has_document( string $path ): bool {
		if ( ! class_exists( 'ZipArchive' ) ) {
			// Without ext-zip the extractor reports the problem; the signature matched.
			return true;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return false;
		}
		$found = false !== $zip->locateName( 'word/document.xml' );
		$zip->close();
		return $found;
	}

	/**
	 * Plain text check: no NUL bytes, except in UTF-16 text (BOM, or NULs
	 * in alternating positions as in Hebrew/Latin UTF-16 without a BOM).
	 *
	 * @param string $head First bytes (up to 8 KB).
	 * @return bool
	 */
	private static function looks_like_text( string $head ): bool {
		if ( 0 === strncmp( $head, "\xFF\xFE", 2 ) || 0 === strncmp( $head, "\xFE\xFF", 2 ) ) {
			return true;
		}
		if ( false === strpos( $head, "\0" ) ) {
			return true;
		}
		$len = strlen( $head ) - ( strlen( $head ) % 2 );
		if ( $len < 4 ) {
			return false;
		}
		$even = 0;
		$odd  = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			if ( "\0" === $head[ $i ] ) {
				if ( 0 === $i % 2 ) {
					++$even;
				} else {
					++$odd;
				}
			}
		}
		$pairs = $len / 2;
		// UTF-16: one byte of (almost) every pair is NUL (Latin) or a fixed
		// high byte (Hebrew 0x05); NULs never in both positions.
		return ( 0 === $even && $odd >= $pairs * 0.05 ) || ( 0 === $odd && $even >= $pairs * 0.05 );
	}

	/**
	 * MIME type reported by fileinfo ('' when unavailable).
	 *
	 * @param string $path File path.
	 * @return string Lowercase MIME type.
	 */
	private static function detect_mime( string $path ): string {
		if ( ! class_exists( 'finfo' ) ) {
			return '';
		}
		try {
			$finfo = new finfo( FILEINFO_MIME_TYPE );
			$mime  = $finfo->file( $path );
		} catch ( \Throwable $e ) {
			return '';
		}
		return is_string( $mime ) ? strtolower( trim( $mime ) ) : '';
	}

	/**
	 * Whether a MIME type is acceptable for an extension (Word types accept
	 * each other's MIME types, see check_magic()).
	 *
	 * @param string $mime MIME type (lowercase).
	 * @param string $ext  Extension.
	 * @return bool
	 */
	private static function mime_allowed( string $mime, string $ext ): bool {
		$all     = self::allowed_mimes();
		$allowed = isset( $all[ $ext ] ) ? $all[ $ext ] : array();
		if ( 'doc' === $ext || 'docx' === $ext ) {
			$allowed = array_merge( $all['doc'], $all['docx'] );
		}
		foreach ( $allowed as $pattern ) {
			$pattern = strtolower( $pattern );
			if ( '*' === substr( $pattern, -1 ) ) {
				if ( 0 === strpos( $mime, substr( $pattern, 0, -1 ) ) ) {
					return true;
				}
			} elseif ( $pattern === $mime ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Message for content that does not match the extension.
	 *
	 * @param string $path  File path.
	 * @param string $ext   Extension.
	 * @param string $label File name for the message.
	 * @return string
	 */
	private static function content_error_message( string $path, string $ext, string $label ): string {
		$head = ltrim( (string) file_get_contents( $path, false, null, 0, 512 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( ( 'doc' === $ext || 'docx' === $ext ) && 0 === strncmp( $head, '{\\rtf', 5 ) ) {
			/* translators: %s: file name. */
			return sprintf( __( '%s is an RTF file saved with a Word extension. Re-save it in Word as .docx and upload it again.', 'smart-document-search' ), $label );
		}
		if ( ( 'doc' === $ext || 'docx' === $ext ) && preg_match( '/^(?:<!doctype html|<html|<\?xml[^>]*>\s*<html)/i', $head ) ) {
			/* translators: %s: file name. */
			return sprintf( __( '%s is an HTML file saved with a Word extension. Re-save it in Word as .docx and upload it again.', 'smart-document-search' ), $label );
		}
		/* translators: 1: file name, 2: extension. */
		return sprintf( __( '%1$s: the file content does not match the .%2$s format (the file is damaged or has a wrong extension).', 'smart-document-search' ), $label, $ext );
	}

	/**
	 * Readable message for a PHP upload error code.
	 *
	 * @param int    $code      UPLOAD_ERR_* code.
	 * @param string $label     File name for the message.
	 * @param int    $max_bytes Size limit.
	 * @return string
	 */
	private static function upload_error_message( int $code, string $label, int $max_bytes ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				/* translators: 1: file name, 2: size limit. */
				return sprintf( __( '%1$s: the file is larger than the server allows (limit: %2$s).', 'smart-document-search' ), $label, size_format( $max_bytes ) );
			case UPLOAD_ERR_PARTIAL:
				/* translators: %s: file name. */
				return sprintf( __( '%s: the file was only partially uploaded. Please try again.', 'smart-document-search' ), $label );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was uploaded.', 'smart-document-search' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'The server has no temporary folder for uploads. Contact your hosting provider.', 'smart-document-search' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the uploaded file to disk. Contact your hosting provider.', 'smart-document-search' );
			case UPLOAD_ERR_EXTENSION:
				/* translators: %s: file name. */
				return sprintf( __( '%s: the upload was stopped by a PHP extension on the server.', 'smart-document-search' ), $label );
			default:
				/* translators: %s: file name. */
				return sprintf( __( '%s: the upload failed. Please try again.', 'smart-document-search' ), $label );
		}
	}
}
