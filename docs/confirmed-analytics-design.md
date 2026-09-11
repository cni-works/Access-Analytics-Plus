# 確認済みアクセス集計（DB Version 4）

新規アクセスは `aap_shadow_events` に `aggregation_mode=staged` として仮保存する。生IPとUser-Agent全文は保存せず、用途別HMAC、UA系統、端末分類、ISO国コード、表示・操作・engagement信号だけを7日間保持する。

3秒の表示、スクロール・クリック・タップ・キー操作、engagementのいずれかが届き、複合リスクスコアが閾値未満なら、トランザクションと行ロックの中で既存のpages / sessions / pageviews / dailyへ一度だけ昇格する。Cronは昇格に必須ではなく、5分を過ぎたpendingの確定と除外理由集計に利用する。

5分後も信号がない行は `unconfirmed`、複合リスクが高い行は `bot`、既知の国外コードが許可リスト外なら `geo_excluded` とする。国コードが不明な `ZZ` は誤除外を避けて通常候補に残す。既存の診断行はDB更新時の既定値 `legacy` のまま再分類・再集計しない。

国判定は外部APIを呼ばない。明示的に信頼した `CF-IPCountry`、明示的に信頼したサーバー変数 `GEOIP_COUNTRY_CODE`、または `aap_country_code` フィルターを使用する。Cloudflareを経由しない接続からヘッダーを偽装されない構成確認はサイト管理者の責任範囲となる。

既知Bot、除外ユーザー、除外IP、外部Origin、レート制限は仮保存より前に従来どおり処理する。旧ビルドが発行したpageview tokenは移行直後の通信だけ互換受理する。
