<?php
/**
 * ブロック l2d-scroll-image-sequence/sequence のフロント側の描画.
 *
 * 出力の形:
 *   外側の箱(.l2dsis-sequence. JS が動くと .is-pinned が付き、高さが 100vh × (1 + scrollLength) になる)
 *   └ 固定する箱(.l2dsis-sequence__sticky. 高さ 100vh. .is-pinned のときだけ sticky)
 *     ├ 1 コマ目の <picture>(JS が動かないときや、コマを読み込む前の下敷き)
 *     ├ <canvas>(view.js がスクロールに合わせてコマを描く)
 *     └ 中身(InnerBlocks の $content)
 *
 * SP の幅(l2dsis_get_breakpoint() 以下)では、<picture> と canvas を幅いっぱいの横長の枠にして上下中央に置く.
 * その CSS はメインファイルの l2dsis_get_mobile_css(). 枠の縦横比はここで --l2dsis-mobile-ratio に入れる.
 *
 * コマの URL・切り替え幅・中身を出す位置は data- 属性で view.js に渡す.
 * このファイルは描画のたびに require される(wp-includes/blocks.php)ので、関数を定義しない.
 *
 * 使える変数: $attributes(ブロックの属性)・$content(InnerBlocks を描画した HTML)・$block(WP_Block).
 * 状態ごとの表示はプラン ~/.claude/plans/alpine-2026-a290-image-sequence.md の 3-3 を参照.
 *
 * @package L2dScrollImageSequence
 */

$l2dsis_sequence_id        = isset( $attributes['sequenceId'] ) ? absint( $attributes['sequenceId'] ) : 0;
$l2dsis_sequence_id_mobile = isset( $attributes['sequenceIdMobile'] ) ? absint( $attributes['sequenceIdMobile'] ) : 0;

// scrollLength は画面の高さの何倍でコマを最後まで進めるか. 0 以下や極端に大きい値は、ページが壊れない範囲に収める.
$l2dsis_scroll_length = isset( $attributes['scrollLength'] ) ? (float) $attributes['scrollLength'] : 1.8;
$l2dsis_scroll_length = min( 10.0, max( 0.1, $l2dsis_scroll_length ) );

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
$l2dsis_scroll_length_text = wp_json_encode( $l2dsis_scroll_length );
$l2dsis_reveal_at_text     = wp_json_encode( $l2dsis_reveal_at );

$l2dsis_wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'              => 'l2dsis-sequence ' . ( $l2dsis_has_frames ? 'has-frames' : 'is-empty' ),
		'style'              => '--l2dsis-scroll-length:' . $l2dsis_scroll_length_text . ';'
			. ( '' !== $l2dsis_mobile_ratio ? '--l2dsis-mobile-ratio:' . $l2dsis_mobile_ratio . ';' : '' ),
		// 値は get_block_wrapper_attributes() の中で esc_attr() される(wp-includes/class-wp-block-supports.php).
		'data-frames'        => $l2dsis_has_frames ? wp_json_encode( $l2dsis_frames ) : '',
		'data-frames-mobile' => ! empty( $l2dsis_frames_mobile ) ? wp_json_encode( $l2dsis_frames_mobile ) : '',
		'data-breakpoint'    => (string) $l2dsis_breakpoint,
		'data-reveal-at'     => $l2dsis_reveal_at_text,
	)
);
?>
<div <?php echo $l2dsis_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 値は get_block_wrapper_attributes() の中で esc_attr() 済み(wp-includes/class-wp-block-supports.php). ?>>
	<div class="l2dsis-sequence__sticky">
		<?php if ( $l2dsis_has_frames ) : ?>
			<?php /* 1 コマ目. JS が動かないときはこれだけが見える. SP 用のシーケンスがあれば、SP の幅ではその 1 コマ目にする. */ ?>
			<picture class="l2dsis-sequence__poster">
				<?php if ( ! empty( $l2dsis_frames_mobile ) ) : ?>
					<source media="<?php echo esc_attr( '(max-width: ' . $l2dsis_breakpoint . 'px)' ); ?>" srcset="<?php echo esc_url( $l2dsis_frames_mobile[0] ); ?>">
				<?php endif; ?>
				<img src="<?php echo esc_url( $l2dsis_frames[0] ); ?>" alt="" decoding="async">
			</picture>
			<canvas class="l2dsis-sequence__canvas" aria-hidden="true"></canvas>
		<?php endif; ?>
		<div class="l2dsis-sequence__content">
			<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- InnerBlocks をコアが描画した HTML. ?>
		</div>
	</div>
</div>
