<?php

namespace Plugin\BlastengineMailer\Form\Extension\Admin;

use Eccube\Entity\Customer;
use Eccube\Form\Type\Admin\CustomerType;
use Plugin\BlastengineMailer\Service\BounceService;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * 管理画面の会員編集に「メールの宛先状態」を追加する。
 * 「有効」に戻す＝手動解除。「宛先不明」にする＝手動で止める。判定元は manual として記録する。
 */
class CustomerTypeExtension extends AbstractTypeExtension
{
    private ?array $prev = null;

    public function __construct(private BounceService $bounce)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!method_exists(Customer::class, 'isMailBounced')) {
            return;
        }
        $builder->add('mailBounceStatus', ChoiceType::class, [
            'label' => 'メールの宛先状態',
            'choices' => ['有効' => 0, '宛先不明（エラー停止）。通知・販促とも送らない' => 1],
            'expanded' => true,
            'eccube_form_options' => ['auto_render' => true],
        ]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $Customer = $event->getForm()->getData();
            if ($Customer instanceof Customer) {
                $this->prev = [$Customer->getMailBounceStatus(), $Customer->isMailBounced()];
            }
        });
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $Customer = $event->getForm()->getData();
            if (!$Customer instanceof Customer || $this->prev === null) {
                return;
            }
            [$prevStatus, $prevEffective] = $this->prev;
            $now = $Customer->getMailBounceStatus();
            if ($now === 1 && !$prevEffective) {
                $Customer->markMailBounced(BounceService::SOURCE_MANUAL, '管理画面で手動設定');
            } elseif ($now === 0 && $prevStatus === 1) {
                $Customer->clearMailBounce(BounceService::SOURCE_MANUAL);
            }
        });
    }

    public static function getExtendedTypes(): iterable
    {
        return [CustomerType::class];
    }
}
