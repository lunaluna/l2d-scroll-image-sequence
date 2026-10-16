<?php
/**
 * コマの入れ替え L2dsis_Sequences::swap_frames() のテスト.
 *
 * 2026-10-15 のレビューの指摘 3: 古いコマは、新しいコマが入るまで消さず、失敗したら戻すこと.
 * move() の呼ばれる順番: 1 = 一時フォルダ → frames-new/(move_dir() のスタブ経由), 2 = frames/ → frames-old/,
 * 3 = frames-new/ → frames/. テスト用の $wp_filesystem で、決めた回の move() を失敗させる.
 *
 * @package L2dScrollImageSequence
 */

/**
 * 入れ替えの成功と、途中の失敗.
 */
class SwapFramesTest extends L2dsis_Test_Case {

	/**
	 * 投稿 ID.
	 */
	const POST_ID = 20;

	/**
	 * 古いコマ(frames/)と、新しいコマ(一時フォルダの frames/)を用意する.
	 *
	 * @return array{0: string, 1: string} 一時フォルダと、シーケンスのフォルダ.
	 */
	private function prepare() {
		$this->make_frames( self::POST_ID, array( 'old-1.webp', 'old-2.webp' ) );

		$work_dir = $this->tmp_dir . '/work';
		mkdir( $work_dir . '/frames', 0777, true );
		file_put_contents( $work_dir . '/frames/new-1.webp', 'n' );

		return array( $work_dir, L2dsis_Sequences::get_dir( self::POST_ID ) );
	}

	/**
	 * シーケンスのフォルダの中のフォルダとファイルの一覧.
	 *
	 * @param string $sequence_dir シーケンスのフォルダ.
	 * @return array<string, string[]> フォルダ名とファイル名.
	 */
	private function snapshot( $sequence_dir ) {
		$result = array();
		foreach ( array( 'frames', 'frames-new', 'frames-old' ) as $folder ) {
			if ( is_dir( $sequence_dir . '/' . $folder ) ) {
				$files = array_values( array_diff( scandir( $sequence_dir . '/' . $folder ), array( '.', '..' ) ) );
				sort( $files );
				$result[ $folder ] = $files;
			}
		}
		return $result;
	}

	/**
	 * 成功したら新しいコマだけが残り、frames-new/・frames-old/ は残らない.
	 *
	 * @return void
	 */
	public function test_success_replaces_frames() {
		list( $work_dir, $sequence_dir ) = $this->prepare();

		$this->assertTrue( $this->call_private( 'swap_frames', array( $work_dir, $sequence_dir ) ) );
		$this->assertSame( array( 'frames' => array( 'new-1.webp' ) ), $this->snapshot( $sequence_dir ) );
	}

	/**
	 * 初めての取り込み(古い frames/ が無い)でも入る.
	 *
	 * @return void
	 */
	public function test_first_import() {
		$work_dir = $this->tmp_dir . '/work';
		mkdir( $work_dir . '/frames', 0777, true );
		file_put_contents( $work_dir . '/frames/new-1.webp', 'n' );
		$sequence_dir = L2dsis_Sequences::get_dir( self::POST_ID );
		mkdir( $sequence_dir, 0777, true );

		$this->assertTrue( $this->call_private( 'swap_frames', array( $work_dir, $sequence_dir ) ) );
		$this->assertSame( array( 'frames' => array( 'new-1.webp' ) ), $this->snapshot( $sequence_dir ) );
	}

	/**
	 * 失敗させる move() の回ごとに、古いコマがそのまま残る.
	 *
	 * @return array<string, array{0: int}>
	 */
	public function failing_moves() {
		return array(
			'1 新しいコマを frames-new/ へ'  => array( 1 ),
			'2 古いコマを frames-old/ へ'    => array( 2 ),
			'3 frames-new/ を frames/ へ' => array( 3 ),
		);
	}

	/**
	 * どの段階で失敗しても、古いコマが frames/ に残り、frames-new/・frames-old/ は残らない.
	 *
	 * @dataProvider failing_moves
	 *
	 * @param int $fail_at 失敗させる move() の回.
	 * @return void
	 */
	public function test_failure_keeps_old_frames( $fail_at ) {
		list( $work_dir, $sequence_dir ) = $this->prepare();
		$GLOBALS['wp_filesystem']->fail_move_at = $fail_at;

		$result = $this->call_private( 'swap_frames', array( $work_dir, $sequence_dir ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'frames' => array( 'old-1.webp', 'old-2.webp' ) ), $this->snapshot( $sequence_dir ) );
	}

	/**
	 * 前回が途中で止まって frames-old/ だけが残っていれば、それを frames/ に戻してから入れ替える.
	 *
	 * @return void
	 */
	public function test_recovers_leftover_old_frames() {
		$this->make_frames( self::POST_ID, array( 'old-1.webp' ), 'frames-old' );
		$this->make_frames( self::POST_ID, array( 'half.webp' ), 'frames-new' );

		$work_dir = $this->tmp_dir . '/work';
		mkdir( $work_dir . '/frames', 0777, true );
		file_put_contents( $work_dir . '/frames/new-1.webp', 'n' );
		$sequence_dir = L2dsis_Sequences::get_dir( self::POST_ID );

		// 片付けの move()(frames-old/ → frames/)が 1 回目になるので、3 回目(= 退避)を失敗させて古いコマが戻ることを見る.
		$GLOBALS['wp_filesystem']->fail_move_at = 3;
		$result = $this->call_private( 'swap_frames', array( $work_dir, $sequence_dir ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'frames' => array( 'old-1.webp' ) ), $this->snapshot( $sequence_dir ) );
	}
}
