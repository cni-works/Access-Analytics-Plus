# Google Site Kit経由 Search Console連携プロトタイプ

## 目的

Access Analytics Plus（AAP）がGoogleの認証情報を直接保持せず、同じWordPressに導入済みのSite Kitが現在の利用者へ許可したSearch Consoleデータだけを表示する試験機能です。

## 境界と安全性

- Site Kitがインストールされていないサイトでは検索キーワードカード自体を表示しません。
- AAPはGoogleのAccess Token、Refresh Token、Client Secretを取得・保存しません。
- Site Kitのoption、user meta、内部PHP classを参照しません。
- AAPのREST endpointから、WordPress内部の`rest_do_request()`でSite Kit REST endpointを呼びます。
- WordPress REST Serverを経由するため、Site Kit自身の認証・権限callbackが適用されます。
- Site Kit未導入、停止、未接続、権限不足、再認証、応答形式変更、通信失敗は検索カード内だけで処理し、AAP本体の計測・集計へ影響させません。

## V1の取得範囲

- Dimension: `query`のみ
- 指標: query、clicks、impressions、ctr、position
- 件数: 上位20件
- 表示: 初期状態は上位5件。「もっと見る」で取得済みの最大20件を展開
- 期間: 7日、28日、3か月
- Search Consoleの集計遅延を考慮し、終了日は前日です。
- 長期保存はせず、利用者・サイト・期間・dimension・Site Kit Version別に2時間のtransient cacheを持ちます。

## Site Kit互換性

動作確認対象Version帯はコード内の`VERIFIED_MIN_VERSION`以上、`VERIFIED_MAX_VERSION`未満です。範囲外でも処理は停止せず、管理画面で「未確認Version」と分かる状態にしてfail-softで取得を試みます。内部RESTの経路や応答schemaが変わった場合は検索カードだけを一時エラーにします。

Site Kit 1.187.0で確認した、トップレベル配列内にGoogle APIの行オブジェクトが含まれる応答にも対応します。応答全体をJSON相当の配列へ深く正規化し、従来の配列行も同じ結果へ変換します。失敗時の開発者診断には`top-level type`、`row type`、`row count`、`schema result`だけを表示し、検索語本文やGoogle認証情報は含めません。通信失敗は`temporary_error`、形式不一致は`schema_error`として区別します。

## 手動確認

1. Site Kit未導入で、AAP解析画面にインストール案内が出ること。
2. Site Kitを有効化し、Search Console未接続時に接続案内が出ること。
3. Site KitでSearch Console接続済みの管理者に、検索語・表示・クリック・CTR・平均順位が表示されること。
4. Site Kitのダッシュボード共有を受けた利用者は、共有権限の範囲で表示できること。
5. 非共有利用者にはデータではなく権限案内が表示されること。
6. Google再認証が必要な場合にSite Kit側の再接続案内が出ること。
7. 7日・28日・3か月を切り替えられ、スマートフォンで横スクロールしないこと。
8. Site Kitを停止してもAAPの通常解析、Bot対策、国・都道府県集計が動作すること。

## 既知の制限

- Site Kitの内部REST APIは公開互換APIではないため、Site Kit更新時に確認が必要です。
- Search Consoleデータの反映時期、匿名化、値の丸めはGoogle側仕様に従います。
- Property選択、page/country/device dimension、CSV保存、AAP独自OAuthはこのプロトタイプの対象外です。
