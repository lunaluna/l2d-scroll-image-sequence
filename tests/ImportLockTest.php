<?php
/**
 * 取り込みのロック L2dsis_Sequences::acquire_import_lock() / release_import_lock() のテスト.
 *
 * 2026-10-16 の最終レビューの指摘 1: 同じシーケンスへの取り込みが重なったら、後の方を断ること.
 * flock() のロックは、同じプロセスでも別に開いたファイルどうしでは競合するので、1 つのテストの中で
 * 「先に取り込み中の処理」と「後から来た処理」を作れる.
 *
 * @package L2dScrollImageSequence
 */

/**
 * 同時の取り込みの排他制御.
 */
class ImportLockTest extends L2dsis_Test_Case {

	/**
	 * ロックを取る.
	 *
	 * @param int $post_id 投稿 ID.
	 * @return mixed 戻り値.
	 */
	private function acquire( $post_id ) {
		return $this->call_private( 'acquire_import_lock', array( $post_id ) );
	}

	/**
	 * ロックを放す.
	 *
	 * @param mixed $handle ロック.
	 * @return void
	 */
	private function release( $handle ) {
		$this->call_private( 'release_import_lock', array( $handle ) );
	}

	/**
	 * 取り込み中の投稿には、後から来た処理がロックを取れず、取り込み中のエラーになる.
	 *
	 * @return void
	 */
	public function test_second_import_is_rejected_while_locked() {
		$first = $this->acquire( 50 );
		$this->assertIsResource( $first );

		$second = $this->acquire( 50 );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'l2dsis_import_locked', $second->get_error_code() );

		$this->release( $first );
	}

	/**
	 * 先の処理がロックを放せば、次の処理は取れる.
	 *
	 * @return void
	 */
	public function test_lock_can_be_taken_again_after_release() {
		$this->release( $this->acquire( 51 ) );

		$again = $this->acquire( 51 );
		$this->assertIsResource( $again );
		$this->release( $again );
	}

	/**
	 * 別の投稿の取り込みは、互いに止めない.
	 *
	 * @return void
	 */
	public function test_other_sequences_are_independent() {
		$first = $this->acquire( 52 );
		$other = $this->acquire( 53 );

		$this->assertIsResource( $first );
		$this->assertIsResource( $other );

		$this->release( $first );
		$this->release( $other );
	}

	/**
	 * ロックのファイルは uploads のこのプラグインのフォルダの直下にでき、放しても消さない.
	 *
	 * @return void
	 */
	public function test_lock_file_is_kept_after_release() {
		$this->release( $this->acquire( 54 ) );

		$this->assertFileExists( L2dsis_Sequences::get_base_dir() . '/54.lock' );
	}
}
