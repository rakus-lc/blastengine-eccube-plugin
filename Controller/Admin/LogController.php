<?php

namespace Plugin\BlastengineMailer\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\BlastengineMailer\Entity\MailLog;
use Plugin\BlastengineMailer\Repository\ConfigRepository;
use Plugin\BlastengineMailer\Repository\MailLogRepository;
use Plugin\BlastengineMailer\Service\BlastengineApiClient;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 送信ログ。API 方式で送ったものは blastengine 側の配信結果を照会できる。
 */
class LogController extends AbstractController
{
    public function __construct(
        private ConfigRepository $configRepository,
        private MailLogRepository $logRepository,
        private BlastengineApiClient $api,
    ) {
    }

    /**
     * @Route("/%eccube_admin_route%/blastengine_mailer/log", name="blastengine_mailer_admin_log", methods={"GET"})
     * @Template("@BlastengineMailer/admin/log.twig")
     */
    public function index(Request $request)
    {
        $orderId = $request->query->getInt('order_id');
        $logs = $orderId > 0 ? $this->logRepository->findByOrderId($orderId) : $this->logRepository->findRecent(50);

        return [
            'logs' => $logs,
            'orderId' => $orderId ?: null,
            'Config' => $this->configRepository->get(),
        ];
    }

    /**
     * blastengine 側の配信結果を照会してログに保存する。
     *
     * @Route("/%eccube_admin_route%/blastengine_mailer/log/{id}/refresh", name="blastengine_mailer_admin_log_refresh", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function refresh(Request $request, MailLog $log)
    {
        $this->isTokenValid();
        if ($log->getDeliveryId() === null) {
            $this->addError('この送信には blastengine の配信 ID がありません（SMTP 方式や退避送信）。', 'admin');

            return $this->redirectToRoute('blastengine_mailer_admin_log');
        }
        try {
            $Config = $this->configRepository->get();
            $result = $this->api->mailResults($Config, $log->getDeliveryId());
            $status = $this->summarize($log, $result);
            $log->setDeliveryStatus($status);
            $this->entityManager->flush();
            $this->addSuccess(sprintf('配信 ID %d の結果: %s', $log->getDeliveryId(), $status), 'admin');
        } catch (\Throwable $e) {
            $this->addError('配信結果の照会に失敗しました: '.$e->getMessage(), 'admin');
        }

        return $this->redirectToRoute('blastengine_mailer_admin_log');
    }

    /** /logs/mails/results の応答から状態を 1 行に潰す（形は 2026-09-29 に実機で確認） */
    private function summarize(MailLog $log, array $result): string
    {
        $items = $result['data'] ?? $result['results'] ?? $result;
        if (!is_array($items) || $items === []) {
            return '結果なし';
        }
        if (!is_array(reset($items))) {
            $items = [$items];
        }
        // EC-CUBE は店舗宛 BCC を同送するため、応答には宛先ごとの行が複数並ぶ。
        // BCC の行（SENT）が先頭に来て宛先本人のエラーが隠れることがあるので、to_email に一致する行を優先する
        $row = null;
        foreach ($items as $item) {
            if (is_array($item) && isset($item['email']) && strcasecmp((string) $item['email'], (string) $log->getToEmail()) === 0) {
                $row = $item;
                break;
            }
        }
        $row = $row ?? reset($items);
        $parts = [];
        // delivery_status 列は 64 文字なので、キー名を短くしてメッセージまで入るようにする
        foreach (['status' => 'status', 'last_response_code' => 'code', 'last_response_message' => 'msg', 'delivery_time' => 'time'] as $k => $label) {
            if (isset($row[$k]) && !is_array($row[$k])) {
                $parts[] = $label.'='.$row[$k];
            }
        }

        return $parts ? mb_substr(implode(' ', $parts), 0, 64) : mb_substr(json_encode($row, JSON_UNESCAPED_UNICODE), 0, 64);
    }
}
