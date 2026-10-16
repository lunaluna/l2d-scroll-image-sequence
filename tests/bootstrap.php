<?php
/**
 * PHPUnit の bootstrap.
 *
 * WordPress のテストスイートは使わず(DB も WordPress 本体も要らない), テストで触る WordPress の関数だけを
 * 最小限の代わり(スタブ)で置き換える. 参照元の forced-auto-update-controller と同じ作り.
 * スタブの戻り値は、テストごとに $GLOBALS['l2dsis_test'] で変える(L2dsis_Test_Case::setUp() が毎回戻す).
 *
 * スタブを定義してから、プラグインのメインファイルを読み込む(読み込み時に add_action などを呼ぶため).
 *
 * @package L2dScrollImageSequence
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1024 * 1024 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'FS_CHMOD_DIR' ) ) {
	define( 'FS_CHMOD_DIR', 0755 );
}
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}

require_once __DIR__ . '/class-l2dsis-test-wp-error.php';
require_once __DIR__ . '/class-l2dsis-test-filesystem.php';

/**
 * スタブが使う状態の初期値を返す.
 *
 * @return array<string, mixed> 状態.
 */
function l2dsis_test_default_state() {
	return array(
		// apply_filters() の戻り値の差し替え. キーはフィルター名、値は返す値.
		'filters'     => array(),
		// wp_upload_dir() の basedir. テストごとに一時フォルダを入れる.
		'upload_dir'  => sys_get_temp_dir(),
		// get_post_type() / get_post_status() が返す値. キーは投稿 ID.
		'post_types'  => array(),
		'post_status' => array(),
		// current_user_can( 'edit_post', $id ) で true を返す投稿 ID.
		'can_edit'    => array(),
		// is_preview() の戻り値.
		'is_preview'  => false,
		// get_posts() の戻り値.
		'posts'       => array(),
		// set_transient() で入れた値. キーは transient の名前.
		'transients'  => array(),
		// get_current_user_id() の戻り値.
		'user_id'     => 1,
	);
}

$GLOBALS['l2dsis_test'] = l2dsis_test_default_state();

/**
 * Add_action() のスタブ. 何もしない.
 *
 * @param string   $hook_name     フック名.
 * @param callable $callback      コールバック.
 * @param int      $priority      優先度.
 * @param int      $accepted_args 引数の数.
 * @return true 常に true.
 */
function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	return true;
}

/**
 * Add_filter() のスタブ. 何もしない.
 *
 * @param string   $hook_name     フック名.
 * @param callable $callback      コールバック.
 * @param int      $priority      優先度.
 * @param int      $accepted_args 引数の数.
 * @return true 常に true.
 */
function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	return true;
}

/**
 * Apply_filters() のスタブ. 状態の filters に値があればそれを、無ければ元の値を返す.
 *
 * @param string $hook_name フィルター名.
 * @param mixed  $value     元の値.
 * @return mixed 値.
 */
function apply_filters( $hook_name, $value ) {
	return array_key_exists( $hook_name, $GLOBALS['l2dsis_test']['filters'] ) ? $GLOBALS['l2dsis_test']['filters'][ $hook_name ] : $value;
}

/**
 * Register_activation_hook() のスタブ. 何もしない.
 *
 * @param string   $file     プラグインのファイル.
 * @param callable $callback コールバック.
 * @return void
 */
function register_activation_hook( $file, $callback ) {}

/**
 * Plugin_dir_path() のスタブ.
 *
 * @param string $file ファイル.
 * @return string 末尾に / を付けたフォルダのパス.
 */
function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/';
}

/**
 * __() のスタブ. 訳さずにそのまま返す.
 *
 * @param string $text   文言.
 * @param string $domain テキストドメイン.
 * @return string 文言.
 */
function __( $text, $domain = 'default' ) {
	return $text;
}

/**
 * Absint() のスタブ(WordPress と同じ).
 *
 * @param mixed $maybeint 値.
 * @return int 0 以上の整数.
 */
function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

/**
 * Untrailingslashit() のスタブ(WordPress と同じ).
 *
 * @param string $value パス.
 * @return string 末尾の / と \ を除いたパス.
 */
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

/**
 * Wp_upload_dir() のスタブ. 状態の upload_dir を basedir にする.
 *
 * @param string|null $time        使わない.
 * @param bool        $create_dir  使わない.
 * @return array{basedir: string, baseurl: string} uploads の場所.
 */
function wp_upload_dir( $time = null, $create_dir = true ) {
	return array(
		'basedir' => $GLOBALS['l2dsis_test']['upload_dir'],
		'baseurl' => 'https://example.test/wp-content/uploads',
	);
}

/**
 * Wp_basename() のスタブ(WordPress と同じく / と \ の両方を区切りとして扱う).
 *
 * @param string $path パス.
 * @return string 最後の名前.
 */
