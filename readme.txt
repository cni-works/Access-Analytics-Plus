=== Access Analytics Plus ===
Contributors: access-analytics-plus
Tags: analytics, statistics, pageviews, dashboard
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.5.0-beta
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress管理画面で、サイトのアクセス傾向を簡単に確認するための軽量アクセス解析プラグインです。

== Description ==

Phase 1の基本解析に加え、Phase 2の有効閲覧時間、閲覧品質指標、デバイス比率、IP・権限・Bot除外設定、負荷・キャッシュ・複数タブ対策を追加した開発版です。日付・期間の移動、時間別・日別一覧、実データへ影響しない固定サンプル表示にも対応しています。解析データはWordPressサイトの専用テーブルに保存します。

== Privacy ==

このプラグインはアクセス解析のため、匿名のブラウザー識別子、閲覧ページ、流入元、端末分類、ページが画面に表示されていた概算時間を保存します。
クリック位置や入力内容は保存しません。
生のIPアドレスは解析テーブルへ保存しません。詳細データの初期保存期間は90日です。
IP除外へ登録したアドレスは正規化後にハッシュ化し、生の値を保存せずに照合します。

== Changelog ==

= 0.5.0-beta =

* 基本アクセス解析版を最初の公開ベータ版として提供。
* 今日・昨日・7日・30日・今月・期間指定と、期間の前後移動に対応。
* カレンダー、時間別・日別一覧、訪問者数・PVの重ね棒グラフを追加。
* WordPressダッシュボードへ、今日・昨日・7日を切り替えられる簡易棒グラフを追加。
* 平均有効閲覧時間、直帰率、1訪問あたりPV、デバイス、流入元、人気ページを表示。
* Bot・管理者・権限・IP除外、保存期間、サンプル表示に対応。
* GitHub Release経由の標準更新通知に対応。
