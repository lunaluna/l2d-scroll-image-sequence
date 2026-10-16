<?php
/**
 * テスト用の $wp_filesystem. 実際のファイル操作で動き、決めた回の move() を失敗させられる.
 *
 * コマの入れ替え(L2dsis_Sequences::swap_frames())が、途中で失敗したときに古いコマを戻すかを試すために使う.
 *
 * @package L2dScrollImageSequence
 */

/**
 * WP_Filesystem_Direct の代わり.
 */
class L2dsis_Test_Filesystem {

	/**
	 * これまでに呼ばれた move() の回数.
	 *
	 * @var int
	 */
	public $move_calls = 0;

	/**
	 * 失敗させる move() の回(1 から数える). 0 なら失敗させない.
	 *
	 * @var int
	 */
	public $fail_move_at = 0;

	/**
	 * ファイルかフォルダがあるか.
	 *
	 * @param string $path パス.
	 * @return bool あれば true.
	 */
	public function exists( $path ) {
		return file_exists( $path );
	}

	/**
	 * 名前を変える(移す). fail_move_at の回は何もせず false を返す.
	 *
	 * @param string $source      元.
	 * @param string $destination 先.
	 * @return bool 成功したら true.
	 */
	public function move( $source, $destination ) {
		++$this->move_calls;
		if ( $this->move_calls === $this->fail_move_at ) {
			return false;
		}
		return file_exists( $source ) && ! file_exists( $destination ) && rename( $source, $destination );
	}

	/**
	 * ファイルかフォルダを消す.
	 *
	 * @param string $path      パス.
	 * @param bool   $recursive フォルダの中身ごと消すか.
	 * @return bool 消したら true.
	 */
	public function delete( $path, $recursive = false ) {
		if ( is_file( $path ) || is_link( $path ) ) {
			return unlink( $path );
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}
		if ( $recursive ) {
			foreach ( scandir( $path ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					$this->delete( $path . '/' . $name, true );
				}
			}
		}
		return rmdir( $path );
	}

	/**
	 * 権限を変える. テストでは何もしない.
	 *
	 * @param string $path パス.
	 * @param int    $mode 権限.
	 * @return bool 常に true.
	 */
	public function chmod( $path, $mode ) {
		return true;
	}
}
