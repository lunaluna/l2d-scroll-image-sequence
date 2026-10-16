<?php
/**
 * フロントに表示してよいシーケンス L2dsis_Sequences::get_sequence_frame_urls() のテスト.
 *
 * 2026-10-15 のレビューの指摘 5: 表示するのは「公開」だけ. プレビューのときだけ、編集できる人には下書きなども出す.
 *
 * @package L2dScrollImageSequence
 */

/**
 * 状態とプレビューと権限の組み合わせ.
 */
class SequenceVisibilityTest extends L2dsis_Test_Case {

	/**
	 * 投稿 ID.
	 */
	const POST_ID = 30;

	/**
	 * 状態の組み合わせ: 状態・プレビューか・編集できるか・表示するか.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool}>
	 */
	public function cases() {
		return array(
			'公開'                    => array( 'publish', false, false, true ),
			'下書き'                   => array( 'draft', false, true, false ),
			'下書き・プレビュー・編集できる'      => array( 'draft', true, true, true ),
			'下書き・プレビュー・編集できない'     => array( 'draft', true, false, false ),
			'非公開・プレビュー・編集できる'      => array( 'private', true, true, true ),
			'ゴミ箱・プレビュー・編集できる'      => array( 'trash', true, true, false ),
		);
	}

	/**
	 * 組み合わせごとに、表示するか.
	 *
	 * @dataProvider cases
	 *
	 * @param string $status     状態.
	 * @param bool   $is_preview プレビューか.
	 * @param bool   $can_edit   編集できるか.
	 * @param bool   $visible    表示するか.
	 * @return void
	 */
	public function test_visibility( $status, $is_preview, $can_edit, $visible ) {
		$this->make_frames( self::POST_ID, array( 'a.webp' ) );
		$GLOBALS['l2dsis_test']['post_types'][ self::POST_ID ]  = L2dsis_Sequences::POST_TYPE;
		$GLOBALS['l2dsis_test']['post_status'][ self::POST_ID ] = $status;
		$GLOBALS['l2dsis_test']['is_preview']                   = $is_preview;
		$GLOBALS['l2dsis_test']['can_edit']                     = $can_edit ? array( self::POST_ID ) : array();

		$this->assertCount( $visible ? 1 : 0, L2dsis_Sequences::get_sequence_frame_urls( self::POST_ID ) );
	}

	/**
	 * 別の投稿タイプの ID や 0 は表示しない.
	 *
	 * @return void
	 */
	public function test_other_post_type_and_zero() {
		$this->make_frames( self::POST_ID, array( 'a.webp' ) );
		$GLOBALS['l2dsis_test']['post_types'][ self::POST_ID ]  = 'post';
		$GLOBALS['l2dsis_test']['post_status'][ self::POST_ID ] = 'publish';

		$this->assertSame( array(), L2dsis_Sequences::get_sequence_frame_urls( self::POST_ID ) );
		$this->assertSame( array(), L2dsis_Sequences::get_sequence_frame_urls( 0 ) );
	}
}