function wp_basename( $path ) {
	return urldecode( basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ) ) );
}

/**
 * Validate_file() のスタブ. WordPress と同じく、../ を含む・./ を含む・ドライブ指定(C: など)の名前で 0 以外を返す.
 *
 * @param string $file ファイルのパス.
 * @return int 問題なければ 0.
 */
function validate_file( $file ) {
	if ( '' === $file ) {
		return 0;
	}
	if ( false !== strpos( $file, '..' ) && preg_match( '#(^|/)\.\.(/|$)#', str_replace( '\\', '/', $file ) ) ) {
		return 1;
	}
	if ( false !== strpos( $file, './' ) ) {
		return 1;
	}
	if ( ':' === substr( $file, 1, 1 ) ) {
		return 2;
	}
	return 0;
}

/**
 * Sanitize_file_name() のスタブ. テストではふつうの名前しか使わないので、空白を - にするだけ.
 *
 * @param string $filename ファイル名.
 * @return string ファイル名.
 */
function sanitize_file_name( $filename ) {
	return str_replace( ' ', '-', $filename );
}

/**
 * Size_format() のスタブ.
 *
 * @param int $bytes バイト数.
 * @return string バイト数の文字列.
 */
function size_format( $bytes ) {
	return $bytes . ' B';
}

/**
 * Is_wp_error() のスタブ.
 *
 * @param mixed $thing 値.
 * @return bool WP_Error なら true.
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Move_dir() のスタブ. $wp_filesystem->move() を使う(失敗の差し込みもそちらで効く).
 *
 * @param string $from 移す元.
 * @param string $to   移す先.
 * @return true|WP_Error 成功したら true.
 */
function move_dir( $from, $to ) {
	global $wp_filesystem;
	return $wp_filesystem->move( $from, $to ) ? true : new WP_Error( 'move_dir_failed', 'move_dir failed.' );
}

/**
 * Wp_check_filetype_and_ext() のスタブ. 拡張子だけで判定する(中身の MIME の検査は wp_getimagesize() のスタブに任せる).
 *
 * @param string $file     パス.
 * @param string $filename ファイル名.
 * @param array  $mimes    受け付ける拡張子と MIME.
 * @return array{ext: string|false, type: string|false, proper_filename: false} 判定.
 */
function wp_check_filetype_and_ext( $file, $filename, $mimes ) {
	$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	foreach ( $mimes as $pattern => $mime ) {
		if ( preg_match( '!^(' . $pattern . ')$!i', $extension ) ) {
			return array(
				'ext'             => $extension,
				'type'            => $mime,
				'proper_filename' => false,
			);
		}
	}
	return array(
		'ext'             => false,
		'type'            => false,
		'proper_filename' => false,
	);
}

/**
 * Wp_getimagesize() のスタブ. PHP の getimagesize() そのもの.
 *
 * @param string $filename パス.
 * @return array|false 大きさ.
 */
