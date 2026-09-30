<?php

namespace Plugin\BlastengineMailer;

use Eccube\Plugin\AbstractPluginManager;
use Plugin\BlastengineMailer\Entity\Config;
use Psr\Container\ContainerInterface;

class PluginManager extends AbstractPluginManager
{
    /** 有効化時に設定行（id=1、mode=none）を用意する。有効化しただけでは送信経路は変わらない。 */
    public function enable(array $meta, ContainerInterface $container)
    {
        $em = $container->get('doctrine')->getManager();
        if ($em->getRepository(Config::class)->find(1) === null) {
            $em->persist(new Config());
            $em->flush();
        }
    }
}
