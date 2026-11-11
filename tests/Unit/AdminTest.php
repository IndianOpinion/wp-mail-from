<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests\Unit;

use IndianOpinion\MailFrom\Admin\Notices;
use IndianOpinion\MailFrom\Plugin;
use IndianOpinion\MailFrom\SiteHealth;
use IndianOpinion\MailFrom\Tests\TestCase;

final class AdminTest extends TestCase
{
    private function notices(): Notices
    {
        return new Notices($this->plugin()->report);
    }

    /**
     * @return list<string>
     */
    private function keys(Notices $notices): array
    {
        return array_column($notices->notices(), 'key');
    }

    /** Decision 4: a derived no-reply address shows a warning recommending a real mailbox. */
    public function testNoReplyNotice(): void
    {
        \WP::$options[1]['admin_email'] = 'owner@gmail.example';
        $notices = $this->notices()->notices();
        $derived = array_values(array_filter($notices, static fn (array $n): bool => $n['key'] === 'derived'))[0];
        $this->assertSame('warning', $derived['type']);
        $this->assertStringContainsString('no-reply@example.org', $derived['message']);
        $this->assertStringContainsString('real, read mailbox', $derived['message']);
    }

    /** C40, C41, C46, C49, C51: each situation has its notice. */
    public function testNoticeKinds(): void
    {
        $this->constants['WPMF_FROM'] = 'bad';
        $this->definedForDetector = ['WPMS_PLUGIN_VER'];
        Plugin::noteDuplicate('/srv/plugins/wp-mail-from/wp-mail-from.php');
        $keys = $this->keys($this->notices());
        $this->assertSame(['invalid', 'derived', 'smtp', 'wp-mail', 'duplicate'], $keys);

        $this->setUp();
        \WP::$homeUrls[1] = 'http://localhost';
        \WP::$options[1]['admin_email'] = 'a@localhost';
        $this->assertContains('no-identity', $this->keys($this->notices()));

        $this->setUp();
        $this->constants['WPMF_FROM'] = 'mail@example.org';
        $this->assertSame(['wp-mail'], $this->keys($this->notices()));
    }

    /** Notices only for users who can manage options; dismissal is per user and nonce-checked. */
    public function testRenderAndDismiss(): void
    {
        \WP::$options[1]['admin_email'] = 'owner@gmail.example';
        $notices = $this->notices();

        \WP::$caps['manage_options'] = false;
        ob_start();
        $notices->render();
        $this->assertSame('', ob_get_clean());

        \WP::$caps['manage_options'] = true;
        ob_start();
        $notices->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('wpmf_dismiss=derived', $html);
        $this->assertStringContainsString('_wpnonce=nonce-wpmf_dismiss_derived', $html);

        $_GET['wpmf_dismiss'] = 'derived';
        $this->assertSame('/wp-admin/options-general.php', $notices->handleDismiss());
        ob_start();
        $notices->render();
        $html = (string) ob_get_clean();
        $this->assertStringNotContainsString('wpmf_dismiss=derived', $html);

        // The notice comes back when the situation changes.
        update_option('admin_email', 'owner@other.example');
        \WP::$blogNames[1] = 'Renamed';
        update_option('home', 'https://example.com');
        \WP::$homeUrls[1] = 'https://example.com';
        ob_start();
        $notices->render();
        $this->assertStringContainsString('wpmf_dismiss=derived', (string) ob_get_clean());

        $_GET['wpmf_dismiss'] = 'unknown';
        $this->assertNull($notices->handleDismiss());

        $_GET['wpmf_dismiss'] = 'derived';
        \WP::$nonceValid = false;
        $this->expectException(\RuntimeException::class);
        $notices->handleDismiss();
    }

    /** Site Health: good, recommended and critical. */
    public function testSiteHealth(): void
    {
        $this->constants['WPMF_FROM'] = 'mail@example.org';
        $health = new SiteHealth($this->plugin()->report);
        $tests = $health->register(['direct' => []]);
        $this->assertIsArray($tests);
        $this->assertArrayHasKey(SiteHealth::TEST, $tests['direct']);
        $result = $health->run();
        $this->assertSame('good', $result['status']);
        $this->assertStringContainsString('mail@example.org', $result['label']);

        $this->setUp();
        \WP::$options[1]['admin_email'] = 'owner@gmail.example';
        $result = (new SiteHealth($this->plugin()->report))->run();
        $this->assertSame('recommended', $result['status']);
        $this->assertStringContainsString('no-reply', $result['description']);

        $this->setUp();
        \WP::$homeUrls[1] = 'http://localhost';
        \WP::$options[1]['admin_email'] = 'a@localhost';
        $this->assertSame('critical', (new SiteHealth($this->plugin()->report))->run()['status']);
    }
}
