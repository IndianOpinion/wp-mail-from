<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests;

use IndianOpinion\MailFrom\Logger;
use IndianOpinion\MailFrom\Options;
use IndianOpinion\MailFrom\Plugin;
use IndianOpinion\MailFrom\SmtpDetector;
use IndianOpinion\MailFrom\Validator;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var array<string, mixed> */
    protected array $constants = [];

    /** @var list<string> */
    protected array $log = [];

    /** @var list<string> Constants the SMTP detector should see as defined. */
    protected array $definedForDetector = [];

    protected function setUp(): void
    {
        parent::setUp();
        \WP::reset();
        \WP_CLI::reset();
        Plugin::resetForTests();
        $this->constants = [];
        $this->log = [];
        $this->definedForDetector = [];
    }

    protected function options(): Options
    {
        return new Options(fn (string $name): mixed => $this->constants[$name] ?? null);
    }

    protected function logger(): Logger
    {
        return new Logger(function (string $line): bool {
            $this->log[] = $line;

            return true;
        });
    }

    protected function plugin(?Validator $validator = null): Plugin
    {
        $plugin = new Plugin(
            '/tmp/wp-mail-from/wp-mail-from.php',
            $this->options(),
            $this->logger(),
            $validator,
            new SmtpDetector(fn (string $c): bool => in_array($c, $this->definedForDetector, true))
        );

        return Plugin::install($plugin);
    }

    /**
     * @param string|array<int|string, mixed> $headers
     * @return array<string, mixed> What the fake PHPMailer sent.
     */
    protected function send(string|array $headers = '', string $to = 'reader@example.net'): array
    {
        $count = count(\WP::$sent);
        $ok = wp_mail($to, 'Subject', 'Body', $headers);
        $this->assertTrue($ok, 'wp_mail() failed: ' . implode("\n", $this->log));
        $this->assertCount($count + 1, \WP::$sent);

        return \WP::$sent[$count];
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function siteOption(array $settings): void
    {
        \WP::$options[\WP::$blogId][Options::SITE_OPTION] = $settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function networkOption(array $settings): void
    {
        \WP::$siteOptions[Options::NETWORK_OPTION] = $settings;
    }

    protected function logContains(string $needle): bool
    {
        foreach ($this->log as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }
}
