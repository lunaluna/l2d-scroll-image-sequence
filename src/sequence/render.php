<?php
/**
 * ブロック l2d-scroll-image-sequence/sequence のフロント側の描画.
 *
 * いまは create-block が生成した仮の出力のまま. Step 3 で固定の箱・canvas・
 * InnerBlocks を描く処理に作り直す.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/docs/reference-guides/block-api/block-metadata.md#render
 *
 * @package L2dScrollImageSequence
 */

?>
<p <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 値は get_block_wrapper_attributes() の中で esc_attr() 済み(wp-includes/class-wp-block-supports.php). ?>>
	<?php esc_html_e( 'Scroll Image Sequence – hello from a dynamic block!', 'l2d-scroll-image-sequence' ); ?>
</p>
