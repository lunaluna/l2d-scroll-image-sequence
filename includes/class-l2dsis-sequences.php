<?php
/**
 * 投稿タイプ「画像シーケンス」と、コマ画像の ZIP の取り込み.
 *
 * - 管理画面だけで使う非公開の投稿タイプ l2dsis_sequence を登録する
 * - 編集画面のメタボックスで ZIP を受け取り、画像だけを
 *   uploads/l2d-scroll-image-sequence/{投稿 ID}/frames/ に展開する
 * - 上げた ZIP は残さない. 取り込みで捨てたファイルも入っている ZIP を、だれでも取れる uploads に置かないため
 *   (2026-10-15 ユーザー決定). 本番へ運ぶときや差し替えの元には、編集画面のボタンで frames/ の画像だけから
 *   ZIP を作ってダウンロードする(download_frames())
 * - コマの一覧は投稿メタに持たず、表示のたびにフォルダを読む(postmeta を使う 2 ケースに当たらないため)
 *
 * 設計はプラン ~/.claude/plans/alpine-2026-a290-image-sequence.md の 3-1 を参照.
 *
 * @package L2dScrollImageSequence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // セキュリティ: 直接アクセス禁止.
}

/**
 * 投稿タイプ「画像シーケンス」と ZIP の取り込みをまとめたクラス.
 *
 * 状態を持たないので、すべて static で持つ. init() でフックを登録する.
 */
class L2dsis_Sequences {

	/**
	 * 投稿タイプ名. register_post_type() の 20 文字以内の制限に収まる.
	 */
	const POST_TYPE = 'l2dsis_sequence';

	/**
	 * 画像シーケンスを入れる、uploads の中のこのプラグイン専用のフォルダ名.
	 */
	const UPLOAD_DIR = 'l2d-scroll-image-sequence';

	/**
	 * シーケンスのフォルダの中で、コマ画像を入れるフォルダ名.
	 */
	const FRAMES_DIR = 'frames';

	/**
	 * コマを ZIP でダウンロードする admin-post.php のアクション名. nonce のアクションにも使う(後ろに投稿 ID を付ける).
	 */
	const DOWNLOAD_ACTION = 'l2dsis_download_frames';

	/**
	 * メタボックスのファイル欄の name.
	 */
	const FILE_FIELD = 'l2dsis_zip';

	/**
	 * Nonce のアクション名.
	 */
	const NONCE_ACTION = 'l2dsis_save_sequence';

	/**
	 * Nonce のフィールド名.
	 */
	const NONCE_NAME = 'l2dsis_sequence_nonce';

	/**
	 * 保存結果のお知らせを入れる transient の接頭辞. 後ろにユーザー ID を付ける.
	 */
	const NOTICE_TRANSIENT = 'l2dsis_notice_';

	/**
	 * コマとして受け付ける拡張子と MIME タイプ.
	 *
	 * 形は wp_check_filetype_and_ext() の $mimes と同じ(キーは拡張子の正規表現). 画像は中身の MIME も調べられる.
	 *
	 * @var string[]
	 */
	const FRAME_MIMES = array(
		'webp'     => 'image/webp',
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
	);

	/**
	 * 関数 get_frame_files() の結果を、1 回のリクエストの中だけ覚えておく場所. キーは投稿 ID.
	 *
	 * @var array<int, string[]>
	 */
	private static $frame_files_cache = array();

	/**
	 * フックを登録する.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'add_form_enctype' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_files' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );

		// admin_post_{action} はログインしているときだけ呼ばれる(wp-admin/admin-post.php). nopriv は登録しない.
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( __CLASS__, 'download_frames' ) );
	}

	/**
	 * 投稿タイプ「画像シーケンス」を登録する.
	 *
	 * 非公開(フロントに URL を持たない)で、管理画面のメニューにだけ出す.
	 * show_in_rest を false にするとブロックエディターが使われず(wp-includes/post.php の
	 * use_block_editor_for_post_type()), クラシックの編集画面のメタボックスで ZIP のファイル欄が使える.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Image Sequences', 'l2d-scroll-image-sequence' ),
					'singular_name'      => __( 'Image Sequence', 'l2d-scroll-image-sequence' ),
					'add_new_item'       => __( 'Add New Image Sequence', 'l2d-scroll-image-sequence' ),
					'edit_item'          => __( 'Edit Image Sequence', 'l2d-scroll-image-sequence' ),
					'new_item'           => __( 'New Image Sequence', 'l2d-scroll-image-sequence' ),
					'search_items'       => __( 'Search Image Sequences', 'l2d-scroll-image-sequence' ),
					'not_found'          => __( 'No image sequences found.', 'l2d-scroll-image-sequence' ),
					'not_found_in_trash' => __( 'No image sequences found in Trash.', 'l2d-scroll-image-sequence' ),
					'all_items'          => __( 'All Image Sequences', 'l2d-scroll-image-sequence' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-images-alt2',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/**
	 * 編集画面にメタボックス「コマ画像」を足す.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'l2dsis-frames',
			__( 'Frames', 'l2d-scroll-image-sequence' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * 編集画面のフォームでファイルを送れるようにする.
	 *
	 * クラシックの編集画面の <form> には enctype が無く、そのままだと $_FILES が空になる.
	 * post_edit_form_tag は <form> タグの中で呼ばれる(wp-admin/edit-form-advanced.php).
	 *
	 * @param WP_Post $post 編集中の投稿.
	 * @return void
	 */
	public static function add_form_enctype( $post ) {
		if ( self::POST_TYPE === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/**
	 * メタボックス「コマ画像」の中身を出す.
	 *
	 * 今のコマ数・1 コマ目と最後のコマの縮小表示・ZIP のファイル欄を出す.
	 *
	 * @param WP_Post $post 編集中の投稿.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$frame_urls = self::get_frame_urls( $post->ID );
		$count      = count( $frame_urls );
		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of frames. */
					_n( 'This sequence has %d frame.', 'This sequence has %d frames.', $count, 'l2d-scroll-image-sequence' ),
					$count
				)
			);
			?>
		</p>

