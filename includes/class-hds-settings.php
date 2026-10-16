<?php
/**
 * Plugin settings (one autoloaded option) and the encrypted API key.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings.
 *
 * All settings live in the `hds_settings` option. The OpenAI API key is read
 * from the HDS_OPENAI_KEY constant when defined; otherwise it is stored
 * encrypted (libsodium secretbox, key derived from the site's auth salt) in
 * `api_key_enc`. The plaintext key is never returned to the settings form,
 * never stored in the option and never logged.
 */
final class HDS_Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'hds_settings';

	/**
	 * Default values (also the list of known keys).
	 */
	const DEFAULTS = array(
		'embedding_model' => 'text-embedding-3-small',
		'chat_model'      => 'gpt-4o-mini',
		'top_n'           => 5,
		'rate_limit'      => 10,
		'max_file_mb'     => 20,
		'license_key'     => '',
		'api_key_enc'     => '',
	);

	/**
	 * Settings errors are reported under this setting slug.
	 */
	const ERROR_SLUG = 'hds_settings';

	/**
	 * Valid model name.
	 */
	const MODEL_PATTERN = '/^[a-z0-9][a-z0-9._:\-]{1,63}$/i';

	/**
	 * Something that looks like an OpenAI API key.
	 */
	const API_KEY_PATTERN = '/^[A-Za-z0-9_\-]{20,200}$/';

	/**
	 * Longest accepted license key (base64 of a 64-byte signature is 88 chars).
	 */
	const LICENSE_MAX_LENGTH = 200;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * All settings merged with the defaults, with the expected types.
	 *
	 * @return array
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$out = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			$value = array_key_exists( $key, $stored ) ? $stored[ $key ] : $default;
			if ( is_int( $default ) ) {
				$value = is_numeric( $value ) ? (int) $value : $default;
			} else {
				$value = is_scalar( $value ) ? (string) $value : $default;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed Null for unknown keys.
	 */
	public static function get( string $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Save settings (internal use): merges the given known keys into the
	 * current values. Values are validated by sanitize() when WordPress runs
	 * the registered sanitize callback; unknown keys are ignored.
	 *
	 * @param array $values Key => value.
	 * @return void
	 */
	public static function update( array $values ): void {
		$merged = array_merge( self::all(), array_intersect_key( $values, self::DEFAULTS ) );
		update_option( self::OPTION, $merged );
	}

	/**
	 * Sanitize callback of register_setting().
	 *
	 * Idempotent and safe to call twice in one request (WordPress does so
	 * when the option is created): it starts from the current values, takes
	 * the submitted ones that are valid, and returns only known keys, never
	 * the plaintext API key. Form-only fields: `api_key_new` (encrypted into
	 * api_key_enc) and `api_key_remove`. An `api_key_enc` value present in the
	 * input (internal saves) is kept as-is.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$current = self::all();
		if ( ! is_array( $input ) ) {
			return $current;
		}
		$out      = $current;
		$internal = array_key_exists( 'api_key_enc', $input );

		// Models.
		foreach ( array( 'embedding_model', 'chat_model' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = is_scalar( $input[ $key ] ) ? trim( sanitize_text_field( (string) $input[ $key ] ) ) : '';
			if ( preg_match( self::MODEL_PATTERN, $value ) ) {
				$out[ $key ] = $value;
			} else {
				self::add_error(
					'hds_invalid_' . $key,
					'embedding_model' === $key
						? __( 'The embedding model name is not valid. The previous value was kept.', 'smart-document-search' )
						: __( 'The chat model name is not valid. The previous value was kept.', 'smart-document-search' )
				);
			}
		}

		// Numbers (clamped to their ranges).
		$max_mb = max( 1, (int) floor( self::wp_max_upload_bytes() / 1048576 ) );
		$ranges = array(
			'top_n'       => array( 1, 20 ),
			'rate_limit'  => array( 1, 1000 ),
			'max_file_mb' => array( 1, $max_mb ),
		);
		foreach ( $ranges as $key => $range ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$raw = is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( '' === $raw || ! is_numeric( $raw ) ) {
				continue;
			}
			$out[ $key ] = (int) min( $range[1], max( $range[0], (int) round( (float) $raw ) ) );
		}

		// License key: whitespace removed, base64 alphabet only.
		if ( array_key_exists( 'license_key', $input ) ) {
			$license = is_scalar( $input['license_key'] ) ? (string) preg_replace( '/\s+/', '', (string) $input['license_key'] ) : '';
			if ( '' !== $license && ( strlen( $license ) > self::LICENSE_MAX_LENGTH || ! preg_match( '#^[A-Za-z0-9+/=_\-]+$#', $license ) ) ) {
				self::add_error( 'hds_invalid_license_format', __( 'The license key contains invalid characters. The previous value was kept.', 'smart-document-search' ) );
			} else {
				$out['license_key'] = $license;
				$changed            = $license !== $current['license_key'];
				if ( '' !== $license && ( $changed || ! $internal ) && class_exists( 'HDS_License' ) ) {
					$domain = HDS_License::current_domain();
					if ( ! HDS_License::verify( $license, $domain ) ) {
						self::add_error(
							'hds_invalid_license',
							sprintf(
								/* translators: %s: site domain. */
								__( 'License key is not valid for %s. The plugin stays in demo mode.', 'smart-document-search' ),
								$domain
							)
						);
					}
				}
			}
		}

		// API key.
		if ( $internal ) {
			$out['api_key_enc'] = is_string( $input['api_key_enc'] ) ? $input['api_key_enc'] : $current['api_key_enc'];
		}
		if ( ! empty( $input['api_key_remove'] ) ) {
			$out['api_key_enc'] = '';
		}
		if ( isset( $input['api_key_new'] ) && is_scalar( $input['api_key_new'] ) ) {
			$new = trim( (string) $input['api_key_new'] );
			if ( '' !== $new ) {
				if ( ! preg_match( self::API_KEY_PATTERN, $new ) ) {
					self::add_error( 'hds_invalid_api_key', __( 'The OpenAI API key does not look valid. The previous key was kept.', 'smart-document-search' ) );
				} else {
					$encrypted = self::encrypt( $new );
					if ( '' === $encrypted ) {
						self::add_error( 'hds_api_key_encrypt', __( 'The OpenAI API key could not be stored securely (libsodium is not available). Define HDS_OPENAI_KEY in wp-config.php instead.', 'smart-document-search' ) );
					} else {
						$out['api_key_enc'] = $encrypted;
					}
				}
			}
			unset( $new );
		}

		// Side effects of changes.
		if ( $out['embedding_model'] !== $current['embedding_model'] ) {
			if ( class_exists( 'HDS_Documents' ) ) {
				HDS_Documents::reset_indexed();
			}
			if ( class_exists( 'HDS_Vector_Store' ) ) {
				HDS_Vector_Store::bump_version();
			}
		}
		if ( $out['license_key'] !== $current['license_key'] && class_exists( 'HDS_License' ) ) {
			HDS_License::reset_cache();
		}

		return $out;
	}

	/**
	 * The OpenAI API key: the HDS_OPENAI_KEY constant when defined and not
	 * empty, else the decrypted stored key, else ''.
	 *
	 * @return string
	 */
	public static function get_api_key(): string {
		$constant = self::constant_key();
		if ( '' !== $constant ) {
			return $constant;
		}
		$enc = (string) self::get( 'api_key_enc' );
		return '' === $enc ? '' : self::decrypt( $enc );
	}

	/**
	 * Where the API key comes from.
	 *
	 * @return string 'constant', 'option' or 'none'.
	 */
	public static function api_key_source(): string {
		if ( '' !== self::constant_key() ) {
			return 'constant';
		}
		$enc = (string) self::get( 'api_key_enc' );
		if ( '' !== $enc && '' !== self::decrypt( $enc ) ) {
			return 'option';
		}
		return 'none';
	}

	/**
	 * Masked API key for display, e.g. 'sk-…a1b2'; '' when there is no key.
	 *
	 * @return string
	 */
	public static function mask_api_key(): string {
		$key = self::get_api_key();
		if ( '' === $key ) {
			return '';
		}
		$prefix = 0 === strpos( $key, 'sk-' ) ? 'sk-' : '';
		$tail   = strlen( $key ) >= 12 ? substr( $key, -4 ) : '';
		return $prefix . '…' . $tail;
	}

	/**
	 * Encrypt a secret: base64( nonce . secretbox ).
	 *
	 * @param string $plain Plaintext.
	 * @return string '' when libsodium is unavailable or $plain is empty.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! self::has_sodium() ) {
			return '';
		}
		try {
			$key    = self::crypto_key();
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
			self::wipe( $key );
			return base64_encode( $nonce . $cipher );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Decrypt a value made by encrypt().
	 *
	 * @param string $cipher Base64 ciphertext.
	 * @return string '' on any failure (wrong salt, tampering, bad input).
	 */
	public static function decrypt( string $cipher ): string {
		if ( '' === $cipher || ! self::has_sodium() ) {
			return '';
		}
		try {
			$raw = base64_decode( $cipher, true );
			if ( ! is_string( $raw ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
				return '';
			}
			$key   = self::crypto_key();
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$key
			);
			self::wipe( $key );
			return is_string( $plain ) ? $plain : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Fingerprint of everything that changes the stored chunks/vectors.
	 *
	 * @return string
	 */
	public static function index_fingerprint(): string {
		return md5(
			implode(
				'|',
				array(
					(string) self::get( 'embedding_model' ),
					(string) HDS_Chunker::SIZE,
					(string) HDS_Chunker::OVERLAP,
					(string) HDS_Extractor_Factory::VERSION,
				)
			)
		);
	}

	/**
	 * Largest accepted upload in bytes: min( max_file_mb, wp_max_upload_size() ).
	 *
	 * @return int
	 */
	public static function max_upload_bytes(): int {
		$setting = max( 1, (int) self::get( 'max_file_mb' ) ) * 1048576;
		$server  = self::wp_max_upload_bytes();
		return $server > 0 ? (int) min( $setting, $server ) : $setting;
	}

	/**
	 * Server upload limit in bytes (0 = unknown).
	 *
	 * @return int
	 */
	private static function wp_max_upload_bytes(): int {
		return function_exists( 'wp_max_upload_size' ) ? max( 0, (int) wp_max_upload_size() ) : 0;
	}

	/**
	 * The HDS_OPENAI_KEY constant, trimmed ('' when not defined).
	 *
	 * @return string
	 */
	private static function constant_key(): string {
		if ( defined( 'HDS_OPENAI_KEY' ) && is_string( constant( 'HDS_OPENAI_KEY' ) ) ) {
			return trim( (string) constant( 'HDS_OPENAI_KEY' ) );
		}
		return '';
	}

	/**
	 * Whether the secretbox functions exist (ext-sodium or WordPress' sodium_compat).
	 *
	 * @return bool
	 */
	private static function has_sodium(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& function_exists( 'sodium_crypto_generichash' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_MACBYTES' );
	}

	/**
	 * Encryption key derived from the site's auth salt.
	 *
	 * @return string
	 */
	private static function crypto_key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|hds-openai-key', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Overwrite a secret in memory when possible.
	 *
	 * @param string $secret Secret (by reference).
	 * @return void
	 */
	private static function wipe( string &$secret ): void {
		if ( function_exists( 'sodium_memzero' ) ) {
			try {
				sodium_memzero( $secret );
			} catch ( \Throwable $e ) {
				$secret = '';
			}
		} else {
			$secret = '';
		}
	}

	/**
	 * Add a settings error once per request (sanitize() may run twice).
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return void
	 */
	private static function add_error( string $code, string $message ): void {
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}
		if ( function_exists( 'get_settings_errors' ) ) {
			foreach ( (array) get_settings_errors( self::ERROR_SLUG ) as $error ) {
				if ( is_array( $error ) && isset( $error['code'], $error['message'] ) && $code === $error['code'] && $message === $error['message'] ) {
					return;
				}
			}
		}
		add_settings_error( self::ERROR_SLUG, $code, $message, 'error' );
	}
}
