<?php

/**
 * Plugin Name: Mail From (must-use loader)
 * Description: Loads wp-mail-from from the wp-mail-from directory next to this file. WordPress does not load subdirectories of mu-plugins on its own.
 * Version:     0.1.0
 * License:     MIT
 *
 * Copy this file into wp-content/mu-plugins/ and the plugin directory into
 * wp-content/mu-plugins/wp-mail-from/.
 *
 * @package IndianOpinion\MailFrom
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    return;
}

$wpmf_main = __DIR__ . '/wp-mail-from/wp-mail-from.php';
if (is_file($wpmf_main)) {
    require_once $wpmf_main;
} else {
    error_log('[wp-mail-from] error: loader cannot find ' . $wpmf_main);
}
unset($wpmf_main);
