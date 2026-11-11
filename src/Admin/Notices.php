<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Admin;

use IndianOpinion\MailFrom\Report;

/**
 * Dismissible admin notices for invalid configuration, a derived identity,
 * a detected SMTP plugin, a replaced wp_mail() and a duplicate copy.
 *
 * A dismissal is stored per user and per notice key, together with a hash
 * of the message, so the notice comes back when the situation changes.
 */
final class Notices
{
    public const META_PREFIX = 'wpmf_dismissed_';
    public const KEYS = ['invalid', 'no-identity', 'derived', 'smtp', 'wp-mail', 'duplicate'];

    public function __construct(private readonly Report $report)
    {
    }

    /**
     * Every meta key this class may write, for uninstall.
     *
     * @return list<string>
     */
    public static function metaKeys(): array
    {
        return array_map(static fn (string $key): string => self::META_PREFIX . $key, self::KEYS);
    }

    /**
     * @return list<array{key: string, type: string, message: string}>
     */
    public function notices(): array
    {
        $data = $this->report->collect();
        $id = $data['identity'];
        $out = [];

        if (!$id->isValid()) {
            $out[] = [
                'key' => 'no-identity',
                'type' => 'error',
                'message' => sprintf(
                    /* translators: %s: site domain. */
                    __('Mail From: no valid From address for this site (domain "%s"). WordPress defaults are used. Set WPMF_FROM.', 'wp-mail-from'),
                    $id->siteDomain
                ),
            ];
        }
        if ($id->warnings !== []) {
            $parts = [];
            foreach ($id->warnings as $w) {
                $parts[] = sprintf('%s from %s: "%s" (%s)', $w['field'], $w['source'], $w['value'], $w['reason']);
            }
            $out[] = [
                'key' => 'invalid',
                'type' => 'warning',
                'message' => __('Mail From: invalid settings were skipped:', 'wp-mail-from') . ' ' . implode('; ', $parts),
            ];
        }
        if ($id->isNoReply()) {
            $out[] = [
                'key' => 'derived',
                'type' => 'warning',
                'message' => sprintf(
                    /* translators: %s: derived address. */
                    __('Mail From: mail goes out from %s, as the admin email is off-domain. Bounces are lost: set a real, read mailbox.', 'wp-mail-from'),
                    (string) $id->from
                ),
            ];
        } elseif ($id->isDerived()) {
            $out[] = [
                'key' => 'derived',
                'type' => 'info',
                'message' => sprintf(
                    /* translators: %s: derived address. */
                    __('Mail From: mail is sent from %s, taken from the admin email. Set the From address explicitly to keep it stable.', 'wp-mail-from'),
                    (string) $id->from
                ),
            ];
        }
        if ($id->smtpMode !== 'ignore' && $data['smtp'] !== []) {
            $names = implode(', ', array_column($data['smtp'], 'name'));
            $out[] = [
                'key' => 'smtp',
                'type' => 'info',
                'message' => $id->smtpMode === 'stand-aside'
                    /* translators: %s: plugin names. */
                    ? sprintf(__('Mail From: %s detected; this plugin stands aside and changes nothing.', 'wp-mail-from'), $names)
                    /* translators: %s: plugin names. */
                    : sprintf(__('Mail From: %s detected; it may override the From address set here.', 'wp-mail-from'), $names),
            ];
        }
        if ($data['wp_mail_replaced_by'] !== null) {
            $out[] = [
                'key' => 'wp-mail',
                'type' => 'info',
                'message' => sprintf(
                    /* translators: %s: file path. */
                    __('Mail From: wp_mail() is replaced by %s. Whether it honours the From filters is outside this plugin\'s control.', 'wp-mail-from'),
                    $data['wp_mail_replaced_by']
                ),
            ];
        }
        if ($data['duplicates'] !== []) {
            $out[] = [
                'key' => 'duplicate',
                'type' => 'warning',
                'message' => sprintf(
                    /* translators: 1: active file, 2: ignored files. */
                    __('Mail From: two copies are loaded. Active: %1$s. Ignored: %2$s. Remove one.', 'wp-mail-from'),
                    $data['active_file'],
                    implode(', ', $data['duplicates'])
                ),
            ];
        }

        return $out;
    }

    public function render(): void
    {
        $capability = is_network_admin() ? 'manage_network_options' : 'manage_options';
        if (!current_user_can($capability)) {
            return;
        }
        $userId = get_current_user_id();
        foreach ($this->notices() as $notice) {
            $hash = md5($notice['message']);
            if (get_user_meta($userId, self::META_PREFIX . $notice['key'], true) === $hash) {
                continue;
            }
            $url = wp_nonce_url(
                add_query_arg(['wpmf_dismiss' => $notice['key']]),
                'wpmf_dismiss_' . $notice['key']
            );
            printf(
                '<div class="notice notice-%1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message']),
                esc_url($url),
                esc_html__('Dismiss', 'wp-mail-from')
            );
        }
    }

    /**
     * Handle a dismissal link. Returns the URL to redirect to, or null when
     * the request is not a dismissal. The caller redirects and exits.
     */
    public function handleDismiss(): ?string
    {
        // Nonce is verified below, before anything is stored.
        if (!isset($_GET['wpmf_dismiss'])) {
            return null;
        }
        $key = sanitize_key((string) wp_unslash($_GET['wpmf_dismiss']));
        if (!in_array($key, self::KEYS, true)) {
            return null;
        }
        check_admin_referer('wpmf_dismiss_' . $key);
        $capability = is_network_admin() ? 'manage_network_options' : 'manage_options';
        if (!current_user_can($capability)) {
            return null;
        }
        foreach ($this->notices() as $notice) {
            if ($notice['key'] === $key) {
                update_user_meta(get_current_user_id(), self::META_PREFIX . $key, md5($notice['message']));
            }
        }

        return remove_query_arg(['wpmf_dismiss', '_wpnonce']);
    }
}
