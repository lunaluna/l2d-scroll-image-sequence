<?php
/**
 * Plugin Name:       Scroll Image Sequence
 * Plugin URI:        https://github.com/lunaluna/l2d-scroll-image-sequence
 * Description:       Plays an image sequence on a canvas in sync with page scroll while the section is pinned.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Tested up to:      7.1
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
	register_block_type( __DIR__ . '/build/sequence' );
}
add_action( 'init', 'l2dsis_register_blocks' );
