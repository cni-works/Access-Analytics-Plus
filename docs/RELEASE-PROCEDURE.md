# Release手順

通常変更、バックアップ、正式Releaseは区別する。

## 通常変更

1. コードを変更する。
2. 必要な構文検査と回帰テストを行う。
3. `.\build-release.ps1 -Force`で現在Version ZIPを最新化する。
4. ZIP構造を確認する。
5. Commit、Push、Tag、GitHub Releaseは行わない。

## バックアップ

1. 通常変更の手順を完了する。
2. `git status`、`git diff`、`git diff --check`を確認する。
3. remoteが`https://github.com/cni-works/Access-Analytics-Plus.git`、branchが`main`であることを確認する。
4. 秘密情報・個人情報・実データ・ZIPがGit対象にないことを確認する。
5. Commitし、fast-forward可能な`main`だけをPushする。
6. TagとGitHub Releaseは作成しない。

## 正式Release

1. 公開範囲と次Versionを確定する。
2. Plugin Header Version、`AAP_VERSION`、`readme.txt` Stable tagを一致させる。
3. 全検査を実行する。
4. `.\build-release.ps1`で新Version ZIPを生成する。
5. ZIP最上位、Updater、Version、Update URI、開発ファイル不在を確認する。
6. Commitして`main`をPushする。
7. `v{version}` Tagを作成・Pushする。
8. 安定版は通常のGitHub Release、`-beta`等のプレリリース版はGitHub Pre-releaseとして作成する。
9. `access-analytics-plus-{version}.zip`を専用Release Assetとして添付する。
10. Tag、Asset名、ZIP内Version、ZIP rootを再確認する。
11. 旧Versionのテストサイトで標準更新通知、手動更新、自動更新UI、設定保持、本体機能を確認する。

GitHubのSource code ZIPはRelease Assetとして扱わない。

## ベータチャンネル

- `include_prereleases`を明示的に有効化した開発版だけがGitHub Pre-releaseを更新候補に含める。
- 安定版では`include_prereleases`を無効化し、Pre-releaseを自動配布しない。
- プレリリースTag、Plugin Header Version、Stable tag、専用ZIP名を完全一致させる。
