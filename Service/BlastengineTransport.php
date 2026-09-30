<?php

namespace Plugin\BlastengineMailer\Service;

use Doctrine\DBAL\Connection;
use Plugin\BlastengineMailer\Entity\Config;
use Plugin\BlastengineMailer\Entity\MailLog;
use Plugin\BlastengineMailer\Repository\ConfigRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * EC-CUBE の送信経路（mailer.transports。Mailer が実際に使うサービス）を包み、設定に応じて blastengine へ流す Transport。
 *
 * - 使わない: 元の Transport（MAILER_DSN）にそのまま渡す
 * - SMTP リレー: 設定画面の値で SMTP Transport を組み立てて送る
 * - API: POST /deliveries/transaction。返ってきた配信 ID を送信ログに残す
 *
 * blastengine への送信に失敗したときは、設定が許せば元の Transport で送る（通知メールは届かないと事故）。
 * エラー停止連携が有効なら、宛先不明と判定済みの会員宛は送らず、送信ログに "suppressed" として残す
 * （店舗宛 BCC も同じメッセージなので一緒に送られない。注文自体は管理画面で確認できる）。
 * ログの書き込みは DBAL で直接行い、送信元のトランザクションや EntityManager の状態に依存しない。
 */
class BlastengineTransport implements TransportInterface
{
    /** Event.php が付ける内部ヘッダ。送信前に外す */
    public const HEADER_ORDER = 'X-Eccube-Order-Id';
    public const HEADER_CUSTOMER = 'X-Eccube-Customer-Id';
    public const HEADER_KIND = 'X-Eccube-Mail-Kind';

    private ?TransportInterface $smtp = null;
    private ?string $smtpKey = null;

    public function __construct(
        private TransportInterface $inner,
        private ConfigRepository $configRepository,
        private SecretCrypter $crypter,
        private BlastengineApiClient $api,
        private BounceService $bounce,
        private Connection $conn,
        private LoggerInterface $logger,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            $Config = $this->configRepository->get();
        } catch (\Throwable $e) {
            // プラグインのテーブルが無い等。何も挟まず元の経路で送る
            return $this->inner->send($message, $envelope);
        }
        $mode = $Config->getMode();
        $meta = $this->extractMeta($message);
        if ($mode === Config::MODE_NONE) {
            $this->stripHeaders($message);

            return $this->inner->send($message, $envelope);
        }

        // 宛先不明（エラー停止）の会員には送らない。黙って落とさず、送信ログに理由を残す
        if ($Config->isBounceSyncEnabled() && $Config->isBounceSuppressSend()
            && $meta['to'] !== null && $this->bounce->isBounced($meta['to'])) {
            $this->stripHeaders($message);
            $this->logger->info('[BlastengineMailer] suppressed: bounced address '.$meta['to']);
            $this->log($Config, $meta, $mode, MailLog::STATUS_SUPPRESSED, null, '宛先不明（エラー停止）のため未送信');

            return new SentMessage($message, $envelope ?? Envelope::create($message));
        }

