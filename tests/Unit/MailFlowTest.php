<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests\Unit;

use IndianOpinion\MailFrom\Tests\TestCase;

/**
 * End-to-end through the model of core's wp_mail() in tests/stubs.
 */
final class MailFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->constants['WPMF_FROM'] = 'mail@example.org';
        $this->constants['WPMF_FROM_NAME'] = 'Example News';
    }

    /** No explicit From: core default replaced, Sender filled (C4 path, C30). */
    public function testDefaultReplaced(): void
    {
        $this->plugin();
        $sent = $this->send();
        $this->assertSame('mail@example.org', $sent['from']);
        $this->assertSame('Example News', $sent['name']);
        $this->assertSame('mail@example.org', $sent['sender']);
        $this->assertSame([], $sent['reply_to']);
    }

    /** T18 (C17): an explicit core default address, any case, is replaced. */
    public function testExplicitCoreDefaultReplaced(): void
    {
        \WP::$networkHome = 'https://www.Example.org';
        $this->plugin();
        $sent = $this->send(['From: WordPress <WordPress@EXAMPLE.org>']);
        $this->assertSame('mail@example.org', $sent['from']);
        $this->assertSame('Example News', $sent['name']);
    }

    /** T19 (C18): an invalid explicit From is replaced and warned about. */
    public function testInvalidExplicitFrom(): void
    {
        $this->plugin();
        $sent = $this->send(['From: Visitor <visitor@nowhere>']);
        $this->assertSame('mail@example.org', $sent['from']);
        $this->assertSame('Example News', $sent['name']);
        $this->assertTrue($this->logContains('invalid explicit From "v***@nowhere"'));
    }

    /** T21 (C20): respect mode keeps the explicit From and name. */
    public function testRespectMode(): void
    {
        $this->plugin();
        $sent = $this->send(['From: Jane Visitor <jane@visitor.example>']);
        $this->assertSame('jane@visitor.example', $sent['from']);
        $this->assertSame('Jane Visitor', $sent['name']);
        $this->assertSame([], $sent['reply_to']);
    }

    /** T22 (C21): override replaces From and moves the original to Reply-To. */
    public function testOverrideMovesToReplyTo(): void
    {
        $this->constants['WPMF_EXPLICIT_MODE'] = 'override';
        $this->plugin();
        $sent = $this->send(['From: Jane Visitor <jane@visitor.example>']);
        $this->assertSame('mail@example.org', $sent['from']);
        $this->assertSame('Example News', $sent['name']);
        $this->assertSame(['jane@visitor.example'], $sent['reply_to']);
    }

    /** T23 (C21, C28): override keeps an existing Reply-To. */
    public function testOverrideKeepsExistingReplyTo(): void
    {
        $this->constants['WPMF_EXPLICIT_MODE'] = 'override';
        $this->constants['WPMF_REPLY_TO'] = 'desk@example.org';
        $this->plugin();
        $sent = $this->send(['From: jane@visitor.example', 'Reply-To: help@visitor.example']);
        $this->assertSame('mail@example.org', $sent['from']);
        $this->assertSame(['help@visitor.example'], $sent['reply_to']);
    }

    /** T24 (C22): align keeps an aligned From and replaces an unaligned one. */
    public function testAlignMode(): void
    {
        $this->constants['WPMF_EXPLICIT_MODE'] = 'align';
        $this->plugin();
        $kept = $this->send(['From: Orders <orders@shop.example.org>']);
        $this->assertSame('orders@shop.example.org', $kept['from']);
        $this->assertSame('Orders', $kept['name']);

        $replaced = $this->send(['From: Jane <jane@visitor.example>']);
        $this->assertSame('mail@example.org', $replaced['from']);
        $this->assertSame('Example News', $replaced['name']);
        $this->assertSame(['jane@visitor.example'], $replaced['reply_to']);
    }

    /** T25 (C23): no display name gets the configured name only when aligned. */
    public function testNoDisplayName(): void
    {
        $this->plugin();
        $aligned = $this->send(['From: desk@example.org']);
        $this->assertSame('desk@example.org', $aligned['from']);
        $this->assertSame('Example News', $aligned['name']);

        $visitor = $this->send(['From: <jane@visitor.example>']);
        $this->assertSame('jane@visitor.example', $visitor['from']);
        $this->assertSame('', $visitor['name'], 'a visitor address never carries the site name');
    }

    /** T26 (C24): the core default name is replaced under the C23 rule. */
    public function testDefaultNameReplaced(): void
    {
        $this->plugin();
        $this->assertSame('Example News', $this->send(['From: WordPress <desk@example.org>'])['name']);
        $this->assertSame('', $this->send(['From: WordPress <jane@visitor.example>'])['name']);
    }

    /** T27 (C25): mode by filter per message; an unknown filtered mode is respect. */
    public function testModeFilter(): void
    {
        $this->plugin();
        add_filter('wpmf_explicit_mode', static function (string $mode, array $atts): string {
            return $atts['subject'] === 'Subject' ? 'override' : $mode;
        }, 10, 2);
        $this->assertSame('mail@example.org', $this->send(['From: jane@visitor.example'])['from']);

        remove_all_filters('wpmf_explicit_mode');
        add_filter('wpmf_explicit_mode', static fn (): string => 'bogus');
        $this->assertSame('jane@visitor.example', $this->send(['From: jane@visitor.example'])['from']);
        $this->assertTrue($this->logContains('wpmf_explicit_mode returned an unknown value'));
    }

    /** T28 (C26): Reply-To untouched by default. */
    public function testReplyToUntouchedByDefault(): void
    {
        $this->plugin();
        $this->assertSame([], $this->send()['reply_to']);
        $this->assertSame(['a@example.net'], $this->send(['Reply-To: a@example.net'])['reply_to']);
    }

    /** T29 (C27): configured Reply-To added when absent, never replacing one. */
    public function testConfiguredReplyTo(): void
    {
        $this->constants['WPMF_REPLY_TO'] = 'desk@example.org';
        $this->plugin();
        $this->assertSame(['desk@example.org'], $this->send()['reply_to']);
        $this->assertSame(['a@example.net'], $this->send(['Reply-To: a@example.net'])['reply_to']);
    }

    /** T30 (C28): a moved Reply-To beats the configured one. */
    public function testMovedBeatsConfigured(): void
    {
        $this->constants['WPMF_EXPLICIT_MODE'] = 'override';
        $this->constants['WPMF_REPLY_TO'] = 'desk@example.org';
        $this->plugin();
        $this->assertSame(['jane@visitor.example'], $this->send(['From: jane@visitor.example'])['reply_to']);
    }

    /** T31 (C29): no duplicate when another phpmailer_init hook added a Reply-To. */
    public function testReplyToNotDuplicated(): void
    {
        $this->constants['WPMF_REPLY_TO'] = 'desk@example.org';
        $this->plugin();
        add_action('phpmailer_init', static function ($mailer): void {
            $mailer->addReplyTo('other@example.net');
        }, 50);
        $this->assertSame(['other@example.net'], $this->send()['reply_to']);
    }

    /** T32 (C30): Sender filled when empty, from the return path, then From. */
    public function testSenderFilled(): void
    {
        $this->plugin();
        $this->assertSame('mail@example.org', $this->send()['sender']);

        \WP::reset();
        \IndianOpinion\MailFrom\Plugin::resetForTests();
        $this->constants['WPMF_RETURN_PATH'] = 'bounces@example.org';
        $this->plugin();
        $this->assertSame('bounces@example.org', $this->send()['sender']);
    }

    /** T32 (C30): a Sender set by someone else is left alone in if-empty mode. */
    public function testSenderKeptWhenSet(): void
    {
        $this->plugin();
        add_action('phpmailer_init', static function ($mailer): void {
            $mailer->Sender = 'relay-bounce@relay.example';
        }, 10);
        $this->assertSame('relay-bounce@relay.example', $this->send()['sender']);
    }

    /** T33 (C31): always mode overwrites a Sender. */
    public function testSenderAlways(): void
    {
        $this->constants['WPMF_SENDER_MODE'] = 'always';
        $this->plugin();
        add_action('phpmailer_init', static function ($mailer): void {
            $mailer->Sender = 'relay-bounce@relay.example';
        }, 10);
        $this->assertSame('mail@example.org', $this->send()['sender']);
    }

    /** T34 (C32): never mode leaves Sender alone. */
    public function testSenderNever(): void
    {
        $this->constants['WPMF_SENDER_MODE'] = 'never';
        $this->plugin();
        $this->assertSame('', $this->send()['sender']);
    }

    /** T35 (C33): Sender follows a From changed by a later plugin. */
    public function testSenderFollowsLaterFrom(): void
    {
        $this->plugin();
        add_filter('wp_mail_from', static fn (): string => 'forced@relay.example', 20);
        $sent = $this->send();
        $this->assertSame('forced@relay.example', $sent['from']);
        $this->assertSame('forced@relay.example', $sent['sender']);
    }

    /** T36 (C34): two messages in one request do not leak Sender. */
    public function testSenderDoesNotLeak(): void
    {
        $this->plugin();
        $first = $this->send();
        $this->assertSame('mail@example.org', $first['sender']);

        // Second message: a later plugin forces another From; the Sender
        // this plugin set last time must not survive on the shared object.
        add_filter('wp_mail_from', static fn (): string => 'forced@relay.example', 20);
        $second = $this->send();
        $this->assertSame('forced@relay.example', $second['sender']);

        // Third message with sender mode never: the old value is cleared, not reused.
        remove_all_filters('wp_mail_from');
        add_filter('wpmf_sender_mode', static fn (): string => 'never');
        $this->plugin();
        $third = $this->send();
        $this->assertSame('', $third['sender']);
    }

    /** T37 (C35): no Return-Path header is ever added. */
    public function testNoReturnPathHeader(): void
    {
        $this->constants['WPMF_RETURN_PATH'] = 'bounces@example.org';
        $this->constants['WPMF_EXPLICIT_MODE'] = 'override';
        $this->plugin();
        $sent = $this->send(['From: jane@visitor.example', 'X-Test: 1']);
        foreach ($sent['headers'] as $header) {
            $this->assertStringStartsNotWith('return-path', strtolower($header));
        }
        $this->assertSame(['X-Test: 1'], $sent['headers']);
    }

    /** T47 (C46, C63): in defer mode a later force-From filter wins. */
    public function testDeferLetsSmtpPluginWin(): void
    {
        $this->definedForDetector = ['WPMS_PLUGIN_VER'];
        $this->plugin();
        add_filter('wp_mail_from', static fn (): string => 'smtp@relay.example', 10);
        add_filter('wp_mail_from_name', static fn (): string => 'Relay', 10);
        $sent = $this->send();
        $this->assertSame('smtp@relay.example', $sent['from']);
        $this->assertSame('Relay', $sent['name']);
        $this->assertSame('smtp@relay.example', $sent['sender'], 'empty Sender still filled');
    }

    /** T48 (C47): stand-aside changes nothing when an SMTP plugin is detected. */
    public function testStandAside(): void
    {
        $this->constants['WPMF_SMTP_MODE'] = 'stand-aside';
        $this->constants['WPMF_REPLY_TO'] = 'desk@example.org';
        $this->definedForDetector = ['FLUENTMAIL'];
        $this->plugin();
        $records = [];
        add_action('wpmf_decision', static function (array $r) use (&$records): void {
            $records[] = $r;
        });
        $sent = $this->send();
        $this->assertSame('wordpress@example.org', $sent['from']);
        $this->assertSame('WordPress', $sent['name']);
        $this->assertSame('', $sent['sender']);
        $this->assertSame([], $sent['reply_to']);
        $this->assertSame('skip', $records[0]['decision']);
        $this->assertStringContainsString('FluentSMTP', $records[0]['reason']);
    }

    /** T49 (C48): ignore mode behaves as if nothing were detected. */
    public function testIgnoreMode(): void
    {
        $this->constants['WPMF_SMTP_MODE'] = 'ignore';
        $this->definedForDetector = ['FLUENTMAIL'];
        $this->plugin();
        $records = [];
        add_action('wpmf_decision', static function (array $r) use (&$records): void {
            $records[] = $r;
        });
        $this->assertSame('mail@example.org', $this->send()['from']);
        $this->assertSame([], $records[0]['smtp']);
    }

    /** T51 (C50, C65): a pre_wp_mail short-circuit leaves no state behind. */
    public function testShortCircuitLeavesNoState(): void
    {
        $plugin = $this->plugin();
        add_filter('pre_wp_mail', static fn (): bool => true, 10);
        $this->assertTrue(wp_mail('a@example.net', 'S', 'B', ['From: jane@visitor.example']));
        $this->assertNull($plugin->handler->state());
        $this->assertSame([], \WP::$sent);

        remove_all_filters('pre_wp_mail');
        $plugin->handler->register(5);
        // A From filter called outside a message must not reuse old state.
        $this->assertSame('x@example.net', apply_filters('wp_mail_from', 'x@example.net'));
    }

    /** T58 (C57): dry run changes nothing. */
    public function testDryRunChangesNothing(): void
    {
        $this->constants['WPMF_DRY_RUN'] = true;
        $this->constants['WPMF_REPLY_TO'] = 'desk@example.org';
        $this->plugin();
        $sent = $this->send();
        $this->assertSame('wordpress@example.org', $sent['from']);
        $this->assertSame('WordPress', $sent['name']);
        $this->assertSame('', $sent['sender']);
        $this->assertSame([], $sent['reply_to']);
    }

    /** T59 (C58): dry-run log line format, without recipients or subject. */
    public function testDryRunLogLine(): void
    {
        $this->constants['WPMF_DRY_RUN'] = 'yes';
        $this->constants['WPMF_REPLY_TO'] = 'desk@example.org';
        $this->plugin();
        wp_mail('secret-reader@example.net', 'Secret subject', 'Secret body');
        $lines = array_values(array_filter($this->log, static fn (string $l): bool => str_contains($l, 'dry-run')));
        $this->assertCount(1, $lines);
        $this->assertSame(
            '[wp-mail-from] info: dry-run decision=would-replace-default,would-add-reply-to,would-set-sender'
            . ' mode=respect from="wordpress@example.org"->"mail@example.org" name="WordPress"->"Example News"'
            . ' sender=""->"mail@example.org" reason="no explicit From; core default replaced"',
            $lines[0]
        );
        $this->assertStringNotContainsString('secret', implode("\n", $this->log));
        $this->assertStringNotContainsString('Secret', implode("\n", $this->log));
    }

    /** T60 (C59): the decision action fires in dry run and live with the same record. */
    public function testDecisionActionInBothModes(): void
    {
        $records = [];
        $collect = static function (array $r) use (&$records): void {
            $records[] = $r;
        };
        $this->plugin();
        add_action('wpmf_decision', $collect);
        $this->send(['From: jane@visitor.example']);

        add_filter('wpmf_dry_run', '__return_true');
        $this->send(['From: jane@visitor.example']);

        $this->assertCount(2, $records);
        $this->assertFalse($records[0]['dry_run']);
        $this->assertTrue($records[1]['dry_run']);
        $this->assertSame('set-name', $records[0]['decision']);
        $this->assertSame('would-set-name', $records[1]['decision']);
        foreach (['mode', 'from_before', 'from_after', 'name_before', 'name_after', 'reason'] as $key) {
            $this->assertSame($records[0][$key], $records[1][$key], $key);
        }
    }

    /** T61 (C60): failures are logged with the prefix, switchable, without recipients. */
    public function testFailureLogging(): void
    {
        $this->plugin();
        \WP::$sendResult = 'throw';
        $this->assertFalse(wp_mail('reader@example.net', 'S', 'B'));
        $this->assertTrue($this->logContains('[wp-mail-from] error: wp_mail failed: SMTP Error: The following recipients failed: [address]'));
        $this->assertStringNotContainsString('reader@example.net', implode("\n", $this->log));

        $this->log = [];
        add_filter('wpmf_log_failures', '__return_false');
        wp_mail('reader@example.net', 'S', 'B');
        $this->assertSame([], $this->log);
    }

    /** T61 (C60): the constant switches failure logging off. */
    public function testFailureLoggingConstant(): void
    {
        $this->constants['WPMF_LOG_FAILURES'] = false;
        $this->plugin();
        \WP::$sendResult = 'throw';
        wp_mail('reader@example.net', 'S', 'B');
        $this->assertFalse($this->logContains('wp_mail failed'));
    }

    /** T62 (C61): a failing logger does not affect the send result. */
    public function testLoggerFailureDoesNotAffectSend(): void
    {
        $plugin = new \IndianOpinion\MailFrom\Plugin(
            '/tmp/x.php',
            $this->options(),
            new \IndianOpinion\MailFrom\Logger(static function (): never {
                throw new \RuntimeException('disk full');
            })
        );
        \IndianOpinion\MailFrom\Plugin::install($plugin);
        add_filter('wpmf_dry_run', '__return_true');
        $this->assertTrue(wp_mail('reader@example.net', 'S', 'B'));
        \WP::$sendResult = 'throw';
        $this->assertFalse(wp_mail('reader@example.net', 'S', 'B'));
    }

    /** T64 (C65): per-message state is cleared on success and on failure. */
    public function testStateClearedAfterMessage(): void
    {
        $plugin = $this->plugin();
        $this->send();
        $this->assertNull($plugin->handler->state());
        \WP::$sendResult = 'throw';
        wp_mail('reader@example.net', 'S', 'B');
        $this->assertNull($plugin->handler->state());
    }

    /** T68 (section 8): a visitor From is masked in logs in respect mode. */
    public function testVisitorMaskedInLogs(): void
    {
        $this->constants['WPMF_DRY_RUN'] = true;
        $this->plugin();
        wp_mail('reader@example.net', 'S', 'B', ['From: Jane <jane.doe@visitor.example>']);
        $log = implode("\n", $this->log);
        $this->assertStringContainsString('from="j***@visitor.example"->"j***@visitor.example"', $log);
        $this->assertStringNotContainsString('jane.doe', $log);
    }

    /** wpmf_should_handle false leaves the message alone. */
    public function testShouldHandleFilter(): void
    {
        $this->plugin();
        add_filter('wpmf_should_handle', '__return_false');
        $this->assertSame('wordpress@example.org', $this->send()['from']);
    }

    /** A later wp_mail filter that rewrites headers is honoured. */
    public function testLaterHeaderRewrite(): void
    {
        $this->plugin();
        add_filter('wp_mail', static function (array $atts): array {
            $atts['headers'] = ['From: jane@visitor.example'];

            return $atts;
        }, 2000);
        $sent = $this->send();
        $this->assertSame('jane@visitor.example', $sent['from']);
        $this->assertSame('', $sent['name']);
    }
}
