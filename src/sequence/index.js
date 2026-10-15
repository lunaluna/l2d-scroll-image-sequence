/**
 * ブロック l2d-scroll-image-sequence/sequence を登録する.
 *
 * フロントの描画は render.php(ダイナミックブロック). 保存されるのは中身(InnerBlocks)だけ.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-registration/
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * フロントと編集画面の両方に効く CSS. ファイル名に style を含むので style-index.css に出る.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import './style.scss';

/**
 * Internal dependencies
 */
import Edit from './edit';
import save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: Edit,
	save,
} );