        try {
            $this->stripHeaders($message);
            if ($mode === Config::MODE_API) {
                $sent = $this->sendViaApi($Config, $message, $envelope, $meta);
            } else {
                $sent = $this->smtpTransport($Config)->send($message, $envelope);
                $this->log($Config, $meta, Config::MODE_SMTP, MailLog::STATUS_SENT);
            }

            return $sent;
        } catch (\Throwable $e) {
            $this->logger->error('[BlastengineMailer] send failed via '.$mode.': '.$e->getMessage());
            if (!$Config->isFallbackOnError()) {
                $this->log($Config, $meta, $mode, MailLog::STATUS_FAILED, null, $e->getMessage());
                throw $e;
            }
            $sent = $this->inner->send($message, $envelope);
            $this->log($Config, $meta, $mode, MailLog::STATUS_FALLBACK, null, $e->getMessage());

            return $sent;
        }
    }

    public function __toString(): string
    {
        return 'blastengine://'.$this->inner;
    }

    // ---- API ----

    private function sendViaApi(Config $Config, RawMessage $message, ?Envelope $envelope, array $meta): SentMessage
    {
        if (!$message instanceof Email) {
            throw new \RuntimeException('API 方式で送れるのは Email メッセージだけです。');
        }
        $from = $message->getFrom()[0] ?? ($envelope ? $envelope->getSender() : null);
        if (!$from instanceof Address) {
            throw new \RuntimeException('From が設定されていません。');
        }
        $to = $message->getTo();
        if ($to === []) {
            throw new \RuntimeException('To が設定されていません。');
        }
        $body = [
            'from' => array_filter(['email' => $from->getAddress(), 'name' => $from->getName() !== '' ? $from->getName() : null]),
            'to' => $to[0]->getAddress(),
            'subject' => (string) $message->getSubject(),
            'text_part' => (string) ($message->getTextBody() ?? ''),
            'encode' => 'UTF-8',
        ];
        // 宛先が複数のときは 2 人目以降を cc に回す（トランザクション API の to は 1 件）
        $cc = array_merge(array_slice($to, 1), $message->getCc());
        if ($cc !== []) {
            $body['cc'] = array_map(fn (Address $a) => $a->getAddress(), array_slice($cc, 0, 10));
        }
        if ($message->getBcc() !== []) {
            $body['bcc'] = array_map(fn (Address $a) => $a->getAddress(), array_slice($message->getBcc(), 0, 10));
        }
        if ($message->getReplyTo() !== []) {
            $r = $message->getReplyTo()[0];
            $body['reply_to'] = array_filter(['email' => $r->getAddress(), 'name' => $r->getName() !== '' ? $r->getName() : null]);
        }
        if ($message->getHtmlBody()) {
            $body['html_part'] = (string) $message->getHtmlBody();
        }
        if ($body['text_part'] === '' && !isset($body['html_part'])) {
            $body['text_part'] = ' ';
        }

        $res = $this->api->sendTransaction($Config, $body);
        $deliveryId = isset($res['delivery_id']) ? (int) $res['delivery_id'] : null;
        $this->log($Config, $meta, Config::MODE_API, MailLog::STATUS_SENT, $deliveryId);

        $sent = new SentMessage($message, $envelope ?? Envelope::create($message));
        if ($deliveryId !== null) {
            $sent->setMessageId('blastengine-'.$deliveryId.'@'.parse_url($Config->getApiBaseUrl(), PHP_URL_HOST));
        }

        return $sent;
    }

    // ---- SMTP ----

    private function smtpTransport(Config $Config): TransportInterface
    {
        $user = (string) $Config->getSmtpUsername();
        $pass = (string) $this->crypter->decrypt($Config->getSmtpPassword());
        $scheme = $Config->getSmtpEncryption() === Config::ENC_TLS ? 'smtps' : 'smtp';
        $dsn = sprintf('%s://%s%s:%d', $scheme,
            $user !== '' ? rawurlencode($user).':'.rawurlencode($pass).'@' : '',
            $Config->getSmtpHost(), $Config->getSmtpPort());
        if ($Config->getSmtpEncryption() === Config::ENC_NONE) {
            $dsn .= '?auto_tls=false';
        }
        $key = hash('sha256', $dsn);
        if ($this->smtp === null || $this->smtpKey !== $key) {
            $this->smtp = Transport::fromDsn($dsn, null, null, $this->logger);
            $this->smtpKey = $key;
        }

        return $this->smtp;
    }

    /** 設定画面の「接続テスト」用: SMTP に接続して EHLO/AUTH まで通す */
    public function testSmtp(Config $Config): void
    {
        $transport = $this->smtpTransport($Config);
        if (method_exists($transport, 'start')) {
            $transport->start();
            $transport->stop();
        }
    }

    // ---- ログ ----

    private function extractMeta(RawMessage $message): array
    {
        $meta = ['to' => null, 'subject' => null, 'order_id' => null, 'customer_id' => null, 'kind' => null];
        if (!$message instanceof Email) {
            return $meta;
        }
        $h = $message->getHeaders();
        $meta['to'] = $message->getTo() !== [] ? $message->getTo()[0]->getAddress() : null;
        $meta['subject'] = mb_substr((string) $message->getSubject(), 0, 255);
        $meta['order_id'] = $h->has(self::HEADER_ORDER) ? (int) $h->get(self::HEADER_ORDER)->getBodyAsString() : null;
        $meta['customer_id'] = $h->has(self::HEADER_CUSTOMER) ? (int) $h->get(self::HEADER_CUSTOMER)->getBodyAsString() : null;
        $meta['kind'] = $h->has(self::HEADER_KIND) ? $h->get(self::HEADER_KIND)->getBodyAsString() : null;

        return $meta;
    }

    private function stripHeaders(RawMessage $message): void
    {
        if ($message instanceof Email) {
            foreach ([self::HEADER_ORDER, self::HEADER_CUSTOMER, self::HEADER_KIND] as $name) {
                $message->getHeaders()->remove($name);
            }
        }
    }

    private function log(Config $Config, array $meta, string $mode, string $status, ?int $deliveryId = null, ?string $error = null): void
    {
        if (!$Config->isLogEnabled()) {
            return;
        }
        try {
            $this->conn->insert('plg_blastengine_mailer_log', [
                'create_date' => $this->conn->convertToDatabaseValue(new \DateTime(), 'datetimetz'),
                'mode' => $mode,
                'status' => $status,
                'to_email' => $meta['to'],
                'subject' => $meta['subject'],
                'mail_kind' => $meta['kind'],
                'order_id' => $meta['order_id'],
                'customer_id' => $meta['customer_id'],
                'delivery_id' => $deliveryId,
                'error' => $error === null ? null : mb_substr($error, 0, 2000),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[BlastengineMailer] log insert failed: '.$e->getMessage());
        }
    }
}
