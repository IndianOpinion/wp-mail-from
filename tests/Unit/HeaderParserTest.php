<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Tests\Unit;

use IndianOpinion\MailFrom\HeaderParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeaderParserTest extends TestCase
{
    private HeaderParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HeaderParser();
    }

    /** T9 (C9): CRLF string headers. */
    public function testCrlfString(): void
    {
        $parsed = $this->parser->parse("Content-Type: text/html\r\nFrom: Shop <orders@example.org>\r\nX-Test: 1");
        $this->assertSame('orders@example.org', $parsed->fromAddress);
        $this->assertSame('Shop', $parsed->fromName);
    }

    /** T10 (C9): LF-only string headers. */
    public function testLfString(): void
    {
        $parsed = $this->parser->parse("X-Test: 1\nFrom: orders@example.org\n");
        $this->assertSame('orders@example.org', $parsed->fromAddress);
        $this->assertNull($parsed->fromName);
    }

    /** T11 (C10): array headers. */
    public function testArray(): void
    {
        $parsed = $this->parser->parse(['Content-Type: text/plain', 'From: Shop <orders@example.org>']);
        $this->assertSame('orders@example.org', $parsed->fromAddress);
    }

    /** T12 (C11): header name case variants and spacing. */
    #[DataProvider('caseVariants')]
    public function testNameCase(string $line): void
    {
        $this->assertSame('a@example.org', $this->parser->parse([$line])->fromAddress);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function caseVariants(): array
    {
        return [
            'title' => ['From: a@example.org'],
            'lower' => ['from: a@example.org'],
            'upper' => ['FROM: a@example.org'],
            'no space' => ['From:a@example.org'],
            'tabs' => ["From:\t  a@example.org  "],
            'leading space' => ['  From : a@example.org'],
        ];
    }

    /** T13 (C12): each From value shape. */
    #[DataProvider('shapes')]
    public function testShapes(string $value, string $address, ?string $name): void
    {
        $parsed = $this->parser->parse('From: ' . $value);
        $this->assertSame($address, $parsed->fromAddress);
        $this->assertSame($name, $parsed->fromName);
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function shapes(): array
    {
        return [
            'name and address' => ['Shop Team <orders@example.org>', 'orders@example.org', 'Shop Team'],
            'quoted name with comma' => ['"Smith, Jane" <jane@example.org>', 'jane@example.org', 'Smith, Jane'],
            'bare' => ['jane@example.org', 'jane@example.org', null],
            'angle only' => ['<jane@example.org>', 'jane@example.org', null],
            'padded' => ['   Jane   <  jane@example.org  >  ', 'jane@example.org', 'Jane'],
        ];
    }

    /** T14 (C13): the last of several From headers wins. */
    public function testLastFromWins(): void
    {
        $parsed = $this->parser->parse(['From: First <first@example.org>', 'From: second@example.org']);
        $this->assertSame('second@example.org', $parsed->fromAddress);
        // Core keeps the name of the earlier header when the later one has none.
        $this->assertSame('First', $parsed->fromName);
    }

    /** T15 (C14): a string-keyed element without a colon is not a header. */
    public function testStringKeyNotAHeader(): void
    {
        $this->assertFalse($this->parser->parse(['From' => 'a@example.org'])->hasFrom());
        $this->assertSame('a@example.org', $this->parser->parse(['x' => 'From: a@example.org'])->fromAddress);
    }

    /** T16 (C15): lines without a colon are ignored. */
    public function testLinesWithoutColon(): void
    {
        $parsed = $this->parser->parse("From a@example.org\n boundary=\"abc\"\n folded continuation");
        $this->assertFalse($parsed->hasFrom());
    }

    /** T17 (C16): empty string, empty array and null. */
    public function testEmpty(): void
    {
        foreach (['', [], null, 0] as $empty) {
            $this->assertFalse($this->parser->parse($empty)->hasFrom());
        }
        $this->assertFalse($this->parser->parse('From:   ')->hasFrom(), 'an empty value sets nothing');
    }

    /** T20 (C19): Reply-To with several addresses and several headers. */
    public function testReplyTo(): void
    {
        $parsed = $this->parser->parse([
            'Reply-To: a@example.org, "B" <b@example.org>',
            'reply-to: c@example.org',
        ]);
        $this->assertSame(['a@example.org', '"B" <b@example.org>', 'c@example.org'], $parsed->replyTo);
        $this->assertTrue($parsed->hasReplyTo());
        $this->assertFalse($this->parser->parse('From: x@example.org')->hasReplyTo());
    }
}
