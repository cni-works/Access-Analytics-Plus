# Access Analytics Plus

Current release: `0.5.8-beta` / Build `beta.20260913.8`

## 確認済みアクセスの集計

Build `beta.20260911.3` 以降の新規アクセスは、3秒の表示、操作、engagementのいずれかを確認してから通常集計へ一度だけ反映します。IPアドレスとUser-Agent全文は保存せず、用途別HMAC識別値、端末分類、国コード、確認信号を最長7日間だけ保持します。

未確認、自動巡回、設定した対象国外のアクセスは通常指標から分離し、除外理由として確認できます。既存データと旧診断行は再分類しません。

Build `beta.20260911.4` では、DB-IP Country LiteのローカルMMDBを同梱しました。国判定はサイト側フィルター、信頼済みCloudflare、信頼済みサーバーGeoIP、ローカルMMDBの順で行い、失敗時は判定不能（ZZ）へ安全に戻ります。生IPは国判定時にだけ利用し、解析データへ保存しません。

Build `beta.20260912.6` では、Google Site Kit接続済みサイトを対象に、Search Console検索キーワードをAAP内へ表示する試験機能を追加しています。Google Tokenを直接扱わず、管理画面を開いたユーザーのSite Kit権限で内部RESTへ問い合わせ、正規化した結果だけを2時間キャッシュします。長期保存とCron同期は行いません。

Build `beta.20260913.1` では、Site Kit 1.187.0の汎用REST RouteをAAPが未登録と誤判定する問題を修正し、具体的なSearch Console Routeは実際の内部REST応答で確認するよう変更しました。

Build `beta.20260913.2` では、Site Kitの配列内にGoogle行オブジェクトが含まれる応答を深く正規化し、通信失敗と形式エラーを区別できる安全な開発者診断を追加しました。

Build `beta.20260913.3` では、Google検索キーワードを上位5件だけ初期表示し、必要な場合に最大20件まで開閉できる表示へ変更しました。

Build `beta.20260913.4` では、Site Kit未導入サイトでGoogle検索キーワードカードを表示せず、導入済みサイトだけに接続状態に応じた内容を表示するよう変更しました。

Build `beta.20260913.5` では、既存の集計済み解析情報を安全なMarkdownへ整形する「AI相談用レポート」V1を追加しました。AAPからAIサービスへ通信せず、利用者が内容をプレビューしてから自分でダウンロードします。

Build `beta.20260913.6` では、AI相談用レポートの入力例を特定の業種や地域に依存しない表現へ変更しました。

Build `beta.20260913.7` では、ダウンロードしたレポートを利用者自身のAIへ添付して使う手順を、ダウンロード欄へ明記しました。

Build `beta.20260913.8` では、レポートのダウンロードと普段利用するAIへの添付を別ステップに分け、操作の流れを明確にしました。

WordPress管理画面で、ホームページ運営者がアクセス状況を短時間で確認できる軽量アクセス解析プラグインです。

## 動作要件

- WordPress 6.8以上
- PHP 8.1以上
- WordPressインストールディレクトリ: `access-analytics-plus`

## 開発構成

- メインファイル: `access-analytics-plus.php`
- namespace: `AccessAnalyticsPlus`
- GitHub Updater namespace: `CniWorks\AccessAnalyticsPlus\Updater`
- 更新配布元: `https://github.com/cni-works/Access-Analytics-Plus`

## 配布ZIP

WordPressへ配布するZIPはGitHubのSource code ZIPではなく、次のコマンドで生成します。

```powershell
.\build-release.ps1
```

同じVersionのZIPを検証済み内容で置き換える場合だけ、次を使用します。

```powershell
.\build-release.ps1 -Force
```

完成物は`release/access-analytics-plus-{version}.zip`です。`release/`はGit管理しません。

## 更新方式

公開済みのGitHub Releaseから、厳密な`vX.Y.Z`または`vX.Y.Z-prerelease` Tagと同Versionの専用Release Assetだけを使用します。Pre-releaseはベータチャンネルを明示的に有効化した版だけが取得します。GitHub API障害やRelease情報不正時は更新なしとして扱い、アクセス解析本体の計測・管理画面・フロント動作を継続します。

詳しくは[GitHub Updater仕様](docs/GITHUB-UPDATER.md)と[Release手順](docs/RELEASE-PROCEDURE.md)を参照してください。

## ライセンス

GPL-2.0-or-later。第三者ライブラリについては`THIRD_PARTY_NOTICES.md`を参照してください。
