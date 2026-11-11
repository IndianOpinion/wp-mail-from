<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * Field definitions and raw reads from constants and stored options.
 *
 * Nothing here validates addresses; that is the resolver's job. This class
 * only answers "what does each source say".
 */
final class Options
{
    public const SITE_OPTION = 'wpmf_settings';
    public const NETWORK_OPTION = 'wpmf_network_settings';

    public const EXPLICIT_MODES = ['respect', 'override', 'align'];
    public const SENDER_MODES = ['if-empty', 'always', 'never'];
    public const SMTP_MODES = ['defer', 'stand-aside', 'ignore'];

    /** Fields that hold an address. */
    public const ADDRESS_FIELDS = ['from', 'reply_to', 'return_path'];

    /** Fields a super admin can lock at network level. */
    public const LOCKABLE_FIELDS = [
        'from',
        'from_name',
        'reply_to',
        'return_path',
        'explicit_mode',
        'sender_mode',
        'smtp_mode',
        'dry_run',
        'log_failures',
    ];

    /** Field name to wp-config.php constant. */
    public const CONSTANTS = [
        'from' => 'WPMF_FROM',
        'from_name' => 'WPMF_FROM_NAME',
        'reply_to' => 'WPMF_REPLY_TO',
        'return_path' => 'WPMF_RETURN_PATH',
        'explicit_mode' => 'WPMF_EXPLICIT_MODE',
        'sender_mode' => 'WPMF_SENDER_MODE',
        'smtp_mode' => 'WPMF_SMTP_MODE',
        'dry_run' => 'WPMF_DRY_RUN',
        'log_failures' => 'WPMF_LOG_FAILURES',
    ];

    /** Defaults for the non-address fields. */
    public const DEFAULTS = [
        'explicit_mode' => 'respect',
        'sender_mode' => 'if-empty',
        'smtp_mode' => 'defer',
        'dry_run' => false,
        'log_failures' => true,
    ];

    /** @var callable(string): mixed */
    private $constantReader;

    /**
     * @param (callable(string): mixed)|null $constantReader Returns a constant's value or null when undefined.
     *        Injected by tests; the default reads real constants.
     */
    public function __construct(?callable $constantReader = null)
    {
        $this->constantReader = $constantReader ?? static fn (string $name): mixed
            => defined($name) ? constant($name) : null;
    }

    /**
     * The value of a field's constant, or null when it is undefined or empty
     * (C6: an empty constant counts as not set).
     */
    public function constant(string $field): mixed
    {
        $name = self::CONSTANTS[$field] ?? null;
        if ($name === null) {
            return null;
        }
        $value = ($this->constantReader)($name);

        return self::isBlank($value) ? null : $value;
    }

    public function hasConstant(string $field): bool
    {
        return $this->constant($field) !== null;
    }

    /**
     * Read any constant through the injected reader.
     */
    public function rawConstant(string $name): mixed
    {
        return ($this->constantReader)($name);
    }

    /**
     * The stored per-site settings (current site; follows switch_to_blog()).
     *
     * @return array<string, mixed>
     */
    public function siteSettings(): array
    {
        $value = get_option(self::SITE_OPTION, []);

        return is_array($value) ? $value : [];
    }

    /**
     * The stored network settings. Empty on single site.
     *
     * @return array<string, mixed>
     */
    public function networkSettings(): array
    {
        if (!is_multisite()) {
            return [];
        }
        $value = get_site_option(self::NETWORK_OPTION, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Fields locked at network level (C54). Always empty on single site.
     *
     * @return list<string>
     */
    public function lockedFields(): array
    {
        $locked = $this->networkSettings()['locked'] ?? [];
        if (!is_array($locked)) {
            return [];
        }

        return array_values(array_intersect(self::LOCKABLE_FIELDS, array_map('strval', $locked)));
    }

    public function isLocked(string $field): bool
    {
        return in_array($field, $this->lockedFields(), true);
    }

    /**
     * Domains a site administrator may use besides the site domain (C44).
     *
     * @return list<string>
     */
    public function allowedDomains(int $siteId): array
    {
        $stored = $this->networkSettings()['allowed_domains'] ?? [];
        $domains = [];
        foreach (is_array($stored) ? $stored : [] as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        /**
         * Filters the extra domains a site administrator may send from on a
         * multisite network.
         *
         * @param list<string> $domains Lower-case domains.
         * @param int          $siteId  The site being configured.
         */
        $filtered = apply_filters('wpmf_allowed_domains', $domains, $siteId);

        return is_array($filtered) ? array_values(array_map('strval', $filtered)) : $domains;
    }

    /**
     * The ordered non-derived sources for one field (C1, C52): constant,
     * then the per-site option unless the network locked the field, then the
     * network option. Blank values are skipped.
     *
     * @return list<array{0: string, 1: mixed}> Pairs of source name and raw value.
     */
    public function sources(string $field): array
    {
        $sources = [];
        $constant = $this->constant($field);
        if ($constant !== null) {
            $sources[] = ['constant', $constant];
        }
        $multisite = is_multisite();
        if (!$multisite || !$this->isLocked($field)) {
            $site = $this->siteSettings()[$field] ?? null;
            if (!self::isBlank($site)) {
                $sources[] = ['site-option', $site];
            }
        }
        if ($multisite) {
            $network = $this->networkSettings()[$field] ?? null;
            if (!self::isBlank($network)) {
                $sources[] = ['network-option', $network];
            }
        }

        return $sources;
    }

    /**
     * Interpret a stored or constant value as a boolean.
     */
    public static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }

    public static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || (is_string($value) && trim($value) === '');
    }
}
