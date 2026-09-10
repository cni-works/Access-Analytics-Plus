# GitHub Updaterテスト計画

## 自動検査

- 全PHP構文
- Updater正常・異常系回帰テスト
- 既存PHP回帰テスト
- Tracker JavaScript回帰テスト
- 管理画面UI回帰テスト
- PowerShell構文
- `git diff --check`
- 配布ZIP自動検証

## Updater正常系

- `update_plugins_github.com`へ登録される。
- HeaderのUpdate URI、basename、slugが完全一致する場合だけ応答する。
- 厳密な新Version Tagと唯一の専用Assetから更新データを返す。
- ベータチャンネルでは`vX.Y.Z-beta`形式のPre-releaseを取得し、最も新しい検証済みVersionを選ぶ。
- 安定版チャンネルではPre-releaseを取得しない。
- 同Versionまたは旧VersionはPackageなしの`no_update`用メタデータを返す。
- 成功キャッシュ12時間、失敗キャッシュ1時間を使用する。
- `force-check=1`かつ更新権限がある場合だけ対象Repositoryのキャッシュを削除する。

## Updater異常系

- timeout、`WP_Error`、HTTP 403・404・429・500
- 空レスポンス、不正JSON、想定外のJSON型
- Draft、不正Tag、チャンネルと一致しないPre-release
- Assetなし、名前不一致、重複、state不正
- GitHub以外のPackage URL
- Update URI、basename、slug、インストールディレクトリ不一致

すべて更新Packageなしとして処理し、アクセス解析本体へ例外を伝播させない。

## WordPress実機

- 最新版で標準の「自動更新を有効化」が表示される。
- ON／OFF状態が`auto_update_plugins`に保持される。
- 安定版では新Versionの通常Releaseだけ更新通知が出る。
- ベータ版では新VersionのGitHub Pre-releaseにも更新通知が出る。
- 専用Release Assetから更新でき、ディレクトリ名と設定が保持される。
- GitHub API障害時も本体の計測、専用解析画面、ダッシュボードが動作する。
