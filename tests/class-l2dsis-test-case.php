<?php
/**
 * テストの共通の親クラス.
 *
 * テストごとに、スタブの状態・コマの一覧のキャッシュ・$wp_filesystem を戻し、一時フォルダを作って消す.
 *
 * @package L2dScrollImageSequence
 */

use PHPUnit\Framework\TestCase;

/**
 * 共通の親クラス.
 */
abstract class L2dsis_Test_Case extends TestCase {

	/**
	 * このテストの一時フォルダ(uploads の代わり).
	 *
	 * @var string
	 */
	protected $tmp_dir;

	/**
	 * テストの前に、状態を初期値に戻し、一時フォルダを作る.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->tmp_dir = sys_get_temp_dir() . '/l2dsis-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp_dir, 0777, true );

		$GLOBALS['l2dsis_test']               = l2dsis_test_default_state();
		$GLOBALS['l2dsis_test']['upload_dir'] = $this->tmp_dir;
		$GLOBALS['wp_filesystem']             = new L2dsis_Test_Filesystem();

		// コマの一覧の、リクエストの中だけのキャッシュを空にする(テストどうしで混ざらないように).
		$cache = new ReflectionProperty( 'L2dsis_Sequences', 'frame_files_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, array() );

		// お知らせのトークン(リクエストごと)を空にする. テストどうしを別のリクエストとして扱うため.
		$this->reset_notice_token();

		// 「ページの中で最初のブロックか」と、1 コマ目の先読みの登録を空にする(テストどうしを別のページとして扱う).
		foreach ( array(
			'first_block_claimed' => false,
			'preloads'            => array(),
		) as $name => $value ) {
			$property = new ReflectionProperty( 'L2dsis_Sequences', $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	/**
	 * お知らせのトークンを空にする(次の保存を、別のリクエストとして扱う).
	 *
	 * @return void
	 */
	protected function reset_notice_token() {
		$token = new ReflectionProperty( 'L2dsis_Sequences', 'notice_token' );
		$token->setAccessible( true );
		$token->setValue( null, '' );
	}

	/**
	 * テストのあとで、一時フォルダを消す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		( new L2dsis_Test_Filesystem() )->delete( $this->tmp_dir, true );
		parent::tearDown();
	}

	/**
	 * L2dsis_Sequences の private な静的メソッドを呼ぶ.
	 *
	 * @param string $method メソッド名.
	 * @param array  $args   引数.
	 * @return mixed 戻り値.
	 */
	protected function call_private( $method, array $args ) {
		$reflection = new ReflectionMethod( 'L2dsis_Sequences', $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	/**
	 * シーケンスの frames/ に、名前だけのファイルを作る(中身は空).
	 *
	 * @param int      $post_id 投稿 ID.
	 * @param string[] $names   ファイル名.
	 * @param string   $folder  frames/ の代わりに作るフォルダ名.
	 * @return string 作ったフォルダのパス.
	 */
	protected function make_frames( $post_id, array $names, $folder = 'frames' ) {
		$dir = L2dsis_Sequences::get_dir( $post_id ) . '/' . $folder;
		mkdir( $dir, 0777, true );
		foreach ( $names as $name ) {
			file_put_contents( $dir . '/' . $name, $name );
		}
		return $dir;
	}
}
