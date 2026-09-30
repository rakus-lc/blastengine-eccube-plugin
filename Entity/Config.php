<?php

namespace Plugin\BlastengineMailer\Entity;

use Doctrine\ORM\Mapping as ORM;

if (!class_exists('\Plugin\BlastengineMailer\Entity\Config', false)) {
    /**
     * プラグイン設定（1行のみ、id=1）。
     *
     * 接続方式（mode）で送信経路を切り替える。設定は DB に持つので管理画面だけで完結し、
     * `.env` の MAILER_DSN を触らない。パスワード・API キーは SecretCrypter で暗号化して保存する。
     *
     * @ORM\Table(name="plg_blastengine_mailer_config")
     * @ORM\Entity(repositoryClass="Plugin\BlastengineMailer\Repository\ConfigRepository")
     */
    class Config
    {
        public const MODE_NONE = 'none';   // 使わない（元の MAILER_DSN で送る）
        public const MODE_SMTP = 'smtp';   // blastengine の SMTP リレー
        public const MODE_API = 'api';     // blastengine のトランザクション API

        public const ENC_AUTO = 'auto';    // ポートに応じて自動（587=STARTTLS、465=TLS）
        public const ENC_TLS = 'tls';      // 接続時から TLS（smtps://）
        public const ENC_NONE = 'none';    // 暗号化なし（ローカル検証用）

        /**
         * @ORM\Column(name="id", type="integer", options={"unsigned":true})
         * @ORM\Id
         * @ORM\GeneratedValue(strategy="IDENTITY")
         */
        private $id;

        /** @ORM\Column(name="mode", type="string", length=16, options={"default":"none"}) */
        private $mode = self::MODE_NONE;

        /** @ORM\Column(name="smtp_host", type="string", length=255, options={"default":"smtp.engn.jp"}) */
        private $smtpHost = 'smtp.engn.jp';

        /** @ORM\Column(name="smtp_port", type="integer", options={"default":587}) */
        private $smtpPort = 587;

        /** @ORM\Column(name="smtp_username", type="string", length=255, nullable=true) */
        private $smtpUsername;

        /** @ORM\Column(name="smtp_password", type="string", length=255, nullable=true) */
        private $smtpPassword;

        /** @ORM\Column(name="smtp_encryption", type="string", length=16, options={"default":"auto"}) */
        private $smtpEncryption = self::ENC_AUTO;

        /** @ORM\Column(name="api_base_url", type="string", length=255, options={"default":"https://app.engn.jp/api/v1"}) */
        private $apiBaseUrl = 'https://app.engn.jp/api/v1';

        /** @ORM\Column(name="api_login_id", type="string", length=255, nullable=true) */
        private $apiLoginId;

        /** @ORM\Column(name="api_key", type="string", length=255, nullable=true) */
        private $apiKey;

        /**
         * blastengine への送信に失敗したら元の送信経路（MAILER_DSN）で送る。
         * 通知メールは届かないと事故になるので既定 true。
         *
         * @ORM\Column(name="fallback_on_error", type="boolean", options={"default":true})
         */
        private $fallbackOnError = true;

        /** @ORM\Column(name="log_enabled", type="boolean", options={"default":true}) */
        private $logEnabled = true;

        /**
         * エラー停止連携。blastengine のエラー停止一覧を会員の「宛先状態」に反映し、
         * 宛先不明の会員には通知メールを送らない（bounce_suppress_send）。既定は無効。
         *
         * @ORM\Column(name="bounce_sync_enabled", type="boolean", options={"default":false})
         */
        private $bounceSyncEnabled = false;

        /** @ORM\Column(name="bounce_suppress_send", type="boolean", options={"default":true}) */
        private $bounceSuppressSend = true;

        /** 最後にエラー停止一覧を取得した日時（差分取得の起点） @ORM\Column(name="bounce_last_fetch_at", type="datetimetz", nullable=true) */
        private $bounceLastFetchAt;

        public function getId()
        {
            return $this->id;
        }

        public function getMode(): string
        {
            return $this->mode ?: self::MODE_NONE;
        }

        public function setMode(string $v): self
        {
            $this->mode = $v;

            return $this;
        }

        public function getSmtpHost(): string
        {
            return (string) $this->smtpHost;
        }

        public function setSmtpHost(?string $v): self
        {
            $this->smtpHost = $v;

            return $this;
        }

        public function getSmtpPort(): int
        {
            return (int) ($this->smtpPort ?: 587);
        }

        public function setSmtpPort(?int $v): self
        {
            $this->smtpPort = $v;

            return $this;
        }

        public function getSmtpUsername(): ?string
        {
            return $this->smtpUsername;
        }

        public function setSmtpUsername(?string $v): self
        {
            $this->smtpUsername = $v;

            return $this;
        }

        public function getSmtpPassword(): ?string
        {
            return $this->smtpPassword;
        }

        public function setSmtpPassword(?string $v): self
        {
            $this->smtpPassword = $v;

            return $this;
        }

        public function getSmtpEncryption(): string
        {
            return $this->smtpEncryption ?: self::ENC_AUTO;
        }

        public function setSmtpEncryption(string $v): self
        {
            $this->smtpEncryption = $v;

            return $this;
        }

        public function getApiBaseUrl(): string
        {
            return rtrim((string) $this->apiBaseUrl, '/');
        }

        public function setApiBaseUrl(?string $v): self
        {
            $this->apiBaseUrl = $v;

            return $this;
        }

        public function getApiLoginId(): ?string
        {
            return $this->apiLoginId;
        }

        public function setApiLoginId(?string $v): self
        {
            $this->apiLoginId = $v;

            return $this;
        }

        public function getApiKey(): ?string
        {
            return $this->apiKey;
        }

        public function setApiKey(?string $v): self
        {
            $this->apiKey = $v;

            return $this;
        }

        public function isFallbackOnError(): bool
        {
            return (bool) $this->fallbackOnError;
        }

        public function setFallbackOnError(bool $v): self
        {
            $this->fallbackOnError = $v;

            return $this;
        }

        public function isBounceSyncEnabled(): bool
        {
            return (bool) $this->bounceSyncEnabled;
        }

        public function setBounceSyncEnabled(bool $v): self
        {
            $this->bounceSyncEnabled = $v;

            return $this;
        }

        public function isBounceSuppressSend(): bool
        {
            return (bool) $this->bounceSuppressSend;
        }

        public function setBounceSuppressSend(bool $v): self
        {
            $this->bounceSuppressSend = $v;

            return $this;
        }

        public function getBounceLastFetchAt(): ?\DateTimeInterface
        {
            return $this->bounceLastFetchAt;
        }

        public function setBounceLastFetchAt(?\DateTimeInterface $v): self
        {
            $this->bounceLastFetchAt = $v;

            return $this;
        }

        public function isLogEnabled(): bool
        {
            return (bool) $this->logEnabled;
        }

        public function setLogEnabled(bool $v): self
        {
            $this->logEnabled = $v;

            return $this;
        }
    }
}
