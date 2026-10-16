/**
 * frames.js(コマの選び方・読み方・持ち方の判断)のテスト. npm run test:unit(wp-scripts test-unit-js).
 *
 * 2026-10-16 の最終レビューの指摘: JS の動作の自動テストが無かった.
 * テストは Vitest で動く(@wordpress/scripts 36 から test-unit-js は Vitest). 書き方は Jest とほぼ同じ.
 */

/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	LOADER_FAR,
	LOADER_NEAR_AHEAD,
	LOADER_NEAR_BEHIND,
	LOADER_VISIBLE,
	MIN_KEPT_FRAMES,
	clamp,
	frameIndexForProgress,
	keepRange,
	loaderPriority,
	maxFramesForBudget,
	nearestIndex,
	nextIndexToLoad,
	orderLoaders,
	parseFrames,
	pickFrames,
} from '../frames';

describe( 'clamp', () => {
	it( 'keeps the value within the range', () => {
		expect( clamp( -1, 0, 1 ) ).toBe( 0 );
		expect( clamp( 0.4, 0, 1 ) ).toBe( 0.4 );
		expect( clamp( 2, 0, 1 ) ).toBe( 1 );
	} );
} );

describe( 'parseFrames', () => {
	it( 'reads a JSON array of URLs', () => {
		expect( parseFrames( '["a.webp","b.webp"]' ) ).toEqual( [
			'a.webp',
			'b.webp',
		] );
	} );

	it( 'returns an empty array for empty, broken or non-array JSON', () => {
		expect( parseFrames( undefined ) ).toEqual( [] );
		expect( parseFrames( '' ) ).toEqual( [] );
		expect( parseFrames( '[broken' ) ).toEqual( [] );
		expect( parseFrames( '{"a":1}' ) ).toEqual( [] );
	} );
} );

describe( 'pickFrames', () => {
	const desktop = [ 'pc-1', 'pc-2' ];
	const mobile = [ 'sp-1' ];

	it( 'uses the mobile frames on small screens', () => {
		expect( pickFrames( true, desktop, mobile ) ).toBe( mobile );
	} );

	it( 'uses the desktop frames on large screens', () => {
		expect( pickFrames( false, desktop, mobile ) ).toBe( desktop );
	} );

	it( 'falls back to the desktop frames when there are no mobile frames', () => {
		expect( pickFrames( true, desktop, [] ) ).toBe( desktop );
	} );
} );

describe( 'frameIndexForProgress', () => {
	it( 'maps progress 0..1 to the first..last frame', () => {
		expect( frameIndexForProgress( 0, 90 ) ).toBe( 0 );
		expect( frameIndexForProgress( 0.5, 90 ) ).toBe( 45 );
		expect( frameIndexForProgress( 1, 90 ) ).toBe( 89 );
	} );

	it( 'clamps progress outside 0..1', () => {
		expect( frameIndexForProgress( -0.2, 10 ) ).toBe( 0 );
		expect( frameIndexForProgress( 1.5, 10 ) ).toBe( 9 );
	} );

	it( 'returns -1 when there are no frames', () => {
		expect( frameIndexForProgress( 0.5, 0 ) ).toBe( -1 );
	} );
} );

describe( 'nearestIndex', () => {
	const loaded = ( indexes ) => ( index ) => indexes.includes( index );

	it( 'returns the wanted index when it matches', () => {
		expect( nearestIndex( loaded( [ 3, 5 ] ), 5, 10 ) ).toBe( 5 );
	} );

	it( 'returns the nearest matching index, preferring the earlier one on a tie', () => {
		expect( nearestIndex( loaded( [ 3, 7 ] ), 5, 10 ) ).toBe( 3 );
		expect( nearestIndex( loaded( [ 2, 6 ] ), 5, 10 ) ).toBe( 6 );
	} );

	it( 'returns -1 when nothing matches', () => {
		expect( nearestIndex( loaded( [] ), 5, 10 ) ).toBe( -1 );
	} );
} );

