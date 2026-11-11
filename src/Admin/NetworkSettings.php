<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Admin;

use IndianOpinion\MailFrom\Options;
use IndianOpinion\MailFrom\Settings;

/**
 * Network defaults, locks and allowed domains on Network Settings (C52,
 * C54, C44). Core's network/settings.php checks `manage_network_options`
 * and the `siteoptions` nonce before firing `update_wpmu_options`; this
 * class checks both again.
 */
final class NetworkSettings
{
    public const ERRORS_META = 'wpmf_network_errors';

    private const ADDRESS_FIELDS = [
        'from' => 'Mail From address',
        'from_name' => 'Mail From name',
        'reply_to' => 'Reply-To address',
        'return_path' => 'Envelope sender (return path)',
    ];

    public function __construct(
        private readonly Options $options,
        private readonly Settings $settings
    ) {
    }

    public function render(): void
    {
        $stored = $this->options->networkSettings();
        $locked = $this->options->lockedFields();

        echo '<h2>' . esc_html__('Mail From', 'wp-mail-from') . '</h2>';
        echo '<p>' . esc_html__(
            'Network defaults. A site can override any field that is not locked. Constants in wp-config.php override both.',
            'wp-mail-from'
        ) . '</p>';
        echo '<input type="hidden" name="wpmf_network[locked_present]" value="1" />';
        echo '<table class="form-table" role="presentation">';

        foreach (self::ADDRESS_FIELDS as $field => $label) {
            $value = $stored[$field] ?? '';
            echo '<tr><th scope="row"><label for="wpmf_network_' . esc_attr($field) . '">'
                . esc_html($label) . '</label></th><td>';
            printf(
                '<input type="text" class="regular-text" id="wpmf_network_%1$s" name="wpmf_network[%1$s]" value="%2$s"%3$s /> ',
                esc_attr($field),
                esc_attr(is_scalar($value) ? (string) $value : ''),
                $this->options->hasConstant($field) ? ' readonly="readonly"' : ''
            );
            printf(
                '<label><input type="checkbox" name="wpmf_network[locked][]" value="%1$s"%2$s /> %3$s</label>',
                esc_attr($field),
                in_array($field, $locked, true) ? ' checked="checked"' : '',
                esc_html__('Lock on every site', 'wp-mail-from')
            );
            if ($this->options->hasConstant($field)) {
                echo '<p class="description">' . esc_html__('Set in wp-config.php.', 'wp-mail-from') . '</p>';
            }
            echo '</td></tr>';
        }

        $modes = [
            'explicit_mode' => [__('Mail that sets its own From', 'wp-mail-from'), Options::EXPLICIT_MODES],
            'sender_mode' => [__('Envelope sender', 'wp-mail-from'), Options::SENDER_MODES],
            'smtp_mode' => [__('When an SMTP plugin is active', 'wp-mail-from'), Options::SMTP_MODES],
        ];
        foreach ($modes as $field => [$label, $choices]) {
            $current = $stored[$field] ?? '';
            echo '<tr><th scope="row"><label for="wpmf_network_' . esc_attr($field) . '">'
                . esc_html($label) . '</label></th><td>';
            echo '<select id="wpmf_network_' . esc_attr($field) . '" name="wpmf_network[' . esc_attr($field) . ']">';
            echo '<option value="">' . esc_html__('Default', 'wp-mail-from') . '</option>';
            foreach ($choices as $choice) {
                printf(
                    '<option value="%1$s"%2$s>%1$s</option>',
                    esc_attr($choice),
                    $current === $choice ? ' selected="selected"' : ''
                );
            }
            echo '</select> ';
            printf(
                '<label><input type="checkbox" name="wpmf_network[locked][]" value="%1$s"%2$s /> %3$s</label>',
                esc_attr($field),
                in_array($field, $locked, true) ? ' checked="checked"' : '',
                esc_html__('Lock on every site', 'wp-mail-from')
            );
            echo '</td></tr>';
        }

        $domains = $stored['allowed_domains'] ?? [];
        echo '<tr><th scope="row"><label for="wpmf_network_allowed_domains">'
            . esc_html__('Extra domains site admins may send from', 'wp-mail-from') . '</label></th><td>';
        printf(
            '<textarea id="wpmf_network_allowed_domains" name="wpmf_network[allowed_domains]" rows="3" class="large-text code">%s</textarea>',
            esc_textarea(implode("\n", is_array($domains) ? array_map('strval', $domains) : []))
        );
        echo '<p class="description">' . esc_html__(
            'One per line. Site administrators may always use their own site domain; super admins may use any domain.',
            'wp-mail-from'
        ) . '</p></td></tr></table>';
    }

    /**
     * Save handler for `update_wpmu_options`.
     *
     * @param array<string, mixed>|null $input Raw input; read from the request when null.
     */
    public function save(?array $input = null): void
    {
        if (!current_user_can('manage_network_options')) {
            return;
        }
        check_admin_referer('siteoptions');

        if ($input === null) {
            // Nonce verified above; values are validated field by field below.
            $raw = isset($_POST['wpmf_network']) ? wp_unslash($_POST['wpmf_network']) : null;
            if (!is_array($raw)) {
                return;
            }
            $input = $raw;
        }
        if (isset($input['locked_present']) && !isset($input['locked'])) {
            $input['locked'] = [];
        }
        unset($input['locked_present']);

        $result = $this->settings->sanitizeNetworkOption($input);
        update_site_option(Options::NETWORK_OPTION, $result['settings']);
        if ($result['errors'] !== []) {
            update_user_meta(get_current_user_id(), self::ERRORS_META, $result['errors']);
        }
    }

    /**
     * Show and clear the errors of the last network save.
     */
    public function renderErrors(): void
    {
        if (!current_user_can('manage_network_options')) {
            return;
        }
        $userId = get_current_user_id();
        $errors = get_user_meta($userId, self::ERRORS_META, true);
        if (!is_array($errors) || $errors === []) {
            return;
        }
        delete_user_meta($userId, self::ERRORS_META);
        foreach ($errors as $error) {
            printf(
                '<div class="notice notice-error"><p>%s %s</p></div>',
                esc_html__('Mail From: not saved:', 'wp-mail-from'),
                esc_html((string) $error)
            );
        }
    }
}
