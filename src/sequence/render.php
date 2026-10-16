<?php
/**
 * ブロック l2d-scroll-image-sequence/sequence のフロント側の描画.
 *
 * 出力の形:
 *   外側の箱(.l2dsis-sequence. JS が固定すると .is-pinned が付く)
 *   ├ 固定の区間(.l2dsis-sequence__track. 固定するときは高さ 100vh × (1 + スクロールの長さ))
 *   │ └ 固定する箱(.l2dsis-sequence__sticky. 固定しないときは中身に合わせて伸びる. 固定するときは 100vh で sticky)
 *   │   ├ 枠(.l2dsis-sequence__media)
 *   │   │ ├ 1 コマ目の <picture>(JS が動かないときや、固定しないとき、コマを読み込む前の下敷き)
 *   │   │ └ <canvas>(view.js がスクロールに合わせてコマを描く)
 *   │   └ 中身(InnerBlocks の $content)
 *   └ 後ろの入れ物(.l2dsis-sequence__after. 最初は空)
 *
 * SP の幅(l2dsis_get_breakpoint() 以下)では、view.js が中身の直下の .l2dsis-after-mobile のブロックを「後ろの入れ物」へ移し、
 * 固定が終わったときに枠のすぐ下に並ぶよう負の margin-top を付ける(UK の A290 の SP と同じ. 2026-10-15 ユーザー決定).
 * SP の枠の CSS はメインファイルの l2dsis_get_mobile_css(). 枠の縦横比はここで --l2dsis-mobile-ratio に入れる.
 * 固定するかどうかは view.js が、中身が 100vh に収まるかで決める.
 *
 * コマの URL・切り替え幅・中身を出す位置は data- 属性で view.js に渡す.
 * このファイルは描画のたびに require される(wp-includes/blocks.php)ので、関数を定義しない.
 *
 * 使える変数: $attributes(ブロックの属性)・$content(InnerBlocks を描画した HTML)・$block(WP_Block).
 * 状態ごとの表示はプラン ~/.claude/plans/alpine-2026-a290-image-sequence.md の 3-3 を参照.
 *
 * @package L2dScrollImageSequence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // セキュリティ: 直接アクセスを防止.
}

$l2dsis_sequence_id        = isset( $attributes['sequenceId'] ) ? absint( $attributes['sequenceId'] ) : 0;
$l2dsis_sequence_id_mobile = isset( $attributes['sequenceIdMobile'] ) ? absint( $attributes['sequenceIdMobile'] ) : 0;

// scrollLength は画面の高さの何倍でコマを最後まで進めるか. 0 以下や極端に大きい値は、ページが壊れない範囲に収める.
$l2dsis_scroll_length = isset( $attributes['scrollLength'] ) ? (float) $attributes['scrollLength'] : 1.8;
$l2dsis_scroll_length = min( 10.0, max( 0.1, $l2dsis_scroll_length ) );

// scrollLengthMobile は SP の幅でのスクロールの長さ. 0 なら PC と同じ(CSS 変数を出さない).
$l2dsis_scroll_length_mobile = isset( $attributes['scrollLengthMobile'] ) ? (float) $attributes['scrollLengthMobile'] : 0.0;
$l2dsis_scroll_length_mobile = $l2dsis_scroll_length_mobile > 0 ? min( 10.0, max( 0.1, $l2dsis_scroll_length_mobile ) ) : 0.0;

// revealAt は進み具合(0〜1)のどこで、中身の .l2dsis-reveal を出すか.
$l2dsis_reveal_at = isset( $attributes['revealAt'] ) ? (float) $attributes['revealAt'] : 0.6;
$l2dsis_reveal_at = min( 1.0, max( 0.0, $l2dsis_reveal_at ) );

$l2dsis_frames        = L2dsis_Sequences::get_sequence_frame_urls( $l2dsis_sequence_id );
$l2dsis_frames_mobile = ( $l2dsis_sequence_id_mobile && $l2dsis_sequence_id_mobile !== $l2dsis_sequence_id )
	? L2dsis_Sequences::get_sequence_frame_urls( $l2dsis_sequence_id_mobile )
	: array();

// SP 用のシーケンスに切り替える幅. フィルター l2dsis_breakpoint はメインファイルの関数の中で 1 か所だけ読む.
$l2dsis_breakpoint = l2dsis_get_breakpoint();

$l2dsis_has_frames = ! empty( $l2dsis_frames );

// SP の幅のときにコマを描く、幅いっぱいの枠の縦横比(SP 用のコマの 1 コマ目. 無ければ PC 用). 読めなければ CSS の既定 16 / 9.
$l2dsis_mobile_ratio = '';
if ( $l2dsis_has_frames ) {
	$l2dsis_mobile_ratio = L2dsis_Sequences::get_frame_ratio( ! empty( $l2dsis_frames_mobile ) ? $l2dsis_sequence_id_mobile : $l2dsis_sequence_id );
}

// 小数を文字列にするときは wp_json_encode() を使う. PHP 7.4 の (string) はロケールによって小数点が「,」になり、
// CSS や JS が読めなくなるため(json_encode はロケールに左右されない).
$l2dsis_style = '--l2dsis-scroll-length:' . wp_json_encode( $l2dsis_scroll_length ) . ';';
if ( $l2dsis_scroll_length_mobile > 0 ) {
	$l2dsis_style .= '--l2dsis-scroll-length-mobile:' . wp_json_encode( $l2dsis_scroll_length_mobile ) . ';';
}
if ( '' !== $l2dsis_mobile_ratio ) {
	$l2dsis_style .= '--l2dsis-mobile-ratio:' . $l2dsis_mobile_ratio . ';';
}

// SP の枠の最小の高さ(例 330px・100vh). 「数値 + 単位」だけを通す(l2dsis_sanitize_css_length()). 空なら出さない
// (CSS の既定 0px = 今までどおりコマの比率の枠). テーマの CSS からも同じ変数で上書きできる.
$l2dsis_mobile_min_height = l2dsis_sanitize_css_length( isset( $attributes['mobileMinHeight'] ) ? $attributes['mobileMinHeight'] : '' );
if ( '' !== $l2dsis_mobile_min_height ) {
	$l2dsis_style .= '--l2dsis-mobile-min-height:' . $l2dsis_mobile_min_height . ';';
}

// 1 コマ目(下敷き)を早く出す(2026-10-16. Live Link の細い回線で、コマの表示が遅かった).
// ページの中で最初のブロックだけ、1 コマ目を <head> で先読みし(ブロックテーマのとき), 下敷きの <img> を優先して読む.
// 2 つ目以降のブロックは、下敷きを画面に近づいてから読む(loading="lazy"). 上のブロックと回線を取り合わないように.
// SP 用があれば、SP 用と PC 用を media で出し分けて、両方を読まないようにする.
$l2dsis_is_first_block = $l2dsis_has_frames && L2dsis_Sequences::claim_first_block();
if ( $l2dsis_is_first_block ) {
	if ( ! empty( $l2dsis_frames_mobile ) ) {
		L2dsis_Sequences::add_preload( $l2dsis_frames_mobile[0], '(max-width: ' . $l2dsis_breakpoint . 'px)' );
		L2dsis_Sequences::add_preload( $l2dsis_frames[0], '(min-width: ' . ( $l2dsis_breakpoint + 1 ) . 'px)' );
	} else {
		L2dsis_Sequences::add_preload( $l2dsis_frames[0] );
	}
}

$l2dsis_wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'              => 'l2dsis-sequence ' . ( $l2dsis_has_frames ? 'has-frames' : 'is-empty' ),
		'style'              => $l2dsis_style,
		// 値は get_block_wrapper_attributes() の中で esc_attr() される(wp-includes/class-wp-block-supports.php).
		// コマの URL は JSON_UNESCAPED_SLASHES で、普通の URL の形(http://…)のまま出す. 既定の http:\/\/… の形だと、
		// HTML の中の URL を書き換える仕組み(Local の Live Link・CDN 系のプラグイン)が書き換えられず、別のホストの
		// 画像を取りに行ってしまう(2026-10-16【A】の指摘. Live Link でスマホから開くとコマだけ読み込まれなかった).
		'data-frames'        => $l2dsis_has_frames ? wp_json_encode( $l2dsis_frames, JSON_UNESCAPED_SLASHES ) : '',
		'data-frames-mobile' => ! empty( $l2dsis_frames_mobile ) ? wp_json_encode( $l2dsis_frames_mobile, JSON_UNESCAPED_SLASHES ) : '',
		'data-breakpoint'    => (string) $l2dsis_breakpoint,
		'data-reveal-at'     => wp_json_encode( $l2dsis_reveal_at ),
	)
);
?>
<div <?php echo $l2dsis_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 値は get_block_wrapper_attributes() の中で esc_attr() 済み(wp-includes/class-wp-block-supports.php). ?>>
	<div class="l2dsis-sequence__track">
		<div class="l2dsis-sequence__sticky">
			<?php if ( $l2dsis_has_frames ) : ?>
				<?php /* 枠. 1 コマ目(JS が動かないときや固定しないときはこれだけが見える. SP 用があれば SP の幅ではその 1 コマ目)と canvas. */ ?>
				<div class="l2dsis-sequence__media">
					<picture class="l2dsis-sequence__poster">
						<?php if ( ! empty( $l2dsis_frames_mobile ) ) : ?>
							<source media="<?php echo esc_attr( '(max-width: ' . $l2dsis_breakpoint . 'px)' ); ?>" srcset="<?php echo esc_url( $l2dsis_frames_mobile[0] ); ?>">
						<?php endif; ?>
						<?php if ( $l2dsis_is_first_block ) : ?>
							<img src="<?php echo esc_url( $l2dsis_frames[0] ); ?>" alt="" decoding="async" fetchpriority="high">
						<?php else : ?>
							<img src="<?php echo esc_url( $l2dsis_frames[0] ); ?>" alt="" decoding="async" loading="lazy">
						<?php endif; ?>
					</picture>
					<canvas class="l2dsis-sequence__canvas" aria-hidden="true"></canvas>
				</div>
			<?php endif; ?>
			<div class="l2dsis-sequence__content">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- InnerBlocks をコアが描画した HTML. ?>
			</div>
		</div>
	</div>
	<?php /* 後ろの入れ物. SP の幅で view.js が .l2dsis-after-mobile のブロックを移す. JS が動かないときは空のまま. */ ?>
	<div class="l2dsis-sequence__after"></div>
</div>
