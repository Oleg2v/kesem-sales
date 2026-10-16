<?php
/**
 * Offline license check (Ed25519 signature of the site domain).
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * License.
 *
 * A license key is base64( Ed25519 detached signature of the normalized
 * domain ), issued with tools/keygen.php from the vendor's private key. The
 * plugin only holds the public key, so keys cannot be forged from the plugin
 * code, and the check needs no network and has no expiry. Without a valid key
 * the plugin runs in demo mode (DEMO_DOC_LIMIT documents).
 *
 * The signature functions come from ext-sodium or, without it, from the
 * sodium_compat polyfill bundled with WordPress (5.2+).
 */
final class HDS_License {

	/**
	 * Base64 Ed25519 public key (32 bytes). Empty until the owner runs
	 * `php tools/keygen.php init` and pastes the printed line here.
	 */
	const PUBLIC_KEY = '';

	/**
	 * Documents indexed in demo mode.
	 */
	const DEMO_DOC_LIMIT = 15;

	/**
	 * Ed25519 sizes in bytes.
	 */
	const SIGNATURE_BYTES  = 64;
	const PUBLIC_KEY_BYTES = 32;

	/**
	 * Per-request verification results, keyed by the inputs.
	 *
	 * @var array<string, bool>
	 */
	private static $cache = array();

	/**
	 * Normalize a URL or host name to the domain a license is issued for.
	 *
	 * Strips the scheme, userinfo, port, path, query, fragment, trailing dots
	 * and ONE leading "www."; lowercases; converts IDN to punycode when the
	 * intl extension is available. 'https://www.Example.co.il:8080/x' →
	 * 'example.co.il'. Subdomains other than www stay distinct.
	 *
	 * @param string $url_or_host URL or host name.
	 * @return string Normalized domain, '' when nothing valid is left.
	 */
	public static function normalize_domain( string $url_or_host ): string {
		$host = trim( $url_or_host );
		if ( '' === $host ) {
			return '';
		}

		// Scheme ("https://") or protocol-relative "//".
		$host = (string) preg_replace( '#^(?:[a-z][a-z0-9+.\-]*:)?//#i', '', $host );
		// Path, query, fragment.
		$cut = strcspn( $host, '/?#\\' );
		$host = substr( $host, 0, $cut );
		// Userinfo.
		$at = strrpos( $host, '@' );
		if ( false !== $at ) {
			$host = substr( $host, $at + 1 );
		}
		// Port (an IPv6 literal keeps its brackets and colons).
		if ( '' !== $host && '[' === $host[0] ) {
			$close = strpos( $host, ']' );
			$host  = false === $close ? '' : substr( $host, 0, $close + 1 );
		} else {
			$colon = strpos( $host, ':' );
			if ( false !== $colon ) {
				$host = substr( $host, 0, $colon );
			}
		}

		$host = rtrim( $host, '.' );
		if ( '' === $host || ! mb_check_encoding( $host, 'UTF-8' ) ) {
			return '';
		}
		$host = mb_strtolower( $host, 'UTF-8' );

		if ( preg_match( '/[^\x00-\x7F]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = defined( 'INTL_IDNA_VARIANT_UTS46' )
				? idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 )
				: idn_to_ascii( $host );
			if ( is_string( $ascii ) && '' !== $ascii ) {
				$host = strtolower( $ascii );
			}
		}

		if ( 0 === strpos( $host, 'www.' ) && strlen( $host ) > 4 ) {
			$host = substr( $host, 4 );
		}

		// Letters (any script, for IDN without intl), digits, dots, hyphens,
		// underscores; or a bracketed IPv6 literal.
		if ( ! preg_match( '/^(?:[\p{L}\p{M}\p{N}_\-]+(?:\.[\p{L}\p{M}\p{N}_\-]+)*|\[[0-9a-f:.]+\])$/u', $host ) ) {
			return '';
		}
		return $host;
	}

	/**
	 * Domain of this site, normalized.
	 *
	 * @return string
	 */
	public static function current_domain(): string {
		return self::normalize_domain( (string) home_url() );
	}

	/**
	 * The public key the plugin verifies licenses with.
	 *
	 * @return string Base64.
	 */
	public static function public_key(): string {
		return self::PUBLIC_KEY;
	}

	/**
	 * Verify a license key for a domain. Never throws.
	 *
	 * @param string $license_key    Base64 signature (surrounding/embedded whitespace is ignored).
	 * @param string $domain         Domain or URL; normalized before checking.
	 * @param string $public_key_b64 Base64 public key; '' = the built-in PUBLIC_KEY.
	 * @return bool
	 */
	public static function verify( string $license_key, string $domain, string $public_key_b64 = '' ): bool {
		try {
			if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				return false;
			}
			if ( '' === $public_key_b64 ) {
				$public_key_b64 = self::public_key();
			}
			$public_key = self::decode( $public_key_b64, self::PUBLIC_KEY_BYTES );
			$signature  = self::decode( $license_key, self::SIGNATURE_BYTES );
			$message    = self::normalize_domain( $domain );
			if ( '' === $public_key || '' === $signature || '' === $message ) {
				return false;
			}
			return true === sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether the stored license key is valid for this site.
	 *
	 * The result is cached for the request, keyed by the key, the domain and
	 * the public key, so a changed setting is picked up immediately.
	 *
	 * @param string $public_key_b64 Base64 public key; '' (always, outside unit tests) = the built-in PUBLIC_KEY.
	 * @return bool
	 */
	public static function is_active( string $public_key_b64 = '' ): bool {
		$key        = self::stored_key();
		$domain     = self::current_domain();
		$public_key = '' !== $public_key_b64 ? $public_key_b64 : self::public_key();
		if ( '' === trim( $key ) || '' === $domain || '' === $public_key ) {
			return false;
		}

		$cache_key = hash( 'sha256', $public_key . '|' . $domain . '|' . $key );
		if ( ! isset( self::$cache[ $cache_key ] ) ) {
			self::$cache[ $cache_key ] = self::verify( $key, $domain, $public_key );
		}
		return self::$cache[ $cache_key ];
	}

	/**
	 * Whether the plugin runs in demo mode.
	 *
	 * @param string $public_key_b64 Base64 public key; '' (always, outside unit tests) = the built-in PUBLIC_KEY.
	 * @return bool
	 */
	public static function is_demo( string $public_key_b64 = '' ): bool {
		return ! self::is_active( $public_key_b64 );
	}

	/**
	 * Forget cached verification results (after the license key changes).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cache = array();
	}

	/**
	 * License key saved in the settings.
	 *
	 * @return string
	 */
	private static function stored_key(): string {
		if ( class_exists( 'HDS_Settings' ) ) {
			$key = HDS_Settings::get( 'license_key' );
		} else {
			$settings = get_option( 'hds_settings', array() );
			$key      = is_array( $settings ) && isset( $settings['license_key'] ) ? $settings['license_key'] : '';
		}
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Decode strict base64 (standard or URL-safe alphabet, whitespace ignored)
	 * of an exact length.
	 *
	 * @param string $b64   Base64 text.
	 * @param int    $bytes Expected decoded length.
	 * @return string Decoded bytes, '' when invalid.
	 */
	private static function decode( string $b64, int $bytes ): string {
		$b64 = (string) preg_replace( '/\s+/', '', $b64 );
		if ( '' === $b64 || strlen( $b64 ) > 4 * $bytes ) {
			return '';
		}
		$raw = base64_decode( strtr( $b64, '-_', '+/' ), true );
		return ( is_string( $raw ) && strlen( $raw ) === $bytes ) ? $raw : '';
	}
}