		<?php if ( $count > 0 ) : ?>
			<?php /* 1 コマ目と最後のコマを並べて、取り込んだ順番が正しいかを見られるようにする. */ ?>
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:1em;">
				<figure style="margin:0;">
					<img src="<?php echo esc_url( $frame_urls[0] ); ?>" alt="" style="display:block;width:240px;height:auto;background:#000;">
					<figcaption><?php esc_html_e( 'First frame', 'l2d-scroll-image-sequence' ); ?></figcaption>
				</figure>
				<figure style="margin:0;">
					<img src="<?php echo esc_url( $frame_urls[ $count - 1 ] ); ?>" alt="" style="display:block;width:240px;height:auto;background:#000;">
					<figcaption><?php esc_html_e( 'Last frame', 'l2d-scroll-image-sequence' ); ?></figcaption>
				</figure>
			</div>

			<?php /* 本番へ運ぶときや差し替えの元にするための、コマの ZIP のダウンロード. 押したときに frames/ から作る. */ ?>
			<p>
				<a class="button" href="<?php echo esc_url( self::get_download_url( $post->ID ) ); ?>">
					<?php esc_html_e( 'Download frames as ZIP', 'l2d-scroll-image-sequence' ); ?>
				</a>
			</p>
		<?php endif; ?>

		<?php
		// 寄稿者など upload_files の無いユーザーには、上げても save() で止まるだけなので欄を出さない.
		if ( ! current_user_can( 'upload_files' ) ) :
			?>
			<p class="description"><?php esc_html_e( 'You are not allowed to upload frames. Ask an editor or administrator to upload the ZIP file.', 'l2d-scroll-image-sequence' ); ?></p>
			<?php
			return;
		endif;
		?>

		<?php /* ZIP のファイル欄. 選ばずに保存したときは、今のコマをそのまま残す. */ ?>
		<p>
			<label for="<?php echo esc_attr( self::FILE_FIELD ); ?>">
				<?php echo esc_html( $count > 0 ? __( 'Replace frames with a ZIP file:', 'l2d-scroll-image-sequence' ) : __( 'Upload a ZIP file of frames:', 'l2d-scroll-image-sequence' ) ); ?>
			</label><br>
			<input type="file" id="<?php echo esc_attr( self::FILE_FIELD ); ?>" name="<?php echo esc_attr( self::FILE_FIELD ); ?>" accept=".zip,application/zip">
		</p>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: maximum upload file size. */
					__( 'Put WebP, JPEG or PNG images in the ZIP. Frames are ordered by file name (natural order, e.g. 2 before 10). Other files are ignored. Maximum upload size: %s.', 'l2d-scroll-image-sequence' ),
					size_format( wp_max_upload_size() )
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * 保存時に ZIP を受け取って取り込む.
	 *
	 * フック save_post_{post_type} は新規作成の下書き(auto-draft)やクイック編集でも呼ばれる.
	 * そのときはメタボックスの nonce が無いので、何もしない.
	 *
	 * @param int $post_id 投稿 ID.
	 * @return void
	 */
	public static function save( $post_id ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// メタボックスから送られていない保存(auto-draft・クイック編集など)は対象外.
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		// ファイルを選ばずに保存したときは、今のコマをそのまま残す.
		if ( ! isset( $_FILES[ self::FILE_FIELD ]['error'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ self::FILE_FIELD ]['error'] ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'upload_files' ) ) {
			self::set_notice( 'error', __( 'You are not allowed to upload frames for this sequence.', 'l2d-scroll-image-sequence' ) );
			return;
		}

		$upload_error = (int) $_FILES[ self::FILE_FIELD ]['error'];
		if ( UPLOAD_ERR_OK !== $upload_error ) {
			self::set_notice( 'error', self::get_upload_error_message( $upload_error ) );
			return;
		}

		// tmp_name は PHP が付けた一時ファイルのパスで、書き換えるとファイルを指さなくなるのでサニタイズしない.
		// 代わりに is_uploaded_file() で、このリクエストで上げられたファイルかを確かめる.
		$tmp_name  = isset( $_FILES[ self::FILE_FIELD ]['tmp_name'] ) ? $_FILES[ self::FILE_FIELD ]['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 一時ファイルのパス. 上のコメントを参照.
		$file_name = isset( $_FILES[ self::FILE_FIELD ]['name'] ) ? sanitize_file_name( wp_unslash( $_FILES[ self::FILE_FIELD ]['name'] ) ) : '';

		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			self::set_notice( 'error', __( 'The uploaded file could not be read.', 'l2d-scroll-image-sequence' ) );
			return;
		}

		// 拡張子が .zip かを確かめる. 中身が ZIP でなければ、import_zip() で ZipArchive が開く段階で失敗する.
		$filetype = wp_check_filetype( $file_name, array( 'zip' => 'application/zip' ) );
		if ( 'zip' !== $filetype['ext'] ) {
			self::set_notice( 'error', __( 'Please upload a ZIP file.', 'l2d-scroll-image-sequence' ) );
			return;
		}

		$result = self::import_zip( $post_id, $tmp_name );
		if ( is_wp_error( $result ) ) {
			self::set_notice( 'error', $result->get_error_message() );
			return;
		}

		$message = sprintf(
			/* translators: %d: number of frames. */
			_n( 'Imported %d frame.', 'Imported %d frames.', $result['frames'], 'l2d-scroll-image-sequence' ),
			$result['frames']
		);
		if ( $result['skipped'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of ignored files. */
				_n( '%d other file in the ZIP file was not imported.', '%d other files in the ZIP file were not imported.', $result['skipped'], 'l2d-scroll-image-sequence' ),
				$result['skipped']
			);
		}
		self::set_notice( 'success', $message );
	}

