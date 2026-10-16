<?php
/**
 * Tests for HDS_Suggest.
 *
 * @package SmartDocumentSearch
 */

use PHPUnit\Framework\TestCase;

/**
 * Suggestion dictionary tests.
 */
class SuggestTest extends TestCase {

	protected function setUp(): void {
		hds_test_reset();
		hds_test_create_tables();
	}

	public function test_phrases_in_two_documents_are_kept(): void {
		$dict = HDS_Suggest::build_from(
			array(),
			array(
				1 => array( 'בית המשפט העליון דחה את הערעור. הנאשם הורשע בעבירת גניבה.' ),
				2 => array( 'בפני בית המשפט העליון הוגשה עתירה. הוראות חוק העונשין חלות.' ),
			)
		);
		$this->assertContains( 'בית המשפט', $dict['phrases'] );
		$this->assertContains( 'בית המשפט העליון', $dict['phrases'] );
		$this->assertContains( 'המשפט העליון', $dict['phrases'] );
		$this->assertNotContains( 'דחה את הערעור', $dict['phrases'], 'Only in one document, once.' );
		$this->assertNotContains( 'העליון דחה', $dict['phrases'] );
		$this->assertSame( array(), $dict['titles'] );
	}

	public function test_three_occurrences_in_one_document_are_kept(): void {
		$dict = HDS_Suggest::build_from(
			array(),
			array(
				7 => array( 'עורך הדין טען. עורך הדין השיב. עורך הדין סיכם. התובע ויתר.' ),
			)
		);
		$this->assertContains( 'עורך הדין', $dict['phrases'] );
		$this->assertNotContains( 'התובע ויתר', $dict['phrases'] );
	}

	public function test_stopwords_numbers_and_short_tokens_are_rejected(): void {
		$text = 'של בית הדין. בית הדין של. ב 2020 12 34 נקבע. 12 34 ו 12 34 x. the court of appeal. the court of appeal.';
		$dict = HDS_Suggest::build_from( array(), array( 1 => $text, 2 => $text ) );
		$this->assertContains( 'בית הדין', $dict['phrases'] );
		$this->assertContains( 'court of appeal', $dict['phrases'] );
		foreach ( $dict['phrases'] as $phrase ) {
			$words = explode( ' ', $phrase );
			$this->assertFalse( HDS_Suggest::is_stopword( $words[0] ), $phrase );
			$this->assertFalse( HDS_Suggest::is_stopword( end( $words ) ), $phrase );
			$this->assertDoesNotMatchRegularExpression( '/^[\d\s]+$/u', $phrase, 'Not purely numeric.' );
			foreach ( $words as $word ) {
				$this->assertGreaterThanOrEqual( 2, mb_strlen( $word ), $phrase );
			}
			$this->assertLessThanOrEqual( 60, mb_strlen( $phrase ) );
		}
		$this->assertNotContains( 'of appeal', $dict['phrases'] );
		$this->assertNotContains( 'the court', $dict['phrases'] );
	}

	public function test_phrases_do_not_cross_sentences(): void {
		$text = 'הערעור נדחה. המערער ישלם הוצאות.';
		$dict = HDS_Suggest::build_from( array(), array( 1 => $text, 2 => $text ) );
		$this->assertContains( 'הערעור נדחה', $dict['phrases'] );
		$this->assertContains( 'ישלם הוצאות', $dict['phrases'] );
		$this->assertNotContains( 'נדחה המערער', $dict['phrases'] );
	}

	public function test_ranking_and_normalization(): void {
		$dict = HDS_Suggest::build_from(
			array( 'פְּסַק דין  A', 'פסק דין a', '', 'Second Title' ),
			array(
				1 => 'Alpha Beta. Gamma Delta. Gamma Delta.',
				2 => 'Alpha Beta. Gamma Delta.',
				3 => 'Alpha Beta.',
			)
		);
		$this->assertSame( array( 'alpha beta', 'gamma delta' ), $dict['phrases'], 'More documents first, lowercase.' );
		$this->assertSame( array( 'פסק דין a', 'second title' ), $dict['titles'], 'Nikud removed, lowercased, deduplicated.' );
	}

	public function test_overlap_between_chunks_is_counted_once(): void {
		$first  = 'הוראות שונות בעניין זה. בית משפט השלום בתל אביב קבע את הדברים הבאים בפסק הדין.';
		$second = 'בית משפט השלום בתל אביב קבע את הדברים הבאים בפסק הדין. אין צו להוצאות.';
		$dict   = HDS_Suggest::build_from( array(), array( 1 => array( $first, $second ) ) );
		$this->assertNotContains( 'משפט השלום', $dict['phrases'], 'Seen twice only because of the chunk overlap.' );

		$dict = HDS_Suggest::build_from( array(), array( 1 => array( $first, $second, 'עוד. ' . $second ) ) );
		$this->assertNotContains( 'משפט השלום', $dict['phrases'], 'Two real occurrences.' );

		$dict = HDS_Suggest::build_from( array(), array( 1 => array( $first, $second, 'עוד. ' . $second, 'שוב. ' . $second ) ) );
		$this->assertContains( 'משפט השלום', $dict['phrases'], 'Three real occurrences.' );
	}

