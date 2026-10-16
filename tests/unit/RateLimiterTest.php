<?php
/**
 * Tests for HDS_Rate_Limiter.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Rate limiter tests (test clock shared with the transient stubs).
 */
class RateLimiterTest extends TestCase {

	/**
	 * Original $_SERVER['REMOTE_ADDR'].
	 *
	 * @var mixed
	 */
	private $remote_addr;

	protected function setUp(): void {
		hds_test_reset();
		hds_test_set_time( 1700000000 );
		$this->remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
	}

	protected function tearDown(): void {
		if ( null === $this->remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}
	}

	/**
	 * Limiter using the stub clock.
	 *
	 * @param int $limit Limit per minute.
	 * @return HDS_Rate_Limiter
	 */
	private function limiter( int $limit ): HDS_Rate_Limiter {
		return new HDS_Rate_Limiter(
			$limit,
			static function () {
				return hds_test_now();
			}
		);
	}

	/**
	 * Advance the stub clock.
	 *
	 * @param int $seconds Seconds.
	 * @return void
	 */
	private function advance( int $seconds ): void {
		hds_test_set_time( hds_test_now() + $seconds );
	}

	public function test_allows_up_to_the_limit_then_blocks(): void {
		$limiter = $this->limiter( 3 );
		$this->assertSame( 0, $limiter->retry_after( '1.2.3.4' ) );
		$this->assertTrue( $limiter->hit( '1.2.3.4' ) );
		$this->assertTrue( $limiter->hit( '1.2.3.4' ) );
		$this->assertTrue( $limiter->hit( '1.2.3.4' ) );
		$this->assertFalse( $limiter->hit( '1.2.3.4' ) );
		$this->assertFalse( $limiter->hit( '1.2.3.4' ) );
		$this->assertSame( 60, $limiter->retry_after( '1.2.3.4' ) );
	}

	public function test_fixed_window_storage(): void {
		$limiter = $this->limiter( 5 );
		$limiter->hit( '1.2.3.4' );

		$this->assertCount( 1, $GLOBALS['hds_test']['transients'] );
		$name = array_keys( $GLOBALS['hds_test']['transients'] )[0];
		$this->assertSame( 'hds_rl_' . substr( hash_hmac( 'sha256', '1.2.3.4', wp_salt( 'nonce' ) ), 0, 32 ), $name );
		$this->assertStringNotContainsString( '1.2.3.4', $name, 'The IP is not stored.' );
		list( $value, $expires ) = $GLOBALS['hds_test']['transients'][ $name ];
		$this->assertSame(
			array(
				'count' => 1,
				'start' => 1700000000,
			),
			$value
		);
		$this->assertSame( 1700000000 + 60, $expires );

		// Later hits count in the same window and keep its end.
		$this->advance( 20 );
		$limiter->hit( '1.2.3.4' );
		list( $value, $expires ) = $GLOBALS['hds_test']['transients'][ $name ];
		$this->assertSame(
			array(
				'count' => 2,
				'start' => 1700000000,
			),
			$value
		);
		$this->assertSame( 1700000000 + 60, $expires );
	}

	public function test_window_resets_after_60_seconds(): void {
		$limiter = $this->limiter( 2 );
		$this->assertTrue( $limiter->hit( '10.0.0.1' ) );
		$this->advance( 30 );
		$this->assertTrue( $limiter->hit( '10.0.0.1' ) );
		$this->assertFalse( $limiter->hit( '10.0.0.1' ) );
		$this->assertSame( 30, $limiter->retry_after( '10.0.0.1' ) );

		$this->advance( 29 );
		$this->assertFalse( $limiter->hit( '10.0.0.1' ) );
		$this->assertSame( 1, $limiter->retry_after( '10.0.0.1' ) );

		$this->advance( 1 ); // 60 s after the first hit: new window.
		$this->assertSame( 0, $limiter->retry_after( '10.0.0.1' ) );
		$this->assertTrue( $limiter->hit( '10.0.0.1' ) );
		$this->assertTrue( $limiter->hit( '10.0.0.1' ) );
		$this->assertFalse( $limiter->hit( '10.0.0.1' ) );
		$this->assertSame( 60, $limiter->retry_after( '10.0.0.1' ) );
	}

	public function test_stale_transient_is_ignored(): void {
		$limiter = $this->limiter( 1 );
		$limiter->hit( '10.0.0.2' );
		// Simulate a transient that outlived its window (e.g. object cache without expiry).
		$name                                         = array_keys( $GLOBALS['hds_test']['transients'] )[0];
		$GLOBALS['hds_test']['transients'][ $name ][1] = 0;
		$this->advance( 61 );
		$this->assertTrue( $limiter->hit( '10.0.0.2' ) );

		// Garbage values are ignored too.
		$GLOBALS['hds_test']['transients'][ $name ] = array( 'garbage', 0 );
		$this->assertTrue( $limiter->hit( '10.0.0.2' ) );
		// A window "from the future" (clock skew) does not block.
		$GLOBALS['hds_test']['transients'][ $name ] = array(
			array(
				'count' => 99,
				'start' => hds_test_now() + 30,
			),
			0,
		);
		$this->assertTrue( $limiter->hit( '10.0.0.2' ) );
	}

