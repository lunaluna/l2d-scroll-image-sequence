<?php
/**
 * 保存結果のお知らせ L2dsis_Sequences::set_notice() / add_notice_to_redirect() / render_notice() のテスト.
 *
 * 2026-10-16【A】の指摘: 同じユーザーが 2 つのタブで同時に保存すると、お知らせがユーザーごとの 1 つの transient に
 * 入っていたため、断られたタブに先のタブの「取り込みました」が出た. 保存のリクエストごとのトークンで分け、
 * それぞれのタブ(移動先の URL のトークン)に自分の結果だけが出ること.
 *
 * @package L2dScrollImageSequence
 */

/**
 * リクエストごとのお知らせ.
 */
class NoticeTest extends L2dsis_Test_Case {

	/**
	 * 投稿 ID(画像シーケンス).
	 */
	const POST_ID = 60;

	/**
	 * テストの前に、投稿 ID を画像シーケンスにする.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['l2dsis_test']['post_types'][ self::POST_ID ] = L2dsis_Sequences::POST_TYPE;
	}

	/**
	 * テストのあとで、URL の引数を消す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_GET[ L2dsis_Sequences::NOTICE_QUERY_ARG ] );
		parent::tearDown();
	}

	/**
	 * 1 回の保存(リクエスト)で、お知らせを作り、移動先の URL のトークンを返す.
	 *
	 * @param string $type    種類.
	 * @param string $message 文.
	 * @return string トークン.
	 */
	private function save_request( $type, $message ) {
		$this->reset_notice_token();
		$this->call_private( 'set_notice', array( $type, $message ) );

		$location = L2dsis_Sequences::add_notice_to_redirect( 'https://example.test/wp-admin/post.php?post=60&action=edit&message=1', self::POST_ID );
		$this->assertMatchesRegularExpression( '/[?&]l2dsis_notice=([a-z0-9-]+)$/', $location );
		preg_match( '/[?&]l2dsis_notice=([a-z0-9-]+)$/', $location, $matches );
		return $matches[1];
	}

	/**
	 * 移動先の URL のトークンで、お知らせを出す.
	 *
	 * @param string|null $token トークン. null なら URL に付けない.
	 * @return string 出した HTML.
	 */
	private function render( $token ) {
		unset( $_GET[ L2dsis_Sequences::NOTICE_QUERY_ARG ] );
		if ( null !== $token ) {
			$_GET[ L2dsis_Sequences::NOTICE_QUERY_ARG ] = $token;
		}
		ob_start();
		L2dsis_Sequences::render_notice();
		return ob_get_clean();
	}

	/**
	 * 同じユーザーの 2 つのタブで続けて保存しても、それぞれのタブに自分の結果だけが出る.
	 *
	 * @return void
	 */
	public function test_each_tab_shows_its_own_notice() {
		// 後のタブ(断られた)の保存が先に終わり、その直後に先のタブ(成功)の保存が終わった順(【A】で見た順).
		$rejected_token = $this->save_request( 'error', 'Another import for this sequence is in progress.' );
		$success_token  = $this->save_request( 'success', 'Imported 140 frames.' );

		$this->assertNotSame( $rejected_token, $success_token );

		$rejected_html = $this->render( $rejected_token );
		$this->assertStringContainsString( 'notice-error', $rejected_html );
		$this->assertStringContainsString( 'Another import for this sequence is in progress.', $rejected_html );
		$this->assertStringNotContainsString( 'Imported', $rejected_html );

		$success_html = $this->render( $success_token );
		$this->assertStringContainsString( 'notice-success', $success_html );
		$this->assertStringContainsString( 'Imported 140 frames.', $success_html );
	}

	/**
	 * 1 回出したお知らせは消える(再読み込みで二度出ない).
	 *
	 * @return void
	 */
	public function test_notice_is_shown_once() {
		$token = $this->save_request( 'success', 'Imported 3 frames.' );

		$this->assertStringContainsString( 'Imported 3 frames.', $this->render( $token ) );
		$this->assertSame( '', $this->render( $token ) );
	}

	/**
	 * URL にトークンが無いとき・形の違うトークンのとき・別のユーザーのときは、何も出さない.
	 *
	 * @return void
	 */
	public function test_nothing_without_a_valid_token_of_the_user() {
		$token = $this->save_request( 'success', 'Imported 3 frames.' );

		$this->assertSame( '', $this->render( null ) );
		$this->assertSame( '', $this->render( '../' . $token ) );

		$GLOBALS['l2dsis_test']['user_id'] = 2;
		$this->assertSame( '', $this->render( $token ) );

		$GLOBALS['l2dsis_test']['user_id'] = 1;
		$this->assertStringContainsString( 'Imported 3 frames.', $this->render( $token ) );
	}

	/**
	 * お知らせを作っていないリクエストや、ほかの投稿タイプでは、移動先の URL を変えない.
	 *
	 * @return void
	 */
	public function test_redirect_is_unchanged_without_a_notice() {
		$url = 'https://example.test/wp-admin/post.php?post=60&action=edit&message=1';
		$this->assertSame( $url, L2dsis_Sequences::add_notice_to_redirect( $url, self::POST_ID ) );

		$this->call_private( 'set_notice', array( 'success', 'x' ) );
		$GLOBALS['l2dsis_test']['post_types'][61] = 'post';
		$this->assertSame( $url, L2dsis_Sequences::add_notice_to_redirect( $url, 61 ) );
	}

	/**
	 * お知らせの引数は、表示のあとでアドレス欄から消す引数に入る.
	 *
	 * @return void
	 */
	public function test_query_arg_is_removable() {
		$this->assertContains( 'l2dsis_notice', L2dsis_Sequences::add_removable_query_arg( array( 'message' ) ) );
	}
}
