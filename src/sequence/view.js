/**
 * ブロック l2d-scroll-image-sequence/sequence のフロントの動き.
 *
 * render.php が出した .l2dsis-sequence ごとに、スクロールの進み具合(0〜1)に合わせてコマを canvas に描く.
 * 素の JS で書く(ライブラリは使わない). 状態ごとの表示はプラン ~/.claude/plans/alpine-2026-a290-image-sequence.md の 3-3.
 * コマの選び方・読み方・持ち方の判断は frames.js(Jest でテストする).
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
 * Internal dependencies
 */
import {
	clamp,
	frameIndexForProgress,
	keepRange,
	levelsToServe,
	loaderPriority,
	maxFramesForBudget,
	nearestIndex,
	nextIndexToLoad,
	orderLoaders,
	parseFrames,
	pickFrames,
} from './frames';

/**
 * 先読みを始める距離. 箱の上端が「画面の下端 + 画面の高さ × 2」まで近づいたら、コマを読み始める.
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
 * ページ全体で、同時に読み込むコマの数の上限.
 *
 * 全コマに一斉に読み込みを出すと、サーバーや CDN へリクエストが集中し、どのコマから届くかも決められない
 * (2026-10-15 のレビューの指摘 2). ページの中のすべてのブロックで、1 つのキューを共有する.
 * 6 は、HTTP/1.1 のブラウザが 1 つのホストに同時に張る接続の数の上限(それ以上出しても待たされるだけ).
 * 2026-10-16 の【A】の実測: 6 件に絞っても、全部を読み終える時間は前と同じだった.
 */
const MAX_CONCURRENT_LOADS = 6;

/**
 * ページ全体で、デコードした画像として一度に持つコマの、画素数(幅 × 高さ)の合計の予算.
 *
 * 読んだコマを全部持つと、デコード後のメモリが大きくなる(2026-10-16 の最終レビューの指摘 2. A290 の #19 の PC 用
 * 140 コマ × 2048 × 1152 ≒ 3.3 億画素で、RGBA なら約 1.3GB). 予算はページの中のブロックの数で分け、
 * 各ブロックは今のコマの前後だけを持ち、範囲から外れたコマは手放す(戻ると、ブラウザのキャッシュから読み直す).
 * 2 億画素は RGBA で約 800MB. 未実測(【A】で、ブラウザのメモリの使い方と、戻る方向に速くスクロールしたときの
 * 見え方を確かめる). 例: A290 のページはブロックが 2 つなので 1 つあたり 1 億画素 = 2048 × 1152 なら約 42 コマ.
 */
const PAGE_DECODED_PIXEL_BUDGET = 200000000;

/**
 * コマを粗い順に読むときの、間引きの間隔. まず今のコマ、次にこの間隔ごとのコマ、最後に残りを読む(frames.js の nextIndexToLoad()).
 *
 * 細い回線(Local の Live Link など)で、全部のコマがそろう前からスクロールに合わせて絵が動くようにする(2026-10-16).
 * 8 は未実測. 90 コマなら最初の約 12 枚で全体の動きが出る. 【A】で Live Link の回線の見え方を確かめて決め直す.
 */
const LOAD_STRIDE = 8;

/**
 * 近くに入ったブロックの下敷き(1 コマ目)を待つ、最も長い時間(ミリ秒). 過ぎたら、届いていなくても届いた扱いにする.
 *
 * 下敷きを待っているあいだは、そのブロックのコマも、後ろの段のブロックのコマも読まない(levelsToServe())ので、
 * 回線が止まったままでも読み込みが止まり続けないようにする. 5000 は、【A】の実測(2026-10-16. HTTP キャッシュなし)で
 * 1 コマ目が届くまでの最も遅い値(SP・5Mbps・遅延 300ms で 1,258ms)の約 4 倍.
 */
const POSTER_WAIT_LIMIT_MS = 5000;

/**
 * 読み込みのキューに登録したブロック. 各要素は、次に読むコマを返す関数 nextLoad() と、
 * 画面との近さの段を返す関数 priority()(frames.js の loaderPriority())と、下敷き(1 コマ目)を待っているかを返す関数 waiting() を持つ.
 *
 * @type {Array<{nextLoad: () => ({url: string, done: (image: HTMLImageElement|null) => void}|null), priority: () => number, waiting: () => boolean}>}
 */
const loaders = [];

/**
 * 今、読み込み中のコマの数(ページ全体).
 */
let activeLoads = 0;

/**
 * 同じ近さのブロックの中で、回す順番の先頭をずらす数. pumpLoads() のたびに 1 増やす.
 */
let pumpRotation = 0;

