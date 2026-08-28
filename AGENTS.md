# Access Analytics Plus 作業ルール

## 対象

- このフォルダだけを独立したWordPressプラグインとして扱います。
- 正式Repositoryは `https://github.com/cni-works/Access-Analytics-Plus.git` です。
- 正式branchは `main` です。
- WordPress slugとインストールディレクトリは `access-analytics-plus` から変更しません。
- 指定されたRepository、branch、プロジェクト以外へGit操作を行いません。

## 通常変更

ユーザーが「変更して」と依頼した場合は、コード変更、必要な検査、`build-release.ps1 -Force`による現在Version ZIPの最新化まで行います。Commit、Push、Tag、GitHub Releaseは行いません。

## バックアップ

ユーザーが「バックアップして」と依頼した場合だけ、通常変更に加えて次を行います。

1. remote URLが正式Repositoryと完全一致することを確認します。
2. 現在branchが`main`であることを確認します。
3. `git status`、差分、公開不可情報、配布ZIPを確認します。
4. 対象プロジェクトのソースだけをCommitします。
5. `main`を正式RepositoryへPushします。

履歴が分岐している場合やnon-fast-forwardの場合は停止します。Force Pushは行いません。

## リリース

ユーザーが「リリースして」と依頼した場合だけ、新Versionの確定、Version更新、専用ZIP生成、Commit、Push、`v{version}` Tag、GitHub Release、Release Asset添付、WordPress更新通知確認まで進めます。

- Versionの指定がない場合は案を提示し、勝手に確定しません。
- GitHub自動生成のSource code ZIPは配布に使用しません。
- `build-release.ps1`で生成した`access-analytics-plus-{version}.zip`だけをRelease Assetにします。
- Tag、Asset名、ZIP内Version、ZIP最上位フォルダを一致させます。
- BuildまたはZIP検証に失敗した場合はCommit以降へ進みません。

## 開発・配布

- WordPress標準APIと既存コードの互換性を優先します。
- 入力の検証とサニタイズ、出力のエスケープ、必要な権限・nonce確認を行います。
- Versionは正式Release時だけ変更します。
- コード変更後は現在Versionの`release/access-analytics-plus-{version}.zip`を最新化します。
- `release/`とZIPはGit管理しません。
- 配布ZIPには実行時に必要なファイルだけを含め、`.git`、`docs`、`tools`、`release`、`AGENTS.md`、`README.md`、ビルドスクリプト、Git設定を含めません。
- APIキー、Password、Token、サーバー情報、クライアント固有情報、個人情報、実アクセス由来データをRepositoryへ追加しません。
- 本番サイトへ直接反映しません。

## 検査

- 変更したPHPファイルと全PHPファイルの構文を確認します。
- 既存のPHP、Tracker、管理画面UI回帰テストを実行します。
- Updater変更時は正常系、異常系、キャッシュ、fail-safe、標準自動更新用`no_update`を確認します。
- `build-release.ps1`の構文、ZIP構造、Version、Update URI、Updater同梱、開発ファイル不在を確認します。
- Git操作前に`git diff --check`、status、remote、branchを確認します。
