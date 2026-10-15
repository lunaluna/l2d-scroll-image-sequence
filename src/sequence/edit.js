/**
 * ブロックの編集画面.
 *
 * - サイドバーでシーケンス(PC・SP)・スクロールの長さ・中身を出す位置を選ぶ
 * - 本体は 1 コマ目を背景にして、中身(InnerBlocks)を重ねる. フロントとほぼ同じ見た目にするため
 * - 固定(sticky)・縦の長さ・コマの動きは編集画面では付けない(編集しにくくなるため)
 *
 * 設計はプラン ~/.claude/plans/alpine-2026-a290-image-sequence.md の 3-2 を参照.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import {
	Notice,
	PanelBody,
	RangeControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * 編集画面だけに効く CSS.
 */
import './editor.scss';

/**
 * REST ルート(includes/class-l2dsis-rest.php)のパス.
 */
const SEQUENCES_PATH = '/l2dsis/v1/sequences';

/**
 * シーケンスの一覧を REST から取る.
 *
 * @return {{sequences: Array|null, error: string}} 一覧(読み込み中は null)とエラーの文.
 */
function useSequences() {
	const [ sequences, setSequences ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		// 読み込みの途中でブロックが消されたときに、消えたあとの state を書き換えないようにする.
		let active = true;

		apiFetch( { path: SEQUENCES_PATH } )
			.then( ( items ) => {
				if ( active ) {
					setSequences( Array.isArray( items ) ? items : [] );
				}
			} )
			.catch( ( fetchError ) => {
				if ( active ) {
					setSequences( [] );
					setError(
						fetchError?.message ||
							__(
								'Could not load image sequences.',
								'l2d-scroll-image-sequence'
							)
					);
				}
			} );

		return () => {
			active = false;
		};
	}, [] );

	return { sequences, error };
}

/**
 * セレクトボックスの選択肢を作る.
 *
 * @param {Array}  sequences  REST の一覧.
 * @param {string} emptyLabel 「選ばない」の選択肢の文.
 * @return {Array} SelectControl の options.
 */
function toOptions( sequences, emptyLabel ) {
	return [
		{ label: emptyLabel, value: 0 },
		...sequences.map( ( sequence ) => ( {
			label: sprintf(
				/* translators: 1: sequence title, 2: number of frames. */
				__( '%1$s (%2$d frames)', 'l2d-scroll-image-sequence' ),
				sequence.title,
				sequence.frames
			),
			value: sequence.id,
		} ) ),
	];
}

/**
 * @param {Object}                       props               ブロックの props.
 * @param {Object}                       props.attributes    属性.
 * @param {(attributes: Object) => void} props.setAttributes 属性を書き換える関数.
 * @return {Element} 編集画面の要素.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		sequenceId,
		sequenceIdMobile,
		scrollLength,
		revealAt,
		scrollLengthMobile,
		mobileLayout,
	} = attributes;
	const { sequences, error } = useSequences();

	const selected = sequences?.find(
		( sequence ) => sequence.id === sequenceId
	);

	const blockProps = useBlockProps( {
		className: 'l2dsis-sequence l2dsis-sequence--editor',
	} );
	const innerBlocksProps = useInnerBlocksProps( {
		className: 'l2dsis-sequence__content',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Image sequence',
						'l2d-scroll-image-sequence'
					) }
				>
					{ null === sequences && <Spinner /> }
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					{ null !== sequences && (
						<>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Sequence',
									'l2d-scroll-image-sequence'
								) }
								value={ sequenceId }
								options={ toOptions(
									sequences,
									__(
										'— Select —',
										'l2d-scroll-image-sequence'
									)
								) }
								onChange={ ( value ) =>
									setAttributes( {
										sequenceId: parseInt( value, 10 ) || 0,
									} )
								}
							/>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Sequence for small screens',
									'l2d-scroll-image-sequence'
								) }
								help={ __(
									'Used when the screen is at or below the breakpoint (1024px by default).',
									'l2d-scroll-image-sequence'
								) }
								value={ sequenceIdMobile }
								options={ toOptions(
									sequences,
									__(
										'Same as above',
										'l2d-scroll-image-sequence'
									)
								) }
								onChange={ ( value ) =>
									setAttributes( {
										sequenceIdMobile:
											parseInt( value, 10 ) || 0,
									} )
								}
							/>
						</>
					) }
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Scroll length (× screen height)',
							'l2d-scroll-image-sequence'
						) }
						help={ __(
							'How far the visitor scrolls, in screen heights, to play all frames while the section is pinned.',
							'l2d-scroll-image-sequence'
						) }
						value={ scrollLength }
						min={ 0.5 }
						max={ 5 }
						step={ 0.1 }
						onChange={ ( value ) =>
							setAttributes( { scrollLength: value ?? 1.8 } )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Reveal point',
							'l2d-scroll-image-sequence'
						) }
						help={ __(
							'Blocks inside with the "l2dsis-reveal" class fade in when playback reaches this point (0 = start, 1 = end). Their child elements appear one after another.',
							'l2d-scroll-image-sequence'
						) }
						value={ revealAt }
						min={ 0 }
						max={ 1 }
						step={ 0.05 }
						onChange={ ( value ) =>
							setAttributes( { revealAt: value ?? 0.6 } )
						}
					/>
				</PanelBody>

				{ /* SP の幅(既定 1024px 以下)だけに効く設定. 編集画面の見た目には出さない(フロントで確かめる). */ }
				<PanelBody
					title={ __( 'Small screens', 'l2d-scroll-image-sequence' ) }
					initialOpen={ false }
				>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Layout', 'l2d-scroll-image-sequence' ) }
						help={ __(
							'"Stack" places the frames below the blocks inside. The section is pinned only when the blocks (and the frames) fit in the screen height; otherwise it scrolls normally and shows the first frame.',
							'l2d-scroll-image-sequence'
						) }
						value={ mobileLayout }
						options={ [
							{
								label: __(
									'Overlay (frames behind the blocks)',
									'l2d-scroll-image-sequence'
								),
								value: 'overlay',
							},
							{
								label: __(
									'Stack (frames below the blocks)',
									'l2d-scroll-image-sequence'
								),
								value: 'stack',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( {
								mobileLayout:
									'stack' === value ? 'stack' : 'overlay',
							} )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Scroll length (× screen height)',
							'l2d-scroll-image-sequence'
						) }
						help={ __(
							'0 uses the same scroll length as large screens.',
							'l2d-scroll-image-sequence'
						) }
						value={ scrollLengthMobile }
						min={ 0 }
						max={ 5 }
						step={ 0.1 }
						onChange={ ( value ) =>
							setAttributes( { scrollLengthMobile: value ?? 0 } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="l2dsis-sequence__sticky">
					{ selected?.first_frame ? (
						<div className="l2dsis-sequence__media">
							<img
								className="l2dsis-sequence__poster"
								src={ selected.first_frame }
								alt=""
							/>
						</div>
					) : (
						<p className="l2dsis-sequence__placeholder">
							{ __(
								'Select an image sequence in the block settings.',
								'l2d-scroll-image-sequence'
							) }
						</p>
					) }
					<div { ...innerBlocksProps } />
				</div>
			</div>
		</>
	);
}
