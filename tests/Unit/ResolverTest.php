<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests\Unit;

use IndianOpinion\MailFrom\Tests\TestCase;

final class ResolverTest extends TestCase
{
    /** T1 (C1): constant beats site option beats derived, for the address. */
    public function testAddressPrecedence(): void
    {
        $plugin = $this->plugin();
        $this->assertSame('admin@example.org', $plugin->resolver->resolve()->from);
        $this->assertSame('derived', $plugin->resolver->resolve()->fromSource);

        $this->siteOption(['from' => 'site@example.org']);
        $plugin->resolver->flush();
        $this->assertSame('site@example.org', $plugin->resolver->resolve()->from);
        $this->assertSame('site-option', $plugin->resolver->resolve()->fromSource);

        $this->constants['WPMF_FROM'] = 'const@example.org';
        $plugin->resolver->flush();
        $this->assertSame('const@example.org', $plugin->resolver->resolve()->from);
        $this->assertSame('constant', $plugin->resolver->resolve()->fromSource);
    }

    /** T1 (C1): the filter result is validated again. */
    public function testAddressFilterIsValidated(): void
    {
        $plugin = $this->plugin();
        add_filter('wpmf_from_address', static fn (): string => "evil@example.org\r\nBcc: x@y.z");
        $identity = $plugin->resolver->resolve();
        $this->assertSame('admin@example.org', $identity->from);
        $this->assertSame('filter', $identity->warnings[0]['source']);

        remove_all_filters('wpmf_from_address');
        add_filter('wpmf_from_address', static fn (): string => 'Filtered@Example.ORG');
        $plugin->resolver->flush();
        $this->assertSame('Filtered@example.org', $plugin->resolver->resolve()->from);
        $this->assertSame('filter', $plugin->resolver->resolve()->fromSource);
    }

    /** T2 (C2): same order for the name. */
    public function testNamePrecedence(): void
    {
        $plugin = $this->plugin();
        $this->assertSame('Example Site', $plugin->resolver->resolve()->fromName);

        $this->siteOption(['from_name' => 'Site Name']);
        $plugin->resolver->flush();
        $this->assertSame('Site Name', $plugin->resolver->resolve()->fromName);

        $this->constants['WPMF_FROM_NAME'] = 'Constant Name';
        $plugin->resolver->flush();
        $this->assertSame('Constant Name', $plugin->resolver->resolve()->fromName);
        $this->assertSame('constant', $plugin->resolver->resolve()->fromNameSource);

        add_filter('wpmf_from_name', static fn (): string => "Filtered\nName");
        $plugin->resolver->flush();
        $this->assertSame('Filtered Name', $plugin->resolver->resolve()->fromName);
    }

    /** T3 (C3): constant address plus option name resolves both. */
    public function testMixedSources(): void
    {
        $this->constants['WPMF_FROM'] = 'const@example.org';
        $this->siteOption(['from_name' => 'Option Name']);
        $identity = $this->plugin()->resolver->resolve();
        $this->assertSame('const@example.org', $identity->from);
        $this->assertSame('constant', $identity->fromSource);
        $this->assertSame('Option Name', $identity->fromName);
        $this->assertSame('site-option', $identity->fromNameSource);
    }

    /** T4 (C4): derived address uses an aligned admin email, else no-reply. */
    public function testDerivedAddress(): void
    {
        \WP::$options[1]['admin_email'] = 'ops@mail.example.org';
        $plugin = $this->plugin();
        $identity = $plugin->resolver->resolve();
        $this->assertSame('ops@mail.example.org', $identity->from);
        $this->assertTrue($identity->isDerived());
        $this->assertFalse($identity->isNoReply());

        update_option('admin_email', 'someone@gmail.example');
        $identity = $plugin->resolver->resolve();
        $this->assertSame('no-reply@example.org', $identity->from);
        $this->assertTrue($identity->isNoReply());
    }

    /** T5 (C5): derived name decodes entities; an empty title gives the domain. */
    public function testDerivedName(): void
    {
        \WP::$blogNames[1] = 'Smith &amp; Sons &#8211; News';
        $plugin = $this->plugin();
        $this->assertSame("Smith & Sons \u{2013} News", $plugin->resolver->resolve()->fromName);

        \WP::$blogNames[1] = '';
        $plugin->resolver->flush();
        $this->assertSame('example.org', $plugin->resolver->resolve()->fromName);
    }

