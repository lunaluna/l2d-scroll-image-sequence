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
 * 欲しいコマに一番近い、条件に合うコマの番号を返す.
 *
 * 同じ距離なら、direction が 1(下へスクロール = 後ろの番号へ進む)のときは後ろのコマを、それ以外は前のコマを選ぶ.
 * 読み込めていないコマを、読み込めた近いコマで代わりに描くときや、次に読むコマを選ぶときに使う.
 *
 * @param {(index: number) => boolean} matches   条件(例: 読み込み済みか).
 * @param {number}                     index     欲しいコマの番号.
 * @param {number}                     count     コマの数.
 * @param {number}                     direction スクロールの向き(1 = 下へ・-1 = 上へ). 省けば -1 と同じ(前のコマが先).
 * @return {number} 条件に合うコマの番号. 無ければ -1.
 */
export function nearestIndex( matches, index, count, direction = -1 ) {
	const preferLater = 1 === direction;
	for ( let distance = 0; distance < count; distance++ ) {
		const earlier = index - distance;
		const later = index + distance;
		const first = preferLater ? later : earlier;
		const second = preferLater ? earlier : later;
		if ( first >= 0 && first < count && matches( first ) ) {
			return first;
		}
		if ( second >= 0 && second < count && matches( second ) ) {
			return second;
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
 * 持つ範囲の中で、次に読むコマを返す. まだ読み込みを出していないコマから選ぶ.
 *
 * 読む順番(2026-10-16. 細い回線でも早くスクロールに合わせて絵が動くように、粗い順に読む):
 * 1. 今欲しいコマ
 * 2. 間引いたコマ(番号が stride の倍数のコマと、範囲の最後のコマ)のうち、今欲しいコマに近いもの
 * 3. 残りのコマのうち、今欲しいコマに近いもの
 * 2・3 で同じ距離なら、スクロールの向きの先のコマを先にする(進む方向の絵を先にそろえる. 2026-10-16 ユーザー提案).
 * 間引いたコマがそろえば、そのあいだのコマは近い間引いたコマで代わりに描くので、少ない通信量で全体の動きが出る.
 *
 * @param {(index: number) => boolean}   isRequested 読み込みを出したか.
 * @param {number}                       wanted      今欲しいコマの番号.
 * @param {{start: number, end: number}} range       持つ範囲.
 * @param {number}                       stride      間引きの間隔. 1 なら間引かない(近い順だけ).
 * @param {number}                       direction   スクロールの向き(1 = 下へ・-1 = 上へ). 省けば -1 と同じ.
 * @return {number} 次に読むコマの番号. 無ければ -1.
 */
export function nextIndexToLoad(
	isRequested,
	wanted,
	range,
	stride = 1,
	direction = -1
) {
	const center = clamp( wanted, range.start, range.end );
	const isOpen = ( index ) =>
		index >= range.start && index <= range.end && ! isRequested( index );

	if ( isOpen( center ) ) {
		return center;
	}

	if ( stride > 1 ) {
		const coarse = nearestIndex(
			( index ) =>
				isOpen( index ) &&
				( 0 === index % stride || index === range.end ),
			center,
			range.end + 1,
			direction
		);
		if ( -1 !== coarse ) {
			return coarse;
		}
	}

	return nearestIndex( isOpen, center, range.end + 1, direction );
}

/**
 * ブロックの、画面との近さ. 読み込みのキューは、この順に枠を回す(view.js の pumpLoads()). 遠いブロックは読まない.
 * 画面の中 → 近くてスクロールの向きの先にある → 近くて後ろにある(通り過ぎた) → 遠い(2026-10-16 ユーザー提案).
 */
export const LOADER_VISIBLE = 0;
export const LOADER_NEAR_AHEAD = 1;
export const LOADER_NEAR_BEHIND = 2;
export const LOADER_FAR = 3;

/**
 * ブロックの近さの段を決める.
 *
 * @param {boolean} isVisible 画面の中か.
 * @param {boolean} isNear    先読みの範囲の中か.
 * @param {boolean} isBelow   画面より下にあるか(上にあれば false).
 * @param {number}  direction スクロールの向き(1 = 下へ・-1 = 上へ).
 * @return {number} 近さの段(LOADER_VISIBLE・LOADER_NEAR_AHEAD・LOADER_NEAR_BEHIND・LOADER_FAR).
 */
export function loaderPriority( isVisible, isNear, isBelow, direction ) {
	if ( isVisible ) {
		return LOADER_VISIBLE;
	}
	if ( ! isNear ) {
		return LOADER_FAR;
	}
	const ahead = isBelow ? 1 === direction : 1 !== direction;
	return ahead ? LOADER_NEAR_AHEAD : LOADER_NEAR_BEHIND;
}

/**
 * 指定した近さのブロックの番号を、rotation だけ先頭をずらした順に返す.
 *
 * 読み込みのキューは、まず画面の中のブロック、枠が余れば近いブロックの順に回し、遠いブロックは読まない
 * (2026-10-16【A】の指摘. ページの上のブロックから順に回していたので、下のブロックへ飛ぶと、画面の外になった上のブロックが
 * 枠を取り続け、下のブロックのコマが 10 秒以上読まれなかった). 同じ近さのブロックどうしは、回すたびに先頭をずらして、
 * 1 つのブロックが枠を独り占めしないようにする.
 *
 * @param {number[]} priorities 各ブロックの近さ(loaderPriority() の段).
 * @param {number}   level      取り出す近さ.
 * @param {number}   rotation   先頭をずらす数(0 以上).
 * @return {number[]} ブロックの番号.
 */
export function orderLoaders( priorities, level, rotation ) {
	const count = priorities.length;
	const order = [];
	for ( let offset = 0; offset < count; offset++ ) {
		const index = ( offset + rotation ) % count;
		if ( priorities[ index ] === level ) {
			order.push( index );
		}
	}
	return order;
}
