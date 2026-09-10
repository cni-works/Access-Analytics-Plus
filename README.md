# Access Analytics Plus

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
