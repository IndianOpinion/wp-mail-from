<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests\Unit;

use IndianOpinion\MailFrom\Admin\NetworkSettings;
use IndianOpinion\MailFrom\Options;
use IndianOpinion\MailFrom\Plugin;
use IndianOpinion\MailFrom\Tests\TestCase;

final class UninstallTest extends TestCase
{
    /** T67 (section 6): uninstall removes options and notice meta on every site. */
    public function testUninstallRemovesEverything(): void
    {
        \WP::$multisite = true;
        \WP::addSite(2, 'https://shop.example.org', 'Shop', 'a@shop.example.org');
        \WP::$options[1][Options::SITE_OPTION] = ['from' => 'a@example.org'];
        \WP::$options[2][Options::SITE_OPTION] = ['from' => 'b@shop.example.org'];
        \WP::$options[2]['blogname'] = 'kept';
        \WP::$siteOptions[Options::NETWORK_OPTION] = ['from' => 'n@example.org'];
        \WP::$userMeta[1] = ['wpmf_dismissed_derived' => 'x', NetworkSettings::ERRORS_META => ['e'], 'other' => 1];
        \WP::$userMeta[7] = ['wpmf_dismissed_smtp' => 'y'];

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'wp-mail-from/wp-mail-from.php');
        }
        include dirname(__DIR__, 2) . '/uninstall.php';

        $this->assertArrayNotHasKey(Options::SITE_OPTION, \WP::$options[1]);
        $this->assertArrayNotHasKey(Options::SITE_OPTION, \WP::$options[2]);
        $this->assertSame('kept', \WP::$options[2]['blogname']);
        $this->assertArrayNotHasKey(Options::NETWORK_OPTION, \WP::$siteOptions);
        $this->assertSame(['other' => 1], \WP::$userMeta[1]);
        $this->assertSame([], \WP::$userMeta[7]);
        $this->assertSame(1, \WP::$blogId);
    }

    /** Uninstalling the normal copy keeps settings a must-use copy still uses. */
    public function testUninstallSkippedWhenMustUseCopyActive(): void
    {
        Plugin::boot('/srv/wp-content/mu-plugins/wp-mail-from/wp-mail-from.php');
        \WP::$options[1][Options::SITE_OPTION] = ['from' => 'a@example.org'];
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'wp-mail-from/wp-mail-from.php');
        }
        include dirname(__DIR__, 2) . '/uninstall.php';
        $this->assertArrayHasKey(Options::SITE_OPTION, \WP::$options[1]);
    }
}
