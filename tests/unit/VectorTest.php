<?php
/**
 * Tests for HDS_Vector.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Vector helper tests.
 */
class VectorTest extends TestCase {

	protected function setUp(): void {
		hds_test_reset();
		mt_srand( 42 );
	}

	/**
	 * Random vector with values in [-1, 1].
	 *
	 * @param int $dims Dimensions.
	 * @return float[]
	 */
	private function random_vector( int $dims ): array {
		$v = array();
		for ( $i = 0; $i < $dims; $i++ ) {
			$v[] = mt_rand() / mt_getrandmax() * 2 - 1;
		}
		return $v;
	}

	/**
	 * Naive reference: unpack every row, score, full sort.
	 *
	 * @param float[] $query Query.
	 * @param string  $blob  Rows.
	 * @param int     $dims  Dimensions.
	 * @param int     $n     N.
	 * @return array
	 */
	private function naive_top_n( array $query, string $blob, int $dims, int $n ): array {
		$all  = array();
		$rows = intdiv( strlen( $blob ), $dims * 4 );
		for ( $r = 0; $r < $rows; $r++ ) {
			$row   = array_values( unpack( 'g*', substr( $blob, $r * $dims * 4, $dims * 4 ) ) );
			$dot   = 0.0;
			$nq    = 0.0;
			$nr    = 0.0;
			foreach ( $query as $i => $x ) {
				$dot += $x * $row[ $i ];
				$nq  += $x * $x;
				$nr  += $row[ $i ] * $row[ $i ];
			}
			$all[] = array(
				'row_index' => $r,
				'score'     => ( $nq > 0 && $nr > 0 ) ? $dot / ( sqrt( $nq ) * sqrt( $nr ) ) : 0.0,
			);
		}
		usort(
			$all,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $a['row_index'] <=> $b['row_index'];
				}
				return $b['score'] <=> $a['score'];
			}
		);
		return array_slice( $all, 0, $n );
	}

	public function test_pack_is_little_endian_float32(): void {
		$this->assertSame( "\x00\x00\x80\x3F", HDS_Vector::pack( array( 1.0 ) ) );
		$this->assertSame( "\x00\x00\x00\xC0", HDS_Vector::pack( array( -2 ) ) );
		$this->assertSame( 12, strlen( HDS_Vector::pack( array( 0.1, 0.2, 0.3 ) ) ) );
		$this->assertSame( '', HDS_Vector::pack( array() ) );
		// Keys are ignored, order is kept.
		$this->assertSame( HDS_Vector::pack( array( 1.0, 2.0 ) ), HDS_Vector::pack( array( 5 => 1.0, 2 => 2.0 ) ) );
	}

	public function test_unpack_round_trip_within_float32_precision(): void {
		$v   = $this->random_vector( 1536 );
		$bin = HDS_Vector::pack( $v );
		$this->assertSame( 1536 * 4, strlen( $bin ) );

		$back = HDS_Vector::unpack( $bin );
		$this->assertSame( range( 0, 1535 ), array_keys( $back ) );
		foreach ( $v as $i => $x ) {
			$this->assertIsFloat( $back[ $i ] );
			$this->assertEqualsWithDelta( $x, $back[ $i ], 1e-7 );
		}
		// Re-packing float32 values is lossless.
		$this->assertSame( $bin, HDS_Vector::pack( $back ) );

		$this->assertSame( array(), HDS_Vector::unpack( '' ) );
		$this->assertSame( array(), HDS_Vector::unpack( 'abc' ) );
		$this->assertCount( 1, HDS_Vector::unpack( HDS_Vector::pack( array( 3.5 ) ) . "\x01\x02" ) );
		$this->assertSame( array( 3.5 ), HDS_Vector::unpack( HDS_Vector::pack( array( 3.5 ) ) ) );
	}

	public function test_cosine(): void {
		$this->assertEqualsWithDelta( 1.0, HDS_Vector::cosine( array( 1, 2, 3 ), array( 2, 4, 6 ) ), 1e-12 );
		$this->assertEqualsWithDelta( -1.0, HDS_Vector::cosine( array( 1, 2, 3 ), array( -1, -2, -3 ) ), 1e-12 );
		$this->assertEqualsWithDelta( 0.0, HDS_Vector::cosine( array( 1, 0 ), array( 0, 1 ) ), 1e-12 );
		$this->assertEqualsWithDelta( 1 / sqrt( 2 ), HDS_Vector::cosine( array( 1, 0 ), array( 1, 1 ) ), 1e-12 );

		$this->assertSame( 0.0, HDS_Vector::cosine( array( 1, 2 ), array( 1, 2, 3 ) ) );
		$this->assertSame( 0.0, HDS_Vector::cosine( array(), array() ) );
		$this->assertSame( 0.0, HDS_Vector::cosine( array( 0, 0 ), array( 1, 1 ) ) );
		$this->assertSame( 0.0, HDS_Vector::cosine( array( NAN, 1 ), array( 1, 1 ) ) );

		$v = $this->random_vector( 300 );
		$c = HDS_Vector::cosine( $v, $v );
		$this->assertLessThanOrEqual( 1.0, $c );
		$this->assertEqualsWithDelta( 1.0, $c, 1e-12 );
	}

	public function test_normalize(): void {
		$n = HDS_Vector::normalize( array( 3, 4 ) );
		$this->assertEqualsWithDelta( 0.6, $n[0], 1e-12 );
		$this->assertEqualsWithDelta( 0.8, $n[1], 1e-12 );
		$this->assertSame( array( 0.0, 0.0, 0.0 ), HDS_Vector::normalize( array( 0, 0, 0 ) ) );
		$this->assertSame( array(), HDS_Vector::normalize( array() ) );
		$this->assertSame( array( 0, 1 ), array_keys( HDS_Vector::normalize( array( 'a' => 1, 'b' => 1 ) ) ) );

		$v    = HDS_Vector::normalize( $this->random_vector( 1536 ) );
		$norm = 0.0;
		foreach ( $v as $x ) {
			$norm += $x * $x;
		}
		$this->assertEqualsWithDelta( 1.0, $norm, 1e-12 );
	}

	public function test_top_n_matches_naive_implementation(): void {
		foreach ( array( array( 8, 50, 5 ), array( 64, 500, 10 ), array( 256, 300, 1 ), array( 32, 200, 150 ), array( 16, 7, 20 ) ) as $case ) {
			list( $dims, $rows, $n ) = $case;
			$blob                    = '';
			for ( $r = 0; $r < $rows; $r++ ) {
				$blob .= HDS_Vector::pack( $this->random_vector( $dims ) );
			}
			$query    = $this->random_vector( $dims );
			$expected = $this->naive_top_n( $query, $blob, $dims, $n );
			$actual   = HDS_Vector::top_n( $query, $blob, $dims, $n );

			$this->assertCount( min( $n, $rows ), $actual, "dims=$dims rows=$rows n=$n" );
			$this->assertSame( array_column( $expected, 'row_index' ), array_column( $actual, 'row_index' ), "dims=$dims rows=$rows n=$n" );
			foreach ( $actual as $i => $hit ) {
				$this->assertSame( array( 'row_index', 'score' ), array_keys( $hit ) );
				$this->assertIsInt( $hit['row_index'] );
				$this->assertIsFloat( $hit['score'] );
				$this->assertEqualsWithDelta( $expected[ $i ]['score'], $hit['score'], 1e-9 );
				if ( $i > 0 ) {
					$this->assertGreaterThanOrEqual( $hit['score'], $actual[ $i - 1 ]['score'] );
				}
			}
		}
	}

	public function test_top_n_finds_planted_rows_and_breaks_ties_by_row(): void {
		$dims  = 32;
		$query = $this->random_vector( $dims );
		$rows  = array();
		for ( $r = 0; $r < 400; $r++ ) {
			$rows[] = $this->random_vector( $dims );
		}
		// Exact copies of the query (scaling by 2 is exact in float32): equal scores, ties → lower row first.
		$rows[ 300 ] = array_map(
			static function ( $x ) {
				return $x * 2;
			},
			$query
		);
		$rows[ 17 ]  = $query;
		$rows[ 250 ] = $query;
		$blob        = '';
		foreach ( $rows as $row ) {
			$blob .= HDS_Vector::pack( $row );
		}
		$top = HDS_Vector::top_n( $query, $blob, $dims, 3 );
		$this->assertSame( array( 17, 250, 300 ), array_column( $top, 'row_index' ) );
		foreach ( $top as $hit ) {
			$this->assertEqualsWithDelta( 1.0, $hit['score'], 1e-6 );
		}
	}

	public function test_top_n_edge_cases(): void {
		$blob = HDS_Vector::pack( array( 1, 0 ) ) . HDS_Vector::pack( array( 0, 0 ) ) . HDS_Vector::pack( array( 0, 1 ) );

		$this->assertSame( array(), HDS_Vector::top_n( array( 1, 0 ), '', 2, 5 ) );
		$this->assertSame( array(), HDS_Vector::top_n( array( 1, 0 ), $blob, 2, 0 ) );
		$this->assertSame( array(), HDS_Vector::top_n( array( 1, 0, 0 ), $blob, 2, 5 ), 'Query dims mismatch.' );
		$this->assertSame( array(), HDS_Vector::top_n( array( 1, 0 ), $blob, 0, 5 ) );
		$this->assertSame( array(), HDS_Vector::top_n( array( 0, 0 ), $blob, 2, 5 ), 'Zero query.' );

		$top = HDS_Vector::top_n( array( 1, 0 ), $blob . "\x00\x00", 2, 5 ); // Trailing partial row ignored.
		$this->assertSame( array( 0, 1, 2 ), array_column( $top, 'row_index' ) );
		$this->assertSame( array( 1.0, 0.0, 0.0 ), array_column( $top, 'score' ) );

		// Negative similarity sorts last.
		$top = HDS_Vector::top_n( array( 1, 0 ), $blob . HDS_Vector::pack( array( -1, 0 ) ), 2, 4 );
		$this->assertSame( array( 0, 1, 2, 3 ), array_column( $top, 'row_index' ) );
		$this->assertSame( -1.0, $top[3]['score'] );

		// Non-finite rows score 0 and never break the ordering.
		$bad = HDS_Vector::pack( array( NAN, 1 ) ) . HDS_Vector::pack( array( INF, 1 ) ) . HDS_Vector::pack( array( 2, 0 ) );
		$top = HDS_Vector::top_n( array( 1, 0 ), $bad, 2, 3 );
		$this->assertSame( 2, $top[0]['row_index'] );
		foreach ( $top as $hit ) {
			$this->assertTrue( is_finite( $hit['score'] ) );
		}
	}

	public function test_top_n_performance_and_memory(): void {
		$dims = 1536;
		$rows = 3000;
		$blob = '';
		for ( $r = 0; $r < $rows; $r++ ) {
			$blob .= HDS_Vector::pack( $this->random_vector( $dims ) );
		}
		$this->assertSame( $rows * $dims * 4, strlen( $blob ) );
		$query = $this->random_vector( $dims );

		gc_collect_cycles();
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$before = memory_get_usage();
		$t0     = microtime( true );
		$top    = HDS_Vector::top_n( $query, $blob, $dims, 10 );
		$time   = microtime( true ) - $t0;
		$peak   = memory_get_peak_usage() - $before;

		$this->assertCount( 10, $top );
		$this->assertLessThan( 3.0, $time, 'top_n over 3000 x 1536 must take < 3 s.' );
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			$this->assertLessThan( 20 * 1024 * 1024, $peak, 'top_n must stream the blob.' );
		}

		// Spot-check against the naive implementation on the same data.
		$this->assertSame(
			array_column( $this->naive_top_n( $query, $blob, $dims, 10 ), 'row_index' ),
			array_column( $top, 'row_index' )
		);
	}
}