describe( 'maxFramesForBudget', () => {
	it( 'divides the budget by the pixels of one frame', () => {
		// 1 億画素 ÷ 2048 × 1152 ≒ 42.4 → 42 コマ.
		expect( maxFramesForBudget( 100000000, 2048 * 1152, 140 ) ).toBe( 42 );
	} );

	it( 'keeps all frames when they fit in the budget', () => {
		expect( maxFramesForBudget( 100000000, 1280 * 720, 90 ) ).toBe( 90 );
	} );

	it( 'keeps at least the minimum number of frames', () => {
		expect( maxFramesForBudget( 1, 2048 * 1152, 140 ) ).toBe(
			MIN_KEPT_FRAMES
		);
	} );

	it( 'does not limit until the frame size is known, or without a budget', () => {
		expect( maxFramesForBudget( 100000000, 0, 140 ) ).toBe( 140 );
		expect( maxFramesForBudget( 0, 2048 * 1152, 140 ) ).toBe( 140 );
	} );
} );

describe( 'keepRange', () => {
	it( 'keeps all frames when the limit is not smaller than the count', () => {
		expect( keepRange( 5, 10, 10 ) ).toEqual( { start: 0, end: 9 } );
	} );

	it( 'centers the range on the wanted frame', () => {
		expect( keepRange( 50, 140, 42 ) ).toEqual( { start: 30, end: 71 } );
	} );

	it( 'shifts the range at both ends to keep the same number of frames', () => {
		expect( keepRange( 0, 140, 42 ) ).toEqual( { start: 0, end: 41 } );
		expect( keepRange( 139, 140, 42 ) ).toEqual( { start: 98, end: 139 } );
	} );
} );

describe( 'nextIndexToLoad', () => {
	const requestedSet = ( indexes ) => ( index ) => indexes.includes( index );

	it( 'loads the wanted frame first', () => {
		expect(
			nextIndexToLoad( requestedSet( [] ), 5, { start: 0, end: 9 } )
		).toBe( 5 );
	} );

	it( 'skips requested frames and picks the nearest one', () => {
		expect(
			nextIndexToLoad( requestedSet( [ 5, 4 ] ), 5, { start: 0, end: 9 } )
		).toBe( 6 );
	} );

	it( 'never picks a frame outside the range', () => {
		expect(
			nextIndexToLoad( requestedSet( [ 3, 4, 5 ] ), 4, {
				start: 3,
				end: 5,
			} )
		).toBe( -1 );
	} );

	it( 'starts from the nearest end when the wanted frame is outside the range', () => {
		expect(
			nextIndexToLoad( requestedSet( [] ), 0, { start: 98, end: 139 } )
		).toBe( 98 );
	} );
} );

describe( 'nextIndexToLoad with a stride (coarse to fine)', () => {
	const requestedSet = ( indexes ) => ( index ) => indexes.includes( index );
	const range = { start: 0, end: 89 };

	it( 'loads the wanted frame first', () => {
		expect( nextIndexToLoad( requestedSet( [] ), 30, range, 8 ) ).toBe(
			30
		);
	} );

	it( 'then loads the nearest frame on the stride grid', () => {
		// 30 の次は、8 の倍数のうち近い 32(24 より近い).
		expect( nextIndexToLoad( requestedSet( [ 30 ] ), 30, range, 8 ) ).toBe(
			32
		);
		expect(
			nextIndexToLoad( requestedSet( [ 30, 32 ] ), 30, range, 8 )
		).toBe( 24 );
	} );

	it( 'treats the last frame in the range as part of the grid', () => {
		// 8 の倍数(0〜88)がそろったら、次は範囲の最後のコマ 89(1 などの残りより先).
		const grid = [ 0, 8, 16, 24, 32, 40, 48, 56, 64, 72, 80, 88 ];
		expect( nextIndexToLoad( requestedSet( grid ), 0, range, 8 ) ).toBe(
			89
		);
	} );

	it( 'fills the remaining frames after the grid is loaded', () => {
		const grid = [ 0, 8, 16, 24, 32, 40, 48, 56, 64, 72, 80, 88, 89 ];
		expect( nextIndexToLoad( requestedSet( grid ), 0, range, 8 ) ).toBe(
			1
		);
	} );

	it( 'behaves like the nearest order without a stride', () => {
		expect( nextIndexToLoad( requestedSet( [ 30 ] ), 30, range ) ).toBe(
			29
		);
	} );
} );

