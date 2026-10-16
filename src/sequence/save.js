/**
 * 投稿の本文に保存する内容.
 *
 * 外側の箱・1 コマ目・canvas は render.php が描くので、保存するのは中身(InnerBlocks)だけ.
 * 外側の要素を保存しないので、render.php の出力を変えても既存の投稿で検証エラーにならない.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#save
 */
import { InnerBlocks } from '@wordpress/block-editor';

/**
 * @return {Element} 中身だけの要素.
 */
export default function save() {
	return <InnerBlocks.Content />;
}