/**
 * 最後のスクロールの向き(1 = 下へ・-1 = 上へ). ページを開いた直後は下へ.
 *
 * 読み込みの順番に使う: 近いブロックのうち、向きの先にあるブロックを、通り過ぎたブロックより先に読む.
 * ブロックの中でも、同じ距離なら向きの先のコマを先に読む(2026-10-16 ユーザー提案).
 */
let scrollDirection = 1;

/**
 * 最後に見た window.scrollY. 向きを決めるために使う.
 */
let lastScrollY = window.scrollY;

/**
 * スクロールの向きを覚える. 向きが変わったら、読み込みの順番が変わるので、キューを動かす.
 */
function trackScrollDirection() {
	const y = window.scrollY;
	if ( y === lastScrollY ) {
		return;
	}
	const direction = y > lastScrollY ? 1 : -1;
	lastScrollY = y;
	if ( direction !== scrollDirection ) {
		scrollDirection = direction;
		pumpLoads();
	}
}

/**
 * 1 件の読み込みを始める.
 *
 * @param {{url: string, done: (image: HTMLImageElement|null) => void}} task 読むコマ.
 */
function startLoad( task ) {
	activeLoads++;

	const image = new window.Image();
	image.decoding = 'async';
	const finish = ( loaded ) => {
		activeLoads--;
		task.done( loaded ? image : null );
		pumpLoads();
	};
	image.onload = () => finish( true );
	image.onerror = () => finish( false );
	image.src = task.url;
}

/**
 * 読み込みの空きがあれば、画面に近いブロックから順に読み始める.
 *
 * まず画面の中のブロックで空きを埋め、余ったときだけ近い(先読みの範囲の)ブロックに回す. 遠いブロックは読まない
 * (2026-10-16【A】の指摘. ページの上のブロックから順に回していたので、PC で #21 へ飛ぶと、画面の外になった #19 が
 * 最後のコマのまわりを読み直して枠を取り続け、#21 のコマが 5Mbps で 11.5 秒読まれなかった).
 * 同じ近さのブロックどうしは 1 周に 1 ブロック 1 件にし、回すたびに先頭をずらして、独り占めしないようにする.
 * 読み込み中のコマは、ブロックが遠くなっても止めない(届いたときに、範囲の外なら捨てる).
 * 読み込みが 1 件終わるたび・ブロックが読み始めてよくなったたび・今のコマが変わったたび・近さが変わったたびに呼ぶ.
 */
function pumpLoads() {
	const priorities = loaders.map( ( loader ) => loader.priority() );
	const waiting = loaders.map( ( loader ) => loader.waiting() );

	// 画面の中か向きの先のブロックが下敷きを待っているあいだは、それより後ろの段に回さない(levelsToServe()).
	for ( const level of levelsToServe( priorities, waiting ) ) {
		const order = orderLoaders( priorities, level, pumpRotation );
		let started = true;
		while ( activeLoads < MAX_CONCURRENT_LOADS && started ) {
			started = false;
			for ( const index of order ) {
				if ( activeLoads >= MAX_CONCURRENT_LOADS ) {
					break;
				}
				const task = loaders[ index ].nextLoad();
				if ( task ) {
					started = true;
					startLoad( task );
				}
			}
		}
	}

	pumpRotation = ( pumpRotation + 1 ) % Math.max( 1, loaders.length );
}

/**
 * 1 つのブロックの動きを組み立てる.
 *
 * @param {HTMLElement} root         .l2dsis-sequence の要素.
 * @param {number}      budgetPixels このブロックが一度に持つコマの画素数の予算.
 */
