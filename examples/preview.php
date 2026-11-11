<?php

/**
 * Previews the plugin's decision for a few From headers on this site.
 * Nothing is sent.
 *
 *     wp eval-file examples/preview.php
 *     wp eval-file examples/preview.php --url=shop.example.org
 */

declare(strict_types=1);

use IndianOpinion\MailFrom\Plugin;

$wpmfPlugin = Plugin::instance();
if ($wpmfPlugin === null) {
    WP_CLI::error('wp-mail-from is not loaded on this site.');
}

$wpmfHost = (string) wp_parse_url(home_url(), PHP_URL_HOST);
$wpmfSamples = [
    '(no From header)' => [],
    'core default' => ['From: WordPress <wordpress@' . $wpmfHost . '>'],
    'own domain, no name' => ['From: desk@' . $wpmfHost],
    'visitor' => ['From: Visitor <visitor@example.net>'],
    'visitor with Reply-To' => ['From: visitor@example.net', 'Reply-To: help@example.net'],
    'invalid' => ['From: broken@localhost'],
];

$wpmfRows = [];
foreach ($wpmfSamples as $wpmfLabel => $wpmfHeaders) {
    $wpmfState = $wpmfPlugin->handler->evaluate(['headers' => $wpmfHeaders]);
    $wpmfRows[] = [
        'case' => $wpmfLabel,
        'decision' => $wpmfState->decision->action,
        'from' => $wpmfState->decision->fromAfter,
        'name' => $wpmfState->decision->nameAfter,
        'reply_to_moved' => $wpmfState->decision->moveToReplyTo['address'] ?? '',
    ];
}

WP_CLI\Utils\format_items('table', $wpmfRows, ['case', 'decision', 'from', 'name', 'reply_to_moved']);
