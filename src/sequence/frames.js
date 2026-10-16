/**
 * コマの選び方・読み方・持ち方の判断(view.js から使う).
 *
 * DOM に触らない関数だけをここに置き、Jest でテストする(test/frames.test.js. 2026-10-16 の最終レビューの指摘).
 */

/**
 * 値を min〜max に収める.
 *
 * @param {number} value 値.
 * @param {number} min   下限.
 * @param {number} max   上限.
 * @return {number} 収めた値.
 */
export const clamp = ( value, min, max ) =>
	Math.min( max, Math.max( min, value ) );

/**
 * Data- 属性の JSON(コマの URL の配列)を読む. 壊れていたら空.
 *
 * @param {string|undefined} json data- 属性の値.
 * @return {string[]} URL の配列.
 */
export function parseFrames( json ) {
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
 * 画面の幅に合うコマの一覧を選ぶ. SP の幅でも SP 用が無ければ PC 用を使う.
 *
 * @param {boolean}  isMobile      SP の幅か.
 * @param {string[]} desktopFrames PC 用のコマの URL.
 * @param {string[]} mobileFrames  SP 用のコマの URL.
 * @return {string[]} 使うコマの URL.
 */
export function pickFrames( isMobile, desktopFrames, mobileFrames ) {
	return isMobile && mobileFrames.length ? mobileFrames : desktopFrames;
}

/**
 * スクロールの進み具合(0〜1)を、コマの番号にする.
 *
 * @param {number} progress 進み具合. 範囲の外は 0〜1 に収める.
 * @param {number} count    コマの数.
 * @return {number} コマの番号(0 から). コマが無ければ -1.
 */
export function frameIndexForProgress( progress, count ) {
	if ( count <= 0 ) {
		return -1;
	}
	return Math.round( clamp( progress, 0, 1 ) * ( count - 1 ) );
}

/**
 * 欲しいコマに一番近い、条件に合うコマの番号を返す. 同じ距離なら前のコマを選ぶ.
 *
 * 読み込めていないコマを、読み込めた近いコマで代わりに描くために使う.
 *
 * @param {(index: number) => boolean} matches 条件(例: 読み込み済みか).
 * @param {number}                     index   欲しいコマの番号.
 * @param {number}                     count   コマの数.
 * @return {number} 条件に合うコマの番号. 無ければ -1.
 */
export function nearestIndex( matches, index, count ) {
	for ( let distance = 0; distance < count; distance++ ) {
		const earlier = index - distance;
		const later = index + distance;
		if ( earlier >= 0 && earlier < count && matches( earlier ) ) {
			return earlier;
		}
		if ( later >= 0 && later < count && matches( later ) ) {
			return later;
		}
	}
	return -1;
}

/**
 * 一度に持つコマの最少の枚数. 予算がとても小さくても、今のコマと隣のコマは持つ.
 */
export const MIN_KEPT_FRAMES = 2;

/**
 * デコードした画像の画素数の予算から、一度に持つコマの枚数を決める.
 *
 * @param {number} budgetPixels   予算(画素数). 0 以下なら制限しない.
 * @param {number} pixelsPerFrame 1 コマの画素数(幅 × 高さ). まだ分からなければ 0(制限しない).
 * @param {number} count          コマの数.
 * @return {number} 持つ枚数(MIN_KEPT_FRAMES 以上、count 以下).
 */
export function maxFramesForBudget( budgetPixels, pixelsPerFrame, count ) {
	if ( budgetPixels <= 0 || pixelsPerFrame <= 0 ) {
		return count;
	}
	const frames = Math.floor( budgetPixels / pixelsPerFrame );
	return Math.min( count, Math.max( MIN_KEPT_FRAMES, frames ) );
}

/**
 * 今のコマを中心に、持つコマの範囲を決める. 端では範囲をずらして、枚数を保つ.
 *
 * @param {number} wanted  今欲しいコマの番号.
 * @param {number} count   コマの数.
 * @param {number} maxKept 持つ枚数.
 * @return {{start: number, end: number}} 範囲(両端を含む).
 */
export function keepRange( wanted, count, maxKept ) {
	if ( maxKept >= count ) {
		return { start: 0, end: count - 1 };
	}
	const half = Math.floor( ( maxKept - 1 ) / 2 );
	const start = clamp( wanted - half, 0, count - maxKept );
	return { start, end: start + maxKept - 1 };
}

/**
 * 持つ範囲の中で、まだ読み込みを出していない、今欲しいコマに一番近いコマを返す.
 *
 * @param {(index: number) => boolean}   isRequested 読み込みを出したか.
 * @param {number}                       wanted      今欲しいコマの番号.
 * @param {{start: number, end: number}} range       持つ範囲.
 * @return {number} 次に読むコマの番号. 無ければ -1.
 */
export function nextIndexToLoad( isRequested, wanted, range ) {
	const center = clamp( wanted, range.start, range.end );
	return nearestIndex(
		( index ) =>
			index >= range.start &&
			index <= range.end &&
			! isRequested( index ),
		center,
		range.end + 1
	);
}
