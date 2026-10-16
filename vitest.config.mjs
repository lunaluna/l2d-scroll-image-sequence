/**
 * Vitest の設定(npm run test:unit = wp-scripts test-unit-js).
 *
 * `@wordpress/scripts` 36 から test-unit-js は Vitest を使う(node_modules/@wordpress/scripts/docs/vitest-migration.md).
 * テストするのは DOM に触らない src/sequence/frames.js だけなので、環境は node のままでよい.
 * vendor/ などを探しに行かないよう、テストのファイルを src/ の中の *.test.js に限る.
 */
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		environment: 'node',
		globals: false,
		restoreMocks: true,
		include: [ 'src/**/*.test.js' ],
	},
} );
