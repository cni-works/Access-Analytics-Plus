# 問い合わせ・成果計測 V1

## 対象

- `line_click`: LINE導線クリック
- `tel_click`: `tel:`リンクのクリック
- `contact_click`: お問い合わせ導線クリック
- `form_submit`: Contact Form 7の送信成功（`wpcf7mailsent`）
- `map_click`: 地図導線クリック

LINEと電話はリンクから自動判別します。お問い合わせや地図など意味がURLだけでは確定しない要素は、`data-aap-event="contact_click"` または `data-aap-event="map_click"` を指定します。発生場所は最寄りの`data-aap-location`を使用し、未指定時は`header`、`footer`、`content`の順で推定します。

## 確認済みアクセスとの関係

イベント送信にはcollect応答の署名付きtokenを利用します。pendingアクセスでCTA操作を受信した場合、その操作を直接的な人間信号として既存の確認処理を行い、confirmedへ昇格できた場合だけイベントを保存します。既知Bot、対象地域外、無効tokenは保存しません。

## CV定義

設定画面で計測イベントとCVイベントを選択します。初期状態ではLINE、電話、お問い合わせ、フォーム送信成功をCVとし、地図クリックは参考イベントです。

- 操作回数: イベント行数
- CV訪問: 期間中にCVイベントが1回以上あった一意なsession数
- CV率: CV訪問 ÷ 通常集計の訪問数
- ページ別CV率: そのページでCVが発生した一意なsession数 ÷ そのページを閲覧した一意なsession数

同一訪問で複数回操作しても、CV訪問は1件です。操作回数には実回数を表示します。

## 保存とプライバシー

`aap_events`にはイベントUUIDのHMAC、内部session/pageview/page ID、許可済みイベント名、短い位置・対象種別、発生日時だけを保存します。生IP、User-Agent全文、フォーム入力値、電話番号、リンク先URL、クリック座標は保存しません。イベント詳細はpageviewと同じ保存期間で分割削除します。

## V1対象外

ランディングページ別アトリビューション、CV直前ページ、全閲覧経路、アシストCV、金額、任意イベント作成、Contact Form 7以外のフォーム自動連携は含みません。
