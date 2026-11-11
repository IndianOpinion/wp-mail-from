<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * Reads From and Reply-To from the headers given to wp_mail() exactly as
 * core's wp_mail() does (cases C9 to C19).
 *
 * Core quirks kept on purpose, because the point is to predict what core
 * will hand to the `wp_mail_from` filter:
 *
 * - String headers are split on LF after CRLF is turned into LF.
 * - Array headers are read by value only; a string key is ignored.
 * - A line without a colon is skipped.
 * - With several From headers, the last one that yields an address sets the
 *   address, and the last one that carries a name before `<` sets the name.
 */
final class HeaderParser
{
    /**
     * @param mixed $headers The `headers` value of the wp_mail() arguments.
     */
    public function parse(mixed $headers): ParsedHeaders
    {
        if (empty($headers)) {
            return new ParsedHeaders();
        }
        if (is_array($headers)) {
            $lines = array_values($headers);
        } elseif (is_string($headers)) {
            $lines = explode("\n", str_replace("\r\n", "\n", $headers));
        } else {
            return new ParsedHeaders();
        }

        $fromAddress = null;
        $fromName = null;
        $replyTo = [];

        foreach ($lines as $line) {
            if (!is_string($line) || !str_contains($line, ':')) {
                continue;
            }
            [$name, $content] = explode(':', trim($line), 2);
            $name = strtolower(trim($name));
            $content = trim($content);

            if ($name === 'from') {
                $bracket = strpos($content, '<');
                if ($bracket !== false) {
                    if ($bracket > 0) {
                        $fromName = trim(str_replace('"', '', substr($content, 0, $bracket)));
                    }
                    $fromAddress = trim(str_replace('>', '', substr($content, $bracket + 1)));
                } elseif ($content !== '') {
                    $fromAddress = $content;
                }
            } elseif ($name === 'reply-to') {
                foreach (explode(',', $content) as $entry) {
                    $entry = trim($entry);
                    if ($entry !== '') {
                        $replyTo[] = $entry;
                    }
                }
            }
        }

        return new ParsedHeaders($fromAddress, $fromName, $replyTo);
    }
}
