<?php

namespace Plugin\BlastengineMailer\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Eccube\Entity\Customer;
use Plugin\BlastengineMailer\Entity\Config;
use Plugin\BlastengineMailer\Repository\ConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * 会員の「宛先状態」（宛先不明＝エラー停止）の管理。
 *
 * - blastengine のエラー停止一覧（GET /errors）を取得して会員に反映する
 * - 他プラグイン（BlastmailSync）や手動からも markBounced で反映できる
 * - Transport は isBounced で送信前に判定する
 *
 * DB 操作は DBAL で直接行う。送信中の EntityManager の状態に依存させないため。
 * 列が無い（プロキシ未生成・スキーマ未更新）環境では何もしない。
 */
class BounceService
{
    public const SOURCE_BLASTENGINE = 'blastengine';
    public const SOURCE_BLASTMAIL = 'blastmail';
    public const SOURCE_MANUAL = 'manual';

    /** 差分取得の重なり。前回取得時刻からこの秒数だけ戻して取る（取りこぼし防止） */
    private const FETCH_OVERLAP_SEC = 86400;

    private ?bool $columnsExist = null;

    public function __construct(
        private Connection $conn,
        private ConfigRepository $configRepository,
        private BlastengineApiClient $api,
        private LoggerInterface $logger,
    ) {
    }

    /** 会員テーブルに宛先状態の列があるか（プラグイン有効化後にスキーマ更新済みか） */
    public function isSupported(): bool
    {
        if ($this->columnsExist === null) {
            try {
                $cols = $this->conn->createSchemaManager()->listTableColumns('dtb_customer');
                $this->columnsExist = isset($cols['mail_bounce_status']) && isset($cols['mail_bounce_email']);
            } catch (\Throwable $e) {
                $this->columnsExist = false;
            }
        }

        return $this->columnsExist;
    }

    /** 宛先が宛先不明と判定された会員のものか（送信前判定用。会員が居なければ false） */
    public function isBounced(string $email): bool
    {
        if ($email === '' || !$this->isSupported()) {
            return false;
        }
        try {
            $hit = $this->conn->fetchOne(
                'SELECT id FROM dtb_customer WHERE LOWER(email) = LOWER(?) AND mail_bounce_status = 1 AND LOWER(mail_bounce_email) = LOWER(email) LIMIT 1',
                [$email]
            );

            return $hit !== false && $hit !== null;
        } catch (\Throwable $e) {
            $this->logger->error('[BlastengineMailer] bounce lookup failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * メールアドレスを宛先不明として会員に反映する。現在そのアドレスを使っている会員だけが対象。
     * 既に同じアドレスで宛先不明になっている会員は触らない（判定元・日時を上書きしない）。
     *
     * @return int 反映した会員数
     */
    public function markBounced(string $email, string $source, ?string $message = null, ?\DateTimeInterface $at = null): int
    {
        if ($email === '' || !$this->isSupported()) {
            return 0;
        }
        $at = $at ?? new \DateTime();

        return (int) $this->conn->executeStatement(
            'UPDATE dtb_customer SET mail_bounce_status = 1, mail_bounce_email = email, mail_bounce_at = ?, mail_bounce_source = ?, mail_bounce_message = ?
             WHERE LOWER(email) = LOWER(?) AND NOT (mail_bounce_status = 1 AND LOWER(COALESCE(mail_bounce_email, \'\')) = LOWER(email))',
            [$this->conn->convertToDatabaseValue($at, 'datetimetz'), $source, $message === null ? null : mb_substr($message, 0, 255), $email]
        );
    }

    /** 会員の宛先状態を有効に戻す（手動解除） */
    public function clear(int $customerId, string $source = self::SOURCE_MANUAL): void
    {
        if (!$this->isSupported()) {
            return;
        }
        $this->conn->executeStatement(
            'UPDATE dtb_customer SET mail_bounce_status = 0, mail_bounce_email = NULL, mail_bounce_at = ?, mail_bounce_source = ?, mail_bounce_message = NULL WHERE id = ?',
            [$this->conn->convertToDatabaseValue(new \DateTime(), 'datetimetz'), $source, $customerId]
        );
    }

    /**
     * blastengine のエラー停止一覧を取得して会員に反映する。
     * 前回取得日時があれば、その 1 日前以降の更新分だけを取る（差分）。
     *
     * @return array{pages:int, fetched:int, marked:int, since:?string}
     */
    public function fetchFromBlastengine(?Config $Config = null, ?callable $progress = null): array
    {
        $Config = $Config ?? $this->configRepository->get();
        if (!$this->isSupported()) {
            throw new \RuntimeException('会員テーブルに宛先状態の列がありません。プラグインを更新（スキーマ更新）してください。');
        }
        $startedAt = new \DateTime();
        $query = ['size' => 1000, 'page' => 1, 'sort' => 'updated_time:asc'];
        $since = null;
        if ($Config->getBounceLastFetchAt()) {
            $from = \DateTime::createFromInterface($Config->getBounceLastFetchAt())->modify('-'.self::FETCH_OVERLAP_SEC.' seconds');
            $since = $from->format('Y-m-d\TH:i:sP');
            $query['error_start'] = $since;
        }
        $pages = 0;
        $fetched = 0;
        $marked = 0;
        while (true) {
            $res = $this->api->errors($Config, $query);
            $items = $res['data'] ?? [];
            if (!is_array($items) || $items === []) {
                break;
            }
            $pages++;
            foreach ($items as $it) {
                $email = (string) ($it['email'] ?? '');
                if ($email === '') {
                    continue;
                }
                $fetched++;
                $msg = trim(((string) ($it['response_code'] ?? '')).' '.((string) ($it['response_message'] ?? '')));
                $at = null;
                if (!empty($it['error_time'])) {
                    try {
                        $at = new \DateTime((string) $it['error_time']);
                    } catch (\Throwable $e) {
                        $at = null;
                    }
                }
                $marked += $this->markBounced($email, self::SOURCE_BLASTENGINE, $msg !== '' ? $msg : null, $at);
            }
            if ($progress) {
                $progress($pages, $fetched, $marked);
            }
            if (count($items) < $query['size'] || $query['page'] >= 1000) {
                break;
            }
            $query['page']++;
        }
        $this->conn->update('plg_blastengine_mailer_config', [
            'bounce_last_fetch_at' => $this->conn->convertToDatabaseValue($startedAt, 'datetimetz'),
        ], ['id' => 1]);

        return ['pages' => $pages, 'fetched' => $fetched, 'marked' => $marked, 'since' => $since];
    }

    /** 宛先不明の会員一覧（管理画面用） */
    public function listBounced(int $limit = 200): array
    {
        if (!$this->isSupported()) {
            return [];
        }

        return $this->conn->fetchAllAssociative(
            'SELECT id, name01, name02, email, mail_bounce_email, mail_bounce_at, mail_bounce_source, mail_bounce_message,
                    (LOWER(COALESCE(mail_bounce_email, \'\')) = LOWER(email)) AS effective
             FROM dtb_customer WHERE mail_bounce_status = 1 ORDER BY mail_bounce_at DESC LIMIT '.(int) $limit
        );
    }

    public function countBounced(): int
    {
        if (!$this->isSupported()) {
            return 0;
        }

        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM dtb_customer WHERE mail_bounce_status = 1 AND LOWER(COALESCE(mail_bounce_email, \'\')) = LOWER(email)');
    }
}
