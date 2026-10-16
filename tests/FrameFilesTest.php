<?php
/**
 * コマの一覧 L2dsis_Sequences::get_frame_files() / get_frame_urls() のテスト.
 *
 * @package L2dScrollImageSequence
 */

/**
 * 自然順に並べ、画像以外を除き、リクエストの中だけキャッシュすること.
 */
class FrameFilesTest extends L2dsis_Test_Case {

	/**
	 * 自然順(2 は 10 より前)に並べ、画像の拡張子以外とフォルダを除く. 拡張子の大文字・小文字は区別しない.
	 *
	 * @return void
	 */
	public function test_natural_order_and_filtering() {
		$dir = $this->make_frames( 10, array( 'f10.webp', 'f2.webp', 'f1.JPG', 'note.txt', 'f3.png' ) );
		mkdir( $dir . '/sub.webp' );

		$this->assertSame( array( 'f1.JPG', 'f2.webp', 'f3.png', 'f10.webp' ), L2dsis_Sequences::get_frame_files( 10 ) );
	}

	/**
	 * フォルダが無ければ空.
	 *
	 * @return void
	 */
	public function test_missing_folder_is_empty() {
		$this->assertSame( array(), L2dsis_Sequences::get_frame_files( 11 ) );
	}

	/**
	 * 同じリクエストの中では読み直さず、キャッシュを消すと読み直す.
	 *
	 * @return void
	 */
	public function test_request_cache_and_flush() {
		$dir = $this->make_frames( 12, array( 'a.webp' ) );
		$this->assertSame( array( 'a.webp' ), L2dsis_Sequences::get_frame_files( 12 ) );

		file_put_contents( $dir . '/b.webp', 'b' );
		$this->assertSame( array( 'a.webp' ), L2dsis_Sequences::get_frame_files( 12 ), 'キャッシュがあるうちは読み直さない' );

		L2dsis_Sequences::flush_frame_files_cache( 12 );
		$this->assertSame( array( 'a.webp', 'b.webp' ), L2dsis_Sequences::get_frame_files( 12 ) );
	}

	/**
	 * URL は uploads の URL + プラグインのフォルダ + 投稿 ID + frames/ + ファイル名(URL のエンコード付き).
	 *
	 * @return void
	 */
	public function test_frame_urls() {
		$this->make_frames( 13, array( 'frame 1.webp' ) );

		$this->assertSame(
			array( 'https://example.test/wp-content/uploads/l2d-scroll-image-sequence/13/frames/frame%201.webp' ),
			L2dsis_Sequences::get_frame_urls( 13 )
		);
	}
}
