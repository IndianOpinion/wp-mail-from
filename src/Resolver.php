<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * Resolves the configured identity for the current site (C1 to C8, C38 to
 * C42, C52 to C56). The result is cached per site for the request.
 */
final class Resolver
{
    /** @var array<int, Identity> */
    private array $cache = [];

    public function __construct(
        private readonly Options $options,
        private readonly Validator $validator,
        private readonly Logger $logger
    ) {
    }

    /**
     * Drop cached identities (C8). Hooked to changes of the plugin's own
     * options and of the core options the derived default reads.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Option-change hook callback. Flushes when the option matters.
     */
    public function onOptionChange(string $option): void
    {
        if (in_array($option, [Options::SITE_OPTION, 'admin_email', 'blogname', 'home'], true)) {
            $this->flush();
        }
    }

    public function resolve(): Identity
    {
        $siteId = (int) get_current_blog_id();
        if (isset($this->cache[$siteId])) {
            return $this->cache[$siteId];
        }

        $warnings = [];
        $siteDomain = Domains::siteDomain($this->validator);
        $context = ['site_id' => $siteId];

        [$from, $fromSource] = $this->resolveAddress('from', $context, $warnings, $siteDomain, true);
        if ($from === null) {
            $this->logger->once(
                'no-identity-' . $siteId,
                'error',
                sprintf(
                    'no valid From address for site %d (domain "%s"); core values are kept. Set WPMF_FROM.',
                    $siteId,
                    $siteDomain
                )
            );
        }
        [$name, $nameSource] = $this->resolveName($context, $siteDomain);
        [$replyTo, $replyToSource] = $this->resolveAddress('reply_to', $context, $warnings, $siteDomain, false);
        [$returnPath, $returnPathSource] = $this->resolveAddress(
            'return_path',
            $context,
            $warnings,
            $siteDomain,
            false
        );

        [$explicitMode, $explicitModeSource] = $this->resolveChoice('explicit_mode', Options::EXPLICIT_MODES);
        [$senderMode, $senderModeSource] = $this->resolveChoice('sender_mode', Options::SENDER_MODES);
        [$smtpMode, $smtpModeSource] = $this->resolveChoice('smtp_mode', Options::SMTP_MODES);
        [$dryRun, $dryRunSource] = $this->resolveBool('dry_run');
        [$logFailures, $logFailuresSource] = $this->resolveBool('log_failures');

        return $this->cache[$siteId] = new Identity(
            $siteId,
            $siteDomain,
            Domains::coreDefaultAddress(),
            $from,
            $fromSource,
            $name,
            $nameSource,
            $replyTo,
            $replyToSource,
            $returnPath,
            $returnPathSource,
            $explicitMode,
            $explicitModeSource,
            $senderMode,
            $senderModeSource,
            $smtpMode,
            $smtpModeSource,
            $dryRun,
            $dryRunSource,
            $logFailures,
            $logFailuresSource,
            $warnings
        );
    }

    /**
     * The derived default address (C4): the admin email when it is valid and
     * aligned with the site domain, else no-reply at the site domain.
     */
    public function derivedAddress(string $siteDomain): string
    {
        $admin = get_option('admin_email', '');
        if (is_string($admin) && $admin !== '') {
            $result = $this->validator->check($admin);
            if ($result->valid && Domains::aligned(Domains::domainOf($result->address), $siteDomain)) {
                return $result->address;
            }
        }

        return $siteDomain === '' ? '' : 'no-reply@' . $siteDomain;
    }

