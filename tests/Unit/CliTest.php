<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests\Unit;

use IndianOpinion\MailFrom\Cli\Command;
use IndianOpinion\MailFrom\Options;
use IndianOpinion\MailFrom\Tests\TestCase;

final class CliTest extends TestCase
{
    private function command(): Command
    {
        $this->plugin();
        $command = \WP_CLI::$commands['mail-from'];
        $this->assertInstanceOf(Command::class, $command);
        \WP_CLI::$lines = [];

        return $command;
    }

    private function cliOutput(): string
    {
        return implode("\n", \WP_CLI::$lines);
    }

    /** T65, T38 (C36, C37): table output, sources, transport caveat, exit 0. */
    public function testShowTable(): void
    {
        $this->constants['WPMF_FROM'] = 'mail@example.org';
        $this->siteOption(['from_name' => 'Example News']);
        $this->command()->show([], []);
        $out = $this->cliOutput();
        $this->assertStringContainsString('table|from|mail@example.org|constant', $out);
        $this->assertStringContainsString('table|from_name|Example News|site-option', $out);
        $this->assertStringContainsString('table|explicit_mode|respect|default', $out);
        $this->assertStringContainsString('envelope sender requested; the transport may override it', $out);
        $this->assertStringContainsString('table|wp_mail|wp_mail() is replaced by', $out);
        $this->assertNull(\WP_CLI::$exit);
    }

    /** T65 (C40, C49): JSON output carries warnings, derived flag and the replaced wp_mail(). */
    public function testShowJson(): void
    {
        $this->constants['WPMF_FROM'] = 'bad';
        $this->definedForDetector = ['POST_SMTP_VER'];
        $this->command()->show([], ['format' => 'json']);
        $data = json_decode($this->cliOutput(), true);
        $this->assertIsArray($data);
        $this->assertTrue($data['valid']);
        $this->assertSame('admin@example.org', $data['from']['value']);
        $this->assertTrue($data['from']['derived']);
        $this->assertSame('constant', $data['warnings'][0]['source']);
        $this->assertSame('Post SMTP', $data['smtp_plugins'][0]['name']);
        $this->assertStringEndsWith('wp-mail.php', (string) $data['wp_mail_replaced_by']);
        $this->assertSame('envelope sender requested; the transport may override it', $data['envelope_sender']);
    }

    /** T65 (C41): exit status 1 when core values would be kept. */
    public function testShowExitOne(): void
    {
        \WP::$homeUrls[1] = 'http://localhost';
        \WP::$options[1]['admin_email'] = 'a@localhost';
        $this->command()->show([], ['format' => 'yaml']);
        $this->assertSame(1, \WP_CLI::$exit);
        $this->assertStringContainsString('yaml|error|no valid From address', $this->cliOutput());
    }

    /** T66 (C20, C21, C22): --message-from preview matches the live decision. */
    public function testPreviewMatchesLive(): void
    {
        foreach (['respect', 'override', 'align'] as $mode) {
            foreach (['Jane <jane@visitor.example>', 'desk@example.org', 'WordPress <wordpress@example.org>'] as $from) {
                $this->setUp();
                $this->constants['WPMF_FROM'] = 'mail@example.org';
                $this->constants['WPMF_EXPLICIT_MODE'] = $mode;
                $command = $this->command();
                $command->show([], ['format' => 'json', 'message-from' => $from]);
                $preview = json_decode($this->cliOutput(), true);

                $records = [];
                add_action('wpmf_decision', static function (array $r) use (&$records): void {
                    $records[] = $r;
                });
                $sent = $this->send(['From: ' . $from]);

                $this->assertSame($sent['from'], $preview['from_after'], $mode . ' ' . $from);
                $this->assertSame($sent['name'], $preview['name_after'], $mode . ' ' . $from);
                $this->assertSame($records[0]['from_after'], $preview['from_after']);
                $this->assertSame($mode, $preview['mode']);
            }
        }
    }

    /** Table preview output. */
    public function testPreviewTable(): void
    {
        $this->constants['WPMF_EXPLICIT_MODE'] = 'override';
        $this->command()->show([], ['message-from' => 'jane@visitor.example']);
        $this->assertStringContainsString('table|decision|override', $this->cliOutput());
        $this->assertStringContainsString('table|reply_to_moved|jane@visitor.example', $this->cliOutput());
    }

    /** set validates and stores; reset deletes (section 6). */
    public function testSetAndReset(): void
    {
        $command = $this->command();
        $command->set(['from', 'mail@example.org'], []);
        $this->assertSame('mail@example.org', get_option(Options::SITE_OPTION)['from']);
        $this->assertStringContainsString('Success: from saved.', $this->cliOutput());

        try {
            $command->set(['from', 'root@localhost'], []);
            $this->fail('expected an error');
        } catch (\WP_CLI_Halt $e) {
            $this->assertStringContainsString('domain-without-dot', $e->getMessage());
        }

        try {
            $command->set(['from', 'x@example.org'], ['network' => '']);
            $this->fail('expected an error');
        } catch (\WP_CLI_Halt $e) {
            $this->assertStringContainsString('multisite', $e->getMessage());
        }

        \WP::$multisite = true;
        $command->set(['explicit_mode', 'align'], ['network' => '']);
        $this->assertSame('align', get_site_option(Options::NETWORK_OPTION)['explicit_mode']);

        $command->reset([], ['yes' => '']);
        $this->assertFalse(get_option(Options::SITE_OPTION));
        $command->reset([], ['network' => '', 'yes' => '']);
        $this->assertFalse(get_site_option(Options::NETWORK_OPTION));

        $this->expectException(\WP_CLI_Halt::class);
        $command->reset([], []);
    }
}
