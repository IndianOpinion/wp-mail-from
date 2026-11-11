<?php

/**
 * Plugin Name: Mail From: align contact form mail
 * Description: Uses align mode for messages from one contact form, respect for the rest.
 *
 * The form in this example adds an "X-Form: contact" header. Adjust the test
 * to whatever your form plugin sets (a header, a subject prefix, a recipient).
 */

declare(strict_types=1);

add_filter(
    'wpmf_explicit_mode',
    static function (string $mode, array $atts): string {
        $headers = $atts['headers'] ?? [];
        $lines = is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", (string) $headers));
        foreach ($lines as $line) {
            if (is_string($line) && stripos(trim($line), 'x-form: contact') === 0) {
                return 'align';
            }
        }

        return $mode;
    },
    10,
    2
);
