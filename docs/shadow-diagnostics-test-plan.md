# シャドー判定テスト計画

## 目的

新しいアクセスを確認信号が届くまで仮保存し、確認済みだけが通常集計へ一度だけ昇格することと、診断材料を最長7日間だけ保存することを確認する。

## DB Version 4 追加確認

- 3秒表示、各操作、engagementの各経路で1回だけconfirmedへ昇格する。
- 同じsignal / beaconの再送、複数タブ、bfcache復帰でも二重加算されない。
- 2秒以内離脱、非表示タブ、sendBeacon失敗は5分後にunconfirmedとなり通常指標へ入らない。
- webdriver単独では確定Botにせず、端末矛盾・大量新規visitor・同一UA/ページ集中・一定間隔を複合評価する。
- 許可国、許可外の既知国、ZZ、Cloudflare有無、サーバーGeoIPフィルターを確認する。
- 旧pageview / daily / legacy診断行を再分類・削除しない。

## 自動確認

- `php tools/shadow-diagnostics-regression.php`
- `php tools/php-regression.php`
- `node tools/tracker-regression.js`
- `node tools/admin-ui-regression.js`
- 全PHPファイルの`php -l`

## DB

1. `aap_shadow_events.pageview_id`がUNIQUEで、同じPVの診断行が重複しない。
2. `recorded_at`、`ip_key + recorded_at`、`shadow_class + recorded_at`、`finalized + recorded_at`のINDEXがある。
3. IPとUser-Agentの全文が保存されず、HMAC-SHA256値だけが保存される。
4. 診断保存を失敗させても通常の`collect`レスポンスと既存集計が変わらない。

## ブラウザー信号

1. 表示状態が累計3秒未満なら`visible_confirmed`が立たない。
2. 非表示タブの時間を3秒へ加算しない。
3. スクロール、ポインター、タップ、キー操作は位置や内容を保存せずビットだけを保存する。
4. 同じ信号を再送しても状態が後戻りせず、重複加算されない。
5. engagementが届いた場合だけ`engagement_received`が立つ。

## 判定

1. 作成後5分間は、信号なしをリスクへ加えず`pending`にする。
2. 5分経過後も、0秒・1PV・操作なしの単独条件では`Bot疑い`にしない。
3. 同一匿名IPの利用者数が多いだけでは`Bot疑い`にしない。
4. 多数のvisitor IDと表示・engagement未確認が重なった場合は`Bot疑い`になる。
5. `navigator.webdriver`単独では`Bot疑い`にしない。

## 保存期間と画面

1. 7日より古い診断行が5,000件単位で削除される。
2. 設定画面だけに「診断対象アクセス」「人間らしい挙動あり」「未確認」「Bot疑い」を表示する。
3. 通常解析画面、ダッシュボード、CSV相当の通常データへ診断結果を混ぜない。
4. 匿名IP表示は先頭4文字だけで、生IPやUser-Agent全文を表示しない。

## 実機確認

- iPhone Safari、Android Chrome
- WP Fastest Cache有効ページ
- 複数タブ、バックグラウンド、画面ロック、bfcache復帰
- CookieまたはlocalStorageが利用できない環境
