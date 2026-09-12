# 確認済みアクセス集計（DB Version 8）

新規アクセスは `aap_shadow_events` に `aggregation_mode=staged` として仮保存する。生IPとUser-Agent全文は保存せず、用途別HMAC、UA系統、端末分類、ISO国コード、表示・操作・engagement信号だけを7日間保持する。

3秒の表示、スクロール・クリック・タップ・キー操作、engagementのいずれかが届き、複合リスクスコアが閾値未満なら、トランザクションと行ロックの中で既存のpages / sessions / pageviews / dailyへ一度だけ昇格する。Cronは昇格に必須ではなく、5分を過ぎたpendingの確定と除外理由集計に利用する。

5分後も信号がない行は `unconfirmed`、複合リスクが高い行は `bot`、既知の国外コードが許可リスト外なら `geo_excluded` とする。国コードが不明な `ZZ` は誤除外を避けて通常候補に残す。既存の診断行はDB更新時の既定値 `legacy` のまま再分類・再集計しない。

国判定は外部APIを呼ばない。明示的に信頼した `CF-IPCountry`、明示的に信頼したサーバー変数 `GEOIP_COUNTRY_CODE`、または `aap_country_code` フィルターを使用する。Cloudflareを経由しない接続からヘッダーを偽装されない構成確認はサイト管理者の責任範囲となる。

既知Bot、除外ユーザー、除外IP、外部Origin、レート制限は仮保存より前に従来どおり処理する。旧ビルドが発行したpageview tokenは移行直後の通信だけ互換受理する。

確認信号はHTTP成功応答後にだけ送信済みとし、一時失敗は1秒・3秒・8秒間隔で合計3回まで再送する。集団的なIP・UA傾向は、3秒表示や操作などの直接信号がある場合にはBot除外の決定条件にしない。

DB Version 8ではTracker Build、処理段階、昇格試行数、失敗段階・種別・サニタイズ済みDBエラーを7日間の診断行へ保持する。再送時は匿名化したcollect IDのUNIQUE制約で同じ仮保存行を再利用する。既存サイトではpageview_id・session_id・collect_keyを明示的にNULL許可へ移行し、未確定値0をNULLへ変換する。DB Versionは移行と必須テーブル・カラム・INDEX検査の完了後だけ更新する。
