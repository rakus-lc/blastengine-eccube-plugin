---
type: 事実
status: draft
updated: 2026-09-28
version: v0.1
---

# BlastengineMailer 設計資料

どういう作りで、何を気を付けて作っているかをまとめる。**急いで読むなら、図 2 つと「気を付けていること」の表だけで全体がつかめる。**

- 使い方・検証状況（どこまで実機で確認済みか）: [README.md](README.md)
- 正はこのディレクトリのコード（2026-09-28 に通読して記述）。本文とコードが食い違ったらコードが正で、この文書を直す

## 一言でいうと

**EC-CUBE の「送信口」だけを差し替えるプラグイン。** メールを組み立てる処理には一切触れず、Symfony Mailer が最後に使う Transport を包んで（デコレートして）、送信の瞬間に経路を決める。

```mermaid
flowchart LR
    subgraph eccube["EC-CUBE 本体（無改変）"]
        mail["通知メール 9 種<br>（注文受付・会員登録・<br>パスワード再設定など）"] --> mailer["Symfony Mailer"]
    end
    mailer --> t["BlastengineTransport<br>（本プラグイン。設定を読んで経路を決める）"]
    t -->|"使わない"| dsn["元の経路<br>（MAILER_DSN）"]
    t -->|"SMTP リレー"| smtp["blastengine SMTP<br>smtp.engn.jp:587"]
    t -->|"API"| api["blastengine API<br>POST /deliveries/transaction"]
    t -->|"失敗時の退避（既定 ON）"| dsn
    t -.->|"経路・結果・配信 ID を記録"| log[("送信ログ<br>plg_be_mail_log")]
```

- `.env` の `MAILER_DSN` は書き換えない。プラグインを無効化すれば完全に元へ戻る
- 「使わない」を選んでも Transport は挟まったままだが、素通しするだけ

## 構成要素

| 部品 | 役割 |
|---|---|
| [Event.php](Event.php) | メール組み立てイベント（`mail.order` 等 9 種）を購読し、注文 ID・会員 ID・メール種別を**内部ヘッダ**として付ける |
| [Service/BlastengineTransport.php](Service/BlastengineTransport.php) | 中核。元の Transport を包み、送信時に設定を読んで経路を決める。抑止・退避・ログもここ |
| [Service/BlastengineApiClient.php](Service/BlastengineApiClient.php) | blastengine API の薄いクライアント（送信・配信照会・エラー停止一覧）。Bearer 認証の組み立ても担当 |
| [Service/BounceService.php](Service/BounceService.php) | 宛先状態（エラー停止）の判定・取り込み・解除。会員の列 `mail_bounce_*` を読み書き |
| [Service/SecretCrypter.php](Service/SecretCrypter.php) | パスワード・API キーの暗号化（`ECCUBE_AUTH_MAGIC` から HKDF-SHA256 で鍵派生 → libsodium secretbox） |
| Entity / Repository | `Config`（設定 1 行）、`MailLog`（送信ログ）、`CustomerBounceTrait`（会員に宛先状態の列を追加） |
| Controller / Form | 設定画面（接続テスト・テスト送信）、送信ログ一覧、宛先不明の会員一覧、会員編集への宛先状態表示 |
| [Command/BounceSyncCommand.php](Command/BounceSyncCommand.php) | `blastengine:bounce-sync`。cron でエラー停止一覧を差分取得 |

## メール 1 通が送られるまで

```mermaid
sequenceDiagram
    participant EC as EC-CUBE 本体
    participant Ev as Event.php
    participant T as BlastengineTransport
    participant BE as blastengine
    EC->>Ev: メール組み立てイベント（mail.order 等）
    Ev->>Ev: 注文 ID・会員 ID・種別を内部ヘッダで付与
    EC->>T: send()
    T->>T: 設定を読む（読めなければ元の経路へ素通し）
    T->>T: 内部ヘッダを読み取り → 除去（実メールに残さない）
    alt 宛先がエラー停止の会員（エラー停止連携が有効、かつ「送らない」設定 ON のとき）
        T->>T: 送らずログに suppressed（理由付き）
    else 通常
        T->>BE: SMTP または API で送信
        BE-->>T: API なら配信 ID
        T->>T: 送信ログに経路・結果・配信 ID を記録
    end
    opt blastengine への送信が失敗（退避 ON）
        T->>EC: 元の経路（MAILER_DSN）で送り直し、fallback と記録
    end
```

なぜ内部ヘッダ経由か: メールの組み立て（イベント）と送信（Transport）は別の場所・別のタイミングで起きる。注文 ID などの文脈をメッセージ自体に乗せて運び、送信直前に外すことで、**本体のどのクラスにも手を入れずに**ログへ文脈を残せる。ヘッダはあくまでプラグイン内の運搬用なので、どの経路でも（「使わない」の素通しでも）送信前に必ず外し、実際のメールには残さない（受信者に内部 ID が見えない）。

## 気を付けていること

| 原則 | 恐れている事故 | 実装 |
|---|---|---|
| **通知メールは絶対に落とさない** | 注文受付メールが届かない＝店の事故 | 失敗時は元の経路へ退避（既定 ON）。プラグインのテーブルが無い・設定が読めないときも素通しで送る |
| **黙って落とさない** | 「送られなかったこと」が誰にも見えない | 抑止（suppressed）・失敗（failed）・退避（fallback）をすべて送信ログに理由付きで残す |
| **本体を書き換えない** | アップデートやテーマ変更で壊れる | Transport のデコレートとイベント購読のみ。`MAILER_DSN`・テンプレートは無改変 |
| **秘密は DB 単独流出で漏れない** | DB ダンプの流出で API キーが漏れる | サーバー側秘密値 `ECCUBE_AUTH_MAGIC` から派生した鍵で暗号化。DB には平文を置かない |
| **ログは送信の巻き添えにしない** | 送信元のトランザクション失敗でログも消える | ログの書き込みは DBAL で直接行い、EntityManager の状態に依存しない |
| **危ない機能は既定オフ** | 知らないうちに送信が抑止される | エラー停止連携（列の追加・送信抑止）は明示的に有効化した店舗だけ。抑止時も注文自体は残る |
| **判定は自動で失効する** | アドレスを変えた会員に送られ続けない／止まり続けない | 宛先不明の判定は「判定時のアドレス」とセットで持ち、現在のアドレスと違えば自動で有効扱いに戻る |

## エラー停止連携のデータの流れ

宛先状態は「本人の意思（受信可否）」とは別軸の**宛先の事実**。BlastmailSync と列を共有し、通知メールと販促メールの両方に効かせる。

```mermaid
flowchart LR
    be["blastengine<br>GET /errors"] -->|"取り込み<br>（手動 or cron）"| col[("dtb_customer<br>mail_bounce_status / email /<br>at / source / message")]
    bm["blastmail<br>エラー停止読者"] -->|"BlastmailSync 側で取り込み"| col
    col -->|"送信前に照合 → 抑止"| t2["BlastengineTransport<br>（通知メール）"]
    col -->|"『エラー停止』として送る"| s2["BlastmailSync<br>（販促メール）"]
```

## 動作環境

EC-CUBE 4.3 系（PHP 8.1 以上、libsodium）。
