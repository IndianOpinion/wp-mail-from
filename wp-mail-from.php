<?php

/**
 * Plugin Name:       Mail From
 * Plugin URI:        https://github.com/IndianOpinion/wp-mail-from
 * Description:       Sets a real From address, From name and envelope sender on every wp_mail() message. Works as a must-use plugin.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wp-mail-from
 *
 * @package IndianOpinion\MailFrom
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    return;
}

if (class_exists(\IndianOpinion\MailFrom\Plugin::class, false)) {
    // A second copy (for example both in mu-plugins and in plugins): the
    // first one wins and this one only reports itself.
    \IndianOpinion\MailFrom\Plugin::noteDuplicate(__FILE__);
    return;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    // Must-use plugins skip the "Requires PHP" check; fail quietly instead of fatally.
    return;
}

require_once __DIR__ . '/src/autoload.php';

\IndianOpinion\MailFrom\Plugin::boot(__FILE__);