    /** T6 (C6): an empty constant falls through to the option. */
    public function testEmptyConstantFallsThrough(): void
    {
        $this->constants['WPMF_FROM'] = '';
        $this->constants['WPMF_FROM_NAME'] = '   ';
        $this->siteOption(['from' => 'site@example.org', 'from_name' => 'Site']);
        $identity = $this->plugin()->resolver->resolve();
        $this->assertSame('site@example.org', $identity->from);
        $this->assertSame('Site', $identity->fromName);
        $this->assertSame([], $identity->warnings);
    }

    /** T8 (C8): resolution is cached; an option update clears the cache. */
    public function testCacheAndFlush(): void
    {
        $plugin = $this->plugin();
        $first = $plugin->resolver->resolve();
        $this->assertSame($first, $plugin->resolver->resolve());

        \WP::$options[1]['wpmf_settings'] = ['from' => 'quiet@example.org'];
        $this->assertSame($first, $plugin->resolver->resolve(), 'a direct write does not flush');

        update_option('wpmf_settings', ['from' => 'new@example.org']);
        $this->assertSame('new@example.org', $plugin->resolver->resolve()->from);

        update_option('admin_email', 'other@example.org');
        $this->assertNotSame($first, $plugin->resolver->resolve());
    }

    /** T41 (C40): an invalid constant falls through, warns once and fires the action. */
    public function testInvalidConstantFallsThrough(): void
    {
        $this->constants['WPMF_FROM'] = 'not-an-address';
        $this->siteOption(['from' => 'site@example.org']);
        $fired = [];
        add_action('wpmf_invalid_address', static function (string $source, string $value, string $reason) use (&$fired): void {
            $fired[] = [$source, $value, $reason];
        }, 10, 3);

        $plugin = $this->plugin();
        $identity = $plugin->resolver->resolve();
        $this->assertSame('site@example.org', $identity->from);
        $this->assertCount(1, $identity->warnings);
        $this->assertSame('constant', $identity->warnings[0]['source']);
        $this->assertSame([['constant', 'not-an-address', 'not-an-address']], $fired);

        $plugin->resolver->flush();
        $plugin->resolver->resolve();
        $warnings = array_filter($this->log, static fn (string $l): bool => str_contains($l, 'invalid from from constant'));
        $this->assertCount(1, $warnings, 'one warning per source per request');
    }

    /** T42 (C41): all sources invalid leaves core values and logs an error. */
    public function testAllSourcesInvalid(): void
    {
        \WP::$homeUrls[1] = 'http://localhost';
        \WP::$networkHome = 'http://localhost';
        \WP::$options[1]['admin_email'] = 'admin@localhost';
        $this->constants['WPMF_FROM'] = 'root@localhost';
        $plugin = $this->plugin();
        $identity = $plugin->resolver->resolve();
        $this->assertFalse($identity->isValid());
        $this->assertTrue($this->logContains('error: no valid From address'));

        // Core values pass through untouched. (Core's own is_email() then
        // rejects wordpress@localhost, exactly as it would without the plugin.)
        apply_filters('wp_mail', ['headers' => '']);
        $this->assertSame('wordpress@localhost', apply_filters('wp_mail_from', 'wordpress@localhost'));
        $this->assertSame('WordPress', apply_filters('wp_mail_from_name', 'WordPress'));
        $mailer = new \PHPMailer\PHPMailer\PHPMailer();
        $mailer->From = 'wordpress@localhost';
        do_action_ref_array('phpmailer_init', [&$mailer]);
        $this->assertSame('', $mailer->Sender);
    }

    /** T39 (C38): an invalid return path falls back to From and is logged. */
    public function testInvalidReturnPath(): void
    {
        $this->constants['WPMF_RETURN_PATH'] = 'bounces@';
        $this->constants['WPMF_FROM'] = 'mail@example.org';
        $plugin = $this->plugin();
        $this->assertNull($plugin->resolver->resolve()->returnPath);
        $this->assertTrue($this->logContains('invalid return_path from constant'));
        $this->assertSame('mail@example.org', $this->send()['sender']);
    }