function setupSequence( root, budgetPixels ) {
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
	// 今使っているコマの URL と、読み込んだ画像(読み込み中・失敗・持たないコマは null).
	let frames = [];
	let images = [];
	// 読み込みを出したか(読み込み中・読み込み済み・失敗は true). 同じコマを 2 回読まないため.
	let requested = [];
	// 読み込み中か. 範囲の外になったコマを手放すとき、読み込み中のものは done() に任せる.
	let inFlight = [];
	// 1 コマの画素数(幅 × 高さ). 最初に届いたコマで決める(同じシーケンスのコマは同じ大きさ). 分かるまでは 0.
	let pixelsPerFrame = 0;
	// 箱が先読みの範囲に入ったか(IntersectionObserver). 入ったあとで固定するようになったときにも読み始める.
	let nearViewport = false;
	let loadingStarted = false;
	// 下敷きの 1 コマ目(<picture> の <img>)が届いたか. 届くまでほかのコマを読まない(1 コマ目に回線を譲る. 2026-10-16).
	// 失敗したときも、待ち続けないよう届いた扱いにする. JS の 1 コマ目は同じ URL なので、ブラウザのキャッシュから届く.
	let posterReady = true;
	// 最後に描いたコマの番号(読み込みが進んだときに、より近いコマで描き直すかの判定に使う).
	let drawnIndex = -1;
	let wantedIndex = 0;
	let frameRequest = 0;

	/**
	 * 今持つコマの範囲. 視差効果を減らすときは最後のコマだけ.
	 *
	 * @return {{start: number, end: number}} 範囲(両端を含む).
	 */
	const currentRange = () => {
		if ( reducedMotion ) {
			const last = frames.length - 1;
			return { start: last, end: last };
		}
		return keepRange(
			wantedIndex,
			frames.length,
			maxFramesForBudget( budgetPixels, pixelsPerFrame, frames.length )
		);
	};

	/**
	 * 範囲の外になったコマを手放す. 読み込み中のものは、届いたときに done() で捨てる.
	 * 手放したコマは、また範囲に入ったら読み直せるよう「読み込みを出していない」に戻す.
	 */
	const releaseOutsideRange = () => {
		const range = currentRange();
		for ( let index = 0; index < frames.length; index++ ) {
			if (
				( index < range.start || index > range.end ) &&
				! inFlight[ index ]
			) {
				images[ index ] = null;
				requested[ index ] = false;
			}
		}
	};

	/**
	 * 次に読むコマを返す(ページ全体のキュー pumpLoads() から呼ばれる). 読むものが無ければ null.
	 *
	 * 持つ範囲の中で、まだ読み込みを出していないコマのうち、今欲しいコマ(wantedIndex)に一番近いものを選ぶ.
	 * スクロールして欲しいコマが変われば、次に読むコマもそれに合わせて変わる.
	 *
	 * @return {{url: string, done: (image: HTMLImageElement|null) => void}|null} 読むコマの URL と、読み終えたときに呼ぶ関数.
	 */
	const nextLoad = () => {
		if ( ! loadingStarted || ! posterReady || ! frames.length ) {
			return null;
		}

		// 視差効果を減らすときは範囲が最後のコマ 1 枚だけなので、間引きは関係ない.
		const index = nextIndexToLoad(
			( i ) => requested[ i ],
			wantedIndex,
			currentRange(),
			LOAD_STRIDE,
			scrollDirection
		);
		if ( -1 === index ) {
			return null;
		}

		requested[ index ] = true;
		inFlight[ index ] = true;
		const url = frames[ index ];
		return {
			url,
			done: ( image ) => {
				// 一覧を入れ替えたあとに、古い一覧の画像が届いたら捨てる.
				if ( frames[ index ] !== url ) {
					return;
				}
				inFlight[ index ] = false;
				// 失敗したコマは持たない(読み込みを出した印は残し、同じ範囲にいるあいだは読み直さない).
				// 近いコマで代わりに描く(3-3 の「一部が失敗」).
				if ( ! image ) {
					return;
				}
				if ( ! pixelsPerFrame ) {
					pixelsPerFrame = image.naturalWidth * image.naturalHeight;
				}
				// 届くまでのあいだに範囲の外になったら、持たずに捨てる.
				const range = currentRange();
				if ( index < range.start || index > range.end ) {
					requested[ index ] = false;
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
			},
		};
	};

	/**
	 * コマを読み始める. 実際に読むのはページ全体のキュー(pumpLoads())で、ここでは読み始めてよい印を付けるだけ.
	 */
	const startLoading = () => {
		loadingStarted = true;
		pumpLoads();
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
	 * 固定しているときは、固定の区間の上端が画面の上端に来たときが 0、固定の区間の下端が画面の下端に来たときが 1.
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
		const nextWanted = frameIndexForProgress( progress, frames.length );
		if ( nextWanted !== wantedIndex ) {
			wantedIndex = nextWanted;
			// 今のコマが変わったら、範囲の外のコマを手放し、範囲に入ったコマを読む.
			releaseOutsideRange();
			pumpLoads();
		}

		// .l2dsis-reveal の中身を出すかどうか(style.scss で opacity を切り替える).
		root.classList.toggle(
			'is-revealed',
			'reduced' === mode || progress >= revealAt
		);

		const index = nearestIndex(
			( i ) => !! images[ i ],
			wantedIndex,
			frames.length,
			scrollDirection
		);
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
		const next = pickFrames(
			mobileQuery.matches,
			desktopFrames,
			mobileFrames
		);
		if ( next === frames ) {
			return;
		}
		frames = next;
		images = frames.map( () => null );
		requested = frames.map( () => false );
		inFlight = frames.map( () => false );
		// PC 用と SP 用でコマの大きさが違うので、1 コマの画素数は測り直す.
		pixelsPerFrame = 0;
		drawnIndex = -1;
		if ( loadingStarted ) {
			pumpLoads();
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

	// 下敷きの 1 コマ目がまだ届いていなければ、届くまで(または失敗するまで)ほかのコマを読まない.
	// 届いたら、読み込みのキューを動かす(ほかの条件がそろっていれば読み始める).
	const posterImage = media.querySelector( '.l2dsis-sequence__poster img' );
	if ( posterImage && ! posterImage.complete ) {
		posterReady = false;
		const onPosterDone = () => {
			posterReady = true;
			pumpLoads();
		};
		posterImage.addEventListener( 'load', onPosterDone, { once: true } );
		posterImage.addEventListener( 'error', onPosterDone, { once: true } );
	}

	// 安全の時間を数え始めたか(1 回だけ).
	let posterTimerStarted = false;

	/**
	 * 近くに入ったブロックの下敷きを急がせる. 下敷きを待つあいだはほかの読み込みを止める(levelsToServe())ので、
	 * 下敷きが届かないまま止まり続けないようにする.
	 * - 2 つ目以降のブロックの下敷きは loading="lazy" で、ブラウザが「もうすぐ画面に入る」と判断するまで読み込みを始めない.
	 *   その範囲は先読みの範囲より狭いことがあるので、eager に変えて、すぐ読み込みを始めさせる
	 * - 失敗したら onPosterDone() で届いた扱い. 回線が止まったままなら、POSTER_WAIT_LIMIT_MS で届いた扱いにする
	 */
	const hurryPoster = () => {
		if ( posterReady || ! posterImage ) {
			return;
		}
		if ( 'lazy' === posterImage.loading ) {
			posterImage.loading = 'eager';
		}
		if ( ! posterTimerStarted ) {
			posterTimerStarted = true;
			window.setTimeout( () => {
				if ( ! posterReady ) {
					posterReady = true;
					pumpLoads();
				}
			}, POSTER_WAIT_LIMIT_MS );
		}
	};

	// 画面との近さ. 2 つの IntersectionObserver で見張り続ける(読み込みのキューの順番に使う. pumpLoads() を参照).
	// IntersectionObserver が無いブラウザでは、画面の中として扱う(今までどおり読む).
	const hasObserver = 'IntersectionObserver' in window;
	let isNear = ! hasObserver;
	let isVisible = ! hasObserver;

	// ページ全体の読み込みのキューに登録する(読み始めるのは startLoading() のあと).
	loaders.push( {
		nextLoad,
		// 画面より下にあるか(上にあれば false)は、近くて画面の外のときだけ要るので、そのときだけ測る.
		priority: () =>
			loaderPriority(
				isVisible,
				isNear,
				! isVisible && isNear && root.getBoundingClientRect().top >= 0,
				scrollDirection
			),
		waiting: () => ! posterReady,
	} );

	selectFrames();
	updateMode();

	if ( hasObserver ) {
		/**
		 * 見張りの結果(同じ要素の通知が複数来たら最後のもの)を、近さの印に入れて、読み込みのキューを動かす.
		 *
		 * @param {IntersectionObserverEntry[]}     entries 通知.
		 * @param {(intersecting: boolean) => void} apply   印を変える関数.
		 */
		const watch = ( entries, apply ) => {
			apply( entries[ entries.length - 1 ].isIntersecting );
			pumpLoads();
		};

		// 先読みの範囲. 初めて入ったら読み始める. 出たら、そのブロックのコマは読まない(戻れば、そのときの位置から読む).
		new window.IntersectionObserver(
			( entries ) =>
				watch( entries, ( intersecting ) => {
					isNear = intersecting;
					if ( intersecting ) {
						nearViewport = true;
						hurryPoster();
						maybeStartLoading();
					}
				} ),
			{ rootMargin: PRELOAD_ROOT_MARGIN }
		).observe( root );

		// 画面の中. 画面の中のブロックを、近いだけのブロックより先に読む.
		new window.IntersectionObserver( ( entries ) =>
			watch( entries, ( intersecting ) => {
				isVisible = intersecting;
			} )
		).observe( root );
	} else {
		nearViewport = true;
		hurryPoster();
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
 * ページの中の .l2dsis-sequence をすべて動かす. 画素数の予算は、ブロックの数で等しく分ける.
 */
const setupAll = () => {
	const roots = document.querySelectorAll( '.l2dsis-sequence.has-frames' );
	if ( ! roots.length ) {
		return;
	}
	const budgetPixels = Math.floor( PAGE_DECODED_PIXEL_BUDGET / roots.length );
	// スクロールの向きは、ページ全体で 1 つだけ見張る. ブロックごとの描画(requestDraw)より先に足して、
	// 描くときに新しい向きを使えるようにする.
	lastScrollY = window.scrollY;
	window.addEventListener( 'scroll', trackScrollDirection, {
		passive: true,
	} );
	roots.forEach( ( root ) => setupSequence( root, budgetPixels ) );
};

// viewScript が <head> で読まれた場合でもブロックを見つけられるよう、読み込み中なら DOM ができるのを待つ.
if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', setupAll );
} else {
	setupAll();
}