	public function test_different_ips_are_independent(): void {
		$limiter = $this->limiter( 1 );
		$this->assertTrue( $limiter->hit( '1.1.1.1' ) );
		$this->assertFalse( $limiter->hit( '1.1.1.1' ) );
		$this->assertTrue( $limiter->hit( '1.1.1.2' ) );
		$this->assertTrue( $limiter->hit( '2001:db8:1::1' ) );
		$this->assertTrue( $limiter->hit( '2001:db8:2::1' ) );
		$this->assertFalse( $limiter->hit( '2001:db8:1::1' ) );
		$this->assertSame( 0, $limiter->retry_after( '9.9.9.9' ) );
	}

	public function test_ipv6_is_bucketed_by_64_and_canonicalized(): void {
		$limiter = $this->limiter( 2 );
		$this->assertTrue( $limiter->hit( '2001:db8:aa:bb::1' ) );
		// Same /64, different interface id and spelling.
		$this->assertTrue( $limiter->hit( '2001:0DB8:00aa:00bb:ffff:1:2:3' ) );
		$this->assertFalse( $limiter->hit( '2001:db8:aa:bb::9' ) );
		$this->assertGreaterThan( 0, $limiter->retry_after( '2001:db8:aa:bb::1234' ) );
		// Another /64.
		$this->assertTrue( $limiter->hit( '2001:db8:aa:bc::1' ) );

		// IPv4-mapped IPv6 is the IPv4 client.
		$this->assertTrue( $limiter->hit( '192.0.2.7' ) );
		$this->assertTrue( $limiter->hit( '::ffff:192.0.2.7' ) );
		$this->assertFalse( $limiter->hit( '192.0.2.7' ) );
	}

	public function test_limit_is_at_least_one(): void {
		$limiter = $this->limiter( 0 );
		$this->assertTrue( $limiter->hit( '3.3.3.3' ) );
		$this->assertFalse( $limiter->hit( '3.3.3.3' ) );
	}

	public function test_default_clock_uses_time(): void {
		hds_test_set_time( null );
		$limiter = new HDS_Rate_Limiter( 1 );
		$this->assertTrue( $limiter->hit( '4.4.4.4' ) );
		$this->assertFalse( $limiter->hit( '4.4.4.4' ) );
		$retry = $limiter->retry_after( '4.4.4.4' );
		$this->assertGreaterThanOrEqual( 59, $retry );
		$this->assertLessThanOrEqual( 60, $retry );
	}

	public function test_now_can_be_overridden_by_subclass(): void {
		$limiter = new class( 1 ) extends HDS_Rate_Limiter {
			/**
			 * Fixed clock.
			 *
			 * @var int
			 */
			public $t = 1000;

			protected function now(): int {
				return $this->t;
			}
		};
		hds_test_set_time( 1000 );
		$this->assertTrue( $limiter->hit( '5.5.5.5' ) );
		$this->assertFalse( $limiter->hit( '5.5.5.5' ) );
		$limiter->t = 1060;
		hds_test_set_time( 1060 );
		$this->assertTrue( $limiter->hit( '5.5.5.5' ) );
	}

	public function test_client_ip(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame( '203.0.113.9', HDS_Rate_Limiter::client_ip() );

		$_SERVER['REMOTE_ADDR'] = ' 2001:db8::5 ';
		$this->assertSame( '2001:db8::5', HDS_Rate_Limiter::client_ip() );

		foreach ( array( 'garbage', '1.2.3.4, 5.6.7.8', '999.1.1.1', '', '<script>' ) as $bad ) {
			$_SERVER['REMOTE_ADDR'] = $bad;
			$this->assertSame( '0.0.0.0', HDS_Rate_Limiter::client_ip(), $bad );
		}
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( '0.0.0.0', HDS_Rate_Limiter::client_ip() );
	}

	public function test_client_ip_filter(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$seen                   = array();
		$GLOBALS['hds_test']['filters']['hds_client_ip'][] = static function ( $ip ) use ( &$seen ) {
			$seen[] = $ip;
			return '198.51.100.23';
		};
		$this->assertSame( '198.51.100.23', HDS_Rate_Limiter::client_ip() );
		$this->assertSame( array( '10.0.0.1' ), $seen );

		// Invalid filtered values are ignored.
		$GLOBALS['hds_test']['filters']['hds_client_ip'] = array(
			static function () {
				return 'not-an-ip';
			},
		);
		$this->assertSame( '10.0.0.1', HDS_Rate_Limiter::client_ip() );
		$GLOBALS['hds_test']['filters']['hds_client_ip'] = array(
			static function () {
				return array( '1.2.3.4' );
			},
		);
		$this->assertSame( '10.0.0.1', HDS_Rate_Limiter::client_ip() );
	}
}
