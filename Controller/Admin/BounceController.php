<?php

namespace Plugin\BlastengineMailer\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\BlastengineMailer\Repository\ConfigRepository;
use Plugin\BlastengineMailer\Service\BounceService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 宛先不明（エラー停止）の会員一覧。blastengine からの取得と手動解除。
 */
class BounceController extends AbstractController
{
    public function __construct(
        private ConfigRepository $configRepository,
        private BounceService $bounce,
    ) {
    }

    /**
     * @Route("/%eccube_admin_route%/blastengine_mailer/bounce", name="blastengine_mailer_admin_bounce", methods={"GET"})
     * @Template("@BlastengineMailer/admin/bounce.twig")
     */
    public function index(Request $request)
    {
        return [
            'Config' => $this->configRepository->get(),
            'supported' => $this->bounce->isSupported(),
            'rows' => $this->bounce->listBounced(200),
            'count' => $this->bounce->countBounced(),
        ];
    }

    /**
     * @Route("/%eccube_admin_route%/blastengine_mailer/bounce/fetch", name="blastengine_mailer_admin_bounce_fetch", methods={"POST"})
     */
    public function fetch(Request $request)
    {
        $this->isTokenValid();
        try {
            $r = $this->bounce->fetchFromBlastengine();
            $this->addSuccess(sprintf('blastengine のエラー停止を取得しました: %d 件（%s）。宛先不明として反映した会員: %d 名', $r['fetched'], $r['since'] ? $r['since'].' 以降' : '全件', $r['marked']), 'admin');
        } catch (\Throwable $e) {
            $this->addError('取得に失敗しました: '.$e->getMessage(), 'admin');
        }

        return $this->redirectToRoute('blastengine_mailer_admin_bounce');
    }

    /**
     * @Route("/%eccube_admin_route%/blastengine_mailer/bounce/{id}/clear", name="blastengine_mailer_admin_bounce_clear", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function clear(Request $request, int $id)
    {
        $this->isTokenValid();
        $this->bounce->clear($id, BounceService::SOURCE_MANUAL);
        $this->addSuccess(sprintf('会員 ID %d の宛先状態を「有効」に戻しました。blastmail 側のエラー停止は次回の同期で受信可否に応じた状態に戻ります。', $id), 'admin');

        return $this->redirectToRoute('blastengine_mailer_admin_bounce');
    }
}
