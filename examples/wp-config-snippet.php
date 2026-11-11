<?php

/**
 * Example wp-config.php lines for wp-mail-from. Copy what you need above
 * the "That's all, stop editing!" line. Every constant is optional.
 */

// A mailbox that exists and is read. Bounces and replies go here.
define('WPMF_FROM', 'mail@example.org');
define('WPMF_FROM_NAME', 'Example News');

// Optional: where replies go when a message has no Reply-To of its own.
// define('WPMF_REPLY_TO', 'desk@example.org');

// Optional: a separate bounce mailbox (envelope sender). Default: the From address.
// define('WPMF_RETURN_PATH', 'bounces@example.org');

// Mail that sets its own From: respect (default), align or override.
// define('WPMF_EXPLICIT_MODE', 'align');

// Envelope sender: if-empty (default), always or never.
// define('WPMF_SENDER_MODE', 'if-empty');

// When an SMTP plugin is active: defer (default), stand-aside or ignore.
// define('WPMF_SMTP_MODE', 'defer');

// Log what would change, change nothing. Turn off once the log looks right.
// define('WPMF_DRY_RUN', true);

// Log every wp_mail() failure with the prefix [wp-mail-from]. On by default.
// define('WPMF_LOG_FAILURES', true);

// Priority of the wp_mail_from filters. Default 5, so SMTP plugins win.
// define('WPMF_FROM_PRIORITY', 5);
