<?php
/**
 * Autocomplete dictionary: document titles and frequent phrases.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggestions.
 *
 * Built after indexing from the indexed documents only (no OpenAI calls):
 * the titles plus 2–3-word phrases that occur in at least two documents or
 * at least three times, ranked by document count and then frequency. The
 * front end downloads the dictionary once and filters it in the browser.
 */
final class HDS_Suggest {

	/**
	 * Option name (not autoloaded).
	 */
	const OPTION = 'hds_suggest_dict';

	/**
	 * Most phrases kept.
	 */
	const MAX_PHRASES = 1500;

	/**
	 * Most titles kept.
	 */
	const MAX_TITLES = 2000;

	/**
	 * Longest phrase in characters.
	 */
	const MAX_PHRASE_CHARS = 60;

	/**
	 * Candidate count above which rare candidates are pruned while counting.
	 */
	const PRUNE_AT = 250000;

	/**
	 * Hebrew and English words that never start or end a phrase.
	 */
	const STOPWORDS = array(
		// Hebrew.
		'של', 'את', 'על', 'אם', 'לא', 'כי', 'גם', 'או', 'הוא', 'היא', 'הם', 'הן', 'זה', 'זו', 'זאת', 'אשר', 'עם', 'כל',
		'אל', 'מן', 'בין', 'יש', 'אין', 'כן', 'רק', 'עוד', 'כך', 'לכן', 'אך', 'אולם', 'ידי', 'לפי', 'שלא', 'כאשר', 'אף',
		'היה', 'היתה', 'הייתה', 'היו', 'להיות', 'יהיה', 'תהיה', 'וכן', 'וכי', 'ואם', 'כדי', 'לגבי', 'לאור', 'עד', 'אחר',
		'אחרי', 'לפני', 'מול', 'נגד', 'לו', 'לה', 'להם', 'להן', 'בו', 'בה', 'בהם', 'בהן', 'אותו', 'אותה', 'אותם', 'אותן',
		'אני', 'אנו', 'אנחנו', 'אתה', 'אתם', 'אתן', 'שלו', 'שלה', 'שלהם', 'שלי', 'שלנו', 'מה', 'מי', 'איך', 'כמו',
		'כמה', 'אלה', 'אלו', 'ולא', 'וגם', 'אבל', 'אלא', 'שכן', 'משום', 'מפני', 'בשל', 'עקב', 'לכך', 'כגון', 'כלל',
		'כבר', 'שוב', 'אז', 'פה', 'שם', 'כאן', 'יותר', 'פחות', 'מאוד', 'מאד', 'בלבד', 'ממנו', 'ממנה', 'מהם', 'עליו',
		'עליה', 'עליהם', 'אליו', 'אליה', 'אליהם', 'בעניין', 'בענין', 'ביחס', 'שבו', 'שבה', 'שבהם', 'זהו', 'זוהי', 'הנ"ל',
		'וכו', 'כאמור', 'דהיינו', 'היינו', 'ואילו', 'אולי', 'לרבות', 'למעט', 'שאין', 'שיש', 'שהוא', 'שהיא',
		'שהם', 'שזה', 'לאחר', 'במסגרת', 'בעת', 'תוך', 'בתוך', 'מתוך', 'אצל', 'בפני', 'לעיל', 'להלן',
		// English.
		'the', 'and', 'of', 'to', 'in', 'for', 'on', 'with', 'by', 'a', 'an', 'is', 'are', 'be', 'was', 'were', 'been',
		'or', 'as', 'at', 'from', 'that', 'this', 'these', 'those', 'it', 'its', 'not', 'no', 'but', 'if', 'than', 'then',
		'so', 'such', 'into', 'onto', 'over', 'under', 'which', 'who', 'whom', 'whose', 'what', 'when', 'where', 'why',
		'how', 'all', 'any', 'each', 'other', 'there', 'their', 'they', 'them', 'he', 'she', 'his', 'her', 'we', 'our',
		'you', 'your', 'i', 'my', 'me', 'us', 'has', 'have', 'had', 'do', 'does', 'did', 'will', 'would', 'shall',
		'should', 'can', 'could', 'may', 'might', 'must', 'also', 'about', 'per', 'via', 'vs', 'v',
	);

