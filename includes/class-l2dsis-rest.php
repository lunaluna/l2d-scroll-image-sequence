<?php
/**
 * ブロックの編集画面が使う REST ルート.
 *
 * 投稿タイプ l2dsis_sequence は、クラシックの編集画面のメタボックス(ZIP の欄)を使うために
 * show_in_rest を false にしている. そのため wp/v2 のルートが無く、シーケンスの一覧は独自のルートで返す.
 *
 * 設計はプラン ~/.claude/plans/alpine-2026-a290-image-sequence.md の 3-2 を参照.
 *
 * @package L2dScrollImageSequence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // セキュリティ: 直接アクセス禁止.
}

/**
 * REST ルート l2dsis/v1/sequences をまとめたクラス.
 */
class L2dsis_Rest {

	/**
	 * REST の名前空間.
	 */
	const NAMESPACE_V1 = 'l2dsis/v1';

	/**
	 * フックを登録する.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * ルートを登録する.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/sequences',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_sequences' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
	}

	/**
	 * 一覧を読める権限があるか.
	 *
	 * ブロックを置けるのは投稿を編集できるユーザーなので、edit_posts で判定する.
	 * 未ログインは 401、権限の無いログインユーザー(購読者など)は 403 になる(rest_authorization_required_code()).
	 *
	 * @return bool|WP_Error 読めるなら true.
	 */
	public static function can_read() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to list image sequences.', 'l2d-scroll-image-sequence' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * シーケンスの一覧を返す.
	 *
	 * ゴミ箱以外のすべての状態を返す(下書きのシーケンスもブロックで選べるようにする).
	 * 件数は全部(-1). シーケンスはページに置く数だけあればよく少ない(2026-10-15 の alpine2026 で 4 本).
	 *
	 * @return WP_REST_Response 一覧. 各要素は id・title・status・frames(コマ数)・first_frame(1 コマ目の URL. 無ければ空).
	 */
	public static function get_sequences() {
		$posts = get_posts(
			array(
				'post_type'        => L2dsis_Sequences::POST_TYPE,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$frame_urls = L2dsis_Sequences::get_frame_urls( $post->ID );

			$items[] = array(
				'id'          => $post->ID,
				// エディターの React は文字をエスケープして出すので、ここでは生のタイトルを返す.
				'title'       => '' !== $post->post_title ? $post->post_title : sprintf(
					/* translators: %d: post ID. */
					__( '(no title) #%d', 'l2d-scroll-image-sequence' ),
					$post->ID
				),
				'status'      => $post->post_status,
				'frames'      => count( $frame_urls ),
				'first_frame' => isset( $frame_urls[0] ) ? $frame_urls[0] : '',
			);
		}

		return rest_ensure_response( $items );
	}
}
