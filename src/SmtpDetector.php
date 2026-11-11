<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * Detects common SMTP plugins by public markers (C45) and reports whether
 * wp_mail() has been replaced (C49). Never reads another plugin's settings.
 *
 * Detection runs once per request, lazily.
 */
final class SmtpDetector
{
    /**
     * Built-in markers. `slugs` are wordpress.org directory slugs, matched
     * against active plugin paths (`<slug>/...`). `constants`, `classes` and
     * `functions` are public names; any one match is enough. Sources and
     * the date they were checked are in docs/ARCHITECTURE.md.
     *
     * @var list<array{name: string, slugs: list<string>, constants: list<string>, classes: list<string>, functions: list<string>}>
     */
    public const MARKERS = [
        [
            'name' => 'WP Mail SMTP',
            'slugs' => ['wp-mail-smtp', 'wp-mail-smtp-pro'],
            'constants' => ['WPMS_PLUGIN_VER'],
            'classes' => ['WPMailSMTP\\Core'],
            'functions' => ['wp_mail_smtp'],
        ],
        [
            'name' => 'FluentSMTP',
            'slugs' => ['fluent-smtp'],
            'constants' => ['FLUENTMAIL', 'FLUENTMAIL_PLUGIN_VERSION'],
            'classes' => [],
            'functions' => [],
        ],
        [
            'name' => 'Post SMTP',
            'slugs' => ['post-smtp'],
            'constants' => ['POST_SMTP_VER'],
            'classes' => ['PostmanOptions'],
            'functions' => [],
        ],
        [
            'name' => 'Easy WP SMTP',
            'slugs' => ['easy-wp-smtp'],
            'constants' => ['EasyWPSMTP_PLUGIN_VERSION'],
            'classes' => ['EasyWPSMTP\\Core', 'EasyWPSMTP'],
            'functions' => [],
        ],
    ];

    /** @var list<array{name: string, marker: string}>|null */
    private ?array $detected = null;

    /** @var (callable(string): bool)|null */
    private $definedCheck;

    /**
     * @param (callable(string): bool)|null $definedCheck Constant check, injectable for tests.
     */
    public function __construct(?callable $definedCheck = null)
    {
        $this->definedCheck = $definedCheck;
    }

    public function reset(): void
    {
        $this->detected = null;
    }

    /**
     * @return list<array{name: string, marker: string}>
     */
    public function detect(): array
    {
        if ($this->detected !== null) {
            return $this->detected;
        }

        /**
         * Filters the SMTP plugin markers. Add an entry to detect another
         * plugin; each entry has name, slugs, constants, classes, functions.
         *
         * @param list<array<string, mixed>> $markers
         */
        $markers = apply_filters('wpmf_smtp_markers', self::MARKERS);
        $markers = is_array($markers) ? $markers : self::MARKERS;
        $active = $this->activePluginPaths();

        $found = [];
        foreach ($markers as $marker) {
            if (!is_array($marker) || !isset($marker['name']) || !is_string($marker['name'])) {
                continue;
            }
            $hit = $this->match($marker, $active);
            if ($hit !== null) {
                $found[] = ['name' => $marker['name'], 'marker' => $hit];
            }
        }

        /**
         * Filters the detection result. Return an empty array to make the
         * plugin behave as if no SMTP plugin were present.
         *
         * @param list<array{name: string, marker: string}> $found
         */
        $filtered = apply_filters('wpmf_smtp_detection', $found);
        $this->detected = [];
        foreach (is_array($filtered) ? $filtered : $found as $entry) {
            if (is_array($entry) && isset($entry['name'], $entry['marker'])) {
                $this->detected[] = ['name' => (string) $entry['name'], 'marker' => (string) $entry['marker']];
            }
        }

        foreach ($this->detected as $entry) {
            /**
             * Fires once per request for each detected SMTP plugin.
             *
             * @param string $name   Plugin name.
             * @param string $marker What matched, for example "constant:WPMS_PLUGIN_VER".
             */
            do_action('wpmf_smtp_plugin_detected', $entry['name'], $entry['marker']);
        }

        return $this->detected;
    }

    /**
     * The file that defines wp_mail() when it is not core's pluggable.php,
     * or null when core's own wp_mail() is in use or it is not defined yet.
     */
    public function wpMailReplacement(?string $corePluggable = null): ?string
    {
        if (!function_exists('wp_mail')) {
            return null;
        }
        $file = (new \ReflectionFunction('wp_mail'))->getFileName();
        if ($file === false) {
            return null;
        }
        if ($corePluggable === null) {
            if (!defined('ABSPATH') || !defined('WPINC')) {
                return null;
            }
            $corePluggable = ABSPATH . WPINC . '/pluggable.php';
        }

        $normalise = static fn (string $path): string => str_replace('\\', '/', (string) (realpath($path) ?: $path));

        return $normalise($file) === $normalise($corePluggable) ? null : $file;
    }

    /**
     * @param array<string, mixed> $marker
     * @param list<string>         $active
     */
    private function match(array $marker, array $active): ?string
    {
        foreach ($this->stringList($marker['constants'] ?? []) as $constant) {
            $defined = $this->definedCheck !== null ? ($this->definedCheck)($constant) : defined($constant);
            if ($defined) {
                return 'constant:' . $constant;
            }
        }
        foreach ($this->stringList($marker['classes'] ?? []) as $class) {
            if (class_exists($class, false)) {
                return 'class:' . $class;
            }
        }
        foreach ($this->stringList($marker['functions'] ?? []) as $function) {
            if (function_exists($function)) {
                return 'function:' . $function;
            }
        }
        foreach ($this->stringList($marker['slugs'] ?? []) as $slug) {
            foreach ($active as $path) {
                if (str_starts_with($path, $slug . '/')) {
                    return 'plugin:' . $path;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function activePluginPaths(): array
    {
        $paths = [];
        $site = get_option('active_plugins', []);
        foreach (is_array($site) ? $site : [] as $path) {
            $paths[] = (string) $path;
        }
        if (is_multisite()) {
            $network = get_site_option('active_sitewide_plugins', []);
            foreach (is_array($network) ? array_keys($network) : [] as $path) {
                $paths[] = (string) $path;
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
