# l2d-scroll-image-sequence

スクロールに合わせて画像シーケンス(コマ画像)を canvas に描く、汎用の WordPress ブロックプラグイン.
`@wordpress/create-block`(`--variant dynamic`)で作った普通のブロック. ACF には頼らない.

## 最初に読むもの

- **プラン: `~/.claude/plans/alpine-2026-a290-image-sequence.md`**. 設計は 3 章、ステップと進捗は 4 章・6 章
- このリポジトリのセッションには alpine2026 のメモリが読み込まれない. 必要な前提はプランに書いてある

## セッションの分け方

- 【P】このリポジトリのセッション: プラグインのコード・ビルド・lint・phpcs・コミット
- 【A】alpine2026.local のセッション(`/Users/mkgq3lla/works/mgn/alpine2026/alpine2026.local`): WP-CLI・DB・ブラウザでの確認. Local の MySQL ソケットの許可がそちらにしか無い
- 【P】で書いたコードの動作確認は【A】で行い、結果はユーザーがセッション間で伝える

## 確認用サイト

- <http://alpine2026.local/>(A290 のページは `/model/a290/`)
- `app/public/wp-content/plugins/l2d-scroll-image-sequence` は、このリポジトリへの**シンボリックリンク**
  - このプラグインに `wp plugin install --force`・`wp plugin delete`・管理画面の「削除」を使わない. リンク先のこのリポジトリが消える
  - 自己更新の動作確認もシンボリックリンクのままでは行わない(更新処理が古いフォルダを消すため). テスト用のサイトにコピーで入れる

## 開発の決まり

- Node は mise(`mise.toml`). 古いセッションで `node -v` が v14 になるときは `export PATH="$(mise where node)/bin:$PATH"` を前に付ける
- `npm install`・`composer install` は、サンドボックスの中では npm のレジストリなどへの接続が拒否されるので、サンドボックスの外で実行する
- **`build/` はコミットする**. 共有のリリース用ワークフロー(`l2d-wp-github-update-lib` の `plugin-release.yml`)は Node のビルド工程を持たず、作業ツリーを `.distignore` で絞って ZIP にするため. `src/` を変えたら `npm run build` してから `build/` も一緒にコミットする
- 関数・定数の接頭辞は `l2dsis` / `L2DSIS_`. クラスは `L2dsis_*`. フィルターは `l2dsis_...`(WPCS の `_` 区切り)
- `lib/l2d-updater/` は `git subtree` で取り込んだ更新ライブラリ. 直接編集しない(更新は `git subtree pull --prefix=lib/l2d-updater https://github.com/lunaluna/l2d-wp-github-update-lib.git dist-X.Y.Z --squash`)
- `main` へ直接コミットしない. 作業ブランチで進め、`git merge --no-ff` で入れる
- タグと Release 名に `v` を付けない(`1.0.0`)

## 検査とテスト

| コマンド | 内容 | 手元のサンドボックス |
| --- | --- | --- |
| `composer lint` | phpcs(WordPress Coding Standards. `tests/`・`build/`・`lib/` は対象外) | 中で動く |
| `composer analyse` | PHPStan level 5(メインファイル・`includes/`・`uninstall.php`) | **外で動かす**(並列処理のために手元の TCP ポートを開くので、中では `Operation not permitted` で止まる) |
| `composer test` | PHPUnit(`tests/`. WordPress のテストスイートは使わず、`tests/bootstrap.php` で必要な関数だけを置き換える) | 中で動く |
| `npm run lint:js`・`lint:css`・`build` | JS・CSS の lint とビルド | **外で動かす**(`caniuse-lite` の `credential-management.js` が読み取り禁止に当たる) |
| `npm run test:unit` | Vitest(`src/**/*.test.js`. `@wordpress/scripts` 36 から `test-unit-js` は Vitest. 対象は DOM に触らない `src/sequence/frames.js`) | 外で動かした(中で動くかは未確認) |

- テストで WordPress の関数を新しく使うときは、`tests/bootstrap.php` に代わりの関数を足す. 戻り値はテストごとに `$GLOBALS['l2dsis_test']` で変える
- 文言を変えたら、`wp i18n make-pot`(`src` などを除く. プランの Step 7 の【P】の途中経過を参照)→ `.po` を直す → `wp i18n make-mo`・`make-json --no-purge` で翻訳ファイルを作り直す
