<?php

namespace Plugin\BlastengineMailer\Service;

use GuzzleHttp\Client as HttpClient;
use Plugin\BlastengineMailer\Entity\Config;

/**
 * blastengine 公開 API クライアント。
 *
 * 仕様の根拠: 公式 MCP サーバー（rakus-lc/blastengine-mcp）の実装（2026-09-07 確認）。
 *  - ベース URL: https://app.engn.jp/api/v1
 *  - 認証: Authorization: Bearer base64(lower(sha256(ログインID + APIキー)))
 *  - トランザクション送信: POST /deliveries/transaction
 *      { from: {email, name}, to, cc[], bcc[], reply_to: {email, name}, subject, text_part, html_part, encode }
 *      → { delivery_id }
 *  - 配信ログ: GET /logs/mails/results?delivery_id=...   配信一覧: GET /deliveries/{id}
 *  - 使用量: GET /usages/latest
 */
class BlastengineApiClient
{
    private ?HttpClient $http = null;

    public function __construct(private SecretCrypter $crypter)
    {
    }

    public static function bearerToken(string $loginId, string $apiKey): string
    {
        return base64_encode(strtolower(hash('sha256', $loginId.$apiKey)));
    }

    public function isConfigured(Config $Config): bool
    {
        return (string) $Config->getApiLoginId() !== '' && (string) $this->crypter->decrypt($Config->getApiKey()) !== '';
    }

    /** @return array{delivery_id?:int} */
    public function sendTransaction(Config $Config, array $body): array
    {
        return $this->request($Config, 'POST', '/deliveries/transaction', ['json' => $body]);
    }

    public function usageLatest(Config $Config): array
    {
        return $this->request($Config, 'GET', '/usages/latest');
    }

    public function delivery(Config $Config, int $deliveryId): array
    {
        return $this->request($Config, 'GET', '/deliveries/'.$deliveryId);
    }

    /**
     * エラー停止一覧（GET /errors）。size は 1〜1000、page は 1 始まり。
     * sort は error_time / updated_time の asc|desc。error_start / error_end は ISO 8601（+09:00）。
     */
    public function errors(Config $Config, array $query): array
    {
        return $this->request($Config, 'GET', '/errors', ['query' => $query]);
    }

    public function mailResults(Config $Config, int $deliveryId): array
    {
        return $this->request($Config, 'GET', '/logs/mails/results', ['query' => ['delivery_id' => $deliveryId, 'size' => 10]]);
    }

    private function request(Config $Config, string $method, string $path, array $options = []): array
    {
        if (!$this->isConfigured($Config)) {
            throw new BlastengineApiException('blastengine の API 認証情報（ログイン ID・API キー）が設定されていません。', 0);
        }
        $token = self::bearerToken((string) $Config->getApiLoginId(), (string) $this->crypter->decrypt($Config->getApiKey()));
        $options['headers'] = ($options['headers'] ?? []) + [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
        $res = $this->http($Config)->request($method, ltrim($path, '/'), $options);
        $status = $res->getStatusCode();
        $body = (string) $res->getBody();
        if ($status < 200 || $status >= 300) {
            throw BlastengineApiException::fromResponse($status, $body);
        }
        $json = json_decode($body, true);

        return is_array($json) ? $json : [];
    }

    private function http(Config $Config): HttpClient
    {
        // ベース URL が変わった場合に備えて毎回照合する
        if ($this->http === null || $this->http->getConfig('base_uri')->__toString() !== $Config->getApiBaseUrl().'/') {
            $this->http = new HttpClient([
                'base_uri' => $Config->getApiBaseUrl().'/',
                'timeout' => 30,
                'http_errors' => false,
            ]);
        }

        return $this->http;
    }
}