	/**
	 * Boilerplate of documents exported from Nevo (footer lines and the
	 * "copied from Nevo" watermark), removed before phrases are counted.
	 */
	const BOILERPLATE = array(
		'/נוסח מסמך זה כפוף לשינויי ניסוח ועריכה/u'           => "\n",
		'/בעניין עריכה ושינויים במסמכי פסיקה[^\n]*/u'         => "\n",
		'/הועתק מנבו/u'                                        => '',
	);

	/**
	 * Characters that end a phrase context (sentences, clauses, brackets).
	 */
	const BREAK_PATTERN = '/[\n.!?;:,()\[\]{}|\/…•·]+|\s[-–—]\s/u';

	/**
	 * Build the dictionary from titles and chunk texts (pure function).
	 *
	 * @param string[] $titles       Document titles.
	 * @param array    $texts_by_doc doc_id => chunk texts (string[]) or one text (string).
	 * @return array {titles:string[], phrases:string[]}
	 */
	public static function build_from( array $titles, array $texts_by_doc ): array {
		$counts = array();
		foreach ( $texts_by_doc as $texts ) {
			self::add_document( $counts, is_array( $texts ) ? $texts : array( (string) $texts ) );
		}
		return array(
			'titles'  => self::clean_titles( $titles ),
			'phrases' => self::rank( $counts ),
		);
	}

	/**
	 * Rebuild the stored dictionary from the indexed documents.
	 *
	 * @return void
	 */
	public static function rebuild(): void {
		$counts = array();
		HDS_Vector_Store::each_doc_texts(
			static function ( int $doc_id, array $texts ) use ( &$counts ) {
				self::add_document( $counts, $texts );
			}
		);
		$dict = array(
			'titles'  => self::clean_titles( HDS_Documents::indexed_titles() ),
			'phrases' => self::rank( $counts ),
		);
		unset( $counts );
		$json = wp_json_encode( $dict );
		$dict = array(
			'version' => md5( is_string( $json ) ? $json : serialize( $dict ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			'titles'  => $dict['titles'],
			'phrases' => $dict['phrases'],
		);
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $dict, '', 'no' );
		} else {
			update_option( self::OPTION, $dict, false );
		}
	}

	/**
	 * The stored dictionary.
	 *
	 * @return array {version:string, titles:string[], phrases:string[]}
	 */
	public static function get(): array {
		$dict = get_option( self::OPTION, array() );
		if ( ! is_array( $dict ) ) {
			$dict = array();
		}
		return array(
			'version' => isset( $dict['version'] ) && is_string( $dict['version'] ) ? $dict['version'] : '',
			'titles'  => isset( $dict['titles'] ) && is_array( $dict['titles'] ) ? array_values( array_map( 'strval', $dict['titles'] ) ) : array(),
			'phrases' => isset( $dict['phrases'] ) && is_array( $dict['phrases'] ) ? array_values( array_map( 'strval', $dict['phrases'] ) ) : array(),
		);
	}

	/**
	 * Whether a token is a stopword.
	 *
	 * @param string $token Lowercase token.
	 * @return bool
	 */
	public static function is_stopword( string $token ): bool {
		static $set = null;
		if ( null === $set ) {
			$set = array_fill_keys( self::STOPWORDS, true );
		}
		return isset( $set[ $token ] );
	}

	/**
	 * Count the phrases of one document into $counts.
	 *
	 * @param array    $counts phrase => [docs, freq] (by reference).
	 * @param string[] $texts  Chunk texts in order (overlaps are removed).
	 * @return void
	 */
	private static function add_document( array &$counts, array $texts ): void {
		$local = array();
		$prev  = '';
		foreach ( $texts as $text ) {
			$text = (string) $text;
			$new  = self::strip_overlap( $prev, $text );
			$prev = $text;
			foreach ( self::phrases_in( $new ) as $phrase ) {
				$local[ $phrase ] = isset( $local[ $phrase ] ) ? $local[ $phrase ] + 1 : 1;
			}
		}
		foreach ( $local as $phrase => $n ) {
			$phrase = (string) $phrase;
			if ( isset( $counts[ $phrase ] ) ) {
				++$counts[ $phrase ][0];
				$counts[ $phrase ][1] += $n;
			} else {
				$counts[ $phrase ] = array( 1, $n );
			}
		}
		if ( count( $counts ) > self::PRUNE_AT ) {
			foreach ( $counts as $phrase => $c ) {
				if ( $c[0] < 2 && $c[1] < 2 ) {
					unset( $counts[ $phrase ] );
				}
			}
		}
	}

