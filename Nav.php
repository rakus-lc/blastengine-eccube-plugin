<?php

namespace Plugin\BlastengineMailer;

use Eccube\Common\EccubeNav;

class Nav implements EccubeNav
{
    public static function getNav(): array
    {
        return [
            'setting' => [
                'children' => [
                    'blastengine_mailer' => [
                        'name' => 'blastengine_mailer.admin.nav.config',
                        'url' => 'blastengine_mailer_admin_config',
                    ],
                    'blastengine_mailer_log' => [
                        'name' => 'blastengine_mailer.admin.nav.log',
                        'url' => 'blastengine_mailer_admin_log',
                    ],
                    'blastengine_mailer_bounce' => [
                        'name' => 'blastengine_mailer.admin.nav.bounce',
                        'url' => 'blastengine_mailer_admin_bounce',
                    ],
                ],
            ],
        ];
    }
}
