<?php

namespace Plugin\BlastengineMailer\Form\Type\Admin;

use Plugin\BlastengineMailer\Entity\Config;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Url;

class ConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mode', ChoiceType::class, [
                'choices' => [
                    '使わない（.env の MAILER_DSN で送る）' => Config::MODE_NONE,
                    'SMTP リレー（blastengine の SMTP サーバーに中継）' => Config::MODE_SMTP,
                    'API（blastengine のトランザクション API で送る。配信 ID が残る）' => Config::MODE_API,
                ],
                'expanded' => true,
            ])
            // SMTP
            ->add('smtpHost', TextType::class, ['required' => false, 'constraints' => [new Length(['max' => 255])]])
            ->add('smtpPort', IntegerType::class, ['required' => false, 'constraints' => [new Range(['min' => 1, 'max' => 65535])]])
            ->add('smtpUsername', TextType::class, ['required' => false, 'constraints' => [new Length(['max' => 255])]])
            ->add('smtpPassword', PasswordType::class, ['mapped' => false, 'required' => false, 'always_empty' => true])
            ->add('smtpEncryption', ChoiceType::class, [
                'choices' => [
                    '自動（587 は STARTTLS、465 は TLS）' => Config::ENC_AUTO,
                    '接続時から TLS（smtps）' => Config::ENC_TLS,
                    '暗号化なし（ローカル検証用）' => Config::ENC_NONE,
                ],
            ])
            // API
            ->add('apiBaseUrl', TextType::class, ['constraints' => [new NotBlank(), new Url(), new Length(['max' => 255])]])
            ->add('apiLoginId', TextType::class, ['required' => false, 'constraints' => [new Length(['max' => 255])]])
            ->add('apiKey', PasswordType::class, ['mapped' => false, 'required' => false, 'always_empty' => true])
            // 共通
            ->add('fallbackOnError', CheckboxType::class, ['required' => false])
            ->add('logEnabled', CheckboxType::class, ['required' => false])
            // エラー停止連携
            ->add('bounceSyncEnabled', CheckboxType::class, ['required' => false])
            ->add('bounceSuppressSend', CheckboxType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Config::class]);
    }
}