	/**
	 * The part of a chunk that is not the overlap with the previous chunk.
	 *
	 * @param string $prev Previous chunk.
	 * @param string $text Chunk.
	 * @return string
	 */
	private static function strip_overlap( string $prev, string $text ): string {
		if ( '' === $prev || strlen( $text ) < 32 ) {
			return $text;
		}
		$tail  = substr( $prev, -2400 );
		$probe = substr( $text, 0, 24 );
		$pos   = strpos( $tail, $probe );
		while ( false !== $pos ) {
			$len = strlen( $tail ) - $pos;
			if ( $len <= strlen( $text ) && 0 === substr_compare( $text, substr( $tail, $pos ), 0, $len ) ) {
				return (string) substr( $text, $len );
			}
			$pos = strpos( $tail, $probe, $pos + 1 );
		}
		return $text;
	}

	/**
	 * All candidate 2–3-word phrases of a text.
	 *
	 * @param string $text Normalized text.
	 * @return string[]
	 */
	private static function phrases_in( string $text ): array {
		$out  = array();
		$text = (string) preg_replace( array_keys( self::BOILERPLATE ), array_values( self::BOILERPLATE ), $text );
		foreach ( (array) preg_split( self::BREAK_PATTERN, $text ) as $segment ) {
			$tokens = HDS_Normalizer::tokenize( (string) $segment );
			$n      = count( $tokens );
			for ( $i = 0; $i < $n - 1; $i++ ) {
				for ( $len = 2; $len <= 3 && $i + $len <= $n; $len++ ) {
					$words = array_slice( $tokens, $i, $len );
					if ( self::valid_phrase( $words ) ) {
						$out[] = implode( ' ', $words );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Phrase rules: tokens ≥ 2 characters, no stopword at either end, not
	 * only numbers, at most MAX_PHRASE_CHARS characters.
	 *
	 * @param string[] $words Tokens.
	 * @return bool
	 */
	private static function valid_phrase( array $words ): bool {
		$letters = false;
		$chars   = count( $words ) - 1;
		foreach ( $words as $word ) {
			$len = mb_strlen( $word, 'UTF-8' );
			if ( $len < 2 ) {
				return false;
			}
			$chars += $len;
			if ( ! $letters && preg_match( '/\p{L}/u', $word ) ) {
				$letters = true;
			}
		}
		if ( ! $letters || $chars > self::MAX_PHRASE_CHARS ) {
			return false;
		}
		return ! self::is_stopword( $words[0] ) && ! self::is_stopword( $words[ count( $words ) - 1 ] );
	}

	/**
	 * Keep and rank the frequent phrases.
	 *
	 * @param array $counts phrase => [docs, freq].
	 * @return string[]
	 */
	private static function rank( array $counts ): array {
		$kept = array();
		foreach ( $counts as $phrase => $c ) {
			if ( $c[0] >= 2 || $c[1] >= 3 ) {
				$kept[] = array( (string) $phrase, $c[0], $c[1] );
			}
		}
		usort(
			$kept,
			static function ( $a, $b ) {
				if ( $a[1] !== $b[1] ) {
					return $b[1] - $a[1];
				}
				if ( $a[2] !== $b[2] ) {
					return $b[2] - $a[2];
				}
				return strcmp( $a[0], $b[0] );
			}
		);
		$out  = array();
		$seen = array();
		foreach ( $kept as $item ) {
			$phrase = self::normalize( $item[0] );
			if ( '' === $phrase || isset( $seen[ $phrase ] ) ) {
				continue;
			}
			$seen[ $phrase ] = true;
			$out[]           = $phrase;
			if ( count( $out ) >= self::MAX_PHRASES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Normalized, lowercase, unique, non-empty titles.
	 *
	 * @param string[] $titles Titles.
	 * @return string[]
	 */
	private static function clean_titles( array $titles ): array {
		$out = array();
		foreach ( $titles as $title ) {
			$title = self::normalize( (string) $title );
			if ( '' !== $title ) {
				$out[ $title ] = true;
			}
			if ( count( $out ) >= self::MAX_TITLES ) {
				break;
			}
		}
		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * One-line normalized lowercase text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize( string $text ): string {
		$text = HDS_Normalizer::normalize( $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return trim( mb_strtolower( $text, 'UTF-8' ) );
	}
}
