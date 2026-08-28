# CNI Works GitHub Updater適用記録

このRepositoryのUpdaterは、CNI Works共通の`GITHUB-UPDATER-REUSE-SPEC.md`に準拠する。共通仕様を製品固有値へ置き換えた結果は[GitHub Updater仕様](GITHUB-UPDATER.md)に記載する。

## 維持する共通仕様

- Public GitHub Repositoryと`releases/latest` APIを使用する。
- Personal Access Tokenを使用しない。
- WordPressの`Update URI`動的フィルターと標準更新処理を利用する。
- Draft、Pre-release、不正Tag、不正Assetを拒否する。
- Source code ZIPを使用せず、`build-release.ps1`の専用ZIPだけを使用する。
- 成功12時間、失敗1時間のRepository固有site transientを使用する。
- GitHub API障害時もPlugin本体を継続し、Packageなしの安全なメタデータを返す。
- WordPress標準自動更新ON／OFFを尊重し、独自自動更新UIを追加しない。
- 通常変更ではVersion、Tag、GitHub Releaseを変更しない。
- 正式Release時だけVersion、Tag、Release、Release Assetを揃える。

## 製品固有値

```text
owner:        cni-works
repository:   Access-Analytics-Plus
slug:         access-analytics-plus
main file:    access-analytics-plus.php
namespace:    CniWorks\AccessAnalyticsPlus\Updater
Update URI:   https://github.com/cni-works/Access-Analytics-Plus
Asset:        access-analytics-plus-{version}.zip
ZIP root:     access-analytics-plus/
```

共通仕様を変更する場合は、他のCNI Works製品との互換性、複数Updater共存、WordPress標準自動更新UI、fail-safeを再検査する。
