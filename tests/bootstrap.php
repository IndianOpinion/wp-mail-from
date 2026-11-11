<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fake-wordpress/');
define('WPINC', 'wp-includes');
define('WP_CLI', true);

require __DIR__ . '/stubs/WP.php';
require __DIR__ . '/stubs/functions.php';
require __DIR__ . '/stubs/PHPMailer.php';
require __DIR__ . '/stubs/wp-mail.php';
require __DIR__ . '/stubs/wp-cli.php';
require dirname(__DIR__) . '/vendor/autoload.php';

WP::reset();
