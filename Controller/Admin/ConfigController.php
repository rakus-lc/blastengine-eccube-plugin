<?php

namespace Plugin\BlastengineMailer\Controller\Admin;

use Eccube\Controller\AbstractController;
use Eccube\Repository\BaseInfoRepository;
use Plugin\BlastengineMailer\Entity\Config;
use Plugin\BlastengineMailer\Form\Type\Admin\ConfigType;
use Plugin\BlastengineMailer\Repository\ConfigRepository;
use Plugin\BlastengineMailer\Service\BlastengineApiClient;
use Plugin\BlastengineMailer\Service\BlastengineTransport;
use Plugin\BlastengineMailer\Service\SecretCrypter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;

class ConfigController extends AbstractController
{
    public function __construct(
        private ConfigRepository $configRepository,
        private SecretCrypter $crypter,
        private BlastengineApiClient $api,
        private BlastengineTransport $transport,
        private MailerInterface $mailer,
        private BaseInfoRepository $baseInfoRepository,
    ) {
    }

    /**
     * @Route("/%eccube_admin_route%/blastengine_mailer/config", name="blastengine_mailer_admin_config", methods={"GET", "POST"})
     * @Template("@BlastengineMailer/admin/config.twig")
     */
    public function index(Request $request)
    {
        $Config = $this->configRepository->get();
        $form = $this->createForm(ConfigType::class, $Config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // 秘密値は空なら既存値を保持。平文で残っている旧値は暗号化へ移行
            foreach (['smtpPassword' => ['getSmtpPassword', 'setSmtpPassword'], 'apiKey' => ['getApiKey', 'setApiKey']] as $field => [$getter, $setter]) {
                $input = (string) $form->get($field)->getData();
                if ($input !== '') {
                    $Config->$setter($this->crypter->encrypt($input));
                } elseif ($Config->$getter() && !$this->crypter->isEncrypted($Config->$getter())) {
                    $Config->$setter($this->crypter->encrypt($Config->$getter()));
                }
            }
            $this->entityManager->persist($Config);
            $this->entityManager->flush();
            $this->addSuccess('登録しました。', 'admin');

            if ($request->request->has('test_connection')) {
                $this->runConnectionTest($Config);
            }
            if ($request->request->has('test_mail')) {
                $this->sendTestMail($Config);
            }

            return $this->redirectToRoute('blastengine_mailer_admin_config');
        }
        if ($form->isSubmitted() && !$form->isValid()) {
            $this->addError('入力内容に誤りがあります。赤字の項目を確認してください。', 'admin');
        }

        return [
            'form' => $form->createView(),
            'Config' => $Config,
            'secretWeak' => $this->crypter->isServerSecretWeak(),
            'shopEmail' => $this->baseInfoRepository->get()->getEmail01(),
            'currentDsnInfo' => $this->describeInnerTransport(),
        ];
    }

    private function runConnectionTest(Config $Config): void
    {
        try {
            if ($Config->getMode() === Config::MODE_SMTP) {
                $this->transport->testSmtp($Config);
                $this->addSuccess(sprintf('SMTP 接続に成功しました（%s:%d）。', $Config->getSmtpHost(), $Config->getSmtpPort()), 'admin');
            } elseif ($Config->getMode() === Config::MODE_API) {
                $usage = $this->api->usageLatest($Config);
                $this->addSuccess('API 接続に成功しました。使用量: '.json_encode($usage, JSON_UNESCAPED_UNICODE), 'admin');
            } else {
                $this->addError('接続方式が「使わない」のため接続テストはありません。', 'admin');
            }
        } catch (\Throwable $e) {
            $this->addError('接続に失敗しました: '.$e->getMessage(), 'admin');
        }
    }

    private function sendTestMail(Config $Config): void
    {
        $BaseInfo = $this->baseInfoRepository->get();
        $to = (string) $BaseInfo->getEmail01();
        if ($to === '') {
            $this->addError('店舗設定のメールアドレス（送信元）が未設定のためテスト送信できません。', 'admin');

            return;
        }
        try {
            $email = (new Email())
                ->from(new Address($to, (string) $BaseInfo->getShopName()))
                ->to($to)
                ->subject('[EC-CUBE] blastengine 連携のテスト送信')
                ->text(sprintf("blastengine 連携プラグインからのテスト送信です。\n接続方式: %s\n送信日時: %s\n", $Config->getMode(), date('Y-m-d H:i:s')));
            $email->getHeaders()->addTextHeader(BlastengineTransport::HEADER_KIND, 'blastengine_mailer.test');
            $this->mailer->send($email);
            $this->addSuccess(sprintf('テストメールを %s に送りました。結果は送信ログで確認してください。', $to), 'admin');
        } catch (\Throwable $e) {
            $this->addError('テスト送信に失敗しました: '.$e->getMessage(), 'admin');
        }
    }

    /** 「使わない」のときに使われる元の送信経路の説明（秘密値は出さない） */
    private function describeInnerTransport(): string
    {
        $dsn = $_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: '';
        if (!is_string($dsn) || $dsn === '') {
            return '（MAILER_DSN 未設定）';
        }

        return preg_replace('#//([^:@/]+)(:[^@/]*)?@#', '//$1:***@', $dsn);
    }
}
