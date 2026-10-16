<?php
/**
 * Tests for HDS_Storage.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Storage tests.
 */
class StorageTest extends TestCase {

	/**
	 * Scratch directory for "uploaded" temp files.
	 *
	 * @var string
	 */
	private $tmp;

	protected function setUp(): void {
		hds_test_reset();
		hds_test_create_tables();
		hds_test_clean_uploads();
		$this->tmp = dirname( $GLOBALS['hds_test']['upload_dir'] ) . '/tmp';
		wp_mkdir_p( $this->tmp );
	}

	protected function tearDown(): void {
		hds_test_rmdir( dirname( $GLOBALS['hds_test']['upload_dir'] ) );
	}

	/**
	 * Allow local files as uploads (the hds_is_uploaded_file test filter).
	 *
	 * @return void
	 */
	private function allow_local_uploads(): void {
		add_filter(
			'hds_is_uploaded_file',
			static function () {
				return true;
			}
		);
	}

	/**
	 * A $_FILES entry for a temp copy of some content.
	 *
	 * @param string $name    Client file name.
	 * @param string $content File content.
	 * @param int    $error   Upload error.
	 * @return array
	 */
	private function upload( string $name, string $content, int $error = UPLOAD_ERR_OK ): array {
		$path = tempnam( $this->tmp, 'php' );
		file_put_contents( $path, $content );
		return array(
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $path,
			'error'    => $error,
			'size'     => strlen( $content ),
		);
	}

	/**
	 * Fixture bytes.
	 *
	 * @param string $relative Path below tests/fixtures.
	 * @return string
	 */
	private function fixture( string $relative ): string {
		return (string) file_get_contents( HDS_FIXTURES_DIR . '/' . $relative );
	}

