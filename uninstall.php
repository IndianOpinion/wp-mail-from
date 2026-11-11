<?php

/**
 * Uninstall: removes the plugin's options and notice user meta.
 *
 * Must-use plugins have no uninstall hook; see README for the WP-CLI line.
 *
 * @package IndianOpinion\MailFrom
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// When a must-use copy is also installed, it still needs the settings.
if (class_exists(\IndianOpinion\MailFrom\Plugin::class, false)) {
    $wpmf_active = \IndianOpinion\MailFrom\Plugin::activeFile();
    if ($wpmf_active !== '' && dirname($wpmf_active) !== __DIR__) {
        return;
    }
}

require_once __DIR__ . '/src/autoload.php';

\IndianOpinion\MailFrom\Uninstaller::run();
