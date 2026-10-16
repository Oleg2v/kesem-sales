<?php
/**
 * Float32 vector helpers: binary packing, cosine similarity, top-N search.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vector math on embeddings.
 *
 * Embeddings are stored as little-endian float32 (pack('g*')): 4 bytes per
 * dimension, so a 1536-dimension vector takes 6 KB. top_n() scans a blob of
 * concatenated rows one row at a time and keeps only the best N scores, so
 * memory stays flat regardless of the number of rows.
 */
final class HDS_Vector {

	/**
	 * Bytes per float32 value.
	 */
	const FLOAT_BYTES = 4;

	/**
	 * Pack a vector as little-endian float32.
	 *
	 * @param array $v Numbers (keys are ignored, order is kept).
	 * @return string Binary string of count($v) * 4 bytes.
	 */
	public static function pack( array $v ): string {
		if ( array() === $v ) {
			return '';
		}
		$values = array();
		foreach ( $v as $x ) {
			$values[] = (float) $x;
		}
		return pack( 'g*', ...$values );
	}

	/**
	 * Unpack little-endian float32 values.
	 *
	 * @param string $bin Binary string; trailing bytes that do not form a whole float are ignored.
	 * @return float[] 0-indexed.
	 */
	public static function unpack( string $bin ): array {
		$count = intdiv( strlen( $bin ), self::FLOAT_BYTES );
		if ( 0 === $count ) {
			return array();
		}
		$values = unpack( 'g' . $count, $bin );
		return false === $values ? array() : array_values( $values );
	}

	/**
	 * Cosine similarity.
	 *
	 * @param array $a Vector.
	 * @param array $b Vector.
	 * @return float In [-1, 1]; 0.0 when the lengths differ, a vector is empty or has zero norm.
	 */
	public static function cosine( array $a, array $b ): float {
		$n = count( $a );
		if ( 0 === $n || count( $b ) !== $n ) {
			return 0.0;
		}
		$a   = array_values( $a );
		$b   = array_values( $b );
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$x    = (float) $a[ $i ];
			$y    = (float) $b[ $i ];
			$dot += $x * $y;
			$na  += $x * $x;
			$nb  += $y * $y;
		}
		return self::finish( $dot, $na, $nb );
	}

	/**
	 * Scale a vector to unit length.
	 *
	 * @param array $v Vector.
	 * @return float[] 0-indexed; all zeros when the norm is zero (or not finite).
	 */
	public static function normalize( array $v ): array {
		$out  = array();
		$norm = 0.0;
		foreach ( $v as $x ) {
			$x     = (float) $x;
			$out[] = $x;
			$norm += $x * $x;
		}
		$norm = sqrt( $norm );
		if ( ! ( $norm > 0.0 ) || ! is_finite( $norm ) ) {
			return array_fill( 0, count( $out ), 0.0 );
		}
		foreach ( $out as $i => $x ) {
			$out[ $i ] = $x / $norm;
		}
		return $out;
	}

	/**
	 * Best rows of a float32 matrix by cosine similarity to a query.
	 *
	 * Streams the blob: one row is unpacked at a time and only the current
	 * best N candidates are kept.
	 *
	 * @param array  $query Query vector with $dims values.
	 * @param string $blob  Concatenated little-endian float32 rows of $dims values each
	 *                      (a trailing partial row is ignored).
	 * @param int    $dims  Dimensions per row.
	 * @param int    $n     Number of results wanted.
	 * @return array<int, array{row_index: int, score: float}> Sorted by score desc, then row_index asc.
	 *         Empty when the input is invalid (dims mismatch, zero query, n < 1).
	 */
	public static function top_n( array $query, string $blob, int $dims, int $n ): array {
		if ( $dims < 1 || $n < 1 || count( $query ) !== $dims ) {
			return array();
		}

		$q  = array();
		$qn = 0.0;
		foreach ( $query as $x ) {
			$x    = (float) $x;
			$q[]  = $x;
			$qn  += $x * $x;
		}
		if ( ! ( $qn > 0.0 ) || ! is_finite( $qn ) ) {
			return array();
		}

		$row_bytes = $dims * self::FLOAT_BYTES;
		$rows      = intdiv( strlen( $blob ), $row_bytes );
		$format    = 'g' . $dims;

		// Candidates row => score; pruned back to $n whenever it reaches $cap.
		$best      = array();
		$cap       = max( 2 * $n, $n + 64 );
		$threshold = -INF;

		for ( $row = 0, $offset = 0; $row < $rows; $row++, $offset += $row_bytes ) {
			$r = unpack( $format, $blob, $offset ); // 1-indexed.
			if ( false === $r ) {
				continue;
			}
			$dot = 0.0;
			$rn  = 0.0;
			for ( $i = 0, $j = 1; $i < $dims; $i++, $j++ ) {
				$y    = $r[ $j ];
				$dot += $q[ $i ] * $y;
				$rn  += $y * $y;
			}
			$score = self::finish( $dot, $qn, $rn );
			if ( $score <= $threshold || is_nan( $score ) ) {
				continue; // Ties keep the earlier row.
			}
			$best[ $row ] = $score;
			if ( count( $best ) >= $cap ) {
				$best      = self::prune( $best, $n );
				$threshold = (float) end( $best );
			}
		}

		$best = self::prune( $best, $n );
		$out  = array();
		foreach ( $best as $row => $score ) {
			$out[] = array(
				'row_index' => (int) $row,
				'score'     => $score,
			);
		}
		return $out;
	}

	/**
	 * Cosine from a dot product and squared norms.
	 *
	 * @param float $dot Dot product.
	 * @param float $na  Squared norm of a.
	 * @param float $nb  Squared norm of b.
	 * @return float
	 */
	private static function finish( float $dot, float $na, float $nb ): float {
		if ( ! ( $na > 0.0 ) || ! ( $nb > 0.0 ) ) {
			return 0.0;
		}
		$cos = $dot / ( sqrt( $na ) * sqrt( $nb ) );
		if ( ! is_finite( $cos ) ) {
			return 0.0;
		}
		return max( -1.0, min( 1.0, $cos ) );
	}

	/**
	 * Keep the $n best candidates, sorted by score desc then row asc.
	 *
	 * @param array<int, float> $best Row => score.
	 * @param int               $n    How many to keep.
	 * @return array<int, float>
	 */
	private static function prune( array $best, int $n ): array {
		$rows   = array_keys( $best );
		$scores = array_values( $best );
		array_multisort( $scores, SORT_DESC, SORT_NUMERIC, $rows, SORT_ASC, SORT_NUMERIC );
		$kept = array();
		for ( $i = 0, $count = min( $n, count( $rows ) ); $i < $count; $i++ ) {
			$kept[ $rows[ $i ] ] = $scores[ $i ];
		}
		return $kept;
	}
}