	public function test_nevo_boilerplate_is_ignored(): void {
		$footer = "נוסח מסמך זה כפוף לשינויי ניסוח ועריכה\nבעניין עריכה ושינויים במסמכי פסיקה, חקיקה ועוד באתר נבו - הקש כאן";
		$dict   = HDS_Suggest::build_from(
			array(),
			array(
				1 => "13. הועתק מנבוטענותיה של העוררת נדחו.\n" . $footer,
				2 => "14. טענותיה של העוררת התקבלו.\n" . $footer,
			)
		);
		$this->assertContains( 'טענותיה של העוררת', $dict['phrases'], 'The watermark glued to a word is removed.' );
		foreach ( $dict['phrases'] as $phrase ) {
			$this->assertDoesNotMatchRegularExpression( '/ניסוח|נבו|עריכה|הועתק/u', $phrase );
		}
	}

	public function test_max_phrases(): void {
		$texts = array();
		for ( $doc = 0; $doc < 2; $doc++ ) {
			$parts = array();
			for ( $i = 0; $i < 1700; $i++ ) {
				$parts[] = 'מילה' . $i . ' אחרת' . $i;
			}
			$texts[ $doc ] = implode( '. ', $parts );
		}
		$dict = HDS_Suggest::build_from( array(), $texts );
		$this->assertCount( HDS_Suggest::MAX_PHRASES, $dict['phrases'] );
		$this->assertSame( count( $dict['phrases'] ), count( array_unique( $dict['phrases'] ) ) );
	}

	public function test_get_empty(): void {
		$this->assertSame(
			array(
				'version' => '',
				'titles'  => array(),
				'phrases' => array(),
			),
			HDS_Suggest::get()
		);
		update_option( HDS_Suggest::OPTION, 'garbage' );
		$this->assertSame( array(), HDS_Suggest::get()['titles'] );
	}

	public function test_rebuild_uses_indexed_documents_only(): void {
		$store = new HDS_Vector_Store();
		$texts = array(
			'אישור הסדר הפשרה התקבל. בית הדין הארצי לעבודה דן בעניין.',
			'בית הדין הארצי לעבודה החליט. אישור הסדר הפשרה ניתן היום.',
		);
		$ids   = array();
		foreach ( array( 'החלטה ראשונה', 'החלטה שנייה', 'טיוטה' ) as $i => $title ) {
			$id = HDS_Documents::insert(
				array(
					'file_name'     => 'f' . $i . '-abcdefgh.txt',
					'original_name' => 'f' . $i . '.txt',
					'ext'           => 'txt',
					'file_size'     => 1,
					'file_hash'     => hash( 'sha256', (string) $i ),
					'title'         => $title,
				)
			);
			$store->replace_chunks( $id, array( $texts[ $i % 2 ] ) );
			$ids[] = $id;
		}
		HDS_Documents::set_status( $ids[0], HDS_Documents::STATUS_INDEXED );
		HDS_Documents::set_status( $ids[1], HDS_Documents::STATUS_INDEXED );
		// Third document: pending; its text must not count.
		$store->replace_chunks( $ids[2], array( 'מסמך סודי ביותר. מסמך סודי ביותר. מסמך סודי ביותר.' ) );

		HDS_Suggest::rebuild();
		$dict = HDS_Suggest::get();
		$this->assertSame( array( 'החלטה ראשונה', 'החלטה שנייה' ), $dict['titles'] );
		$this->assertContains( 'אישור הסדר הפשרה', $dict['phrases'] );
		$this->assertContains( 'הארצי לעבודה', $dict['phrases'] );
		$this->assertNotContains( 'מסמך סודי', $dict['phrases'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $dict['version'] );

		$version = $dict['version'];
		HDS_Suggest::rebuild();
		$this->assertSame( $version, HDS_Suggest::get()['version'], 'Same content, same version.' );

		HDS_Documents::set_status( $ids[1], HDS_Documents::STATUS_ERROR, 'x' );
		HDS_Suggest::rebuild();
		$this->assertNotSame( $version, HDS_Suggest::get()['version'] );
		$this->assertSame( array( 'החלטה ראשונה' ), HDS_Suggest::get()['titles'] );
	}
}
