<?php
/**
 * Plugin Name:       Scroll Image Sequence
 * Plugin URI:        https://github.com/lunaluna/l2d-scroll-image-sequence
 * Description:       Plays an image sequence on a canvas in sync with page scroll while the section is pinned.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Tested up to:      7.1.3
 * Requires PHP:      7.4
 * Author:            lunaluna_dev
 * Author URI:        https://profiles.wordpress.org/lunaluna_dev/
 * Update URI:        false
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       l2d-scroll-image-sequence
 * Domain Path:       /languages
 *
 * @package L2dScrollImageSequence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // セキュリティ: 直接アクセスを防止.
}

/**
 * 翻訳ファイル (.mo) を読み込む.
 *
 * GitHub 配布で wp.org 未登録のため、翻訳の自動読み込みに頼らず明示的に読み込む.
 *
 * @return void
 */
function l2dsis_load_textdomain() {
	load_plugin_textdomain(
		'l2d-scroll-image-sequence',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'l2dsis_load_textdomain' );

/**
 * プラグイン有効化時の環境チェック (PHP 7.4+, WP 6.7+).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/functions-activation.php';
register_activation_hook( __FILE__, 'l2dsis_check_environment' );

/**
 * 投稿タイプ「画像シーケンス」と、コマ画像の ZIP の取り込み.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-l2dsis-sequences.php';
L2dsis_Sequences::init();

/**
 * ブロックの編集画面が使う REST ルート(l2dsis/v1/sequences).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-l2dsis-rest.php';
L2dsis_Rest::init();

/**
 * GitHub Releases ベースの自己更新機構の読み込み(l2d-wp-github-update-lib).
 *
 * 読み込む lib/l2d-updater/ は git subtree で取り込んだ配布用のコピー. 直接編集しない.
 * 公開リポジトリなので token は渡さない. cache_key・filter_prefix は既存の更新機構から
 * 移るときのための設定なので、新しいこのプラグインでは省いてライブラリの既定値にする.
 */
$l2dsis_updater_register = require plugin_dir_path( __FILE__ ) . 'lib/l2d-updater/loader.php';
$l2dsis_updater_register(
	array(
		'plugin_file' => __FILE__,
		'github_repo' => 'lunaluna/l2d-scroll-image-sequence',
	)
);

/**
 * ブロックを登録する.
 *
 * ビルド済みの build/sequence/block.json を読み、エディター用・フロント用の
 * JS / CSS と render.php をまとめて登録する. build/ は `npm run build` で src/ から作る.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 *
 * @return void
 */
function l2dsis_register_blocks() {
	$block_type = register_block_type( __DIR__ . '/build/sequence' );
	if ( ! $block_type instanceof WP_Block_Type ) {
		return;
	}

	// 編集画面の JS の翻訳(languages/ の JSON)を読む. ハンドル名は block.json から自動で付くので、決め打ちせず取り出す.
	foreach ( $block_type->editor_script_handles as $handle ) {
		wp_set_script_translations( $handle, 'l2d-scroll-image-sequence', plugin_dir_path( __FILE__ ) . 'languages' );
	}

	// SP の幅のときの CSS を、ブロックの CSS(style-index.css)の後ろに足す.
	// 切り替え幅はフィルターで変えられるので、style.scss に決め打ちせずここで作る(view.js も同じ値を data-breakpoint で読む).
	foreach ( $block_type->style_handles as $handle ) {
		wp_add_inline_style( $handle, l2dsis_get_mobile_css() );
	}
}
add_action( 'init', 'l2dsis_register_blocks' );

/**
 * SP 用のシーケンスに切り替える画面の幅(px). この幅以下を SP として扱う.
 *
 * フィルターを使う場所(render.php・SP の CSS)で値がずれないよう、ここ 1 か所で読む.
 *
 * @return int 切り替える幅(px).
 */
function l2dsis_get_breakpoint() {
	/**
	 * SP 用のシーケンスに切り替える画面の幅(px). この幅以下で SP 用を使い、コマを幅いっぱいの枠に描く.
	 *
	 * 既定の 1024 は、確認用サイト alpine2026 のテーマの切り替え幅(PC は width > 1024px)に合わせた値.
	 *
	 * @param int $breakpoint 切り替える幅(px).
	 */
	return absint( apply_filters( 'l2dsis_breakpoint', 1024 ) );
}

/**
 * SP の幅のときの CSS.
 *
 * 1. SP 用のスクロールの長さ: --l2dsis-scroll-length-mobile(属性 scrollLengthMobile. render.php が入れる)が
 *    あればそれを、無ければ PC と同じ --l2dsis-scroll-length を使う(固定の区間 .l2dsis-sequence__track の高さ)
 * 2. 枠(.l2dsis-sequence__media)は、コマを画面いっぱいに切り抜かず、コマの縦横比の枠にコマ全体を描き、
 *    固定する箱の上下左右中央に置く(UK の A290 ページの SP と同じ. 2026-10-15 ユーザー決定. UK の実測:
 *    390 × 844 で枠 390 × 265・上から 289px, 375 × 667 で枠 375 × 255・上から 206px = どちらも上下中央).
 *    縦横比は render.php が --l2dsis-mobile-ratio に入れる. 1 コマ目の <picture> も同じ枠に入るので、
 *    JS が動かないときも同じ見た目になる. 横向き(844 × 390 など)で幅に合わせると枠が画面より高くなり
 *    切り抜かれる(【A】の実測: 844 × 475)ので、幅は「100%」と「100vh × 縦横比」の小さいほうにする
 *
 * 枠の下の空きへの .l2dsis-after-mobile のブロックの寄せ(負の margin-top)は、view.js が枠の位置を測って付ける.
 * 縦横比の変数は "1280 / 720" の形なので、calc() の中で掛け算・割り算としてそのまま使える.
 * 切り替え幅はフィルターで変えられるので、style.scss に決め打ちせずここで作る.
 *
 * 2026-10-15 に一度足した配置「縦に並べる」(mobileLayout: stack)は、UK に寄せる決定で消した(017ec0f にある).
 *
 * @return string CSS.
 */
function l2dsis_get_mobile_css() {
	return sprintf(
		'@media (max-width: %dpx) {'
		// 1. SP 用のスクロールの長さ.
		. '.l2dsis-sequence.is-pinned > .l2dsis-sequence__track {'
		. 'height: calc(100vh * (1 + var(--l2dsis-scroll-length-mobile, var(--l2dsis-scroll-length, 1.8))));'
		. '}'
		// 2. 枠を上下左右中央に置く.
		. '.l2dsis-sequence__media {'
		. 'top: 50%%; right: auto; bottom: auto; left: 50%%;'
		. 'width: min(100%%, calc(100vh * var(--l2dsis-mobile-ratio, 16 / 9)));'
		. 'aspect-ratio: var(--l2dsis-mobile-ratio, 16 / 9);'
		. 'transform: translate(-50%%, -50%%);'
		. '}'
		. '}',
		l2dsis_get_breakpoint()
	);
}
