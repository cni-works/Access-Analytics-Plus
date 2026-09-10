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
| 安定版API | `https://api.github.com/repos/cni-works/Access-Analytics-Plus/releases/latest` |
| ベータ版API | `https://api.github.com/repos/cni-works/Access-Analytics-Plus/releases?per_page=20` |
| Asset | `access-analytics-plus-{version}.zip` |
| Tag | `v{version}` |
| 成功キャッシュ | 12時間 |
| 失敗キャッシュ | 1時間 |
| timeout | 5秒 |

## 検証条件

- RepositoryはPublic、Personal Access Tokenは使用しない。
- Draftと不正なTagを拒否する。
- 安定版チャンネルはPre-releaseを拒否する。
- ベータ版チャンネルは通常ReleaseとPre-releaseを取得対象とし、検証済みVersionのうち最新を選ぶ。
- Tagは先頭ゼロなしの`vX.Y.Z`または`vX.Y.Z-prerelease`とする。
- GitHubのPre-release指定とTagのプレリリース識別子を一致させる。
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

## 初回ベータ移行

`0.1.2`のUpdaterは安定版だけを対象としているため、最初の`0.5.0-beta`は手動導入する。`0.5.0-beta`以降はベータチャンネルが有効になり、`0.5.1-beta`等をWordPress標準更新通知で取得できる。

正式版へ移行するときは`include_prereleases`を無効化し、安定版利用者へ将来のPre-releaseを自動配布しない。
