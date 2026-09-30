<?php

namespace Plugin\BlastengineMailer\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Annotation\EntityExtension;

/**
 * 会員に「宛先状態」（宛先不明＝エラー停止）を追加する。
 *
 * 受信可否（オプトイン／アウト。本人の意思）とは別の軸。宛先不明は技術的な事実で、
 * 通知メール・販促メールの両方に効く。判定元は blastengine のエラー停止一覧、blastmail のエラー停止、手動。
 *
 * メールアドレスを変えたら自動で無効になる（判定時のアドレスを一緒に持ち、現在のアドレスと違えば有効扱い）。
 *
 * @EntityExtension("Eccube\Entity\Customer")
 */
trait CustomerBounceTrait
{
    /**
     * 0=有効 / 1=宛先不明（エラー停止）
     *
     * @ORM\Column(name="mail_bounce_status", type="smallint", options={"default":0})
     */
    private $mailBounceStatus = 0;

    /**
     * 判定したときのメールアドレス（現在のアドレスと違えば判定は無効）
     *
     * @ORM\Column(name="mail_bounce_email", type="string", length=255, nullable=true)
     */
    private $mailBounceEmail;

    /** @ORM\Column(name="mail_bounce_at", type="datetimetz", nullable=true) */
    private $mailBounceAt;

    /**
     * blastengine / blastmail / manual
     *
     * @ORM\Column(name="mail_bounce_source", type="string", length=32, nullable=true)
     */
    private $mailBounceSource;

    /** @ORM\Column(name="mail_bounce_message", type="string", length=255, nullable=true) */
    private $mailBounceMessage;

    public function getMailBounceStatus(): int
    {
        return (int) $this->mailBounceStatus;
    }

    public function setMailBounceStatus(?int $v): self
    {
        $this->mailBounceStatus = (int) $v;

        return $this;
    }

    public function getMailBounceEmail(): ?string
    {
        return $this->mailBounceEmail;
    }

    public function getMailBounceAt(): ?\DateTimeInterface
    {
        return $this->mailBounceAt;
    }

    public function getMailBounceSource(): ?string
    {
        return $this->mailBounceSource;
    }

    public function getMailBounceMessage(): ?string
    {
        return $this->mailBounceMessage;
    }

    /** 現在のメールアドレスが宛先不明と判定されているか */
    public function isMailBounced(): bool
    {
        return (int) $this->mailBounceStatus === 1
            && $this->mailBounceEmail !== null
            && strcasecmp((string) $this->mailBounceEmail, (string) $this->getEmail()) === 0;
    }

    public function markMailBounced(string $source, ?string $message = null, ?\DateTimeInterface $at = null): self
    {
        $this->mailBounceStatus = 1;
        $this->mailBounceEmail = $this->getEmail();
        $this->mailBounceAt = $at ?? new \DateTime();
        $this->mailBounceSource = $source;
        $this->mailBounceMessage = $message === null ? null : mb_substr($message, 0, 255);

        return $this;
    }

    public function clearMailBounce(string $source = 'manual'): self
    {
        $this->mailBounceStatus = 0;
        $this->mailBounceEmail = null;
        $this->mailBounceAt = new \DateTime();
        $this->mailBounceSource = $source;
        $this->mailBounceMessage = null;

        return $this;
    }
}
