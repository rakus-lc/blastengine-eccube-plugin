<?php

namespace Plugin\BlastengineMailer\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\BlastengineMailer\Entity\Config;

class ConfigRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Config::class);
    }

    /** 設定行を返す。無ければ既定値で作成する。 */
    public function get(): Config
    {
        $Config = $this->find(1);
        if ($Config === null) {
            $Config = new Config();
            $em = $this->getEntityManager();
            $em->persist($Config);
            $em->flush();
        }

        return $Config;
    }
}
