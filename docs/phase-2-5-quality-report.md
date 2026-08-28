# Phase 2-5 品質確認・最適化結果

Build: `phase2-5.20260826.1`
正式Version: `0.1.2`（変更なし）
DB内部Version: `2`

## 結論

コード側で再現できる品質確認と最適化は完了した。Phase 1〜2-4の表示項目を増やさず、計測の安定性、集計負荷、削除安全性、開発Build識別を改善した。

ただし、各キャッシュプラグインを有効にした実サイト、iPhone Safari、Android Chrome、実サーバーのMySQLについては、このZIPを導入後に手動受入確認が必要である。未確認の実機項目を自動試験済みとは扱わない。

## 実装した改善

### キャッシュ環境

- 計測・滞在時間はページHTMLではなくREST APIへのPOSTで記録する構成を維持した。
- プラグインの全REST応答へ `private, no-store` を付与した。
- 管理画面の取得もブラウザーキャッシュを使用しない。
- 管理画面レポートへ30秒の短時間サーバーキャッシュを追加した。最大30秒の遅延に抑え、同一期間の重複クエリを削減する。
- Cloudflare Rocket LoaderとWP Fastest Cacheが計測スクリプトを遅延しない属性を付与した。
- LiteSpeed CacheのJS最適化・遅延・Guest Mode除外フィルターへ計測スクリプトを登録した。
- Autoptimizeで「すべてのJSを遅延」する場合は `access-analytics-plus/assets/js/tracker.js` を手動除外する。

参考にした公式資料:

- Cloudflare: https://developers.cloudflare.com/speed/optimization/content/rocket-loader/ignore-javascripts/
- LiteSpeed Cache: https://docs.litespeedtech.com/lscache/lscwp/api/#exclude-javascript-from-optimization
- WP Fastest Cache: https://www.wpfastestcache.com/premium/render-blocking-js/
- Autoptimize開発者資料: https://blog.futtta.be/2014/03/19/how-to-keep-autoptimizes-cache-size-under-control-and-improve-visitor-experience/

### 複数タブ・モバイル復帰

- Cookieに加え、同一オリジンの `localStorage` で訪問IDと有効期限をタブ間共有する。
- ストレージが禁止されたブラウザーではCookieだけで継続する。
- 非表示タブでは有効閲覧時間を加算しない。
- `pagehide` で可能な範囲の最終送信を行う。
- bfcacheから戻る `pageshow` 時に時計の基準だけをリセットし、バックグラウンド時間を加えない。
- 各ページビューは別の署名付きトークンを持ち、同じ合計秒数の再送はDBの最大値更新で二重加算しない。

### DB・表示速度

- 訪問者・訪問・流入元・デバイスを訪問開始期間から直接集計し、不要なpageviews結合を削除した。
- レポート用、品質指標用、滞在時間結合用の複合インデックスを追加した。
- 1PVごとの日次ユニーク判定を2本のCOUNTから、一意マーカーの `INSERT IGNORE` へ変更した。複数タブの同時送信でも二重カウントしにくい。
- ダッシュボードの今日・昨日は日次集計を優先し、専用解析画面の不要な集計を実行しない。
- ダッシュボードAPIは、主要数値、7日推移、人気ページ、今月訪問者だけを返す軽量構成を維持した。

### 保存期間・削除

- 最大90日の期間指定と矛盾しないよう、詳細保存期間の最小値を90日に統一した。
- 選択肢は90日・180日・365日とした。
- 削除処理を毎時、1テーブルあたり最大25,000件の分割処理にした。
- 日別合計と除外日別集計は削除しない。
- 日次ユニーク判定用マーカーは詳細データとして保存期間後に削除する。
- 前日の日次再集計は1日1回だけ実行し、削除ジョブの毎時化で重複させない。

### 開発Build識別

- 正式Versionとは別に `AAP_BUILD` を追加した。
- 設定画面下部に `Build phase2-5.20260826.1（正式Version 0.1.2）` と表示する。
- RESTレポートにもBuildを含める。
- CSS/JSのキャッシュ識別子にもBuildを使う。
- WordPressの更新判定に使われるプラグインVersionは変更していない。

## 大量データ比較試験

ローカルにMySQLサーバーがないため、同じ主要テーブル、インデックス、期間条件をSQLiteで再現したクエリ形状比較である。値は実MySQLの絶対表示時間ではなく、件数増加時の劣化と改善効果を見るための参考値である。3回実行の中央値、単位はms。

| PV | 指標（訪問者・訪問） | PV数 | 品質指標 | 人気ページ | 流入元 | デバイス | 7日人気ページ | 日次推移 | 除外 |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 100,000 | 11.13 | 1.99 | 29.39 | 26.97 | 1.49 | 4.86 | 9.25 | 0.13 | 0.21 |
| 500,000 | 114.23 | 20.20 | 685.87 | 152.24 | 13.01 | 51.29 | 34.36 | 0.16 | 0.19 |
| 1,000,000 | 170.38 | 36.55 | 1,349.40 | 248.68 | 21.25 | 78.14 | 57.43 | 0.13 | 0.18 |

最適化前の100万PV比較値は、指標7,264.89ms、流入元6,046.80ms、デバイス6,185.08ms、品質指標2,866.92msだった。最終構成では、これらがそれぞれ170.38ms、21.25ms、78.14ms、1,349.40msとなった。レポート全体には前期間比較など複数クエリがあるため、単純合計が画面時間ではない。2回目以降は30秒キャッシュが働く。

実行スクリプト: `tools/performance-benchmark.py`

## 自動確認結果

- 全PHPファイル: PHP 8.3構文エラーなし
- `assets/js/admin.js`: Node構文エラーなし
- `assets/js/tracker.js`: Node構文エラーなし
- 今日・昨日・7日・30日・今月・期間指定・未来日・91日範囲: 回帰テスト合格
- Googlebot・AI Bot除外、CUBOT端末・iPhone Safari通常扱い: 回帰テスト合格
- Cloudflare/WP Fastest Cache属性、LiteSpeed除外: 回帰テスト合格
- 2タブのセッションID共有: 回帰テスト合格
- 100秒バックグラウンド後の有効時間非加算: 回帰テスト合格

実行スクリプト:

- `tools/php-regression.php`
- `tools/tracker-regression.js`

## Chromeで分かったこと

実サイトの既存導入Buildを390px幅で確認したところ、期間ボタンは高さ46pxで操作しやすかったが、流入元・人気ページのグリッドが375pxの表示領域に対して682pxまで広がっていた。実サイトのCSSはローカル最終Buildより古く、日付移動JSも最新状態ではなかった。

Phase 2-5では次を追加した。

- モバイルのグリッド列を `minmax(0, 1fr)` に固定
- 直下カードへ `min-width:0; max-width:100%; width:100%; overflow:hidden`
- 外部CSSがCDNに残っていても効くよう、同じ重要ルールを管理画面のインラインCSSでも出力

最終ZIP導入後、ページキャッシュ・CDN・ブラウザーキャッシュを消して再確認する。

## 残る受入確認

次は新機能開発ではなく、`phase-2-5-test-plan.md` に沿って最終ZIPを実サイトまたはステージングへ導入して確認する。

特に未確定なのは次の項目である。

- WP Fastest Cache、Autoptimize、LiteSpeed Cache、Cloudflareの実構成
- iPhone Safari実機
- Android Chrome実機
- 実サーバーMySQLで50万〜100万PVを持つ場合の絶対表示時間
- WP-Cronが極端に少ないサイトでの削除追従速度

これらが合格するまで、Phase 2の「実運用受入完了」とは表現しない。