    /**
     * The derived default name (C5): the site title with entities decoded,
     * or the site domain when the title is empty.
     */
    public function derivedName(string $siteDomain): string
    {
        $title = get_bloginfo('name');
        $title = html_entity_decode(is_string($title) ? $title : '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = $this->validator->cleanName($title);

        return $title !== '' ? $title : $siteDomain;
    }

    /**
     * @param array{site_id: int}                                                          $context
     * @param list<array{field: string, source: string, value: string, reason: string}> $warnings
     * @return array{0: ?string, 1: string} Address (null when none) and its source.
     */
    private function resolveAddress(
        string $field,
        array $context,
        array &$warnings,
        string $siteDomain,
        bool $derive
    ): array {
        $address = null;
        $source = 'none';

        foreach ($this->options->sources($field) as [$sourceName, $raw]) {
            $value = is_scalar($raw) ? (string) $raw : '';
            $result = $this->validator->validate($value);
            if ($result->valid) {
                $address = $result->address;
                $source = $sourceName;
                break;
            }
            $this->reject($field, $sourceName, $value, $result->reason, $context['site_id'], $warnings);
        }

        if ($address === null && $derive) {
            $derived = apply_filters(
                'wpmf_derived_address',
                $this->derivedAddress($siteDomain),
                $context + ['source' => 'derived']
            );
            $derived = is_string($derived) ? $derived : '';
            $result = $this->validator->validate($derived);
            if ($result->valid) {
                $address = $result->address;
                $source = 'derived';
            } else {
                $this->reject($field, 'derived', $derived, $result->reason, $context['site_id'], $warnings);
            }
        }

        $filterName = [
            'from' => 'wpmf_from_address',
            'reply_to' => 'wpmf_reply_to',
            'return_path' => 'wpmf_return_path',
        ][$field] ?? 'wpmf_' . $field;

        /**
         * Filters a resolved address. Documented in README, Hooks.
         *
         * @param string                            $address Resolved address, or '' when none.
         * @param array{site_id: int, source: string} $context
         */
        $filtered = apply_filters($filterName, $address ?? '', $context + ['source' => $source]);
        $filtered = is_string($filtered) ? trim($filtered) : '';
        if ($filtered !== ($address ?? '')) {
            if ($filtered === '' && $field !== 'from') {
                return [null, 'filter'];
            }
            $result = $this->validator->validate($filtered);
            if ($result->valid) {
                return [$result->address, 'filter'];
            }
            $this->reject($field, 'filter', $filtered, $result->reason, $context['site_id'], $warnings);
        }

        return [$address, $source];
    }

    /**
     * @param array{site_id: int} $context
     * @return array{0: string, 1: string}
     */
    private function resolveName(array $context, string $siteDomain): array
    {
        $name = '';
        $source = 'none';
        foreach ($this->options->sources('from_name') as [$sourceName, $raw]) {
            $clean = $this->validator->cleanName(is_scalar($raw) ? (string) $raw : '');
            if ($clean !== '') {
                $name = $clean;
                $source = $sourceName;
                break;
            }
        }
        if ($name === '') {
            $derived = apply_filters(
                'wpmf_derived_name',
                $this->derivedName($siteDomain),
                $context + ['source' => 'derived']
            );
            $name = $this->validator->cleanName(is_string($derived) ? $derived : '');
            $source = 'derived';
        }

        /**
         * Filters the resolved From name.
         *
         * @param string                              $name
         * @param array{site_id: int, source: string} $context
         */
        $filtered = apply_filters('wpmf_from_name', $name, $context + ['source' => $source]);
        $filtered = $this->validator->cleanName(is_string($filtered) ? $filtered : '');
        if ($filtered !== '' && $filtered !== $name) {
            return [$filtered, 'filter'];
        }

        return [$name, $source];
    }

    /**
     * @param list<string> $allowed
     * @return array{0: string, 1: string}
     */
    private function resolveChoice(string $field, array $allowed): array
    {
        foreach ($this->options->sources($field) as [$sourceName, $raw]) {
            $value = is_string($raw) ? strtolower(trim($raw)) : '';
            if (in_array($value, $allowed, true)) {
                return [$value, $sourceName];
            }
            $this->logger->once(
                'mode-' . $field . '-' . $sourceName,
                'warning',
                sprintf(
                    'unknown %s "%s" from %s; using "%s"',
                    $field,
                    is_scalar($raw) ? (string) $raw : gettype($raw),
                    $sourceName,
                    (string) Options::DEFAULTS[$field]
                )
            );

            return [(string) Options::DEFAULTS[$field], 'default'];
        }

        return [(string) Options::DEFAULTS[$field], 'default'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function resolveBool(string $field): array
    {
        foreach ($this->options->sources($field) as [$sourceName, $raw]) {
            $value = Options::toBool($raw);
            if ($value !== null) {
                return [$value, $sourceName];
            }
        }

        return [(bool) Options::DEFAULTS[$field], 'default'];
    }

    /**
     * Record and report one rejected address (C40): one warning per source
     * per request, the invalid-address action, and an entry for the admin
     * notice and the CLI.
     *
     * @param list<array{field: string, source: string, value: string, reason: string}> $warnings
     */
    private function reject(
        string $field,
        string $source,
        string $value,
        string $reason,
        int $siteId,
        array &$warnings
    ): void {
        $warnings[] = ['field' => $field, 'source' => $source, 'value' => $value, 'reason' => $reason];
        $this->logger->once(
            'invalid-' . $siteId . '-' . $field . '-' . $source . '-' . md5($value),
            'warning',
            sprintf(
                'invalid %s from %s on site %d (%s): "%s"; trying the next source',
                $field,
                $source,
                $siteId,
                $reason,
                str_replace(["\r", "\n"], ['\r', '\n'], $value)
            )
        );

        /**
         * Fires when a configured address is rejected.
         *
         * @param string $source constant, site-option, network-option, derived or filter.
         * @param string $value  The rejected value.
         * @param string $reason Machine-readable reason.
         * @param string $field  from, reply_to or return_path.
         */
        do_action('wpmf_invalid_address', $source, $value, $reason, $field);
    }
}