function wp_getimagesize( $filename ) {
	return @getimagesize( $filename ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

/**
 * Wp_delete_file() のスタブ.
 *
 * @param string $file パス.
 * @return void
 */
function wp_delete_file( $file ) {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}

/**
 * Number_format_i18n() のスタブ.
 *
 * @param float $number 数.
 * @return string 3 桁ごとに , を入れた数.
 */
function number_format_i18n( $number ) {
	return number_format( $number );
}

/**
 * Wp_mkdir_p() のスタブ. フォルダを親ごと作る.
 *
 * @param string $target フォルダ.
 * @return bool あれば true.
 */
function wp_mkdir_p( $target ) {
	return is_dir( $target ) || mkdir( $target, 0777, true );
}

/**
 * Get_post_type() のスタブ.
 *
 * @param int $post_id 投稿 ID.
 * @return string|false 投稿タイプ.
 */
function get_post_type( $post_id ) {
	return isset( $GLOBALS['l2dsis_test']['post_types'][ $post_id ] ) ? $GLOBALS['l2dsis_test']['post_types'][ $post_id ] : false;
}

/**
 * Get_post_status() のスタブ.
 *
 * @param int $post_id 投稿 ID.
 * @return string|false 状態.
 */
function get_post_status( $post_id ) {
	return isset( $GLOBALS['l2dsis_test']['post_status'][ $post_id ] ) ? $GLOBALS['l2dsis_test']['post_status'][ $post_id ] : false;
}

/**
 * Is_preview() のスタブ.
 *
 * @return bool プレビューなら true.
 */
function is_preview() {
	return $GLOBALS['l2dsis_test']['is_preview'];
}

/**
 * Current_user_can() のスタブ. edit_post は状態の can_edit にある投稿 ID だけ true. ほかの権限は true.
 *
 * @param string $capability 権限.
 * @param mixed  ...$args    投稿 ID など.
 * @return bool 権限があれば true.
 */
function current_user_can( $capability, ...$args ) {
	if ( 'edit_post' === $capability ) {
		return in_array( (int) $args[0], $GLOBALS['l2dsis_test']['can_edit'], true );
	}
	return true;
}

/**
 * Get_posts() のスタブ. 状態の posts をそのまま返す.
 *
 * @param array $args 使わない.
 * @return array 投稿.
 */
function get_posts( $args = array() ) {
	return $GLOBALS['l2dsis_test']['posts'];
}

/**
 * Rest_ensure_response() のスタブ. 値をそのまま返す.
 *
 * @param mixed $response 値.
 * @return mixed 値.
 */
function rest_ensure_response( $response ) {
	return $response;
}

/**
 * Set_transient() のスタブ. 状態の transients に入れる(有効期限は見ない).
 *
 * @param string $transient  名前.
 * @param mixed  $value      値.
 * @param int    $expiration 有効期限(使わない).
 * @return bool 常に true.
 */
function set_transient( $transient, $value, $expiration = 0 ) {
	$GLOBALS['l2dsis_test']['transients'][ $transient ] = $value;
	return true;
}

/**
 * Get_transient() のスタブ.
 *
 * @param string $transient 名前.
 * @return mixed 値. 無ければ false.
 */
function get_transient( $transient ) {
	return array_key_exists( $transient, $GLOBALS['l2dsis_test']['transients'] ) ? $GLOBALS['l2dsis_test']['transients'][ $transient ] : false;
}

/**
 * Delete_transient() のスタブ.
 *
 * @param string $transient 名前.
 * @return bool 消したら true.
 */
function delete_transient( $transient ) {
	$exists = array_key_exists( $transient, $GLOBALS['l2dsis_test']['transients'] );
	unset( $GLOBALS['l2dsis_test']['transients'][ $transient ] );
	return $exists;
}

/**
 * Get_current_user_id() のスタブ.
 *
 * @return int ユーザー ID.
 */
function get_current_user_id() {
	return $GLOBALS['l2dsis_test']['user_id'];
}

/**
 * Wp_generate_uuid4() のスタブ. 本物と同じ形(英小文字・数字・- の 36 文字).
 *
 * @return string UUID.
 */
function wp_generate_uuid4() {
	$hex = bin2hex( random_bytes( 16 ) );
	return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-a' . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
}

/**
 * Add_query_arg() のスタブ. 3 つの引数(名前・値・URL)の形だけ.
 *
 * @param string $key   名前.
 * @param string $value 値.
 * @param string $url   URL.
 * @return string URL.
 */
function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

/**
 * Sanitize_text_field() のスタブ. タグを除いて前後の空白を取る.
 *
 * @param string $str 文字列.
 * @return string 文字列.
 */
function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}

/**
 * Wp_unslash() のスタブ.
 *
 * @param string $value 値.
 * @return string 値.
 */
function wp_unslash( $value ) {
	return stripslashes( $value );
}

/**
 * Esc_html() のスタブ.
 *
 * @param string $text 文字列.
 * @return string エスケープした文字列.
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Esc_attr() のスタブ.
 *
 * @param string $text 文字列.
 * @return string エスケープした文字列.
 */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Esc_url() のスタブ. そのまま返す.
 *
 * @param string $url URL.
 * @return string URL.
 */
function esc_url( $url ) {
	return $url;
}

/**
 * Wp_admin_notice() のスタブ. 種類と文を div で出す.
 *
 * @param string $message 文.
 * @param array  $args    type など.
 * @return void
 */
function wp_admin_notice( $message, $args = array() ) {
	echo '<div class="notice notice-' . esc_attr( $args['type'] ) . '">' . $message . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Wp_json_encode() のスタブ. PHP の json_encode() そのもの.
 *
 * @param mixed $data  値.
 * @param int   $flags json_encode() のフラグ.
 * @return string|false JSON.
 */
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

/**
 * Get_block_wrapper_attributes() のスタブ. 本物と同じく、値を esc_attr() して「名前="値"」を空白でつなぐ
 * (wp-includes/class-wp-block-supports.php). ブロックの supports による class・style の足し込みはしない.
 *
 * @param array<string, string> $extra_attributes 属性.
 * @return string 属性の文字列.
 */
function get_block_wrapper_attributes( $extra_attributes = array() ) {
	$parts = array();
	foreach ( $extra_attributes as $name => $value ) {
		$parts[] = $name . '="' . esc_attr( $value ) . '"';
	}
	return implode( ' ', $parts );
}

// $GLOBALS['wp_query'] は、get_sequence_frame_urls() が is_preview() を呼んでよいかの判定に使う.
$GLOBALS['wp_query'] = new stdClass();

require_once dirname( __DIR__ ) . '/l2d-scroll-image-sequence.php';
require_once __DIR__ . '/class-l2dsis-test-case.php';
