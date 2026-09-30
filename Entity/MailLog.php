<?php

namespace Plugin\BlastengineMailer\Entity;

use Doctrine\ORM\Mapping as ORM;

if (!class_exists('\Plugin\BlastengineMailer\Entity\MailLog', false)) {
    /**
     * 送信ログ。EC-CUBE から出た通知メールが、どの経路で送られ、blastengine 側の配信 ID が何かを残す。
     *
     * @ORM\Table(name="plg_blastengine_mailer_log", indexes={
     *     @ORM\Index(name="plg_bem_log_order", columns={"order_id"}),
     *     @ORM\Index(name="plg_bem_log_customer", columns={"customer_id"})
     * })
     * @ORM\Entity(repositoryClass="Plugin\BlastengineMailer\Repository\MailLogRepository")
     */
    class MailLog
    {
        public const STATUS_SENT = 'sent';         // blastengine で送信
        public const STATUS_FALLBACK = 'fallback'; // blastengine に失敗し、元の経路で送信
        public const STATUS_FAILED = 'failed';     // 送れなかった
        public const STATUS_SUPPRESSED = 'suppressed'; // 宛先不明（エラー停止）のため送らなかった

        /**
         * @ORM\Column(name="id", type="integer", options={"unsigned":true})
         * @ORM\Id
         * @ORM\GeneratedValue(strategy="IDENTITY")
         */
        private $id;

        /** @ORM\Column(name="create_date", type="datetimetz") */
        private $createDate;

        /** smtp / api @ORM\Column(name="mode", type="string", length=16) */
        private $mode;

        /** @ORM\Column(name="status", type="string", length=16) */
        private $status;

        /** @ORM\Column(name="to_email", type="string", length=255, nullable=true) */
        private $toEmail;

        /** @ORM\Column(name="subject", type="string", length=255, nullable=true) */
        private $subject;

        /** メールの種類（EC-CUBE のイベント名。mail.order 等） @ORM\Column(name="mail_kind", type="string", length=64, nullable=true) */
        private $mailKind;

        /** @ORM\Column(name="order_id", type="integer", nullable=true) */
        private $orderId;

        /** @ORM\Column(name="customer_id", type="integer", nullable=true) */
        private $customerId;

        /** blastengine の配信 ID（API 方式のみ） @ORM\Column(name="delivery_id", type="bigint", nullable=true) */
        private $deliveryId;

        /** blastengine 側の配信結果（照会して埋める） @ORM\Column(name="delivery_status", type="string", length=64, nullable=true) */
        private $deliveryStatus;

        /** @ORM\Column(name="error", type="text", nullable=true) */
        private $error;

        public function getId()
        {
            return $this->id;
        }

        public function getCreateDate(): \DateTimeInterface
        {
            return $this->createDate;
        }

        public function getMode(): string
        {
            return $this->mode;
        }

        public function getStatus(): string
        {
            return $this->status;
        }

        public function getToEmail(): ?string
        {
            return $this->toEmail;
        }

        public function getSubject(): ?string
        {
            return $this->subject;
        }

        public function getMailKind(): ?string
        {
            return $this->mailKind;
        }

        public function getOrderId(): ?int
        {
            return $this->orderId;
        }

        public function getCustomerId(): ?int
        {
            return $this->customerId;
        }

        public function getDeliveryId(): ?int
        {
            return $this->deliveryId === null ? null : (int) $this->deliveryId;
        }

        public function getDeliveryStatus(): ?string
        {
            return $this->deliveryStatus;
        }

        public function setDeliveryStatus(?string $v): self
        {
            $this->deliveryStatus = $v;

            return $this;
        }

        public function getError(): ?string
        {
            return $this->error;
        }
    }
}
