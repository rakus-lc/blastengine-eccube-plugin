<?php

namespace Plugin\BlastengineMailer\Service;

class BlastengineApiException extends \RuntimeException
{
    public static function fromResponse(int $status, string $body): self
    {
        $msg = trim($body);
        $json = json_decode($body, true);
        if (is_array($json)) {
            // blastengine のエラー形式は { error_messages: {field: [msg]} } など。読める形に潰す
            $msg = json_encode($json['error_messages'] ?? $json['message'] ?? $json, JSON_UNESCAPED_UNICODE);
        }

        return new self(sprintf('blastengine API エラー (HTTP %d): %s', $status, mb_substr($msg, 0, 300)), $status);
    }
}
