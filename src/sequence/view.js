/**
 * ブロック l2d-scroll-image-sequence/sequence のフロントの動き.
 *
 * render.php が出した .l2dsis-sequence ごとに、スクロールの進み具合(0〜1)に合わせてコマを canvas に描く.
 * 素の JS で書く(ライブラリは使わない). 状態ごとの表示はプラン ~/.claude/plans/alpine-2026-a290-image-sequence.md の 3-3.
 *
 * 状態(mode)は 3 つ. 画面の大きさが変わるたびに決め直す:
 * - reduced(視差効果を減らす. prefers-reduced-motion: reduce): 固定せず、最後のコマ 1 枚だけを読んで描く.
 *   .l2dsis-reveal も出したまま
 * - pinned(固定する): .is-pinned を付けて固定し、スクロールに合わせてコマを進める.
 *   読み込めていないコマは、読み込めた近いコマで代わりに描く
 * - static(固定しない): 中身が画面に収まらないとき(画面が低い SP など). コマは読まず、1 コマ目の <picture> だけを
 *   見せる. 外側の箱は中身に合わせて伸びる. JS が動かないときも、この見え方になる(.is-pinned が付かないため)
 * コマが無いとき(.is-empty)は、ここに来ない.
 *
 * SP の幅では、中身の直下の .l2dsis-after-mobile のブロックを、固定の区間の後ろ(.l2dsis-sequence__after)へ移す.
 * 固定するときは、固定が終わったときに枠のすぐ下に並ぶよう、負の margin-top で寄せる(UK の A290 の SP と同じ).
 */

/**
 * 先読みを始める距離. 箱の上端が「画面の下端 + 画面の高さ × 2」まで近づいたら、全コマを読み始める.
 * 先読みを始めてから固定が始まるまでのスクロールは、画面 3 つ分(1440 × 900 で 2700px)になる.
 *
 * 根拠は 2026-10-15 の【A】の実測(キャッシュなし・最初のコマの要求からの時間. PC 90 コマ 3.4MB):
 * 20Mbps・40ms で最初の 10 コマ 1.1 秒・半分 2.4 秒・全部 4.4 秒 / 5Mbps・150ms で 4.3 秒・9.3 秒・17.4 秒.
 * 1 秒に 1000px スクロールすると(この速さは仮定で未実測), 固定の始まりまで約 2.7 秒あり、20Mbps なら前半のコマが
 * 間に合う. 前の値の 100%(画面 2 つ分・1.8 秒)では最初の 10 コマまでしか間に合わなかった.
 * 5Mbps ではどちらでも間に合わない. それ以上に早めると、セクションまで来ない人にも数 MB を読ませるので、ここで止める.
 * ページを開いた時点で箱がこの範囲に入っていれば(A290 の #19 など)、すぐに読み始める.
 */
const PRELOAD_ROOT_MARGIN = '200% 0px';

/**
 * 値を min〜max に収める.
 *
 * @param {number} value 値.
 * @param {number} min   下限.
 * @param {number} max   上限.
 * @return {number} 収めた値.
 */
const clamp = ( value, min, max ) => Math.min( max, Math.max( min, value ) );

/**
 * data- 属性の JSON(コマの URL の配列)を読む. 壊れていたら空.
 *
 * @param {string|undefined} json data- 属性の値.
 * @return {string[]} URL の配列.
 */
function parseFrames( json ) {
	if ( ! json ) {
		return [];
	}
	try {
		const frames = JSON.parse( json );
		return Array.isArray( frames ) ? frames : [];
	} catch {
		return [];
	}
}

/**
 * 1 つのブロックの動きを組み立てる.
 *
 * @param {HTMLElement} root .l2dsis-sequence の要素.
 */
