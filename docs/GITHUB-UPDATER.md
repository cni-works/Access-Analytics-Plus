# Access Analytics Plus GitHub Updater仕様

## 製品固有設定

| 項目 | 値 |
|---|---|
| type | `plugin` |
| owner | `cni-works` |
| repository | `Access-Analytics-Plus` |
| slug | `access-analytics-plus` |
| plugin basename | `access-analytics-plus/access-analytics-plus.php` |
| Update URI | `https://github.com/cni-works/Access-Analytics-Plus` |
| namespace | `CniWorks\AccessAnalyticsPlus\Updater` |
| API | `https://api.github.com/repos/cni-works/Access-Analytics-Plus/releases/latest` |
| Asset | `access-analytics-plus-{version}.zip` |
| Tag | `v{version}` |
| 成功キャッシュ | 12時間 |
| 失敗キャッシュ | 1時間 |
| timeout | 5秒 |

## 検証条件

- RepositoryはPublic、Personal Access Tokenは使用しない。
- Draft、Pre-release、不正なTagを拒否する。
- Tagは先頭ゼロなしの厳密な`vX.Y.Z`とする。
- Asset名、Tag Version、ZIP内Versionを一致させる。
- 同名Assetが複数ある場合、Assetが`uploaded`でない場合は拒否する。
- Package URLはHTTPSの`github.com`だけを許可する。
- GitHubが自動生成するSource code ZIPは選択しない。
- 新Versionの場合だけ検証済みPackage URLを返す。
- 同Version、旧Version、API障害、Release不正時はPackageなしの安全なメタデータを返す。
- WordPress標準の自動更新ON／OFFを尊重し、Updaterから`autoupdate`を固定しない。

## fail-safe

Updaterの読み込みと初期化は本体Bootstrapから分離する。Updaterファイルが読めない場合や初期化中に例外が発生した場合も、アクセス計測、管理画面、REST API、Cronを通常どおり登録する。

HTTP、JSON、Release検証の失敗は例外として本体へ伝播させず、失敗結果を1時間キャッシュする。利用者向けに秘密情報を含むログやエラーを表示しない。

## 初回導入

Updaterを含まない既存VersionはGitHub Releaseを検出できない。既存サイトへ最初のUpdater入りVersionを一度手動導入した後、次の新VersionからWordPress標準更新通知を利用する。
