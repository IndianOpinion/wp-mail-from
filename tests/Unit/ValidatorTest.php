<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests\Unit;

use IndianOpinion\MailFrom\AddressResult;
use IndianOpinion\MailFrom\Validator;
use IndianOpinion\MailFrom\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ValidatorTest extends TestCase
{
    /** T40 (C39): the validator table. */
    #[DataProvider('addresses')]
    public function testAddresses(string $input, bool $valid, string $reasonOrAddress): void
    {
        $result = (new Validator(false))->validate($input);
        $this->assertSame($valid, $result->valid, $input);
        $this->assertSame($reasonOrAddress, $valid ? $result->address : $result->reason);
    }

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function addresses(): array
    {
        return [
            'valid' => ['mail@example.org', true, 'mail@example.org'],
            'valid upper domain' => ['Mail@EXAMPLE.org', true, 'Mail@example.org'],
            'trimmed' => ['  mail@example.org ', true, 'mail@example.org'],
            'CR' => ["mail@example.org\rBcc: x@example.net", false, 'contains-line-break'],
            'LF' => ["mail@example.org\nBcc: x@example.net", false, 'contains-line-break'],
            'too long' => [str_repeat('a', 64) . '@' . str_repeat('b', 190) . '.org', false, 'too-long'],
            'no dot' => ['root@localhost', false, 'domain-without-dot'],
            'ip literal' => ['root@[192.0.2.1]', false, 'ip-literal-domain'],
            'bare ip' => ['root@192.0.2.1', false, 'ip-literal-domain'],
            'ipv6' => ['root@[IPv6:2001:db8::1]', false, 'ip-literal-domain'],
            'empty' => ['', false, 'empty'],
            'no at' => ['example.org', false, 'not-an-address'],
            'name form' => ['Shop <shop@example.org>', false, 'rejected-by-is-email'],
            'unicode without intl' => ["info@b\u{fc}cher.example", false, 'unicode-domain-needs-intl'],
        ];
    }

    /** T40 (C39): a Unicode domain is converted when intl is available. */
    public function testUnicodeWithIntl(): void
    {
        $validator = new Validator(static fn (string $d): string => $d === "b\u{fc}cher.example" ? 'xn--bcher-kva.example' : $d);
        $result = $validator->validate("info@b\u{fc}cher.example");
        $this->assertTrue($result->valid);
        $this->assertSame('info@xn--bcher-kva.example', $result->address);

        if (function_exists('idn_to_ascii')) {
            $this->assertSame('info@xn--bcher-kva.example', (new Validator())->validate("info@b\u{fc}cher.example")->address);
        }
    }

    /** C39, section 8: the validator filter cannot reopen header injection. */
    public function testFilterCannotBypassHardRules(): void
    {
        add_filter(
            'wpmf_validate_address',
            static fn (AddressResult $r, string $in): AddressResult => AddressResult::ok($in),
            10,
            2
        );
        $validator = new Validator(false);
        $this->assertTrue($validator->validate('root@localhost')->valid, 'the filter may relax soft rules');
        $this->assertFalse($validator->validate("a@example.org\r\nBcc: b@example.org")->valid);
    }

    /** T43 (C42): name cleaning. */
    #[DataProvider('names')]
    public function testNames(string $input, string $expected): void
    {
        $this->assertSame($expected, (new Validator(false))->cleanName($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'plain' => ['Example News', 'Example News'],
            'CRLF' => ["Evil\r\nBcc: x@example.net", 'Evil Bcc: x@example.net'],
            'controls' => ["A\x00B\x07C", 'A B C'],
            'whitespace' => ["  Lots \t of   space  ", 'Lots of space'],
            'empty' => ["  \r\n ", ''],
            'long' => [str_repeat('x', 300), str_repeat('x', 255)],
            'unicode long' => [str_repeat("\u{e9}", 300), str_repeat("\u{e9}", 255)],
        ];
    }
}
