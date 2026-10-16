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
	MIN_KEPT_FRAMES,
	clamp,
	frameIndexForProgress,
	keepRange,
	maxFramesForBudget,
	nearestIndex,
	nextIndexToLoad,
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
