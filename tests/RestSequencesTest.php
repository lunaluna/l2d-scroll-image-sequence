<?php
/**
 * 編集画面がシーケンスの一覧を取る REST ルート L2dsis_Rest::get_sequences() のテスト.
 *
 * 2026-10-15 のレビューの指摘 5: 返すのは「公開」と、そのユーザーが編集できるものだけ.
 *
 * @package L2dScrollImageSequence
 */

/**
 * 一覧に出すシーケンスと、返す項目.
 */
class RestSequencesTest extends L2dsis_Test_Case {

	/**
	 * テスト用の投稿.
	 *
	 * @param int    $id     投稿 ID.
	 * @param string $status 状態.
	 * @param string $title  タイトル.
	 * @return object 投稿の代わり.
	 */
	private function post( $id, $status, $title ) {
		return (object) array(
			'ID'          => $id,
			'post_status' => $status,
			'post_title'  => $title,
		);
	}

	/**
	 * 公開と、編集できる下書きだけを返す. ほかの人の下書きは返さない.
	 *
	 * @return void
	 */
	public function test_lists_published_and_editable() {
		$this->make_frames( 41, array( 'a.webp', 'b.webp' ) );
		$GLOBALS['l2dsis_test']['posts']    = array(
			$this->post( 41, 'publish', 'Published' ),
			$this->post( 42, 'draft', 'My draft' ),
			$this->post( 43, 'draft', 'Someone else draft' ),
		);
		$GLOBALS['l2dsis_test']['can_edit'] = array( 42 );

		$items = L2dsis_Rest::get_sequences();

		$this->assertSame( array( 41, 42 ), array_column( $items, 'id' ) );
		$this->assertSame(
			array(
				'id'          => 41,
				'title'       => 'Published',
				'status'      => 'publish',
				'frames'      => 2,
				'first_frame' => 'https://example.test/wp-content/uploads/l2d-scroll-image-sequence/41/frames/a.webp',
			),
			$items[0]
		);
		$this->assertSame( 0, $items[1]['frames'] );
		$this->assertSame( '', $items[1]['first_frame'] );
	}

	/**
	 * タイトルが空なら「(no title) #ID」にする.
	 *
	 * @return void
	 */
	public function test_empty_title() {
		$GLOBALS['l2dsis_test']['posts'] = array( $this->post( 44, 'publish', '' ) );

		$items = L2dsis_Rest::get_sequences();

		$this->assertSame( '(no title) #44', $items[0]['title'] );
	}
}