    /** T53 (C52): network option, site option and constant precedence on multisite. */
    public function testMultisitePrecedence(): void
    {
        \WP::$multisite = true;
        $this->networkOption(['from' => 'network@example.org', 'from_name' => 'Network', 'explicit_mode' => 'align']);
        $plugin = $this->plugin();
        $identity = $plugin->resolver->resolve();
        $this->assertSame('network@example.org', $identity->from);
        $this->assertSame('network-option', $identity->fromSource);
        $this->assertSame('align', $identity->explicitMode);

        $this->siteOption(['from' => 'site@example.org']);
        $plugin->resolver->flush();
        $identity = $plugin->resolver->resolve();
        $this->assertSame('site@example.org', $identity->from);
        $this->assertSame('Network', $identity->fromName, 'a site option overrides only the fields it sets');

        $this->constants['WPMF_FROM'] = 'const@example.org';
        $plugin->resolver->flush();
        $this->assertSame('const@example.org', $plugin->resolver->resolve()->from);
    }

    /** T55 (C54): a locked network field ignores the site option. */
    public function testLockedFieldIgnoresSiteOption(): void
    {
        \WP::$multisite = true;
        $this->networkOption(['from' => 'network@example.org', 'locked' => ['from']]);
        $this->siteOption(['from' => 'site@example.org', 'from_name' => 'Site']);
        $identity = $this->plugin()->resolver->resolve();
        $this->assertSame('network@example.org', $identity->from);
        $this->assertSame('Site', $identity->fromName);
    }

    /** T54 (C53): derived default per site on subdomain and subdirectory networks. */
    public function testDerivedPerSite(): void
    {
        \WP::$multisite = true;
        \WP::$networkHome = 'https://www.example.org';
        \WP::addSite(2, 'https://shop.example.org', 'Shop', 'owner@gmail.example');
        \WP::addSite(3, 'https://www.example.org/blog', 'Blog', 'admin@example.org');
        $plugin = $this->plugin();

        switch_to_blog(2);
        $identity = $plugin->resolver->resolve();
        $this->assertSame('no-reply@shop.example.org', $identity->from);
        $this->assertSame('wordpress@example.org', $identity->coreDefault, 'core default uses the network host');
        restore_current_blog();

        switch_to_blog(3);
        $this->assertSame('admin@example.org', $plugin->resolver->resolve()->from);
        $this->assertSame('example.org', $plugin->resolver->resolve()->siteDomain);
        restore_current_blog();
    }

    /** T56 (C55): mail under switch_to_blog() uses that site. */
    public function testSwitchToBlogUsesThatSite(): void
    {
        \WP::$multisite = true;
        \WP::addSite(2, 'https://shop.example.org', 'Shop', 'orders@shop.example.org');
        $this->plugin();

        $this->assertSame('admin@example.org', $this->send()['from']);
        switch_to_blog(2);
        $sent = $this->send();
        restore_current_blog();
        $this->assertSame('orders@shop.example.org', $sent['from']);
        $this->assertSame('Shop', $sent['name']);
        $this->assertSame('admin@example.org', $this->send()['from']);
    }

    /** T57 (C56): a domain-mapped site uses the mapped host. */
    public function testDomainMappedSite(): void
    {
        \WP::$multisite = true;
        \WP::addSite(2, 'https://www.mapped-shop.example', 'Mapped', 'admin@example.org');
        $plugin = $this->plugin();
        switch_to_blog(2);
        $identity = $plugin->resolver->resolve();
        restore_current_blog();
        $this->assertSame('mapped-shop.example', $identity->siteDomain);
        $this->assertSame('no-reply@mapped-shop.example', $identity->from);
    }

    /** C25: unknown mode values fall back to the default and are logged once. */
    public function testUnknownModeFallsBack(): void
    {
        $this->constants['WPMF_EXPLICIT_MODE'] = 'aling';
        $this->constants['WPMF_SENDER_MODE'] = 'ALWAYS';
        $plugin = $this->plugin();
        $identity = $plugin->resolver->resolve();
        $this->assertSame('respect', $identity->explicitMode);
        $this->assertSame('always', $identity->senderMode);
        $plugin->resolver->flush();
        $plugin->resolver->resolve();
        $lines = array_filter($this->log, static fn (string $l): bool => str_contains($l, 'unknown explicit_mode'));
        $this->assertCount(1, $lines);
    }
}
