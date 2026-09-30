<?php

namespace Plugin\BlastengineMailer;

use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Plugin\BlastengineMailer\Service\BlastengineTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mime\Email;

/**
 * EC-CUBE がメールを組み立てた直後のイベントで、注文 ID・会員 ID・メール種別を内部ヘッダとして付ける。
 * Transport はこれを読んで送信ログに残し、送信前に外す。
 */
class Event implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        $events = [
            EccubeEvents::MAIL_ORDER, EccubeEvents::MAIL_ADMIN_ORDER,
            EccubeEvents::MAIL_CUSTOMER_CONFIRM, EccubeEvents::MAIL_CUSTOMER_COMPLETE, EccubeEvents::MAIL_CUSTOMER_WITHDRAW,
            EccubeEvents::MAIL_ADMIN_CUSTOMER_CONFIRM, EccubeEvents::MAIL_CONTACT,
            EccubeEvents::MAIL_PASSWORD_RESET, EccubeEvents::MAIL_PASSWORD_RESET_COMPLETE,
        ];

        return array_fill_keys($events, 'onMail');
    }

    public function onMail(EventArgs $event, string $eventName): void
    {
        $message = $event->hasArgument('message') ? $event->getArgument('message') : null;
        if (!$message instanceof Email) {
            return;
        }
        $h = $message->getHeaders();
        $h->addTextHeader(BlastengineTransport::HEADER_KIND, $eventName);
        if ($event->hasArgument('Order') && ($Order = $event->getArgument('Order')) && method_exists($Order, 'getId')) {
            $h->addTextHeader(BlastengineTransport::HEADER_ORDER, (string) $Order->getId());
            if (method_exists($Order, 'getCustomer') && $Order->getCustomer()) {
                $h->addTextHeader(BlastengineTransport::HEADER_CUSTOMER, (string) $Order->getCustomer()->getId());
            }
        } elseif ($event->hasArgument('Customer') && ($Customer = $event->getArgument('Customer')) && method_exists($Customer, 'getId') && $Customer->getId()) {
            $h->addTextHeader(BlastengineTransport::HEADER_CUSTOMER, (string) $Customer->getId());
        }
    }
}
