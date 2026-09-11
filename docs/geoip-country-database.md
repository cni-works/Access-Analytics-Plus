# ローカル国判定データベース

対象Build: `beta.20260911.4`

## 判定順序

1. `aap_country_code` フィルターが返す明示的なISO 2文字コード
2. 管理者が信頼を有効にした `CF-IPCountry`
3. `AAP_TRUST_GEOIP_COUNTRY_CODE` が有効なサーバーの `GEOIP_COUNTRY_CODE`
4. DB-IP Country LiteローカルMMDB（更新データ、同梱データの順）
5. 判定不能 `ZZ`

`ZZ` は国別許可設定で自動除外しない。Cloudflare由来のヘッダーは、信頼設定がOFFのとき利用しない。

## 保存とプライバシー

- IPアドレスはリクエスト中に国コードを検索するためだけに利用する。
- 解析テーブルへ保存する値はISO 2文字国コードと判定元だけで、生IPは保存しない。
- 同梱MMDBは `data/`、更新MMDBは `wp-content/uploads/access-analytics-plus/geo/` に置く。
- 国判定は外部GeoIP APIへアクセスしない。DB更新時だけDB-IP公式配布先へ接続する。

## 更新とfail-safe

- WordPress Cronの既存保守処理から更新要否を1日1回以下で確認する。
- 月が変わり、現在月版が未導入の場合だけ公式gzipを取得する。
- 手動の「今すぐ更新」では現在月版を再取得できる。
- gzipは圧縮サイズ12 MiB、展開サイズ24 MiBを上限とする。
- 展開後にMMDBのdatabase typeと既知IPをMaxMind DB Readerで検証してから有効化する。
- 取得・展開・検証・保存のどこで失敗しても、現在の更新DBまたは同梱DBを使い続ける。
- 更新DBが読めない場合も、リクエスト単位で同梱DBへフォールバックする。
- データ版が60日以上前でも停止せず、設定画面に警告だけ表示する。

## 手動確認

1. 設定画面にデータソース、2026-09版、最終更新、状態、更新ボタンが表示される。
2. 「今すぐ更新」で成功またはfail-safeの通知が表示される。
3. 専用解析画面とダッシュボードウィジェットに `IP Geolocation by DB-IP` が表示される。
4. 国設定を「指定した国のみ通常集計」、許可国を `JP` にして、日本以外の既知IPが地域設定による対象外へ入る。
5. 判定不能 `ZZ` は通常集計候補のままになる。

## ライセンス

帰属、出典、CC BY 4.0、同梱データ版、変更有無は `THIRD_PARTY_NOTICES.md` に記載する。ReaderはMaxMind DB Reader for PHP 1.13.1をApache License 2.0で同梱する。
