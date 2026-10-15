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
 * 先読みを始める距離. 箱がこの距離まで画面に近づいたら、全コマを読み始める.
 *
 * 未実測. いったん「画面 1 つ分手前」(IntersectionObserver の rootMargin の 100%)にしている.
 * 【A】で、全コマを読み終えるまでの時間と、箱に着くまでのスクロールの時間を測ってから決め直す(プランの Step 3).
 */
const PRELOAD_ROOT_MARGIN = '100% 0px';

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
	 */
	const startLoading = () => {
		loadingStarted = true;
		images = frames.map( () => null );

		frames.forEach( ( url, index ) => {
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
	 * Canvas の大きさを箱に合わせる. 画面の解像度(devicePixelRatio)の分だけ大きくして、ぼやけないようにする.
	 */
	const resizeCanvas = () => {
		const ratio = window.devicePixelRatio || 1;
		const width = Math.round( sticky.clientWidth * ratio );
		const height = Math.round( sticky.clientHeight * ratio );
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
