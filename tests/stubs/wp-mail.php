<?php

declare(strict_types=1);

/**
 * A model of core's wp_mail() flow from WordPress 6.4 onwards: the same
 * filters and actions in the same order, the same header parsing, a reused
 * global PHPMailer, setFrom() with $auto false. Written for these tests;
 * attachments, CC, BCC and content type are left out.
 *
 * @param string|list<string> $to
 * @param string|array<int|string, mixed> $headers
 */
function wp_mail(string|array $to, string $subject, string $message, string|array $headers = '', array $attachments = []): bool
{
    $atts = apply_filters('wp_mail', compact('to', 'subject', 'message', 'headers', 'attachments'));
    $pre = apply_filters('pre_wp_mail', null, $atts);
    if ($pre !== null) {
        return (bool) $pre;
    }
    $to = $atts['to'] ?? $to;
    $headers = $atts['headers'] ?? $headers;
    $to = is_array($to) ? $to : explode(',', $to);

    global $phpmailer;
    if (!($phpmailer instanceof PHPMailer\PHPMailer\PHPMailer)) {
        $phpmailer = new PHPMailer\PHPMailer\PHPMailer(true);
    }

    $replyTo = [];
    $custom = [];
    if (!empty($headers)) {
        $lines = is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", $headers));
        foreach ($lines as $line) {
            if (!is_string($line) || !str_contains($line, ':')) {
                continue;
            }
            [$name, $content] = explode(':', trim($line), 2);
            $name = trim($name);
            $content = trim($content);
            switch (strtolower($name)) {
                case 'from':
                    $pos = strpos($content, '<');
                    if ($pos !== false) {
                        if ($pos > 0) {
                            $fromName = trim(str_replace('"', '', substr($content, 0, $pos)));
                        }
                        $fromEmail = trim(str_replace('>', '', substr($content, $pos + 1)));
                    } elseif ($content !== '') {
                        $fromEmail = $content;
                    }
                    break;
                case 'reply-to':
                    $replyTo = array_merge($replyTo, explode(',', $content));
                    break;
                default:
                    $custom[$name] = $content;
            }
        }
    }

    $phpmailer->clearAllRecipients();
    $phpmailer->clearCustomHeaders();
    $phpmailer->clearReplyTos();

    $fromName ??= 'WordPress';
    if (!isset($fromEmail)) {
        $host = wp_parse_url(network_home_url(), PHP_URL_HOST);
        $fromEmail = 'wordpress@';
        if ($host !== null) {
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }
            $fromEmail .= $host;
        }
    }
    $fromEmail = apply_filters('wp_mail_from', $fromEmail);
    $fromName = apply_filters('wp_mail_from_name', $fromName);

    $data = compact('to', 'subject', 'message', 'headers', 'attachments');
    try {
        $phpmailer->setFrom($fromEmail, $fromName, false);
    } catch (PHPMailer\PHPMailer\Exception $e) {
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', $e->getMessage(), $data));

        return false;
    }

    foreach ($to as $address) {
        try {
            $phpmailer->addAddress(trim($address));
        } catch (PHPMailer\PHPMailer\Exception $e) {
            continue;
        }
    }
    foreach ($replyTo as $address) {
        $name = '';
        if (preg_match('/(.*)<(.+)>/', $address, $m) === 1) {
            $name = $m[1];
            $address = $m[2];
        }
        try {
            $phpmailer->addReplyTo(trim($address), trim($name));
        } catch (PHPMailer\PHPMailer\Exception $e) {
            continue;
        }
    }
    foreach ($custom as $name => $content) {
        $phpmailer->addCustomHeader($name . ': ' . $content);
    }

    do_action_ref_array('phpmailer_init', [&$phpmailer]);

    try {
        $sent = $phpmailer->send();
        do_action('wp_mail_succeeded', $data);

        return $sent;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', $e->getMessage(), $data));

        return false;
    }
}
