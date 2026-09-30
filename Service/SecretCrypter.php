<?php

namespace Plugin\BlastengineMailer\Service;

use Eccube\Common\EccubeConfig;

/**
 * SMTP パスワード・API キーの暗号化保存。
 *
 * 鍵はサーバー側の秘密値 ECCUBE_AUTH_MAGIC（EC-CUBE では Symfony の kernel.secret に割り当てられている）
 * から HKDF-SHA256 で派生させる。DB ダンプだけが流出しても復号できない。
 * 形式: "enc1:" + base64(nonce + ciphertext)。libsodium secretbox。接頭辞の無い値は旧平文として扱う。
 * blastmail 連携プラグインと同じ方式だが、用途文字列を分けて鍵を共有しない。
 */
class SecretCrypter
{
    private const PREFIX = 'enc1:';
    private const CONTEXT = 'BlastengineMailer/credentials/v1';

    public function __construct(private EccubeConfig $eccubeConfig)
    {
    }

    public function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /** @throws \RuntimeException 復号に失敗した場合（鍵の変更・データ破損） */
    public function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('保存された認証情報を読めません（データ破損）。設定画面で再入力してください。');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key()
        );
        if ($plain === false) {
            throw new \RuntimeException('保存された認証情報を復号できません。ECCUBE_AUTH_MAGIC が変更された可能性があります。設定画面で再入力してください。');
        }

        return $plain;
    }

    public function isEncrypted(?string $stored): bool
    {
        return $stored !== null && str_starts_with($stored, self::PREFIX);
    }

    public function isServerSecretWeak(): bool
    {
        $magic = (string) $this->eccubeConfig->get('eccube_auth_magic');

        return $magic === '' || $magic === '<change.me>' || strlen($magic) < 16;
    }

    private function key(): string
    {
        $magic = (string) $this->eccubeConfig->get('eccube_auth_magic');
        if ($magic === '') {
            throw new \RuntimeException('ECCUBE_AUTH_MAGIC が設定されていないため認証情報を暗号化できません。');
        }

        return hash_hkdf('sha256', $magic, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::CONTEXT);
    }
}
