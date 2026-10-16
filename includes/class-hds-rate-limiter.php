<?php
/**
 * Per-IP fixed-window rate limiter for the public search endpoint.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limiter.
 *
 * Fixed 60-second window per client, stored in a transient named
 * 'hds_rl_' . first 32 hex chars of HMAC-SHA256( client, wp_salt('nonce') ):
 * the IP address itself is never stored. IPv6 clients are bucketed by their
 * /64 network (one subscriber usually owns a whole /64, so per-address limits
 * would be trivial to evade); IPv4-mapped IPv6 addresses count as IPv4.
 */
class HDS_Rate_Limiter {

	/**
	 * Window length in seconds.
	 */
	const WINDOW = 60;

	/**
	 * Transient name prefix.
	 */
	const PREFIX = 'hds_rl_';

	/**
	 * Allowed requests per window.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Clock: callable(): int, or null for time().
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param int           $limit_per_minute Allowed requests per minute (min 1).
	 * @param callable|null $clock            Optional clock returning a Unix timestamp (tests).
	 */
	public function __construct( int $limit_per_minute, $clock = null ) {
		$this->limit = max( 1, $limit_per_minute );
		$this->clock = is_callable( $clock ) ? $clock : null;
	}

	/**
	 * Count a request.
	 *
	 * @param string $ip Client IP.
	 * @return bool True when allowed, false when over the limit (not counted).
	 */
	public function hit( string $ip ): bool {
		$key   = $this->key( $ip );
		$now   = $this->now();
		$state = $this->state( $key, $now );

		if ( null === $state ) {
			set_transient(
				$key,
				array(
					'count' => 1,
					'start' => $now,
				),
				self::WINDOW
			);
			return true;
		}
		if ( $state['count'] >= $this->limit ) {
			return false;
		}
		++$state['count'];
		// Keep the window fixed: expire when it ends.
		set_transient( $key, $state, max( 1, self::WINDOW - ( $now - $state['start'] ) ) );
		return true;
	}

	/**
	 * Seconds until the current window of a client ends.
	 *
	 * @param string $ip Client IP.
	 * @return int 1..60, or 0 when no window is open.
	 */
	public function retry_after( string $ip ): int {
		$now   = $this->now();
		$state = $this->state( $this->key( $ip ), $now );
		if ( null === $state ) {
			return 0;
		}
		return max( 1, min( self::WINDOW, self::WINDOW - ( $now - $state['start'] ) ) );
	}

	/**
	 * Client IP from REMOTE_ADDR ('0.0.0.0' when missing or invalid).
	 *
	 * Behind a reverse proxy or CDN, REMOTE_ADDR is the proxy: use the
	 * 'hds_client_ip' filter to return the real client IP from a header the
	 * proxy sets (only trust such headers from your own proxy). An invalid
	 * filtered value is ignored.
	 *
	 * @return string
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '0.0.0.0';
		}
		$filtered = apply_filters( 'hds_client_ip', $ip );
		if ( is_string( $filtered ) && false !== filter_var( trim( $filtered ), FILTER_VALIDATE_IP ) ) {
			$ip = trim( $filtered );
		}
		return $ip;
	}

	/**
	 * Current time.
	 *
	 * @return int
	 */
	protected function now(): int {
		return null !== $this->clock ? (int) call_user_func( $this->clock ) : time();
	}

	/**
	 * Current window of a client, or null when none is open.
	 *
	 * @param string $key Transient name.
	 * @param int    $now Current time.
	 * @return array{count: int, start: int}|null
	 */
	private function state( string $key, int $now ): ?array {
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['count'], $state['start'] ) ) {
			return null;
		}
		$start = (int) $state['start'];
		// Expired window (transient not purged yet) or a clock that went backwards.
		if ( $now - $start >= self::WINDOW || $start > $now ) {
			return null;
		}
		return array(
			'count' => max( 0, (int) $state['count'] ),
			'start' => $start,
		);
	}

	/**
	 * Transient name for a client.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private function key( string $ip ): string {
		return self::PREFIX . substr( hash_hmac( 'sha256', self::bucket( $ip ), wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Canonical rate-limit bucket of an address: IPv4 as is, IPv6 by /64.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private static function bucket( string $ip ): string {
		$ip = strtolower( trim( $ip ) );
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return $ip;
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) || ! function_exists( 'inet_pton' ) ) {
			return $ip;
		}
		$bin = inet_pton( $ip );
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return $ip;
		}
		// IPv4-mapped (::ffff:a.b.c.d): the IPv4 client.
		if ( str_repeat( "\0", 10 ) . "\xFF\xFF" === substr( $bin, 0, 12 ) ) {
			return implode( '.', array_map( 'ord', str_split( substr( $bin, 12 ) ) ) );
		}
		return bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
	}
}
