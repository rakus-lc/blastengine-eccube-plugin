<?php

namespace Plugin\BlastengineMailer\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\BlastengineMailer\Entity\MailLog;

class MailLogRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailLog::class);
    }

    /** @return MailLog[] */
    public function findRecent(int $limit = 50): array
    {
        return $this->findBy([], ['id' => 'DESC'], $limit);
    }

    /** @return MailLog[] */
    public function findByOrderId(int $orderId): array
    {
        return $this->findBy(['orderId' => $orderId], ['id' => 'DESC']);
    }
}
