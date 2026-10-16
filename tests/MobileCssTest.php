<?php
/**
 * SP の幅の CSS(l2dsis_get_mobile_css())と、切り替え幅(l2dsis_get_breakpoint())のテスト.
 *
 * @package L2dScrollImageSequence
 */

/**
 * フィルター l2dsis_breakpoint の値が、SP の CSS のメディアクエリに入ること.
 */
class MobileCssTest extends L2dsis_Test_Case {

	/**
	 * 既定は 1024px.
	 *
	 * @return void
	 */
	public function test_default_breakpoint_is_1024() {
		$this->assertSame( 1024, l2dsis_get_breakpoint() );
		$this->assertStringStartsWith( '@media (max-width: 1024px) {', l2dsis_get_mobile_css() );
	}

	/**
	 * フィルターで変えた幅が、メディアクエリに入る.
	 *
	 * @return void
	 */
	public function test_filtered_breakpoint_is_used() {
		$GLOBALS['l2dsis_test']['filters']['l2dsis_breakpoint'] = 768;

		$this->assertSame( 768, l2dsis_get_breakpoint() );
		$this->assertStringStartsWith( '@media (max-width: 768px) {', l2dsis_get_mobile_css() );
	}

	/**
	 * フィルターがマイナスや文字を返しても、0 以上の整数にする(CSS を壊さない).
	 *
	 * @return void
	 */
	public function test_breakpoint_is_absint() {
		$GLOBALS['l2dsis_test']['filters']['l2dsis_breakpoint'] = '-600px';

		$this->assertSame( 600, l2dsis_get_breakpoint() );
	}

	/**
	 * SP の CSS は、枠の最小の高さの CSS 変数と、SP 用のスクロールの長さの CSS 変数を使う.
	 *
	 * @return void
	 */
	public function test_mobile_css_uses_variables() {
		$css = l2dsis_get_mobile_css();

		$this->assertStringContainsString( 'var(--l2dsis-mobile-min-height, 0px)', $css );
		$this->assertStringContainsString( 'var(--l2dsis-scroll-length-mobile, var(--l2dsis-scroll-length, 1.8))', $css );
		$this->assertStringContainsString( 'container-type: inline-size;', $css );
	}
}
