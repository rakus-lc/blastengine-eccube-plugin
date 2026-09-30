# blastengine連携プラグイン（BlastengineMailer）for EC-CUBE 4.3

> ⚠ **本プラグインは参考コードで、動作の保証はありません。** ご利用を検討される場合は、株式会社ラクスライトクラウドまでご相談ください。

EC-CUBE が送る通知メール（注文受付・会員登録・パスワード再設定・出荷通知など 9 種類）の送信経路を、管理画面から blastengine に切り替えます。`.env` の `MAILER_DSN` は変更しません。

## できること

- 接続方式を管理画面で選ぶ: 使わない／SMTP リレー／API
- SMTP リレー: blastengine の SMTP サーバーに中継（ホスト・ポート・認証・暗号化を設定）
- API: blastengine のトランザクション API（`POST /deliveries/transaction`）で送信。配信 ID が送信ログに残り、配信結果を照会できる
- 送信ログ: 経路・結果・宛先・件名・メール種別・注文 ID・会員 ID・配信 ID。注文詳細へのリンク付き
- 失敗時の退避: blastengine への送信に失敗したら元の経路（`MAILER_DSN`）で送る（既定 ON。通知メールは届かないと事故になるため）
- 「保存して接続テスト」「保存してテスト送信」ボタン
- パスワード・API キーは暗号化して保存（サーバー秘密値 `ECCUBE_AUTH_MAGIC` から派生した鍵。DB 単独の流出では復号できない）

## 使い方

1. 管理画面 設定 ＞ blastengine連携設定 で接続方式を選び、必要な項目を入れて「保存して接続テスト」
   - SMTP: blastengine 管理画面「SMTP リレー」で発行したユーザー名・パスワード。通常 587（STARTTLS）
   - API: blastengine 管理画面のログイン ID と API キー
2. 「保存してテスト送信」で店舗メールアドレス宛にテストメールを送り、設定 ＞ blastengine送信ログ で結果を確認
3. 以後、EC-CUBE の通知メールはすべて選んだ経路で送られる

⚠ 送信元（From）は店舗設定のメールアドレスです。blastengine 側でそのドメインの認証（SPF/DKIM）を済ませてください。

## 仕組み

- Symfony Mailer が実際に使う Transport（サービス `mailer.transports`）をデコレートし、送信時に設定を読んで経路を決める。「使わない」なら元の Transport にそのまま渡す
- EC-CUBE のメール組み立てイベント（`mail.order`、`mail.customer.confirm` 等）で注文 ID・会員 ID・種別を内部ヘッダとして付け、Transport がログに記録してから外す（実際のメールには残らない）
- API 方式の本文は text_part（＋html_part があれば）。EC-CUBE 標準の通知メールに添付は無い。宛先が複数のときは 2 人目以降を cc に回す（API の to は 1 件）
- blastengine API の認証: `Authorization: Bearer base64(lower(sha256(ログインID + APIキー)))`（公式 MCP サーバーの実装に準拠）

## エラー停止連携（宛先不明の共有）

宛先不明でエラー停止になったアドレスを、会員の「宛先状態」として EC-CUBE 側に持つ。受信可否（オプトイン／アウト。本人の意思）とは別の項目で、通知メールと販促メール（blastmail 連携プラグイン）の両方に効く。**既定は無効**。「blastengine連携設定」の「エラー停止連携」で有効にする。

- 会員に列を足す（`dtb_customer`）: `mail_bounce_status`（0 有効／1 宛先不明）、`mail_bounce_email`（判定時のアドレス）、`mail_bounce_at`、`mail_bounce_source`（blastengine／blastmail／manual）、`mail_bounce_message`
- **取り込み**: blastengine のエラー停止一覧（`GET /errors`、1,000 件ずつページング）を取得し、同じアドレスの会員を宛先不明にする。前回取得日時の 1 日前以降を差分で取る。管理画面「宛先不明（エラー停止）の会員」の「今すぐ取得」か、cron で `bin/console blastengine:bounce-sync`。API のログイン ID・API キーが必要（接続方式が SMTP リレーでも使える）
- **送信抑止**: 「宛先不明の会員には通知メールを送らない」が有効なら、Transport が送信前に宛先を照合し、該当すれば送らずに送信ログへ `suppressed`（理由: 宛先不明のため未送信）と残す。黙って落とさない。店舗宛 BCC も同じメッセージなので一緒に送られない（注文自体は管理画面で見える）。接続方式が「使わない」のときは抑止しない
- **解除**: 会員がメールアドレスを変えると判定は自動で無効になる（判定時のアドレスと現在のアドレスが違えば有効扱い）。管理画面の一覧か会員編集の「メールの宛先状態」から手動でも戻せる。blastengine 側のエラー停止は API では解除できない（管理画面か 2 週間の自動解除）
- **blastmail 側**: blastmail 連携プラグインの「エラー停止連携」を有効にすると、blastmail のエラー停止読者をこの列に取り込み、宛先不明の会員を受信可否に関係なく「エラー停止」として送る（詳細はそちらの README）

有効化後に **スキーマ更新が必要**（列の追加）。`bin/console eccube:plugin:update BlastengineMailer` か、開発環境なら `bin/console eccube:generate:proxies && bin/console doctrine:schema:update --force`。

## 検証状況

- ✅ **API 方式は実アカウントで確認済み**（2026-09-24）: 会員仮登録・本登録・注文の通知メール 3 通を実送信して到達を確認。送信ログへの配信 ID 記録、注文メールの注文 ID 紐づけ、配信結果の照会（`status=SENT` と配信日時）まで実機で確認
- ✅ 使わない／SMTP（ローカルの mailcatcher）／API の 3 方式で送信、ログ、退避、内部ヘッダの除去、会員 ID・種別の記録、秘密値が画面に出ないこと
- ✅ 送信元ドメインの認証: From のドメインを blastengine 側で認証していないと DMARC が fail する（認証済みドメインなら pass）。2026-09-24 に両方を実機で確認
- ★ SMTP リレー方式（`smtp.engn.jp:587`）の実機送信は未検証
- ★ エラー停止連携（2026-09-08 追加）: モック API（`GET /errors`）で取り込み・送信抑止（`suppressed`）・管理画面の解除を確認済み。実アカウントでの応答形は未確認

## 動作環境

EC-CUBE 4.3 系（PHP 8.1 以上、libsodium）。
