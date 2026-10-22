<?php
/**
 * プラグイン有効化時 (register_activation_hook) に呼び出される関数.
 * PHP 7.4+ / WordPress 6.7+ を必須とし、満たさない場合は wp_die() で有効化を中断.
 *
 * WordPress の最小バージョンは、create-block が生成したヘッダーの
 * Requires at least: 6.7 に合わせている. ヘッダーを変えたらここも変える.
 *
 * @package L2dScrollImageSequence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // セキュリティ: 直接アクセス禁止.
}

/**
 * プラグイン環境をチェックし、要件を満たさない場合は有効化を中断.
 *
 * @return void
 */
function l2dsis_check_environment() {
	$required_php_version = '7.4';
	$required_wp_version  = '6.7';

	$current_php_version = PHP_VERSION;
	$current_wp_version  = get_bloginfo( 'version' );

	// 要件を満たさない場合、wp_die() で有効化自体を中断する.
	if (
		version_compare( $current_php_version, $required_php_version, '<' )
		|| version_compare( $current_wp_version, $required_wp_version, '<' )
	) {
		wp_die(
			sprintf(
				/* translators: 1: required PHP version, 2: required WordPress version, 3: current PHP version, 4: current WordPress version. */
				esc_html__(
					'Scroll Image Sequence Block requires PHP %1$s or higher and WordPress %2$s or higher. You have PHP %3$s and WordPress %4$s.',
					'l2d-scroll-image-sequence'
				),
				esc_html( $required_php_version ),
				esc_html( $required_wp_version ),
				esc_html( $current_php_version ),
				esc_html( $current_wp_version )
			),
			esc_html__( 'Plugin Activation Error', 'l2d-scroll-image-sequence' ),
			array( 'back_link' => true )
		);
	}
}
