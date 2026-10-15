/**
 * ブロック l2d-scroll-image-sequence/sequence のフロントの動き.
 *
 * render.php が出した .l2dsis-sequence ごとに、スクロールの進み具合(0〜1)に合わせてコマを canvas に描く.
 * 素の JS で書く(ライブラリは使わない). 状態ごとの表示はプラン ~/.claude/plans/alpine-2026-a290-image-sequence.md の 3-3:
 *
 * - コマが無い(.is-empty): 何もしない. 黒背景 + 中身だけ
 * - 視差効果を減らす(prefers-reduced-motion: reduce): 固定せず、最後のコマを静止画で描く. .l2dsis-reveal も出したまま
 * - それ以外: .is-pinned を付けて固定し、コマを進める. 読み込めていないコマは、読み込めた近いコマで代わりに描く.
 *   JS が動かないときは .is-pinned が付かないので、固定せず 1 コマ目の <picture> だけが見える
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
	const sticky = root.querySelector( '.l2dsis-sequence__sticky' );
	const canvas = root.querySelector( '.l2dsis-sequence__canvas' );
	const context = canvas?.getContext( '2d' );
	if ( ! sticky || ! canvas || ! context ) {
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

	// 今使っているコマの URL と、読み込んだ画像(読み込み中・失敗は null).
	let frames = [];
	let images = [];
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
	 * 全コマを読み始める. 1 回だけ呼ぶ(コマの一覧を入れ替えたときは呼び直す).
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
	 * 固定していないとき(視差効果を減らす)は 1(最後のコマ).
	 *
	 * @return {number} 進み具合.
	 */
	const getProgress = () => {
		if ( reducedMotion ) {
			return 1;
		}
		const rect = root.getBoundingClientRect();
		const distance = rect.height - window.innerHeight;
		if ( distance <= 0 ) {
			return 0;
		}
		return clamp( -rect.top / distance, 0, 1 );
	};

	/**
	 * 今の進み具合のコマを描く. object-fit: cover と同じように、箱を埋めるように切り抜く.
	 */
	const draw = () => {
		frameRequest = 0;

		const progress = getProgress();
		wantedIndex = Math.round( progress * ( frames.length - 1 ) );

		// .l2dsis-reveal の中身を出すかどうか(style.scss で opacity を切り替える).
		root.classList.toggle(
			'is-revealed',
			reducedMotion || progress >= revealAt
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

	selectFrames();
	resizeCanvas();

	if ( ! reducedMotion ) {
		// 固定する. 高さは style.scss で 100vh × (1 + --l2dsis-scroll-length) になる.
		root.classList.add( 'is-pinned' );
	}

	// 箱が画面に近づいたら全コマを読み始める. 1 回で十分なので、読み始めたら見張るのをやめる.
	if ( 'IntersectionObserver' in window ) {
		const observer = new window.IntersectionObserver(
			( entries ) => {
				if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
					observer.disconnect();
					startLoading();
				}
			},
			{ rootMargin: PRELOAD_ROOT_MARGIN }
		);
		observer.observe( root );
	} else {
		startLoading();
	}

	window.addEventListener( 'scroll', requestDraw, { passive: true } );
	window.addEventListener( 'resize', () => {
		resizeCanvas();
		requestDraw();
	} );
	mobileQuery.addEventListener( 'change', () => {
		selectFrames();
		resizeCanvas();
		requestDraw();
	} );

	requestDraw();
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
