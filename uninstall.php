<?php
/**
 * アンインストール(管理画面でプラグインを「削除」したとき).
 *
 * このプラグインが作ったデータをすべて消す(2026-10-16 ユーザー決定). 無効化では何も消さない.
 * 1. 投稿タイプ l2dsis_sequence の投稿(ゴミ箱・自動下書きを含むすべての状態)
 * 2. uploads/l2d-scroll-image-sequence/(コマの画像. 取り込みの途中の frames-new/・frames-old/ も含む)
 * 3. 保存結果のお知らせの transient(l2dsis_notice_{ユーザー ID})
 * 4. 自己更新(lib/l2d-updater)のキャッシュのサイトの transient
 * マルチサイトでは、1〜3 を各サイトで行う.
 *
 * WordPress は uninstall_plugin()(wp-admin/includes/plugin.php)で、WP_UNINSTALL_PLUGIN を定義してから
 * このファイルだけを読み込む. プラグインの本体は読み込まれないので、投稿タイプの登録も、
 * 「投稿を削除したらフォルダも消す」フック(L2dsis_Sequences::delete_files())も動かない.
 * そのため投稿とフォルダは、ここでそれぞれ消す. 値はプラグインの本体と同じものをここに書く.
 *
 * ページに置いたブロックは投稿の本文に残る. プラグインが無いと、中身(InnerBlocks)だけが普通のブロックとして出る.
 * コマの画像は戻せないので、必要なら削除の前に編集画面の「Download frames as ZIP」で取っておく.
 *
 * @package L2dScrollImageSequence
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // セキュリティ: 直接アクセスを防止.
}

/**
 * 1 つのサイトのデータを消す(投稿・uploads のフォルダ・お知らせの transient).
 *
 * @return void
 */
function l2dsis_uninstall_site() {
	global $wpdb;

	// 1. 投稿. 投稿タイプは登録されていないが、post_type を指定した取得と wp_delete_post() はそのまま使える.
	// 'any' はゴミ箱と自動下書きを含まないので、状態を並べて指定する.
	// get_posts() は suppress_filters の既定値が true なので、ほかのプラグインの絞り込みは効かない
	// (wp-includes/post.php. 明示すると Plugin Check の WordPressVIPMinimum のエラーになるので書かない).
	$post_ids = get_posts(
		array(
			'post_type'      => 'l2dsis_sequence',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	// 2. uploads のフォルダ.
	l2dsis_uninstall_delete_upload_dir();

	// 3. お知らせの transient. ユーザーごとに名前が違う(l2dsis_notice_{ユーザー ID})ので、名前の頭で探して消す.
	// オブジェクトキャッシュを使っているサイトでは DB に無いことがあるが、有効期限が 1 分なので自然に消える.
	$like  = $wpdb->esc_like( '_transient_l2dsis_notice_' ) . '%';
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 名前の頭で探す API が無いため. アンインストールで 1 回だけ.
	foreach ( $names as $name ) {
		delete_transient( substr( $name, strlen( '_transient_' ) ) );
	}
}

/**
 * このプラグイン専用の、uploads の中のフォルダを消す.
 *
 * 消す前に、実際のパス(realpath)が uploads の中にあり、名前がこのプラグインのフォルダであることを確かめる.
 * フォルダがシンボリックリンクなら消さない(リンクを辿って、ほかの場所を消さないため).
 *
 * @return void
 */
function l2dsis_uninstall_delete_upload_dir() {
	global $wp_filesystem;

	$uploads  = wp_upload_dir( null, false );
	$base_dir = untrailingslashit( $uploads['basedir'] );
	$dir      = $base_dir . '/l2d-scroll-image-sequence';

	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}

	$real_base = realpath( $base_dir );
	$real_dir  = realpath( $dir );
	if ( false === $real_base || false === $real_dir || dirname( $real_dir ) !== $real_base || 'l2d-scroll-image-sequence' !== basename( $real_dir ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( WP_Filesystem() && $wp_filesystem ) {
		$wp_filesystem->delete( $real_dir, true );
	}
}

if ( is_multisite() ) {
	$l2dsis_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $l2dsis_site_ids as $l2dsis_site_id ) {
		switch_to_blog( $l2dsis_site_id );
		l2dsis_uninstall_site();
		restore_current_blog();
	}
} else {
	l2dsis_uninstall_site();
}

// 4. 自己更新のキャッシュ. キーはライブラリの既定(lib/l2d-updater/class-l2d-github-updater.php の cache_key).
// メインファイルで cache_key を指定していないので 'l2dwpghul_updater_' . md5( github_repo ) になる.
delete_site_transient( 'l2dwpghul_updater_' . md5( 'lunaluna/l2d-scroll-image-sequence' ) );
