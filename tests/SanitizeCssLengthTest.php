<?php
/**
 * 枠の最小の高さ(属性 mobileMinHeight)の値の検査 l2dsis_sanitize_css_length() のテスト.
 *
 * @package L2dScrollImageSequence
 */

/**
 * 「数値 + 単位」だけを通し、ほかは空にすること.
 */
class SanitizeCssLengthTest extends L2dsis_Test_Case {

	/**
	 * 通る値と、整えたあとの値.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function valid_values() {
		return array(
			'px'                   => array( '330px', '330px' ),
			'vh'                   => array( '100vh', '100vh' ),
			'小数・大文字・前後の空白' => array( ' 20.5REM ', '20.5rem' ),
			'0 を省いた小数'          => array( '.5em', '.5em' ),
			'svh'                  => array( '90svh', '90svh' ),
		);
	}

	/**
	 * 通る値は、整えて返す.
	 *
	 * @dataProvider valid_values
	 *
	 * @param string $value    入力.
	 * @param string $expected 期待する値.
	 * @return void
	 */
	public function test_valid_values_pass( $value, $expected ) {
		$this->assertSame( $expected, l2dsis_sanitize_css_length( $value ) );
	}

	/**
	 * 通らない値.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function invalid_values() {
		return array(
			'%'              => array( '100%' ),
			'単位なし'          => array( '330' ),
			'calc()'         => array( 'calc(100vh)' ),
			'ほかの指定を足す' => array( '330px;color:red' ),
			'指数'            => array( '1e3px' ),
			'マイナス'          => array( '-10px' ),
			'url()'          => array( 'url(x)' ),
			'数値型'           => array( 330 ),
			'空'             => array( '' ),
			'null'           => array( null ),
		);
	}

	/**
	 * 通らない値は空にする.
	 *
	 * @dataProvider invalid_values
	 *
	 * @param mixed $value 入力.
	 * @return void
	 */
	public function test_invalid_values_become_empty( $value ) {
		$this->assertSame( '', l2dsis_sanitize_css_length( $value ) );
	}
}