describe( 'orderLoaders', () => {
	// 0 = #19(画面の外・遠い), 1 = #21(画面の中) のように、近さの一覧から読む順を決める.
	it( 'returns only the blocks with the given level', () => {
		const priorities = [ LOADER_FAR, LOADER_VISIBLE, LOADER_NEAR_AHEAD ];
		expect( orderLoaders( priorities, LOADER_VISIBLE, 0 ) ).toEqual( [
			1,
		] );
		expect( orderLoaders( priorities, LOADER_NEAR_AHEAD, 0 ) ).toEqual( [
			2,
		] );
	} );

	it( 'never returns far blocks for the visible and near levels', () => {
		const priorities = [ LOADER_FAR, LOADER_VISIBLE ];
		const order = [
			...orderLoaders( priorities, LOADER_VISIBLE, 0 ),
			...orderLoaders( priorities, LOADER_NEAR_AHEAD, 0 ),
		];
		expect( order ).toEqual( [ 1 ] );
	} );

	it( 'rotates the start among blocks with the same level', () => {
		const priorities = [
			LOADER_NEAR_AHEAD,
			LOADER_NEAR_AHEAD,
			LOADER_NEAR_AHEAD,
		];
		expect( orderLoaders( priorities, LOADER_NEAR_AHEAD, 0 ) ).toEqual( [
			0, 1, 2,
		] );
		expect( orderLoaders( priorities, LOADER_NEAR_AHEAD, 1 ) ).toEqual( [
			1, 2, 0,
		] );
		expect( orderLoaders( priorities, LOADER_NEAR_AHEAD, 2 ) ).toEqual( [
			2, 0, 1,
		] );
	} );

	it( 'returns an empty list when there are no blocks', () => {
		expect( orderLoaders( [], LOADER_VISIBLE, 0 ) ).toEqual( [] );
	} );
} );

describe( 'loaderPriority', () => {
	it( 'puts visible blocks first and far blocks last', () => {
		expect( loaderPriority( true, true, true, 1 ) ).toBe( LOADER_VISIBLE );
		expect( loaderPriority( false, false, true, 1 ) ).toBe( LOADER_FAR );
	} );

	it( 'puts near blocks ahead in the scroll direction before those behind', () => {
		// 下へスクロール: 画面より下のブロックが先、上(通り過ぎた)が後ろ.
		expect( loaderPriority( false, true, true, 1 ) ).toBe(
			LOADER_NEAR_AHEAD
		);
		expect( loaderPriority( false, true, false, 1 ) ).toBe(
			LOADER_NEAR_BEHIND
		);
		// 上へスクロール: 画面より上のブロックが先.
		expect( loaderPriority( false, true, false, -1 ) ).toBe(
			LOADER_NEAR_AHEAD
		);
		expect( loaderPriority( false, true, true, -1 ) ).toBe(
			LOADER_NEAR_BEHIND
		);
	} );
} );

describe( 'scroll direction within a block', () => {
	const loaded = ( indexes ) => ( index ) => indexes.includes( index );
	const requestedSet = loaded;

	it( 'prefers the later frame on a tie when scrolling down', () => {
		expect( nearestIndex( loaded( [ 3, 7 ] ), 5, 10, 1 ) ).toBe( 7 );
		expect( nearestIndex( loaded( [ 3, 7 ] ), 5, 10, -1 ) ).toBe( 3 );
	} );

	it( 'loads ahead in the scroll direction first', () => {
		const range = { start: 0, end: 89 };
		// 30 の次: 下へなら 31, 上へなら 29(間引かないとき).
		expect(
			nextIndexToLoad( requestedSet( [ 30 ] ), 30, range, 1, 1 )
		).toBe( 31 );
		expect(
			nextIndexToLoad( requestedSet( [ 30 ] ), 30, range, 1, -1 )
		).toBe( 29 );
		// 間引くとき(8 おき): 28 は 24 と 32 の真ん中. 下へなら 32, 上へなら 24.
		expect(
			nextIndexToLoad( requestedSet( [ 28 ] ), 28, range, 8, 1 )
		).toBe( 32 );
		expect(
			nextIndexToLoad( requestedSet( [ 28 ] ), 28, range, 8, -1 )
		).toBe( 24 );
	} );
} );
