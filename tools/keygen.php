<?php
/**
 * License key tool for the plugin vendor (command line only, never shipped).
 *
 *   php tools/keygen.php init [--force] [--key-file=PATH]
 *   php tools/keygen.php sign <domain-or-url> [--key-file=PATH]
 *   php tools/keygen.php verify <domain-or-url> <license-key> [--key-file=PATH]
 *   php tools/keygen.php pubkey [--key-file=PATH]
 *   php tools/keygen.php help
 *
 * A license key is base64( Ed25519 detached signature of the normalized
 * domain ) — see HDS_License. The private key (base64 of the 64-byte Ed25519
 * secret key) lives in tools/private.key, which is git-ignored and excluded
 * from the plugin archive.
 *
 * @package SmartDocumentSearch
 */

if ( 'cli' !== PHP_SAPI ) {
	exit; // Never runs under a web server.
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
require_once dirname( __DIR__ ) . '/includes/autoload.php';

/**
 * Command-line license key tool.
 */
final class HDS_Keygen_Cli {

	/**
	 * Exit codes.
	 */
	const OK    = 0;
	const FAIL  = 1;
	const USAGE = 2;

	/**
	 * Run a command.
	 *
	 * @param string[] $argv Arguments without the script name.
	 * @return int Exit code.
	 */
	public static function main( array $argv ): int {
		$options    = array(
			'force'    => false,
			'key-file' => __DIR__ . '/private.key',
		);
		$positional = array();

		for ( $i = 0, $n = count( $argv ); $i < $n; $i++ ) {
			$arg = (string) $argv[ $i ];
			if ( '--force' === $arg ) {
				$options['force'] = true;
			} elseif ( 0 === strpos( $arg, '--key-file=' ) ) {
				$options['key-file'] = substr( $arg, strlen( '--key-file=' ) );
			} elseif ( '--key-file' === $arg ) {
				if ( ! isset( $argv[ $i + 1 ] ) ) {
					return self::usage_error( '--key-file needs a path.' );
				}
				$options['key-file'] = (string) $argv[ ++$i ];
			} elseif ( '-h' === $arg || '--help' === $arg ) {
				$positional = array( 'help' );
				break;
			} elseif ( '--' !== $arg && 0 === strpos( $arg, '--' ) ) {
				return self::usage_error( 'Unknown option: ' . $arg );
			} else {
				$positional[] = $arg;
			}
		}
		if ( '' === $options['key-file'] ) {
			return self::usage_error( '--key-file needs a path.' );
		}

		$command = array_shift( $positional );
		if ( null === $command ) {
			self::err( self::usage() );
			return self::USAGE;
		}
		if ( 'help' === $command ) {
			self::out( self::usage() );
			return self::OK;
		}

		$expected = array(
			'init'   => 0,
			'pubkey' => 0,
			'sign'   => 1,
			'verify' => 2,
		);
		if ( ! isset( $expected[ $command ] ) ) {
			return self::usage_error( 'Unknown command: ' . $command );
		}
		if ( count( $positional ) !== $expected[ $command ] ) {
			return self::usage_error( 'Wrong number of arguments for "' . $command . '".' );
		}
		if ( 'init' !== $command && $options['force'] ) {
			return self::usage_error( '--force is only valid with init.' );
		}

		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) || ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			self::err( 'Error: the PHP sodium extension (ext-sodium, PHP 7.2+) is required to run this tool.' );
			return self::FAIL;
		}

		try {
			switch ( $command ) {
				case 'init':
					return self::init( $options['key-file'], $options['force'] );
				case 'pubkey':
					return self::pubkey( $options['key-file'] );
				case 'sign':
					return self::sign( $options['key-file'], $positional[0] );
				default:
					return self::verify( $options['key-file'], $positional[0], $positional[1] );
			}
		} catch ( \Throwable $e ) {
			self::err( 'Error: ' . $e->getMessage() );
			return self::FAIL;
		}
	}

	/**
	 * Generate a key pair and write the private key.
	 *
	 * @param string $key_file Private key path.
	 * @param bool   $force    Overwrite an existing key.
	 * @return int
	 */
	private static function init( string $key_file, bool $force ): int {
		if ( file_exists( $key_file ) && ! $force ) {
			self::err( 'Error: ' . $key_file . ' already exists. Every license issued with it would stop working with a new key.' );
			self::err( 'Use --force only if you really want to replace it.' );
			return self::FAIL;
		}
		$dir = dirname( $key_file );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			self::err( 'Error: directory ' . $dir . ' does not exist or is not writable.' );
			return self::FAIL;
		}

		$pair   = sodium_crypto_sign_keypair();
		$secret = sodium_crypto_sign_secretkey( $pair );
		$public = base64_encode( sodium_crypto_sign_publickey( $pair ) );

		// Write to a private temporary file, then move it into place.
		$tmp    = $dir . '/.' . basename( $key_file ) . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		$umask  = umask( 0077 );
		$handle = fopen( $tmp, 'x' );
		umask( $umask );
		if ( false === $handle ) {
			self::err( 'Error: cannot create ' . $tmp . '.' );
			return self::FAIL;
		}
		chmod( $tmp, 0600 );
		$data    = base64_encode( $secret ) . "\n";
		$written = fwrite( $handle, $data );
		fclose( $handle );
		if ( strlen( $data ) !== $written || ! rename( $tmp, $key_file ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			self::err( 'Error: cannot write ' . $key_file . '.' );
			return self::FAIL;
		}
		chmod( $key_file, 0600 );
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $secret );
			sodium_memzero( $pair );
		}

		self::out( 'Key pair created.' );
		self::out( '' );
		self::out( 'Private key: ' . $key_file . ' (permissions 0600)' );
		self::out( '  BACK IT UP somewhere safe and offline. Never commit, upload or share it:' );
		self::out( '  without it you cannot issue license keys that this public key accepts,' );
		self::out( '  and anyone who has it can issue license keys.' );
		self::out( '' );
		self::print_public_key( $public );
		return self::OK;
	}

	/**
	 * Print the public key of the private key file.
	 *
	 * @param string $key_file Private key path.
	 * @return int
	 */
	private static function pubkey( string $key_file ): int {
		self::print_public_key( base64_encode( sodium_crypto_sign_publickey_from_secretkey( self::load_secret( $key_file ) ) ) );
		return self::OK;
	}

	/**
	 * Issue a license key for a domain.
	 *
	 * @param string $key_file Private key path.
	 * @param string $target   Domain or URL.
	 * @return int
	 */
	private static function sign( string $key_file, string $target ): int {
		$domain = HDS_License::normalize_domain( $target );
		if ( '' === $domain ) {
			self::err( 'Error: "' . $target . '" is not a valid domain or URL.' );
			return self::FAIL;
		}
		$secret  = self::load_secret( $key_file );
		$public  = base64_encode( sodium_crypto_sign_publickey_from_secretkey( $secret ) );
		$license = base64_encode( sodium_crypto_sign_detached( $domain, $secret ) );

		if ( ! HDS_License::verify( $license, $domain, $public ) ) {
			self::err( 'Error: self-check failed: the issued key does not verify.' );
			return self::FAIL;
		}
		self::warn_about_plugin_key( $public );

		self::out( 'Domain: ' . $domain );
		self::out( 'License key: ' . $license );
		return self::OK;
	}

	/**
	 * Check a license key against the private key's public half.
	 *
	 * @param string $key_file Private key path.
	 * @param string $target   Domain or URL.
	 * @param string $license  License key.
	 * @return int
	 */
	private static function verify( string $key_file, string $target, string $license ): int {
		$domain = HDS_License::normalize_domain( $target );
		if ( '' === $domain ) {
			self::err( 'Error: "' . $target . '" is not a valid domain or URL.' );
			return self::FAIL;
		}
		$public = base64_encode( sodium_crypto_sign_publickey_from_secretkey( self::load_secret( $key_file ) ) );
		if ( HDS_License::verify( $license, $domain, $public ) ) {
			self::out( 'OK: the license key is valid for ' . $domain . '.' );
			self::warn_about_plugin_key( $public );
			return self::OK;
		}
		self::out( 'FAIL: the license key is NOT valid for ' . $domain . '.' );
		return self::FAIL;
	}

	/**
	 * Read the private key file.
	 *
	 * @param string $key_file Path.
	 * @return string 64-byte secret key.
	 * @throws RuntimeException When missing or invalid.
	 */
	private static function load_secret( string $key_file ): string {
		if ( ! is_file( $key_file ) || ! is_readable( $key_file ) ) {
			throw new RuntimeException( 'private key ' . $key_file . ' not found. Run: php tools/keygen.php init' );
		}
		$secret = base64_decode( trim( (string) file_get_contents( $key_file ) ), true );
		if ( ! is_string( $secret ) || 64 !== strlen( $secret ) ) {
			throw new RuntimeException( $key_file . ' is not a valid private key (expected base64 of 64 bytes).' );
		}
		return $secret;
	}

	/**
	 * Print the public key and the line to paste into the plugin.
	 *
	 * @param string $public Base64 public key.
	 * @return void
	 */
	private static function print_public_key( string $public ): void {
		self::out( 'Public key: ' . $public );
		self::out( '' );
		self::out( 'Paste this line into includes/class-hds-license.php (replacing the PUBLIC_KEY line):' );
		self::out( '' );
		self::out( "\tconst PUBLIC_KEY = '" . $public . "';" );
		self::out( '' );
	}

	/**
	 * Warn when the plugin's PUBLIC_KEY does not match the private key.
	 *
	 * @param string $public Base64 public key of the private key file.
	 * @return void
	 */
	private static function warn_about_plugin_key( string $public ): void {
		if ( '' === HDS_License::PUBLIC_KEY ) {
			self::err( 'Warning: HDS_License::PUBLIC_KEY is empty: the plugin accepts no license until you paste the public key (php tools/keygen.php pubkey).' );
		} elseif ( HDS_License::PUBLIC_KEY !== $public ) {
			self::err( 'Warning: HDS_License::PUBLIC_KEY does not match this private key: the plugin will reject keys signed with it.' );
		}
	}

	/**
	 * Usage text.
	 *
	 * @return string
	 */
	private static function usage(): string {
		return implode(
			"\n",
			array(
				'Smart Document Search - license key tool',
				'',
				'Usage:',
				'  php tools/keygen.php init [--force] [--key-file=PATH]',
				'      Create a new key pair. Writes the private key to tools/private.key (0600),',
				'      prints the public key line to paste into includes/class-hds-license.php.',
				'      Refuses to overwrite an existing key unless --force is given.',
				'  php tools/keygen.php sign <domain-or-url> [--key-file=PATH]',
				'      Print the license key for a site, e.g. sign https://www.example.co.il/',
				'      (scheme, port, path and one leading "www." are ignored; other subdomains',
				'      such as staging.example.co.il need their own key).',
				'  php tools/keygen.php verify <domain-or-url> <license-key> [--key-file=PATH]',
				'      Check a license key; prints OK or FAIL (exit code 0 or 1).',
				'  php tools/keygen.php pubkey [--key-file=PATH]',
				'      Print the public key line again.',
				'  php tools/keygen.php help',
				'',
			)
		);
	}

	/**
	 * Report a usage error.
	 *
	 * @param string $message Message.
	 * @return int
	 */
	private static function usage_error( string $message ): int {
		self::err( 'Error: ' . $message );
		self::err( 'Run "php tools/keygen.php help" for usage.' );
		return self::USAGE;
	}

	/**
	 * Print to stdout.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	private static function out( string $line ): void {
		fwrite( STDOUT, $line . "\n" );
	}

	/**
	 * Print to stderr.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	private static function err( string $line ): void {
		fwrite( STDERR, $line . "\n" );
	}
}

exit( HDS_Keygen_Cli::main( array_slice( $_SERVER['argv'], 1 ) ) );
