<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests\Unit;

use IndianOpinion\MailFrom\Admin\GeneralSettings;
use IndianOpinion\MailFrom\Admin\NetworkSettings;
use IndianOpinion\MailFrom\Options;
use IndianOpinion\MailFrom\Settings;
use IndianOpinion\MailFrom\Tests\TestCase;
use IndianOpinion\MailFrom\Validator;

final class SettingsTest extends TestCase
{
    private function settings(bool $cli = false): Settings
    {
        return new Settings($this->options(), new Validator(false), $cli);
    }

    private function general(): GeneralSettings
    {
        $plugin = $this->plugin();

        return new GeneralSettings($this->options(), $this->settings(), $plugin->resolver);
    }

    private function render(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }

    /** Settings, General gets exactly two fields and the option is registered. */
    public function testRegistersTwoFields(): void
    {
        $this->general()->register();
        $this->assertSame(['wpmf_from', 'wpmf_from_name'], array_keys(\WP::$settingsFields));
        $this->assertSame('general', \WP::$settingsFields['wpmf_from']['page']);
        $this->assertSame('general', \WP::$registeredSettings[Options::SITE_OPTION]['group']);
    }

    /** T7 (C7): a field is read-only when its constant is set; the option is kept but ignored. */
    public function testReadOnlyWhenConstantSet(): void
    {
        $this->constants['WPMF_FROM'] = 'const@example.org';
        $this->siteOption(['from' => 'stored@example.org']);
        $general = $this->general();

        $html = $this->render([$general, 'renderFrom']);
        $this->assertStringContainsString('readonly="readonly"', $html);
        $this->assertStringContainsString('Set in wp-config.php.', $html);
        $this->assertStringContainsString('value="const@example.org"', $html);
        $this->assertStringNotContainsString('name="wpmf_settings[from]"', $html);

        $html = $this->render([$general, 'renderFromName']);
        $this->assertStringContainsString('name="wpmf_settings[from_name]"', $html);

        $saved = $this->settings()->sanitizeSiteOption(['from' => 'new@example.org', 'from_name' => 'New']);
        $this->assertSame('stored@example.org', $saved['from'], 'stored option is kept');
        $this->assertSame('New', $saved['from_name']);
    }

