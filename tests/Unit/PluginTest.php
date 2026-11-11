<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests\Unit;

use IndianOpinion\MailFrom\MailHandler;
use IndianOpinion\MailFrom\Plugin;
use IndianOpinion\MailFrom\Tests\TestCase;

final class PluginTest extends TestCase
{
    /** T63 (C62, C63, C64): hooks at the documented priorities. */
    public function testPriorities(): void
    {
        $handler = $this->plugin()->handler;
        $this->assertSame(1000, has_filter('wp_mail', [$handler, 'onWpMail']));
        $this->assertSame(PHP_INT_MAX, has_filter('pre_wp_mail', [$handler, 'onPreWpMail']));
        $this->assertSame(5, has_filter('wp_mail_from', [$handler, 'filterFrom']));
        $this->assertSame(5, has_filter('wp_mail_from_name', [$handler, 'filterFromName']));
        $this->assertSame(999, has_filter('phpmailer_init', [$handler, 'onPhpMailerInit']));
        $this->assertSame(10, has_filter('wp_mail_succeeded', [$handler, 'onSucceeded']));
        $this->assertSame(10, has_filter('wp_mail_failed', [$handler, 'onFailed']));
        $this->assertSame(MailHandler::PRIORITY_FROM, 5);
    }

    /** T63 (C63): the priority constant is honoured. */
    public function testPriorityConstant(): void
    {
        $this->constants['WPMF_FROM_PRIORITY'] = 50;
        $handler = $this->plugin()->handler;
        $this->assertSame(50, has_filter('wp_mail_from', [$handler, 'filterFrom']));

        $this->setUp();
        $this->constants['WPMF_FROM_PRIORITY'] = 'late';
        $handler = $this->plugin()->handler;
        $this->assertSame(5, has_filter('wp_mail_from', [$handler, 'filterFrom']), 'a non-integer is ignored');
    }

    /** T52 (C51): a second copy registers no hooks and is reported. */
    public function testSecondCopy(): void
    {
        $first = Plugin::boot('/srv/wp-content/mu-plugins/wp-mail-from/wp-mail-from.php');
        $this->assertNotNull($first);
        $before = \WP::$hooks;

        $this->assertNull(Plugin::boot('/srv/wp-content/plugins/wp-mail-from/wp-mail-from.php'));
        require dirname(__DIR__, 2) . '/wp-mail-from.php';

        $this->assertSame($before, \WP::$hooks);
        $this->assertSame(
            ['/srv/wp-content/plugins/wp-mail-from/wp-mail-from.php', realpath(dirname(__DIR__, 2) . '/wp-mail-from.php')],
            Plugin::duplicates()
        );
        $this->assertSame('/srv/wp-content/mu-plugins/wp-mail-from/wp-mail-from.php', Plugin::activeFile());
    }

    /** Loading registers hooks only: no option reads (section 9). */
    public function testLoadReadsNoOptions(): void
    {
        \WP::$options = [];
        \WP::$isAdmin = true;
        $this->plugin();
        $this->assertSame([], \WP::$options);
        $this->assertArrayHasKey('mail-from', \WP_CLI::$commands);
        $this->assertNotFalse(has_filter('site_status_tests'));
        $this->assertNotFalse(has_filter('admin_notices'));
        $this->assertNotFalse(has_filter('wpmu_options'));
    }
}
