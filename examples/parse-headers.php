<?php

/**
 * Shows how wp-mail-from reads From and Reply-To. Runs without WordPress:
 *
 *     php examples/parse-headers.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use IndianOpinion\MailFrom\HeaderParser;

$samples = [
    'string, CRLF' => "Content-Type: text/html\r\nFrom: Shop <orders@example.org>",
    'string, LF, lower case' => "from: orders@example.org\nreply-to: desk@example.org, help@example.org",
    'array, quoted name' => ['From: "Smith, Jane" <jane@example.net>'],
    'two From headers' => ['From: First <first@example.org>', 'From: second@example.org'],
    'string key (ignored, as in core)' => ['From' => 'orders@example.org'],
    'no From' => ['X-Mailer: test'],
];

$parser = new HeaderParser();
foreach ($samples as $label => $headers) {
    $parsed = $parser->parse($headers);
    printf(
        "%-34s from=%-24s name=%-14s reply-to=%s\n",
        $label,
        $parsed->fromAddress ?? '(none)',
        $parsed->fromName ?? '(none)',
        $parsed->replyTo === [] ? '(none)' : implode(', ', $parsed->replyTo)
    );
}