	/**
	 * ZIP から画像だけを取り出して、シーケンスのフォルダの frames/ に入れる.
	 *
	 * 手順:
	 * 1. ZIP の中の一覧だけを見て、取り出すものを選ぶ(select_entries()). 拡張子が webp / jpg / jpeg / png
	 *    以外のもの(PHP・隠しファイル・__MACOSX など)は、一度もディスクに書かない.
	 *    コアの unzip_file() は中身を種類を問わずすべて書き出すので使わない. uploads に PHP が一時的にでも
	 *    置かれると、uploads で PHP が動くサーバーでは実行されるおそれがあるため(2026-10-15 のセキュリティ検査の指摘)
	 * 2. 選んだものを、サーバーの一時フォルダ(get_temp_dir(). 通常は Web から届かない. ただしシステムの一時フォルダが
	 *    書き込めないと WP_CONTENT_DIR に切り替わる)に書き出し、中身が画像かを確かめる. 書くのは画像の拡張子のファイルだけ
	 * 3. 画像が 1 枚以上あったときだけ、古い frames/ と入れ替える.
	 *    入れ替えは frames-new/ を経由し、移動に失敗しても古いコマが消えないようにする.
	 *    上げた ZIP は残さない(PHP の一時ファイルなので、リクエストの終わりに PHP が消す)
	 * 4. 一時フォルダは最後に必ず消す. 致命的エラーで finally が動かないときのために、シャットダウン時にも消す
	 *
	 * @param int    $post_id  投稿 ID.
	 * @param string $zip_path 上げられた ZIP のパス(PHP の一時ファイル).
	 * @return array{frames: int, skipped: int}|WP_Error 取り込んだコマ数と捨てたファイル数. 失敗したら WP_Error.
	 */
	public static function import_zip( $post_id, $zip_path ) {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! class_exists( 'ZipArchive', false ) ) {
			return new WP_Error( 'l2dsis_no_ziparchive', __( 'The PHP ZipArchive extension is required to import frames.', 'l2d-scroll-image-sequence' ) );
		}

		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return new WP_Error( 'l2dsis_fs_unavailable', __( 'Could not access the filesystem.', 'l2d-scroll-image-sequence' ) );
		}

		// 同じシーケンスへの取り込みが重なると、frames-new/・frames-old/ を互いに消したり動かしたりしてコマを失う
		// (2026-10-16 の最終レビューの指摘 1). 投稿 ID ごとのロックを取り、取れなければ今のコマに触らずに断る.
		$lock = self::acquire_import_lock( $post_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return self::import_zip_locked( $post_id, $zip_path );
		} finally {
			self::release_import_lock( $lock );
		}
	}

	/**
	 * ロックを取ったあとの取り込みの本体(import_zip() から呼ぶ).
	 *
	 * @param int    $post_id  投稿 ID.
	 * @param string $zip_path 上げられた ZIP のパス.
	 * @return array{frames: int, skipped: int}|WP_Error 取り込んだコマ数と捨てたファイル数. 失敗したら WP_Error.
	 */
	private static function import_zip_locked( $post_id, $zip_path ) {
		global $wp_filesystem;

		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path );
		if ( true !== $opened ) {
			return new WP_Error( 'l2dsis_zip_open_failed', __( 'Could not open the ZIP file. It may be broken.', 'l2d-scroll-image-sequence' ) );
		}

		// wp_tempnam() は「ファイル」を作るので、消してから同じ名前でフォルダを作る.
		$work_dir = wp_tempnam( 'l2dsis-' . $post_id );
		wp_delete_file( $work_dir );

		// 致命的エラー(メモリ不足・時間切れ)では finally が動かないので、シャットダウン時にも消す.
		// 一時フォルダには画像の拡張子のファイルしか書かないが、残しておく理由も無い.
		register_shutdown_function( array( __CLASS__, 'delete_dir' ), $work_dir );

		try {
			if ( ! wp_mkdir_p( $work_dir ) ) {
				return new WP_Error( 'l2dsis_mkdir_failed', __( 'Could not create a temporary folder.', 'l2d-scroll-image-sequence' ) );
			}
			// wp_mkdir_p() は親フォルダの権限(sticky ビットを含む)を引き継ぐので, /tmp(1777)の下だと誰でも書ける
			// フォルダになる. ここで作るフォルダはあとで uploads へ移すので、権限を明示しておく.
			$wp_filesystem->chmod( $work_dir, FS_CHMOD_DIR );

			$entries = self::select_entries( $zip );
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}

			$extracted = self::extract_entries( $zip, $entries['entries'], $work_dir );
			if ( is_wp_error( $extracted ) ) {
				return $extracted;
			}

			$skipped = $entries['skipped'] + $extracted['skipped'];

			if ( 0 === $extracted['frames'] ) {
				return new WP_Error( 'l2dsis_no_frames', __( 'The ZIP file has no WebP, JPEG or PNG images. The current frames were kept.', 'l2d-scroll-image-sequence' ) );
			}

			$sequence_dir = self::get_dir( $post_id );
			if ( ! wp_mkdir_p( $sequence_dir ) ) {
				return new WP_Error( 'l2dsis_mkdir_failed', __( 'Could not create the folder for this sequence.', 'l2d-scroll-image-sequence' ) );
			}

			$swapped = self::swap_frames( $work_dir, $sequence_dir );
			// 入れ替えに失敗して古いコマを戻した場合も含め、フォルダの中身が変わりうるので覚えた一覧を消す.
			self::flush_frame_files_cache( $post_id );
			if ( is_wp_error( $swapped ) ) {
				return $swapped;
			}

			return array(
				'frames'  => $extracted['frames'],
				'skipped' => $skipped,
			);
		} finally {
			$zip->close();
			self::delete_dir( $work_dir );
		}
	}

	/**
	 * 取り込みのロックのファイルのパス. uploads のこのプラグインのフォルダの直下に、投稿 ID ごとに 1 つ.
	 *
	 * シーケンスのフォルダの中に置かないのは、そのフォルダを消したり作り直したりしてもロックが変わらないようにするため.
	 *
	 * @param int $post_id 投稿 ID.
	 * @return string パス.
	 */
	private static function get_lock_path( $post_id ) {
		return self::get_base_dir() . '/' . absint( $post_id ) . '.lock';
	}

	/**
	 * 投稿 ID ごとの、取り込みのロックを取る.
	 *
	 * ロックには flock() の待たない排他ロック(LOCK_EX | LOCK_NB)を使う. PHP の処理が途中で終わっても OS がロックを放すので、
	 * 「放されずに残ったロック」を何分で無効にするか、を決めなくてよい.
	 * - ほかの処理が持っていれば、WP_Error(l2dsis_import_locked)を返す
	 * - ファイルシステムが flock() に対応していない(ほかが持っている以外の理由で失敗した)ときは、ロックなしで進める
	 *   (すべての取り込みを断ると使えなくなるため). NFS などでは効かないことがある(README に書く)
	 *
	 * @param int $post_id 投稿 ID.
	 * @return resource|null|WP_Error ロックを持つファイル. ロックなしで進めるときは null. 取り込み中なら WP_Error.
	 */
	private static function acquire_import_lock( $post_id ) {
		$base_dir = self::get_base_dir();
		if ( ! wp_mkdir_p( $base_dir ) ) {
			return new WP_Error( 'l2dsis_mkdir_failed', __( 'Could not create the folder for image sequences in the uploads folder.', 'l2d-scroll-image-sequence' ) );
		}

		// 'c' は、無ければ作り、あれば中身を消さずに開く. 中身は使わない(0 バイト).
		$handle = fopen( self::get_lock_path( $post_id ), 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flock() には PHP のファイルハンドルが要る(WP_Filesystem には無い).
		if ( false === $handle ) {
			return null;
		}

		$would_block = 0;
		if ( ! flock( $handle, LOCK_EX | LOCK_NB, $would_block ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 上の fopen() と対.
			if ( $would_block ) {
				return new WP_Error( 'l2dsis_import_locked', __( 'Another import for this sequence is in progress. The current frames were kept. Try again later.', 'l2d-scroll-image-sequence' ) );
			}
			return null;
		}

		return $handle;
	}

	/**
	 * 取り込みのロックを放す.
	 *
	 * ロックのファイルは消さない. 放した直後に消すと、その間に別の処理が開いたファイルと、さらに別の処理が新しく作った
	 * ファイルで、2 つの処理が同時にロックを持ててしまうため(中身は 0 バイト. 投稿の削除とアンインストールで消える).
	 *
	 * @param resource|null $handle acquire_import_lock() が返したファイル.
	 * @return void
	 */
	private static function release_import_lock( $handle ) {
		if ( ! is_resource( $handle ) ) {
			return;
		}
		flock( $handle, LOCK_UN );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- acquire_import_lock() の fopen() と対.
	}

	/**
	 * ZIP の中の一覧から、取り出すコマを選ぶ. ここではまだ何も書き出さない.
	 *
	 * サイズはエントリに書かれた展開後の大きさで確かめる(zip bomb でディスクやメモリを埋められないように).
	 * 書かれた大きさが偽りでも、extract_entries() で読む量を「書かれた大きさ + 1 バイト」までにして止める.
	 *
	 * @param ZipArchive $zip 開いた ZIP.
	 * @return array{entries: array<string, array{index: int, size: int}>, skipped: int}|WP_Error
	 *         取り出すエントリ(キーは保存するファイル名)と捨てた数. 上限を超えたり名前が重なったりしたら WP_Error.
	 */
	private static function select_entries( ZipArchive $zip ) {
		/**
		 * コマ 1 枚の展開後の大きさの上限(バイト).
		 *
		 * 既定の 10MB は、Step 0 で測った 1 コマの最大 62KB(2048 × 1152 の webp)を大きく上回り、
		 * 2560 × 1440 の PNG でも通常は収まる見込みの値(PNG での実測は未).
		 *
		 * @param int $bytes 上限(バイト).
		 */
		$max_frame_bytes = (int) apply_filters( 'l2dsis_max_frame_bytes', 10 * MB_IN_BYTES );

		/**
		 * 1 つの ZIP から取り出すコマの合計の大きさの上限(バイト).
		 *
		 * 既定の 100MB は、Step 0 で測った 1 本の合計 3.4MB(90 コマ)の約 30 倍.
		 * 2026-10-15 のレビューの指摘で 500MB から下げた(ユーザー決定).
		 *
		 * @param int $bytes 上限(バイト).
		 */
		$max_total_bytes = (int) apply_filters( 'l2dsis_max_total_bytes', 100 * MB_IN_BYTES );

		/**
		 * ZIP の中のエントリ(フォルダ・画像以外のファイルも含む)の数の上限.
		 *
		 * 大量の空ファイルなどで、一覧を見るだけでも CPU 時間を使わせないため(2026-10-15 のレビューの指摘).
		 * 既定の 1,000 は、実際のシーケンスのコマ数(90・100)の約 10 倍.
		 *
		 * @param int $count 上限.
		 */
		$max_zip_entries = (int) apply_filters( 'l2dsis_max_zip_entries', 1000 );

		/**
		 * 取り込むコマの数の上限.
		 *
		 * ブラウザで読み込む画像の数を抑えるため(2026-10-15 のレビューの指摘).
		 * 既定の 300 は、実際のシーケンスのコマ数(90・100)の 3 倍.
		 *
		 * @param int $count 上限.
		 */
		$max_frames = (int) apply_filters( 'l2dsis_max_frames', 300 );

		$entry_count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive のプロパティ名.
		if ( $entry_count > $max_zip_entries ) {
			return new WP_Error(
				'l2dsis_too_many_entries',
				sprintf(
					/* translators: 1: number of files in the ZIP file, 2: maximum number. */
					__( 'The ZIP file has %1$d files, more than the limit (%2$d). The current frames were kept.', 'l2d-scroll-image-sequence' ),
					$entry_count,
					$max_zip_entries
				)
			);
		}

		$entries = array();
		$skipped = 0;
		$total   = 0;

		for ( $i = 0; $i < $entry_count; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}

			$name = $stat['name'];

			// フォルダのエントリは飛ばす(サブフォルダは平らにする).
			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}

			// macOS が足す __MACOSX/ と、._* や .htaccess などの隠しファイルは取り出さない.
			// 黙って消えるとわかりにくいので、取り込まなかったファイルとして数える(2026-10-15【A】の指摘).
			$basename = wp_basename( $name );
			if ( 0 === strpos( $name, '__MACOSX/' ) || false !== strpos( $name, '/__MACOSX/' ) || 0 === strpos( $basename, '.' ) ) {
				++$skipped;
				continue;
			}

			// ../ などを含む不正な名前は取り出さない(コアの unzip_file() と同じ validate_file() で判定する).
			// 保存先の名前は basename から作るので外には出ないが、壊れた ZIP として数える.
			$extension = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
			if ( 0 !== validate_file( $name ) || ! in_array( $extension, array( 'webp', 'jpg', 'jpeg', 'png' ), true ) || $stat['size'] <= 0 ) {
				++$skipped;
				continue;
			}

			if ( $stat['size'] > $max_frame_bytes ) {
				return new WP_Error(
					'l2dsis_frame_too_large',
					sprintf(
						/* translators: 1: file name, 2: maximum size. */
						__( 'The image %1$s is larger than the limit (%2$s). The current frames were kept.', 'l2d-scroll-image-sequence' ),
						$basename,
						size_format( $max_frame_bytes )
					)
				);
			}

			$total += $stat['size'];
			if ( $total > $max_total_bytes ) {
				return new WP_Error(
					'l2dsis_total_too_large',
					sprintf(
						/* translators: %s: maximum size. */
						__( 'The images in the ZIP file are larger than the limit in total (%s). The current frames were kept.', 'l2d-scroll-image-sequence' ),
						size_format( $max_total_bytes )
					)
				);
			}

			$file_name = sanitize_file_name( $basename );

			// サブフォルダを平らにするので、同じ名前が 2 つあるとどちらを使うか決まらない. 取り込み全体を止める.
			if ( isset( $entries[ $file_name ] ) ) {
				return new WP_Error(
					'l2dsis_duplicate_name',
					sprintf(
						/* translators: %s: file name. */
						__( 'The ZIP file has more than one image named %s. Frame file names must be unique. The current frames were kept.', 'l2d-scroll-image-sequence' ),
						$file_name
					)
				);
			}

			$entries[ $file_name ] = array(
				'index' => $i,
				'size'  => (int) $stat['size'],
			);

			if ( count( $entries ) > $max_frames ) {
				return new WP_Error(
					'l2dsis_too_many_frames',
					sprintf(
						/* translators: %d: maximum number of frames. */
						__( 'The ZIP file has more images than the limit (%d frames). The current frames were kept.', 'l2d-scroll-image-sequence' ),
						$max_frames
					)
				);
			}
		}

		return array(
			'entries' => $entries,
			'skipped' => $skipped,
		);
	}

	/**
	 * 選んだエントリを一時フォルダの frames/ に書き出し、中身が画像でないものを消す.
	 *
	 * @param ZipArchive                                  $zip      開いた ZIP.
	 * @param array<string, array{index: int, size: int}> $entries  select_entries() が選んだエントリ.
	 * @param string                                      $work_dir 一時フォルダ.
	 * @return array{frames: int, skipped: int}|WP_Error 書き出したコマ数と捨てた数. 失敗したら WP_Error.
	 */
	private static function extract_entries( ZipArchive $zip, array $entries, $work_dir ) {
		global $wp_filesystem;

		$frames_dir = $work_dir . '/' . self::FRAMES_DIR;
		if ( ! wp_mkdir_p( $frames_dir ) ) {
			return new WP_Error( 'l2dsis_mkdir_failed', __( 'Could not create a temporary folder.', 'l2d-scroll-image-sequence' ) );
		}
		// このフォルダがそのまま uploads の frames/ になるので、親の権限を引き継がないよう明示する(import_zip() を参照).
		$wp_filesystem->chmod( $frames_dir, FS_CHMOD_DIR );

		/**
		 * コマ 1 枚の画素数(幅 × 高さ)の上限.
		 *
		 * ファイルが小さくても寸法が極端に大きい画像は、ブラウザでデコードするときに大きなメモリを使うため
		 * (2026-10-15 のレビューの指摘). 既定の 16,000,000(16 メガピクセル)は、実際のコマ 2048 × 1152
		 * (約 2.4 メガピクセル)の約 7 倍で、4K(3840 × 2160 = 約 8.3 メガピクセル)も通る.
		 *
		 * @param int $pixels 上限.
		 */
		$max_frame_pixels = (int) apply_filters( 'l2dsis_max_frame_pixels', 16000000 );

		$frames  = 0;
		$skipped = 0;

		foreach ( $entries as $file_name => $entry ) {
			// 書かれた大きさ + 1 バイトまでしか読まない. 実際の中身のほうが大きければ、細工された ZIP として止める.
			$data = $zip->getFromIndex( $entry['index'], $entry['size'] + 1 );
			if ( false === $data || strlen( $data ) !== $entry['size'] ) {
				return new WP_Error( 'l2dsis_zip_broken', __( 'Could not read an image in the ZIP file. It may be broken.', 'l2d-scroll-image-sequence' ) );
			}

			$path = $frames_dir . '/' . $file_name;
			if ( ! $wp_filesystem->put_contents( $path, $data, FS_CHMOD_FILE ) ) {
				return new WP_Error( 'l2dsis_write_failed', __( 'Could not write an image to the temporary folder.', 'l2d-scroll-image-sequence' ) );
			}
			unset( $data );

			// 拡張子と中身の両方が画像かを確かめる. 中身と拡張子が合わないものは proper_filename が入る.
			$check = wp_check_filetype_and_ext( $path, $file_name, self::FRAME_MIMES );
			$size  = wp_getimagesize( $path );
			if ( ! $check['ext'] || ! $check['type'] || false !== $check['proper_filename'] || false === $size ) {
				wp_delete_file( $path );
				++$skipped;
				continue;
			}

			// 画素数の上限. 超えたら取り込み全体を止める(1 枚だけ捨てると、コマが抜けたシーケンスになるため).
			if ( (int) $size[0] * (int) $size[1] > $max_frame_pixels ) {
				return new WP_Error(
					'l2dsis_frame_too_many_pixels',
					sprintf(
						/* translators: 1: file name, 2: image width, 3: image height, 4: maximum number of pixels. */
						__( 'The image %1$s is %2$d × %3$d pixels, more than the limit (%4$s pixels). The current frames were kept.', 'l2d-scroll-image-sequence' ),
						$file_name,
						(int) $size[0],
						(int) $size[1],
						number_format_i18n( $max_frame_pixels )
					)
				);
			}

			++$frames;
		}

		return array(
			'frames'  => $frames,
			'skipped' => $skipped,
		);
	}

	/**
	 * 一時フォルダの frames/ を、シーケンスのフォルダの frames/ と入れ替える.
	 *
	 * 古いコマは、新しいコマが frames/ に入るまで消さない(2026-10-15 のレビューの指摘 3. それまでは古い frames/ を
	 * 先に消していたので、そのあとの名前の変更に失敗すると古いコマが失われた).
	 * 1. 一時フォルダの frames/ を、シーケンスのフォルダの frames-new/ へ移す. move_dir() は rename() に失敗すると
	 *    (一時フォルダと uploads が別のディスクのとき)コピーに切り替える. 失敗したら古いコマはそのまま
	 * 2. 古い frames/ の名前を frames-old/ に変える(消さない). 失敗したら frames-new/ を消して終わる
	 * 3. frames-new/ の名前を frames/ に変える. 失敗したら frames-old/ を frames/ に戻す
	 * 4. 成功したら frames-old/ を消す
	 * 2・3 は同じフォルダの中なので rename() で済む.
	 *
	 * 前回の取り込みが途中で止まったときの残り: frames/ が無く frames-old/ だけがあれば、それが最後の正しいコマなので
	 * frames/ に戻してから始める. frames/ もあれば frames-old/ は不要なので消す. frames-new/ は消す.
	 *
	 * @param string $work_dir     一時フォルダ.
	 * @param string $sequence_dir シーケンスのフォルダ.
	 * @return true|WP_Error 成功したら true.
	 */
	private static function swap_frames( $work_dir, $sequence_dir ) {
		global $wp_filesystem;

		$frames_dir = $sequence_dir . '/' . self::FRAMES_DIR;
		$new_dir    = $frames_dir . '-new';
		$old_dir    = $frames_dir . '-old';

		// 前回の取り込みが途中で止まって残ったものを片付ける.
		if ( $wp_filesystem->exists( $old_dir ) ) {
			if ( $wp_filesystem->exists( $frames_dir ) ) {
				$wp_filesystem->delete( $old_dir, true );
			} else {
				$wp_filesystem->move( $old_dir, $frames_dir );
			}
		}
		if ( $wp_filesystem->exists( $new_dir ) ) {
			$wp_filesystem->delete( $new_dir, true );
		}

		// 1. 新しいコマを frames-new/ へ.
		$moved = move_dir( $work_dir . '/' . self::FRAMES_DIR, $new_dir );
		if ( is_wp_error( $moved ) ) {
			return new WP_Error(
				'l2dsis_move_failed',
				sprintf(
					/* translators: %s: error message from WordPress. */
					__( 'Could not save the frames: %s The current frames were kept.', 'l2d-scroll-image-sequence' ),
					$moved->get_error_message()
				)
			);
		}

		// 2. 古いコマを frames-old/ へ退避する(初めての取り込みでは frames/ が無い).
		$has_old = $wp_filesystem->exists( $frames_dir );
		if ( $has_old && ! $wp_filesystem->move( $frames_dir, $old_dir ) ) {
			$wp_filesystem->delete( $new_dir, true );
			return new WP_Error( 'l2dsis_move_failed', __( 'Could not replace the frames. The current frames were kept.', 'l2d-scroll-image-sequence' ) );
		}

		// 3. 新しいコマを frames/ へ. 失敗したら古いコマを戻す.
		if ( ! $wp_filesystem->move( $new_dir, $frames_dir ) ) {
			if ( $has_old ) {
				$wp_filesystem->move( $old_dir, $frames_dir );
			}
			$wp_filesystem->delete( $new_dir, true );
			return new WP_Error( 'l2dsis_move_failed', __( 'Could not replace the frames. The current frames were kept.', 'l2d-scroll-image-sequence' ) );
		}

		// 4. 入れ替えが済んだので、古いコマを消す. 消せなくても新しいコマは入っているので失敗にしない(次の取り込みで消す).
		if ( $has_old ) {
			$wp_filesystem->delete( $old_dir, true );
		}

		// rename() は元の権限と持ち主をそのまま保つ. 念のため、入れ替えた後にも uploads のフォルダとしての権限に直す.
		// 持ち主のグループ(/tmp で作ると wheel など)は変えない. 755 なら他のユーザーは書けないため.
		$wp_filesystem->chmod( $frames_dir, FS_CHMOD_DIR );

		return true;
	}

	/**
	 * フォルダを中身ごと消す. 無ければ何もしない.
	 *
	 * 致命的エラーのときに register_shutdown_function() からも呼ぶので public にしている.
	 *
	 * @param string $dir 消すフォルダ.
	 * @return void
	 */
	public static function delete_dir( $dir ) {
		global $wp_filesystem;

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( $wp_filesystem || WP_Filesystem() ) {
			$wp_filesystem->delete( $dir, true );
		}
	}

	/**
	 * コマを ZIP でダウンロードする URL.
	 *
	 * Nonce は投稿ごとに分ける(URL を別の投稿に書き換えて使い回せないように).
	 *
	 * @param int $post_id 投稿 ID.
	 * @return string URL(エスケープ前).
	 */
	public static function get_download_url( $post_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DOWNLOAD_ACTION,
					'post'   => absint( $post_id ),
				),
				admin_url( 'admin-post.php' )
			),
			self::DOWNLOAD_ACTION . '_' . absint( $post_id )
		);
	}

	/**
	 * Frames/ の画像だけから ZIP を作って送る(admin-post.php から呼ぶ).
	 *
	 * 上げた ZIP を残さない代わりに、本番へ運ぶときや差し替えの元として使う.
	 * 入るのは取り込みの検査を通った画像だけなので、取り込みで捨てたファイルは出てこない.
	 * ZIP はサーバーの一時フォルダに作り、送り終えたら消す(公開の URL には置かない).
	 *
	 * @return void
	 */
	public static function download_frames() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		// nonce が合わなければ、ここで「リンクの有効期限が切れています」の画面を出して止まる.
		check_admin_referer( self::DOWNLOAD_ACTION . '_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to download the frames of this sequence.', 'l2d-scroll-image-sequence' ), '', array( 'response' => 403 ) );
		}

		$files = self::get_frame_files( $post_id );
		if ( empty( $files ) ) {
			wp_die( esc_html__( 'This sequence has no frames.', 'l2d-scroll-image-sequence' ), '', array( 'response' => 404 ) );
		}

		if ( ! class_exists( 'ZipArchive', false ) ) {
			wp_die( esc_html__( 'The PHP ZipArchive extension is required to download frames.', 'l2d-scroll-image-sequence' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$zip_path = wp_tempnam( 'l2dsis-download-' . $post_id );

		// 送る途中で接続が切れて止まっても、一時ファイルを残さない.
		register_shutdown_function( 'wp_delete_file', $zip_path );

		// wp_tempnam() が空のファイルを作るので、OVERWRITE で開き直す(空のファイルは ZIP として開けないため).
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not create the ZIP file.', 'l2d-scroll-image-sequence' ) );
		}

		$frames_dir = self::get_dir( $post_id ) . '/' . self::FRAMES_DIR;
		foreach ( $files as $file ) {
			$zip->addFile( $frames_dir . '/' . $file, $file );
			// webp / jpg / png はすでに圧縮されているので、圧縮せずに入れる(時間がかかるだけで小さくならない).
			$zip->setCompressionName( $file, ZipArchive::CM_STORE );
		}

		if ( ! $zip->close() ) {
			wp_die( esc_html__( 'Could not create the ZIP file.', 'l2d-scroll-image-sequence' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="l2dsis-sequence-' . $post_id . '.zip"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );

		// 大きな ZIP をメモリに読み込まずに送るため、WP_Filesystem ではなく readfile() を使う.
		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- 上のコメントを参照.

		wp_delete_file( $zip_path );
		exit;
	}

	/**
	 * 投稿を完全に削除したら、シーケンスのフォルダも消す.
	 *
	 * ゴミ箱に入れただけでは呼ばれない(wp_delete_post() の中で呼ばれる).
	 * 渡される $post は、wp_delete_post() が get_post() で WP_Post にしてから渡す(wp-includes/post.php)ので、
	 * 型の判定はせず投稿タイプだけを見る(PHPStan 2.x の instanceof.alwaysTrue の指摘で外した).
	 *
	 * @param int     $post_id 投稿 ID.
	 * @param WP_Post $post    削除する投稿.
	 * @return void
	 */
	public static function delete_files( $post_id, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		self::delete_dir( self::get_dir( $post_id ) );
		// 取り込みのロックのファイルも消す. ロックを放すときには消していないため.
		wp_delete_file( self::get_lock_path( $post_id ) );
		self::flush_frame_files_cache( $post_id );
	}

	/**
	 * 画像シーケンスを入れる、uploads の中のこのプラグイン専用のフォルダのパス.
	 *
	 * 呼ぶ wp_upload_dir() の 2 番目の引数を false にして、年月のフォルダを作らないようにする.
	 *
	 * @return string 末尾に / を付けないパス.
	 */
	public static function get_base_dir() {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( $uploads['basedir'] ) . '/' . self::UPLOAD_DIR;
	}

	/**
	 * シーケンスのフォルダのパス. 投稿 ID で分ける(スラッグはあとから変わるため).
	 *
	 * @param int $post_id 投稿 ID.
	 * @return string 末尾に / を付けないパス.
	 */
	public static function get_dir( $post_id ) {
		return self::get_base_dir() . '/' . absint( $post_id );
	}

	/**
	 * シーケンスのフォルダの URL.
	 *
	 * @param int $post_id 投稿 ID.
	 * @return string 末尾に / を付けない URL.
	 */
	public static function get_url( $post_id ) {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( $uploads['baseurl'] ) . '/' . self::UPLOAD_DIR . '/' . absint( $post_id );
	}

	/**
	 * コマ画像のファイル名を、表示する順番(ファイル名の自然順)で返す.
	 *
	 * 保存時に一覧を作らず、表示のたびにフォルダを読む(投稿メタを使わない方針. 【A】の実測で 1 回 0.43ms).
	 * 1 つのブロックの描画でも、PC 用・SP 用・縦横比の取得で同じフォルダを何度も読むので、
	 * 1 回のリクエストの中だけ結果を覚えておく(2026-10-15 のレビューの指摘 4). リクエストをまたいでは覚えないので、
	 * フォルダの中身と食い違わない. 同じリクエストの中でコマを入れ替えたときは flush_frame_files_cache() で消す.
	 *
	 * @param int $post_id 投稿 ID.
	 * @return string[] ファイル名の配列. フォルダが無ければ空.
	 */
	public static function get_frame_files( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! isset( self::$frame_files_cache[ $post_id ] ) ) {
			self::$frame_files_cache[ $post_id ] = self::read_frame_files( $post_id );
		}

		return self::$frame_files_cache[ $post_id ];
	}

	/**
	 * 関数 get_frame_files() が 1 回のリクエストの中で覚えた結果を消す.
	 *
	 * @param int $post_id 投稿 ID.
	 * @return void
	 */
	public static function flush_frame_files_cache( $post_id ) {
		unset( self::$frame_files_cache[ absint( $post_id ) ] );
	}

	/**
	 * コマ画像のファイル名を、フォルダを読んで返す(get_frame_files() の中身).
	 *
	 * @param int $post_id 投稿 ID.
	 * @return string[] ファイル名の配列. フォルダが無ければ空.
	 */
	private static function read_frame_files( $post_id ) {
		$frames_dir = self::get_dir( $post_id ) . '/' . self::FRAMES_DIR;
		if ( ! is_dir( $frames_dir ) ) {
			return array();
		}

		// GLOB_BRACE は一部の環境(Alpine Linux など)に無いので使わない. 全部を読んでから拡張子でしぼる.
		// 拡張子の大文字・小文字は区別しない(取り込み時の wp_check_filetype_and_ext() も区別しないため).
		$paths = glob( $frames_dir . '/*', GLOB_NOSORT );
		if ( false === $paths ) {
			return array();
		}

		$files = array();
		foreach ( $paths as $path ) {
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( is_file( $path ) && in_array( $extension, array( 'webp', 'jpg', 'jpeg', 'png' ), true ) ) {
				$files[] = basename( $path );
			}
		}
		usort( $files, 'strnatcmp' );

		return $files;
	}

	/**
	 * コマ画像の URL を、表示する順番で返す.
	 *
	 * @param int $post_id 投稿 ID.
	 * @return string[] URL の配列.
	 */
	public static function get_frame_urls( $post_id ) {
		$base_url = self::get_url( $post_id ) . '/' . self::FRAMES_DIR . '/';

		return array_map(
			static function ( $file ) use ( $base_url ) {
				return $base_url . rawurlencode( $file );
			},
			self::get_frame_files( $post_id )
		);
	}

	/**
	 * ブロックに表示するコマの URL を返す. 表示してよいシーケンスでなければ空.
	 *
	 * ブロックの属性には投稿 ID だけが入るので、あとからシーケンスが消されたり、ゴミ箱や下書きに戻されたりしていることがある.
	 * 表示してよいのは「公開」の状態のシーケンスだけ(2026-10-15 のレビューの指摘 5. ユーザー決定).
	 * プレビューのときだけ、そのシーケンスを編集できる人には、下書き・予約・保留・非公開も表示する(ゴミ箱は出さない).
	 * なお、コマの画像は uploads の公開 URL に置かれるので、状態にかかわらず URL を知っていれば取れる(機密には使えない).
	 *
	 * @param int $post_id シーケンスの投稿 ID.
	 * @return string[] URL の配列.
	 */
	public static function get_sequence_frame_urls( $post_id ) {
		$post_id = absint( $post_id );
		if ( 0 === $post_id || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return array();
		}

		$status = get_post_status( $post_id );
		if ( 'publish' !== $status ) {
			// is_preview() はメインのクエリの前に呼ぶと _doing_it_wrong を出す(wp-includes/query.php)ので、クエリがあるときだけ呼ぶ.
			$is_preview = isset( $GLOBALS['wp_query'] ) && is_preview();
			if ( 'trash' === $status || ! $is_preview || ! current_user_can( 'edit_post', $post_id ) ) {
				return array();
			}
		}

		return self::get_frame_urls( $post_id );
	}

	/**
	 * 1 コマ目の縦横比を、CSS の aspect-ratio に入れる形(例 "1280 / 720")で返す.
	 *
	 * SP の幅のとき、コマを幅いっぱいの枠に描くので、その枠の比率に使う(l2dsis_get_mobile_css()).
	 * 読むのは 1 コマ目の画像の頭だけ(wp_getimagesize()).
	 *
	 * @param int $post_id シーケンスの投稿 ID.
	 * @return string 縦横比. 読めなければ空.
	 */
	public static function get_frame_ratio( $post_id ) {
		$files = self::get_frame_files( $post_id );
		if ( empty( $files ) ) {
			return '';
		}

		$size = wp_getimagesize( self::get_dir( $post_id ) . '/' . self::FRAMES_DIR . '/' . $files[0] );
		if ( ! $size || empty( $size[0] ) || empty( $size[1] ) ) {
			return '';
		}

		return absint( $size[0] ) . ' / ' . absint( $size[1] );
	}

	/**
	 * PHP のアップロードのエラー番号を、画面に出す文に変える.
	 *
	 * @param int $error UPLOAD_ERR_* の値.
	 * @return string エラーの文.
	 */
	private static function get_upload_error_message( $error ) {
		switch ( $error ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: maximum upload file size. */
					__( 'The ZIP file is larger than the maximum upload size (%s).', 'l2d-scroll-image-sequence' ),
					size_format( wp_max_upload_size() )
				);
			case UPLOAD_ERR_PARTIAL:
				return __( 'The ZIP file was only partially uploaded. Please try again.', 'l2d-scroll-image-sequence' );
			default:
				return sprintf(
					/* translators: %d: PHP upload error code. */
					__( 'The ZIP file could not be uploaded (error code %d).', 'l2d-scroll-image-sequence' ),
					$error
				);
		}
	}

	/**
	 * 保存結果のお知らせを、次に開いた管理画面で出せるように取っておく.
	 *
	 * 保存のあとは post.php へリダイレクトされるので、その場では出せない.
	 * ユーザーごとの短い transient に入れる(投稿メタは使わない).
	 *
	 * @param string $type    'success' か 'error'.
	 * @param string $message 出す文.
	 * @return void
	 */
	private static function set_notice( $type, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * 取っておいたお知らせを出して、消す.
	 *
	 * @return void
	 */
	public static function render_notice() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		wp_admin_notice(
			esc_html( $notice['message'] ),
			array(
				'type'        => 'success' === $notice['type'] ? 'success' : 'error',
				'dismissible' => true,
			)
		);
	}
}
