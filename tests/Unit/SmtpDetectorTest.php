<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests\Unit;

use IndianOpinion\MailFrom\SmtpDetector;
use IndianOpinion\MailFrom\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SmtpDetectorTest extends TestCase
{
    /** T46 (C45): each listed plugin is detected by its constant. */
    #[DataProvider('constants')]
    public function testConstantMarkers(string $constant, string $name): void
    {
        $detector = new SmtpDetector(static fn (string $c): bool => $c === $constant);
        $this->assertSame([['name' => $name, 'marker' => 'constant:' . $constant]], $detector->detect());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function constants(): array
    {
        return [
            'wp mail smtp' => ['WPMS_PLUGIN_VER', 'WP Mail SMTP'],
            'fluent' => ['FLUENTMAIL', 'FluentSMTP'],
            'post smtp' => ['POST_SMTP_VER', 'Post SMTP'],
            'easy wp smtp' => ['EasyWPSMTP_PLUGIN_VERSION', 'Easy WP SMTP'],
        ];
    }

    /** T46 (C45): each listed plugin is detected by its active plugin slug. */
    #[DataProvider('slugs')]
    public function testSlugMarkers(string $path, string $name): void
    {
        \WP::$options[1]['active_plugins'] = ['akismet/akismet.php', $path];
        $detector = new SmtpDetector(static fn (): bool => false);
        $this->assertSame([['name' => $name, 'marker' => 'plugin:' . $path]], $detector->detect());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function slugs(): array
    {
        return [
            'wp mail smtp' => ['wp-mail-smtp/wp_mail_smtp.php', 'WP Mail SMTP'],
            'fluent' => ['fluent-smtp/fluent-smtp.php', 'FluentSMTP'],
            'post smtp' => ['post-smtp/postman-smtp.php', 'Post SMTP'],
            'easy wp smtp' => ['easy-wp-smtp/easy-wp-smtp.php', 'Easy WP SMTP'],
        ];
    }

    /** T46 (C45): network-activated plugins count; the filter extends the list; once per request. */
    public function testNetworkAndFilterAndOnce(): void
    {
        \WP::$multisite = true;
        \WP::$siteOptions['active_sitewide_plugins'] = ['my-relay/relay.php' => time()];
        add_filter('wpmf_smtp_markers', static function (array $markers): array {
            $markers[] = ['name' => 'My Relay', 'slugs' => ['my-relay'], 'constants' => [], 'classes' => [], 'functions' => []];

            return $markers;
        });
        $fired = 0;
        add_action('wpmf_smtp_plugin_detected', static function () use (&$fired): void {
            $fired++;
        });
        $detector = new SmtpDetector(static fn (): bool => false);
        $this->assertSame([['name' => 'My Relay', 'marker' => 'plugin:my-relay/relay.php']], $detector->detect());
        $detector->detect();
        $this->assertSame(1, $fired);
    }

    /** The detection filter can clear the result; nothing found by default. */
    public function testDetectionFilter(): void
    {
        $this->assertSame([], (new SmtpDetector(static fn (): bool => false))->detect());
        add_filter('wpmf_smtp_detection', static fn (): array => []);
        $this->assertSame([], (new SmtpDetector(static fn (): bool => true))->detect());
    }

    /** T50 (C49): a replaced wp_mail() is reported with its file. */
    public function testReplacedWpMail(): void
    {
        $detector = new SmtpDetector();
        $stub = realpath(dirname(__DIR__) . '/stubs/wp-mail.php');
        $this->assertSame($stub, $detector->wpMailReplacement());
        $this->assertNull($detector->wpMailReplacement((string) $stub), 'core file means not replaced');
    }
}
