<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Admin;

use IndianOpinion\MailFrom\Options;
use IndianOpinion\MailFrom\Resolver;
use IndianOpinion\MailFrom\Settings;

/**
 * Two fields on Settings, General: From address and From name (C7, C43,
 * C54). Saving goes through core's options.php, which checks the
 * `general-options` nonce and `manage_options` before the sanitize
 * callback runs.
 */
final class GeneralSettings
{
    public function __construct(
        private readonly Options $options,
        private readonly Settings $settings,
        private readonly Resolver $resolver
    ) {
    }

    public function register(): void
    {
        register_setting(
            'general',
            Options::SITE_OPTION,
            [
                'type' => 'object',
                'sanitize_callback' => [$this->settings, 'sanitizeSiteOption'],
                'default' => [],
                'show_in_rest' => false,
            ]
        );
        add_settings_field(
            'wpmf_from',
            __('Mail From address', 'wp-mail-from'),
            [$this, 'renderFrom'],
            'general',
            'default',
            ['label_for' => 'wpmf_from']
        );
        add_settings_field(
            'wpmf_from_name',
            __('Mail From name', 'wp-mail-from'),
            [$this, 'renderFromName'],
            'general',
            'default',
            ['label_for' => 'wpmf_from_name']
        );
    }

    public function renderFrom(): void
    {
        $identity = $this->resolver->resolve();
        $this->renderField(
            'from',
            'wpmf_from',
            'email',
            sprintf(
                /* translators: 1: effective address, 2: where it comes from. */
                __('Mail is sent from %1$s (%2$s). Use a mailbox that exists and is read: bounces go there.', 'wp-mail-from'),
                $identity->from ?? __('the WordPress default', 'wp-mail-from'),
                $identity->fromSource
            )
        );
    }

    public function renderFromName(): void
    {
        $identity = $this->resolver->resolve();
        $this->renderField(
            'from_name',
            'wpmf_from_name',
            'text',
            sprintf(
                /* translators: 1: effective name, 2: where it comes from. */
                __('Mail is sent as "%1$s" (%2$s).', 'wp-mail-from'),
                $identity->fromName,
                $identity->fromNameSource
            )
        );
    }

    /**
     * Whether a field is read-only on this screen, and why.
     */
    public function readOnlyReason(string $field): string
    {
        if ($this->options->hasConstant($field)) {
            return __('Set in wp-config.php.', 'wp-mail-from');
        }
        if (is_multisite() && $this->options->isLocked($field)) {
            return __('Locked by the network administrator.', 'wp-mail-from');
        }

        return '';
    }

    private function renderField(string $field, string $id, string $type, string $description): void
    {
        $reason = $this->readOnlyReason($field);
        if ($reason !== '') {
            $constant = $this->options->constant($field);
            $shown = $constant !== null && is_scalar($constant)
                ? (string) $constant
                : (string) ($this->options->networkSettings()[$field] ?? '');
            printf(
                '<input type="text" id="%1$s" class="regular-text" value="%2$s" readonly="readonly" disabled="disabled" />',
                esc_attr($id),
                esc_attr($shown)
            );
            printf('<p class="description">%s</p>', esc_html($reason . ' ' . $description));

            return;
        }

        $stored = $this->options->siteSettings()[$field] ?? '';
        printf(
            '<input name="%1$s[%2$s]" type="text" id="%3$s" class="regular-text%4$s" value="%5$s"%6$s />',
            esc_attr(Options::SITE_OPTION),
            esc_attr($field),
            esc_attr($id),
            $type === 'email' ? ' ltr' : '',
            esc_attr(is_scalar($stored) ? (string) $stored : ''),
            $type === 'email' ? ' inputmode="email" autocomplete="email"' : ''
        );
        printf('<p class="description">%s</p>', esc_html($description));
    }
}
