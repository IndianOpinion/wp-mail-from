<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * Validation of stored settings (C43, C44). Used by the settings screens
 * and by `wp mail-from set`, so every path that writes an option applies
 * the same rules.
 */
final class Settings
{
    /** @var array<string, true> */
    private array $reported = [];

    /**
     * @param bool|null $cli Whether this is a WP-CLI run; null detects it.
     */
    public function __construct(
        private readonly Options $options,
        private readonly Validator $validator,
        private readonly ?bool $cli = null
    ) {
    }

    /**
     * Validate one field value.
     *
     * @param bool $restrictDomain Apply the multisite rule (C44): the address must be on the
     *                             site domain or an allowed domain.
     * @return array{ok: bool, value: mixed, error: string}
     */
    public function sanitizeField(string $field, mixed $raw, bool $restrictDomain = false, int $siteId = 0): array
    {
        if (in_array($field, Options::ADDRESS_FIELDS, true)) {
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            if ($value === '') {
                return ['ok' => true, 'value' => '', 'error' => ''];
            }
            $result = $this->validator->validate($value);
            if (!$result->valid) {
                return [
                    'ok' => false,
                    'value' => null,
                    /* translators: 1: the rejected value, 2: machine-readable reason. */
                    'error' => sprintf(__('"%1$s" is not a usable address (%2$s).', 'wp-mail-from'), $value, $result->reason),
                ];
            }
            if ($restrictDomain && !$this->domainAllowed($result->address, $siteId)) {
                return [
                    'ok' => false,
                    'value' => null,
                    /* translators: %s: the rejected address. */
                    'error' => sprintf(__('"%s" is not on this site\'s domain or a domain the network allows.', 'wp-mail-from'), $value),
                ];
            }

            return ['ok' => true, 'value' => $result->address, 'error' => ''];
        }

        if ($field === 'from_name') {
            return ['ok' => true, 'value' => $this->validator->cleanName(is_scalar($raw) ? (string) $raw : ''), 'error' => ''];
        }

        $choices = [
            'explicit_mode' => Options::EXPLICIT_MODES,
            'sender_mode' => Options::SENDER_MODES,
            'smtp_mode' => Options::SMTP_MODES,
        ];
        if (isset($choices[$field])) {
            $value = is_string($raw) ? strtolower(trim($raw)) : '';
            if ($value === '' || in_array($value, $choices[$field], true)) {
                return ['ok' => true, 'value' => $value, 'error' => ''];
            }

            return [
                'ok' => false,
                'value' => null,
                /* translators: 1: field, 2: allowed values. */
                'error' => sprintf(__('%1$s must be one of: %2$s.', 'wp-mail-from'), $field, implode(', ', $choices[$field])),
            ];
        }

        if ($field === 'dry_run' || $field === 'log_failures') {
            if ($raw === '' || $raw === null) {
                return ['ok' => true, 'value' => '', 'error' => ''];
            }
            $bool = Options::toBool($raw);
            if ($bool === null) {
                return [
                    'ok' => false,
                    'value' => null,
                    /* translators: %s: field. */
                    'error' => sprintf(__('%s must be true or false.', 'wp-mail-from'), $field),
                ];
            }

            return ['ok' => true, 'value' => $bool, 'error' => ''];
        }

        return [
            'ok' => false,
            'value' => null,
            /* translators: %s: field. */
            'error' => sprintf(__('Unknown field %s.', 'wp-mail-from'), $field),
        ];
    }

    /**
     * Sanitize callback for the per-site option (register_setting). Fields
     * not in the input keep their stored value. A field set by constant or
     * locked by the network keeps its stored value (C7, C54). An invalid
     * value is not stored and the reason is reported (C43).
     *
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitizeSiteOption(mixed $input): array
    {
        $stored = $this->options->siteSettings();
        if (!is_array($input)) {
            return $stored;
        }
        $restrict = $this->mustRestrictDomain();
        $siteId = (int) get_current_blog_id();
        $out = $stored;

        foreach (Options::LOCKABLE_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($this->options->hasConstant($field) || (is_multisite() && $this->options->isLocked($field))) {
                continue;
            }
            $result = $this->sanitizeField($field, $input[$field], $restrict, $siteId);
            if ($result['ok']) {
                $out[$field] = $result['value'];
            } else {
                $this->report($field, $result['error']);
            }
        }

        return $out;
    }

    /**
     * Validate network settings input. Returns the settings to store and the
     * errors for fields that were refused.
     *
     * @param array<string, mixed> $input
     * @return array{settings: array<string, mixed>, errors: list<string>}
     */
    public function sanitizeNetworkOption(array $input): array
    {
        $stored = $this->options->networkSettings();
        $errors = [];
        foreach (Options::LOCKABLE_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $result = $this->sanitizeField($field, $input[$field]);
            if ($result['ok']) {
                $stored[$field] = $result['value'];
            } else {
                $errors[] = $result['error'];
            }
        }
        if (array_key_exists('locked', $input)) {
            $locked = is_array($input['locked']) ? array_map('strval', $input['locked']) : [];
            $stored['locked'] = array_values(array_intersect(Options::LOCKABLE_FIELDS, $locked));
        }
        if (array_key_exists('allowed_domains', $input)) {
            $raw = $input['allowed_domains'];
            $list = is_array($raw) ? $raw : preg_split('/[\s,]+/', is_string($raw) ? $raw : '');
            $domains = [];
            foreach (is_array($list) ? $list : [] as $domain) {
                $domain = strtolower(trim((string) $domain));
                if ($domain === '') {
                    continue;
                }
                $check = $this->validator->check('x@' . $domain);
                if ($check->valid) {
                    $domains[] = substr($check->address, 2);
                } else {
                    /* translators: %s: the rejected domain. */
                    $errors[] = sprintf(__('"%s" is not a usable domain.', 'wp-mail-from'), $domain);
                }
            }
            $stored['allowed_domains'] = array_values(array_unique($domains));
        }

        return ['settings' => $stored, 'errors' => $errors];
    }

    /**
     * C44 applies to site administrators who are not super admins, on
     * multisite, outside WP-CLI (an operator with shell access is trusted).
     */
    public function mustRestrictDomain(): bool
    {
        if (!is_multisite() || is_super_admin()) {
            return false;
        }

        return !($this->cli ?? (defined('WP_CLI') && WP_CLI));
    }

    public function domainAllowed(string $address, int $siteId): bool
    {
        $domain = Domains::domainOf($address);
        if (Domains::within($domain, Domains::siteDomain($this->validator))) {
            return true;
        }
        foreach ($this->options->allowedDomains($siteId) as $allowed) {
            if (Domains::within($domain, $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function report(string $field, string $message): void
    {
        // Core can run a sanitize callback twice for one save; report once.
        if (isset($this->reported[$field])) {
            return;
        }
        $this->reported[$field] = true;
        if (function_exists('add_settings_error')) {
            add_settings_error(Options::SITE_OPTION, 'wpmf_' . $field, $message, 'error');
        }
    }
}
