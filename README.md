# Access Analytics Plus

Current release: `0.5.2-beta` / Build `beta.20260911.5`

## 確認済みアクセスの集計

Build `beta.20260911.3` 以降の新規アクセスは、3秒の表示、操作、engagementのいずれかを確認してから通常集計へ一度だけ反映します。IPアドレスとUser-Agent全文は保存せず、用途別HMAC識別値、端末分類、国コード、確認信号を最長7日間だけ保持します。

未確認、自動巡回、設定した対象国外のアクセスは通常指標から分離し、除外理由として確認できます。既存データと旧診断行は再分類しません。

Build `beta.20260911.4` では、DB-IP Country LiteのローカルMMDBを同梱しました。国判定はサイト側フィルター、信頼済みCloudflare、信頼済みサーバーGeoIP、ローカルMMDBの順で行い、失敗時は判定不能（ZZ）へ安全に戻ります。生IPは国判定時にだけ利用し、解析データへ保存しません。

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