	/**
	 * A minimal valid .docx.
	 *
	 * @param bool $with_document Include word/document.xml.
	 * @return string
	 */
	private function docx( bool $with_document = true ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ext-zip is required to build a .docx.' );
		}
		$path = $this->tmp . '/build.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', '<Types/>' );
		if ( $with_document ) {
			$zip->addFromString( 'word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>שלום</w:t></w:r></w:p></w:body></w:document>' );
		}
		$zip->close();
		$bytes = (string) file_get_contents( $path );
		unlink( $path );
		return $bytes;
	}

	public function test_dirs(): void {
		$base = $GLOBALS['hds_test']['upload_dir'];
		$this->assertSame( $base . '/hds-docs/', HDS_Storage::dir() );
		$this->assertSame( $base . '/hds-docs/cache/', HDS_Storage::cache_dir() );
	}

	public function test_ensure_protected_writes_and_repairs_files(): void {
		$this->assertTrue( HDS_Storage::ensure_protected() );
		foreach ( array( HDS_Storage::dir(), HDS_Storage::cache_dir() ) as $dir ) {
			$htaccess = (string) file_get_contents( $dir . '.htaccess' );
			$this->assertStringContainsString( "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>", $htaccess );
			$this->assertStringContainsString( "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>", $htaccess );
			$this->assertStringStartsWith( '<?php // Silence is golden.', (string) file_get_contents( $dir . 'index.php' ) );
			$web_config = (string) file_get_contents( $dir . 'web.config' );
			$this->assertStringContainsString( '<fileExtensions allowUnlisted="false" />', $web_config );
			$this->assertNotFalse( simplexml_load_string( $web_config ), 'web.config is well-formed XML.' );
		}

		unlink( HDS_Storage::dir() . '.htaccess' );
		file_put_contents( HDS_Storage::cache_dir() . 'index.php', '<?php echo "hacked";' );
		$this->assertTrue( HDS_Storage::ensure_protected() );
		$this->assertFileExists( HDS_Storage::dir() . '.htaccess' );
		$this->assertSame( HDS_Storage::INDEX_PHP, file_get_contents( HDS_Storage::cache_dir() . 'index.php' ) );
	}

	public function test_path_rejects_traversal_and_foreign_names(): void {
		$this->assertSame( HDS_Storage::dir() . 'a-12345678.doc', HDS_Storage::path( 'a-12345678.doc' ) );
		$this->assertSame( HDS_Storage::dir() . 'פסק-דין-abcdefgh.pdf', HDS_Storage::path( 'פסק-דין-abcdefgh.pdf' ) );
		foreach ( array( '', '../x.doc', '..\\x.doc', 'a/b.doc', '/etc/passwd', '.htaccess', 'index.php', 'web.config', 'x.exe', 'x.doc.php', "a\0.doc", 'c:x.doc', '..', 'doc' ) as $bad ) {
			$this->assertSame( '', HDS_Storage::path( $bad ), 'Rejected: ' . $bad );
		}
	}

	public function test_check_magic(): void {
		$doc = HDS_FIXTURES_DIR . '/SH-22-06-57879-590.doc';
		$this->assertTrue( HDS_Storage::check_magic( $doc, 'doc' ) );
		$this->assertTrue( HDS_Storage::check_magic( $doc, 'DOCX' ), 'Word containers are accepted for both extensions.' );
		$this->assertFalse( HDS_Storage::check_magic( $doc, 'pdf' ) );
		$this->assertFalse( HDS_Storage::check_magic( $doc, 'txt' ) );

		$docx = $this->tmp . '/a.docx';
		file_put_contents( $docx, $this->docx() );
		$this->assertTrue( HDS_Storage::check_magic( $docx, 'docx' ) );
		$this->assertTrue( HDS_Storage::check_magic( $docx, 'doc' ) );
		file_put_contents( $docx, $this->docx( false ) );
		$this->assertFalse( HDS_Storage::check_magic( $docx, 'docx' ), 'A zip without word/document.xml.' );

		$this->assertTrue( HDS_Storage::check_magic( HDS_FIXTURES_DIR . '/generated/SH-22-06-57879-590.pdf', 'pdf' ) );
		$this->assertTrue( HDS_Storage::check_magic( HDS_FIXTURES_DIR . '/generated/SH-22-06-57879-590.utf8.txt', 'txt' ) );
		$this->assertTrue( HDS_Storage::check_magic( HDS_FIXTURES_DIR . '/generated/SH-22-06-57879-590.cp1255.txt', 'txt' ) );
		$this->assertFalse( HDS_Storage::check_magic( HDS_FIXTURES_DIR . '/broken/random.doc', 'doc' ) );
		$this->assertFalse( HDS_Storage::check_magic( HDS_FIXTURES_DIR . '/broken/binary.txt', 'txt' ) );
		$this->assertFalse( HDS_Storage::check_magic( HDS_FIXTURES_DIR . '/broken/garbage.docx', 'docx' ) );
		$this->assertFalse( HDS_Storage::check_magic( HDS_FIXTURES_DIR . '/broken/empty.doc', 'doc' ) );
		$this->assertFalse( HDS_Storage::check_magic( $this->tmp . '/missing.doc', 'doc' ) );

		$utf16 = $this->tmp . '/u16.txt';
		file_put_contents( $utf16, "\xFF\xFE" . mb_convert_encoding( 'שלום עולם hello', 'UTF-16LE', 'UTF-8' ) );
		$this->assertTrue( HDS_Storage::check_magic( $utf16, 'txt' ) );
		file_put_contents( $utf16, mb_convert_encoding( 'פסק דין, hello world. ', 'UTF-16LE', 'UTF-8' ) );
		$this->assertTrue( HDS_Storage::check_magic( $utf16, 'txt' ), 'UTF-16 without BOM.' );
		file_put_contents( $utf16, "abc\0\0\0def\0x\0\0" );
		$this->assertFalse( HDS_Storage::check_magic( $utf16, 'txt' ) );
	}

	public function test_allowed_mimes(): void {
		$mimes = HDS_Storage::allowed_mimes();
		$this->assertSame( array( 'doc', 'docx', 'pdf', 'txt' ), array_keys( $mimes ) );
		$this->assertContains( 'application/msword', $mimes['doc'] );
		$this->assertContains( 'application/x-ole-storage', $mimes['doc'] );
		$this->assertContains( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $mimes['docx'] );
		$this->assertContains( 'application/pdf', $mimes['pdf'] );
		$this->assertContains( 'text/plain', $mimes['txt'] );
	}

	public function test_store_upload_doc(): void {
		$this->allow_local_uploads();
		$bytes = $this->fixture( 'SH-22-06-57879-590.doc' );
		$file  = $this->upload( 'SH-22-06-57879-590.doc', $bytes );

		$stored = HDS_Storage::store_upload( $file, 20 * 1048576 );
		$this->assertIsArray( $stored, is_wp_error( $stored ) ? $stored->get_error_message() : '' );
		$this->assertSame( array( 'file_name', 'original_name', 'ext', 'file_size', 'file_hash', 'title' ), array_keys( $stored ) );
		$this->assertMatchesRegularExpression( '/^SH-22-06-57879-590-[a-z0-9]{8}\.doc$/', $stored['file_name'] );
		$this->assertSame( 'SH-22-06-57879-590.doc', $stored['original_name'] );
		$this->assertSame( 'SH-22-06-57879-590', $stored['title'] );
		$this->assertSame( 'doc', $stored['ext'] );
		$this->assertSame( strlen( $bytes ), $stored['file_size'] );
		$this->assertSame( hash( 'sha256', $bytes ), $stored['file_hash'] );

		$path = HDS_Storage::path( $stored['file_name'] );
		$this->assertSame( $bytes, file_get_contents( $path ) );
		$this->assertFileExists( HDS_Storage::dir() . '.htaccess' );
		clearstatcache();
		$this->assertSame( 0640, fileperms( $path ) & 0777 );
	}

	public function test_store_upload_other_formats(): void {
		$this->allow_local_uploads();
		$cases = array(
			'פסק דין.docx'  => $this->docx(),
			'decision.pdf'  => $this->fixture( 'generated/SH-22-06-57879-590.pdf' ),
			'notes.TXT'     => $this->fixture( 'generated/SH-22-06-57879-590.cp1255.txt' ),
			'renamed.doc'   => $this->docx(),
		);
		foreach ( $cases as $name => $bytes ) {
			$stored = HDS_Storage::store_upload( $this->upload( $name, $bytes ), 20 * 1048576 );
			$this->assertIsArray( $stored, $name . ': ' . ( is_wp_error( $stored ) ? $stored->get_error_message() : '' ) );
			$this->assertFileExists( HDS_Storage::path( $stored['file_name'] ) );
		}
		$stored = HDS_Storage::store_upload( $this->upload( 'פסק דין.docx', $this->docx() ), 20 * 1048576 );
		$this->assertSame( 'פסק דין', $stored['title'] );
		$this->assertSame( 'פסק דין.docx', $stored['original_name'] );
		$this->assertMatchesRegularExpression( '/^פסק-דין-[a-z0-9]{8}\.docx$/u', $stored['file_name'] );
	}

	public function test_store_upload_requires_real_upload_without_filter(): void {
		$result = HDS_Storage::store_upload( $this->upload( 'a.txt', 'hello world' ), 1048576 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hds_upload_invalid', $result->get_error_code() );
		$this->assertFalse( is_dir( HDS_Storage::dir() ) && count( glob( HDS_Storage::dir() . '*.txt' ) ) > 0 );
	}

	public function test_store_upload_errors(): void {
		$this->allow_local_uploads();
		$max = 1048576;

		$codes = array(
			UPLOAD_ERR_INI_SIZE   => 'larger than the server allows',
			UPLOAD_ERR_FORM_SIZE  => 'larger than the server allows',
			UPLOAD_ERR_PARTIAL    => 'partially uploaded',
			UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
			UPLOAD_ERR_NO_TMP_DIR => 'temporary folder',
			UPLOAD_ERR_CANT_WRITE => 'could not write',
			UPLOAD_ERR_EXTENSION  => 'PHP extension',
		);
		foreach ( $codes as $code => $text ) {
			$result = HDS_Storage::store_upload( $this->upload( 'a.txt', 'hello', $code ), $max );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertStringContainsString( $text, $result->get_error_message() );
		}

		$cases = array(
			array( 'virus.exe', 'MZ binary', 'hds_upload_type' ),
			array( 'script.php', '<?php echo 1;', 'hds_upload_type' ),
			array( 'noext', 'hello', 'hds_upload_type' ),
			array( 'empty.txt', '', 'hds_upload_empty' ),
			array( 'big.txt', str_repeat( 'a', $max + 1 ), 'hds_upload_size' ),
			array( 'fake.pdf', 'plain text, not a pdf', 'hds_upload_content' ),
			array( 'fake.doc', 'plain text, not a doc', 'hds_upload_content' ),
			array( 'binary.txt', $this->fixture( 'broken/binary.txt' ), 'hds_upload_content' ),
			array( 'rtf.doc', '{\\rtf1\\ansi hello}', 'hds_upload_content' ),
			array( 'page.doc', '<!DOCTYPE html><html><body>x</body></html>', 'hds_upload_content' ),
			array( '', 'hello', 'hds_upload_name' ),
		);
		foreach ( $cases as $case ) {
			$result = HDS_Storage::store_upload( $this->upload( $case[0], $case[1] ), $max );
			$this->assertInstanceOf( WP_Error::class, $result, $case[0] );
			$this->assertSame( $case[2], $result->get_error_code(), $case[0] . ': ' . $result->get_error_message() );
		}

		$rtf = HDS_Storage::store_upload( $this->upload( 'rtf.doc', '{\\rtf1\\ansi hello}' ), $max );
		$this->assertStringContainsString( 'RTF', $rtf->get_error_message() );

		$missing = HDS_Storage::store_upload(
			array(
				'name'     => 'a.txt',
				'tmp_name' => $this->tmp . '/nope',
				'error'    => UPLOAD_ERR_OK,
				'size'     => 3,
			),
			$max
		);
		$this->assertSame( 'hds_upload_missing', $missing->get_error_code() );

		$this->assertSame( array(), glob( HDS_Storage::dir() . '*.{txt,doc,pdf}', GLOB_BRACE ) ?: array() );
	}

	public function test_store_upload_sanitizes_names(): void {
		$this->allow_local_uploads();
		$max = 1048576;

		$stored = HDS_Storage::store_upload( $this->upload( 'C:\\fakepath\\..\\..\\evil.txt', 'hello' ), $max );
		$this->assertSame( 'evil.txt', $stored['original_name'] );
		$this->assertMatchesRegularExpression( '/^evil-[a-z0-9]{8}\.txt$/', $stored['file_name'] );

		$stored = HDS_Storage::store_upload( $this->upload( '../../../etc/passwd.txt', 'hello' ), $max );
		$this->assertMatchesRegularExpression( '/^passwd-[a-z0-9]{8}\.txt$/', $stored['file_name'] );
		$this->assertFileExists( HDS_Storage::dir() . $stored['file_name'] );

		$stored = HDS_Storage::store_upload( $this->upload( 'shell.php.txt', 'hello' ), $max );
		$this->assertSame( 1, substr_count( $stored['file_name'], '.' ), 'Only one extension: ' . $stored['file_name'] );

		$stored = HDS_Storage::store_upload( $this->upload( '<b>title</b>.txt', 'hello' ), $max );
		$this->assertStringNotContainsString( '<', $stored['title'] );
		$this->assertStringNotContainsString( '<', $stored['file_name'] );

		$long   = str_repeat( 'החלטה ', 80 ) . '.txt';
		$stored = HDS_Storage::store_upload( $this->upload( $long, 'hello' ), $max );
		$this->assertLessThanOrEqual( HDS_Storage::MAX_NAME_BYTES, strlen( $stored['file_name'] ) );
		$this->assertTrue( mb_check_encoding( $stored['file_name'], 'UTF-8' ) );
		$this->assertMatchesRegularExpression( '/-[a-z0-9]{8}\.txt$/', $stored['file_name'] );
		$this->assertLessThanOrEqual( 255, mb_strlen( $stored['title'] ) );

		$stored = HDS_Storage::store_upload( $this->upload( '!!!.txt', 'hello' ), $max );
		$this->assertMatchesRegularExpression( '/^document-[a-z0-9]{8}\.txt$/', $stored['file_name'] );

		$a = HDS_Storage::store_upload( $this->upload( 'same.txt', 'one' ), $max );
		$b = HDS_Storage::store_upload( $this->upload( 'same.txt', 'two' ), $max );
		$this->assertNotSame( $a['file_name'], $b['file_name'] );
	}

	public function test_delete_file(): void {
		$this->allow_local_uploads();
		$stored = HDS_Storage::store_upload( $this->upload( 'a.txt', 'hello' ), 1048576 );
		$path   = HDS_Storage::path( $stored['file_name'] );
		$this->assertFileExists( $path );
		HDS_Storage::delete_file( $stored['file_name'] );
		$this->assertFileDoesNotExist( $path );

		HDS_Storage::delete_file( '.htaccess' );
		HDS_Storage::delete_file( 'index.php' );
		HDS_Storage::delete_file( '../hds-docs/.htaccess' );
		$this->assertFileExists( HDS_Storage::dir() . '.htaccess' );
		$this->assertFileExists( HDS_Storage::dir() . 'index.php' );
		HDS_Storage::delete_file( 'missing-12345678.doc' ); // No warning.
	}

	public function test_delete_all(): void {
		$this->allow_local_uploads();
		HDS_Storage::store_upload( $this->upload( 'a.txt', 'hello' ), 1048576 );
		file_put_contents( HDS_Storage::cache_dir() . 'm.bin', 'x' );
		$other = $GLOBALS['hds_test']['upload_dir'] . '/2026/keep.txt';
		wp_mkdir_p( dirname( $other ) );
		file_put_contents( $other, 'keep' );

		HDS_Storage::delete_all();
		$this->assertDirectoryDoesNotExist( HDS_Storage::dir() );
		$this->assertFileExists( $other );
		HDS_Storage::delete_all(); // Nothing left: no warning.
	}

	public function test_delete_all_does_not_follow_symlinks(): void {
		$outside = dirname( $GLOBALS['hds_test']['upload_dir'] ) . '/outside';
		wp_mkdir_p( $outside . '/sub' );
		file_put_contents( $outside . '/precious.txt', 'x' );
		file_put_contents( $outside . '/sub/precious.txt', 'x' );

		// hds-docs itself is a symlink: refuse.
		wp_mkdir_p( $GLOBALS['hds_test']['upload_dir'] );
		symlink( $outside, untrailingslashit( HDS_Storage::dir() ) );
		HDS_Storage::delete_all();
		$this->assertFileExists( $outside . '/precious.txt' );
		unlink( untrailingslashit( HDS_Storage::dir() ) );

		// A symlink inside hds-docs: removed, target kept.
		HDS_Storage::ensure_protected();
		symlink( $outside, HDS_Storage::dir() . 'link' );
		HDS_Storage::delete_all();
		$this->assertDirectoryDoesNotExist( HDS_Storage::dir() );
		$this->assertFileExists( $outside . '/precious.txt' );
		$this->assertFileExists( $outside . '/sub/precious.txt' );
	}
}