    /** Output on the settings screen is escaped. */
    public function testOutputEscaped(): void
    {
        $this->siteOption(['from_name' => '"><script>x</script>']);
        $html = $this->render([$this->general(), 'renderFromName']);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /** T44 (C43): invalid settings input is not saved and the reason is shown. */
    public function testInvalidInputNotSaved(): void
    {
        $this->siteOption(['from' => 'old@example.org']);
        $saved = $this->settings()->sanitizeSiteOption([
            'from' => 'root@localhost',
            'from_name' => "Name\r\nBcc: x",
            'explicit_mode' => 'sideways',
        ]);
        $this->assertSame('old@example.org', $saved['from']);
        $this->assertSame('Name Bcc: x', $saved['from_name']);
        $this->assertArrayNotHasKey('explicit_mode', $saved);
        $codes = array_column(\WP::$settingsErrors, 'code');
        $this->assertSame(['wpmf_from', 'wpmf_explicit_mode'], $codes);
        $this->assertStringContainsString('domain-without-dot', \WP::$settingsErrors[0]['message']);
    }

    /** T44 (C43): through update_option, as options.php does it. */
    public function testThroughUpdateOption(): void
    {
        $this->general()->register();
        update_option(Options::SITE_OPTION, ['from' => 'Valid@Example.org', 'from_name' => 'Ok']);
        $this->assertSame(['from' => 'Valid@example.org', 'from_name' => 'Ok'], get_option(Options::SITE_OPTION));
        update_option(Options::SITE_OPTION, ['from' => "a@example.org\nBcc: b@example.net"]);
        $this->assertSame('Valid@example.org', get_option(Options::SITE_OPTION)['from']);
        $this->assertSame('', $this->settings()->sanitizeSiteOption(['from' => ''])['from'], 'empty clears');
    }

    /** T45 (C44): a site admin cannot save a foreign domain; a super admin can. */
    public function testMultisiteDomainRule(): void
    {
        \WP::$multisite = true;
        \WP::addSite(2, 'https://shop.example.org', 'Shop', 'a@shop.example.org');
        switch_to_blog(2);

        $saved = $this->settings()->sanitizeSiteOption(['from' => 'ceo@example.org']);
        $this->assertArrayNotHasKey('from', $saved, 'parent domain refused for a subsite admin');
        $this->assertSame('wpmf_from', \WP::$settingsErrors[0]['code']);

        $saved = $this->settings()->sanitizeSiteOption(['from' => 'orders@mail.shop.example.org']);
        $this->assertSame('orders@mail.shop.example.org', $saved['from']);

        $this->networkOption(['allowed_domains' => ['partner.example']]);
        $this->assertSame('x@partner.example', $this->settings()->sanitizeSiteOption(['from' => 'x@partner.example'])['from']);

        add_filter('wpmf_allowed_domains', static fn (array $d): array => [...$d, 'filtered.example']);
        $this->assertSame('x@filtered.example', $this->settings()->sanitizeSiteOption(['reply_to' => 'x@filtered.example'])['reply_to']);

        \WP::$superAdmin = true;
        $this->assertSame('ceo@example.org', $this->settings()->sanitizeSiteOption(['from' => 'ceo@example.org'])['from']);
        \WP::$superAdmin = false;
        $this->assertSame('ceo@example.org', $this->settings(true)->sanitizeSiteOption(['from' => 'ceo@example.org'])['from'], 'WP-CLI is trusted');
        restore_current_blog();
    }

    /** T55 (C54): locked network fields are read-only on the site screen and not saved. */
    public function testLockedFieldsReadOnly(): void
    {
        \WP::$multisite = true;
        $this->networkOption(['from' => 'network@example.org', 'locked' => ['from']]);
        $general = $this->general();
        $html = $this->render([$general, 'renderFrom']);
        $this->assertStringContainsString('Locked by the network administrator.', $html);
        $this->assertStringContainsString('value="network@example.org"', $html);
        $this->assertStringContainsString('readonly', $html);
        $this->assertArrayNotHasKey('from', $this->settings()->sanitizeSiteOption(['from' => 'site@example.org']));
        $this->assertStringNotContainsString('readonly', $this->render([$general, 'renderFromName']));
    }

    /** Network settings: save validates, locks, allowed domains, capability and nonce. */
    public function testNetworkSave(): void
    {
        \WP::$multisite = true;
        $network = new NetworkSettings($this->options(), $this->settings());
        $network->save([
            'from' => 'network@example.org',
            'reply_to' => 'not valid',
            'explicit_mode' => 'align',
            'locked' => ['from', 'bogus'],
            'allowed_domains' => "partner.example\n, bad_domain",
            'locked_present' => '1',
        ]);
        $stored = \WP::$siteOptions[Options::NETWORK_OPTION];
        $this->assertSame('network@example.org', $stored['from']);
        $this->assertArrayNotHasKey('reply_to', $stored);
        $this->assertSame('align', $stored['explicit_mode']);
        $this->assertSame(['from'], $stored['locked']);
        $this->assertSame(['partner.example'], $stored['allowed_domains']);
        $this->assertCount(2, \WP::$userMeta[1][NetworkSettings::ERRORS_META]);

        ob_start();
        $network->renderErrors();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('not a usable address', $html);
        $this->assertArrayNotHasKey(NetworkSettings::ERRORS_META, \WP::$userMeta[1]);

        $network->save(['locked_present' => '1']);
        $this->assertSame([], \WP::$siteOptions[Options::NETWORK_OPTION]['locked'], 'unticking every lock clears them');

        \WP::$caps['manage_network_options'] = false;
        $network->save(['from' => 'other@example.org']);
        $this->assertSame('network@example.org', \WP::$siteOptions[Options::NETWORK_OPTION]['from']);

        \WP::$caps['manage_network_options'] = true;
        \WP::$nonceValid = false;
        $this->expectException(\RuntimeException::class);
        $network->save(['from' => 'other@example.org']);
    }

    /** Network screen renders escaped values. */
    public function testNetworkRender(): void
    {
        \WP::$multisite = true;
        $this->networkOption(['from' => 'n@example.org', 'locked' => ['from'], 'allowed_domains' => ['a.example']]);
        ob_start();
        (new NetworkSettings($this->options(), $this->settings()))->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="wpmf_network[from]" value="n@example.org"', $html);
        $this->assertStringContainsString('value="from" checked="checked"', $html);
        $this->assertStringContainsString('a.example</textarea>', $html);
    }

    /** CLI-side field validation covers every field. */
    public function testSanitizeField(): void
    {
        $s = $this->settings();
        $this->assertTrue($s->sanitizeField('dry_run', 'on')['ok']);
        $this->assertTrue($s->sanitizeField('dry_run', 'on')['value']);
        $this->assertFalse($s->sanitizeField('dry_run', 'maybe')['ok']);
        $this->assertSame('always', $s->sanitizeField('sender_mode', 'Always')['value']);
        $this->assertFalse($s->sanitizeField('nope', 'x')['ok']);
    }
}
