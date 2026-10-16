<?php
/**
 * テスト用の WP_Error. 本物と同じく、エラーのコードと文を持つ.
 *
 * @package L2dScrollImageSequence
 */

/**
 * WP_Error のスタブ.
 */
class WP_Error {

	/**
	 * エラーのコード.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * エラーの文.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * コンストラクタ.
	 *
	 * @param string $code    エラーのコード.
	 * @param string $message エラーの文.
	 */
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * エラーのコードを返す.
	 *
	 * @return string コード.
	 */
	public function get_error_code() {
		return $this->code;
	}

	/**
	 * エラーの文を返す.
	 *
	 * @return string 文.
	 */
	public function get_error_message() {
		return $this->message;
	}
}