function setupSequence( root ) {
	const track = root.querySelector( '.l2dsis-sequence__track' );
	const sticky = root.querySelector( '.l2dsis-sequence__sticky' );
	const media = root.querySelector( '.l2dsis-sequence__media' );
	const content = root.querySelector( '.l2dsis-sequence__content' );
	const after = root.querySelector( '.l2dsis-sequence__after' );
	const canvas = root.querySelector( '.l2dsis-sequence__canvas' );
	const context = canvas?.getContext( '2d' );
	if (
		! track ||
		! sticky ||
		! media ||
		! content ||
		! after ||
		! canvas ||
		! context
	) {
		return;
	}

	const desktopFrames = parseFrames( root.dataset.frames );
	if ( ! desktopFrames.length ) {
		return;
	}
	const mobileFrames = parseFrames( root.dataset.framesMobile );

	const breakpoint = parseInt( root.dataset.breakpoint, 10 ) || 1024;
	const revealAt = clamp( parseFloat( root.dataset.revealAt ) || 0, 0, 1 );
	const mobileQuery = window.matchMedia( `(max-width: ${ breakpoint }px)` );
	const reducedMotion = window.matchMedia(
		'(prefers-reduced-motion: reduce)'
	).matches;
	// SP の幅で固定の区間の外(後ろの入れ物)へ移すブロックと、元の場所に置く目印(PC の幅で戻すため).
	// UK の A290 の SP では、数値は固定した画面の外にあり、固定が終わると車のすぐ下に並ぶ(2026-10-15 の実測).
	// 移すのは中身の直下のブロックだけ. グループの中のブロックを移すと、親から外れて親のクラスに頼る
	// テーマの CSS が SP でだけ効かなくなり、編集画面とも構造が食い違うため. テーマのクラスを持つグループは、
	// このブロックの外側に置けば、移したあとも親子関係が変わらない.
	const afterMobileBlocks = [
		...content.querySelectorAll( ':scope > .l2dsis-after-mobile' ),
	].map( ( element ) => ( {
		element,
		marker: document.createComment( 'l2dsis-after-mobile' ),
	} ) );
	let blocksMovedAfter = false;

	// 今の状態('reduced' / 'pinned' / 'static'). 最初は決まっていない.
	let mode = '';
	// 今使っているコマの URL と、読み込んだ画像(読み込み中・失敗・読まないコマは null).
	let frames = [];
	let images = [];
	// 箱が先読みの範囲に入ったか(IntersectionObserver). 入ったあとで固定するようになったときにも読み始める.
	let nearViewport = false;
	let loadingStarted = false;
	// 最後に描いたコマの番号(読み込みが進んだときに、より近いコマで描き直すかの判定に使う).
	let drawnIndex = -1;
	let wantedIndex = 0;
	let frameRequest = 0;

	/**
	 * 画面の幅に合うコマの一覧を選ぶ. SP 用が無ければ PC 用を使う.
	 *
	 * @return {string[]} URL の配列.
	 */
	const pickFrames = () =>
		mobileQuery.matches && mobileFrames.length
			? mobileFrames
			: desktopFrames;

	/**
	 * コマを読み始める. コマの一覧を入れ替えたときは呼び直す.
	 *
	 * 視差効果を減らす設定のときは、最後のコマしか描かないので、最後のコマ 1 枚だけを読む
	 * (2026-10-15 ユーザー決定. それまでは全コマを読み、届くたびに描き直していた).
	 */
	const startLoading = () => {
		loadingStarted = true;
		images = frames.map( () => null );

		frames.forEach( ( url, index ) => {
			if ( reducedMotion && index !== frames.length - 1 ) {
				return;
			}
			const image = new window.Image();
			image.decoding = 'async';
			image.onload = () => {
				// 一覧を入れ替えたあとに、古い一覧の画像が届いたら捨てる.
				if ( frames[ index ] !== url ) {
					return;
				}
				images[ index ] = image;
				// 今欲しいコマに、これまで描いたものより近ければ描き直す.
				if (
					drawnIndex === -1 ||
					Math.abs( index - wantedIndex ) <
						Math.abs( drawnIndex - wantedIndex )
				) {
					requestDraw();
				}
			};
			// 失敗したコマは null のまま. 近いコマで代わりに描く(3-3 の「一部が失敗」).
			image.src = url;
		} );
	};

	/**
	 * 読み始めてよい条件がそろっていれば読み始める. 固定しない状態(static)では 1 コマ目の画像だけで足りるので読まない.
	 */
	const maybeStartLoading = () => {
		if ( nearViewport && ! loadingStarted && 'static' !== mode ) {
			startLoading();
		}
	};

	/**
	 * 欲しいコマに一番近い、読み込めたコマの番号を返す.
	 *
	 * @param {number} index 欲しいコマの番号.
	 * @return {number} 読み込めたコマの番号. 1 枚も無ければ -1.
	 */
	const nearestLoaded = ( index ) => {
		for ( let distance = 0; distance < images.length; distance++ ) {
			if ( images[ index - distance ] ) {
				return index - distance;
			}
			if ( images[ index + distance ] ) {
				return index + distance;
			}
		}
		return -1;
	};

	/**
	 * 中身(InnerBlocks)の、それ自体の高さを測る.
	 *
	 * 固定しているときの中身は height: 100% で画面の高さになるので、いったん高さの指定を外して測る.
	 * 中身の箱は display: flow-root なので、中のブロックの上下の margin も高さに入る.
	 *
	 * @return {number} 高さ(px).
	 */
	const measureContent = () => {
		const { height, minHeight } = content.style;
		content.style.height = 'auto';
		content.style.minHeight = '0';
		const contentHeight = content.offsetHeight;
		content.style.height = height;
		content.style.minHeight = minHeight;
		return contentHeight;
	};

	/**
	 * 100vh の高さ(px)を測るための、見えない要素. 外側の箱(position: relative)の中に 1 つ置く.
	 *
	 * 固定する箱は 100vh なので、「固定したときに中身が収まるか」は 100vh と比べる.
	 * window.innerHeight は SP でアドレスバーが出たり隠れたりするたびに変わり(スクロールのたびに resize が起きる),
	 * 境目の高さの中身だとスクロールの途中で固定・固定しないが切り替わって跳ぶ. 100vh はアドレスバーで変わらない.
	 */
	const viewportProbe = document.createElement( 'div' );
	viewportProbe.setAttribute( 'aria-hidden', 'true' );
	viewportProbe.style.cssText =
		'position:absolute;top:0;left:0;width:0;height:100vh;visibility:hidden;pointer-events:none;';
	root.appendChild( viewportProbe );

	/**
	 * 固定してよいか(固定したときに中身が固定する箱 = 100vh に収まるか)を判定する.
	 * SP で .l2dsis-after-mobile を後ろの入れ物へ移したあとに呼ぶ(移したブロックは高さに入らない).
	 *
	 * @return {boolean} 固定してよいなら true.
	 */
	const fitsInViewport = () => measureContent() <= viewportProbe.offsetHeight;

	/**
	 * SP の幅では .l2dsis-after-mobile のブロックを後ろの入れ物へ移し、PC の幅では元の場所へ戻す.
	 * 元の場所には目印(コメントノード)を置いておき、その直前へ戻す.
	 */
	const placeAfterMobileBlocks = () => {
		const shouldMove = mobileQuery.matches && afterMobileBlocks.length > 0;
		if ( shouldMove === blocksMovedAfter ) {
			return;
		}
		blocksMovedAfter = shouldMove;

		afterMobileBlocks.forEach( ( { element, marker } ) => {
			if ( shouldMove ) {
				element.before( marker );
				after.appendChild( element );
			} else {
				marker.before( element );
				marker.remove();
			}
		} );
	};

	/**
	 * 後ろの入れ物を、枠の下端まで引き上げる(負の margin-top).
	 *
	 * 固定が終わったとき、固定する箱の下端は固定の区間の下端と同じ位置になる. 後ろの入れ物はその後ろに続くので、
	 * 「固定する箱の下端 − 枠の下端」(枠の下の空き)だけ上へ寄せると、枠のすぐ下に並ぶ(UK の SP と同じ).
	 * 枠は transform で中央に置いているので、offsetTop ではなく getBoundingClientRect() で測る.
	 * 移したブロックが無いとき・固定しないとき(固定する箱が中身に合わせて伸びる)は寄せない.
	 */
	const alignAfter = () => {
		let shift = 0;
		if ( blocksMovedAfter && 'pinned' === mode ) {
			const stickyRect = sticky.getBoundingClientRect();
			const mediaRect = media.getBoundingClientRect();
			shift = Math.max(
				0,
				Math.round( stickyRect.bottom - mediaRect.bottom )
			);
		}
		after.style.marginTop = shift ? `${ -shift }px` : '';
	};

	// 最後に判定したときの「画面の幅 × 100vh」. これが変わったときだけ判定し直す(アドレスバーの出し入れでは変わらない).
	let lastViewportKey = '';

	/**
	 * Canvas の描く大きさを、canvas が画面に表示されている大きさに合わせる.
	 * 画面の解像度(devicePixelRatio)の分だけ大きくして、ぼやけないようにする.
	 *
	 * PC では固定する箱いっぱい、SP では幅いっぱいの横長の枠(l2dsis_get_mobile_css())になるので、
	 * 箱ではなく canvas 自身の大きさを見る.
	 */
	const resizeCanvas = () => {
		const ratio = window.devicePixelRatio || 1;
		const width = Math.round( canvas.clientWidth * ratio );
		const height = Math.round( canvas.clientHeight * ratio );
		if ( canvas.width !== width || canvas.height !== height ) {
			canvas.width = width;
			canvas.height = height;
			drawnIndex = -1;
		}
	};

	/**
	 * スクロールの進み具合(0〜1)を返す.
	 *
	 * 固定しているときは、外側の箱の上端が画面の上端に来たときが 0、外側の箱の下端が画面の下端に来たときが 1.
	 * 視差効果を減らすときは 1(最後のコマ).
	 *
	 * @return {number} 進み具合.
	 */
	const getProgress = () => {
		if ( 'reduced' === mode ) {
			return 1;
		}
		// 外側の箱ではなく固定の区間で測る(外側の箱には後ろの入れ物も入っているため).
		const rect = track.getBoundingClientRect();
		const distance = rect.height - window.innerHeight;
		if ( distance <= 0 ) {
			return 0;
		}
		return clamp( -rect.top / distance, 0, 1 );
	};

	/**
	 * 今の進み具合のコマを描く. object-fit: cover と同じように、枠を埋めるように切り抜く.
	 */
	const draw = () => {
		frameRequest = 0;

		// 固定しないときは canvas を使わない(style.scss で隠す). 中身はずっと出しておく.
		if ( 'static' === mode ) {
			root.classList.add( 'is-revealed' );
			return;
		}

		const progress = getProgress();
		wantedIndex = Math.round( progress * ( frames.length - 1 ) );

		// .l2dsis-reveal の中身を出すかどうか(style.scss で opacity を切り替える).
		root.classList.toggle(
			'is-revealed',
			'reduced' === mode || progress >= revealAt
		);

		const index = nearestLoaded( wantedIndex );
		if ( -1 === index || index === drawnIndex ) {
			return;
		}

		const image = images[ index ];
		const scale = Math.max(
			canvas.width / image.naturalWidth,
			canvas.height / image.naturalHeight
		);
		const width = image.naturalWidth * scale;
		const height = image.naturalHeight * scale;

		context.drawImage(
			image,
			( canvas.width - width ) / 2,
			( canvas.height - height ) / 2,
			width,
			height
		);
		drawnIndex = index;
		root.classList.add( 'is-drawn' );
	};

	/**
	 * 次の描画のタイミング(requestAnimationFrame)にまとめて描く. スクロールのたびに描かないようにする.
	 */
	function requestDraw() {
		if ( ! frameRequest ) {
			frameRequest = window.requestAnimationFrame( draw );
		}
	}

	/**
	 * コマの一覧を選び直す(最初と、画面の幅が切り替わったとき).
	 */
	const selectFrames = () => {
		const next = pickFrames();
		if ( next === frames ) {
			return;
		}
		frames = next;
		images = frames.map( () => null );
		drawnIndex = -1;
		if ( loadingStarted ) {
			startLoading();
		}
	};

	/**
	 * 状態(reduced / pinned / static)を決め直し、見た目と読み込みを合わせる.
	 * 最初と、画面の大きさ・幅の切り替えが変わったときに呼ぶ.
	 */
	const updateMode = () => {
		lastViewportKey = `${ window.innerWidth }x${ viewportProbe.offsetHeight }`;

		// 固定できるかの判定の前に、SP なら .l2dsis-after-mobile を外へ出しておく.
		placeAfterMobileBlocks();

		let next = 'reduced';
		if ( ! reducedMotion ) {
			next = fitsInViewport() ? 'pinned' : 'static';
		}

		if ( next !== mode ) {
			mode = next;
			// 固定する. 高さは style.scss(と SP の CSS)で 100vh × (1 + スクロールの長さ) になる.
			root.classList.toggle( 'is-pinned', 'pinned' === mode );
			// 視差効果を減らすときは固定しないが、最後のコマを canvas に描くので canvas を隠さない.
			root.classList.toggle( 'is-reduced-motion', 'reduced' === mode );
			drawnIndex = -1;
		}

		resizeCanvas();
		alignAfter();
		maybeStartLoading();
		requestDraw();
	};

	selectFrames();
	updateMode();

	// 箱が画面に近づいたらコマを読み始める. 1 回で十分なので、範囲に入ったら見張るのをやめる.
	if ( 'IntersectionObserver' in window ) {
		const observer = new window.IntersectionObserver(
			( entries ) => {
				if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
					observer.disconnect();
					nearViewport = true;
					maybeStartLoading();
				}
			},
			{ rootMargin: PRELOAD_ROOT_MARGIN }
		);
		observer.observe( root );
	} else {
		nearViewport = true;
		maybeStartLoading();
	}

	window.addEventListener( 'scroll', requestDraw, { passive: true } );
	window.addEventListener( 'resize', () => {
		// 幅も 100vh も変わっていなければ(SP のアドレスバーの出し入れ)、判定し直さず描き直すだけにする.
		if (
			`${ window.innerWidth }x${ viewportProbe.offsetHeight }` ===
			lastViewportKey
		) {
			requestDraw();
			return;
		}
		updateMode();
	} );
	mobileQuery.addEventListener( 'change', () => {
		selectFrames();
		updateMode();
	} );
}

/**
 * ページの中の .l2dsis-sequence をすべて動かす.
 */
const setupAll = () => {
	document
		.querySelectorAll( '.l2dsis-sequence.has-frames' )
		.forEach( setupSequence );
};

// viewScript が <head> で読まれた場合でもブロックを見つけられるよう、読み込み中なら DOM ができるのを待つ.
if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', setupAll );
} else {
	setupAll();
}
