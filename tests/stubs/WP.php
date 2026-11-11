<?php

declare(strict_types=1);

/**
 * In-memory state behind the WordPress function stubs. Reset before every
 * test. This is a small model of WordPress, not a copy of it.
 */
final class WP
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var array<int, array<string, mixed>> options per site */
    public static array $options = [];
    /** @var array<string, mixed> */
    public static array $siteOptions = [];
    /** @var array<int, array<string, mixed>> */
    public static array $userMeta = [];
    public static bool $multisite = false;
    public static int $blogId = 1;
    /** @var list<int> */
    public static array $blogStack = [];
    /** @var array<int, string> */
    public static array $homeUrls = [1 => 'https://example.org'];
    public static string $networkHome = 'https://example.org';
    /** @var array<int, string> */
    public static array $blogNames = [1 => 'Example Site'];
    public static bool $superAdmin = false;
    /** @var array<string, bool> */
    public static array $caps = ['manage_options' => true, 'manage_network_options' => true];
    public static int $userId = 1;
    public static bool $isAdmin = false;
    public static bool $networkAdmin = false;
    /** @var list<array{setting: string, code: string, message: string}> */
    public static array $settingsErrors = [];
    /** @var array<string, array<string, mixed>> */
    public static array $settingsFields = [];
    /** @var array<string, array<string, mixed>> */
    public static array $registeredSettings = [];
    public static bool $nonceValid = true;
    /** @var list<string> */
    public static array $redirects = [];
    /** @var bool|string true, false or 'throw' */
    public static bool|string $sendResult = true;
    /** @var list<array<string, mixed>> */
    public static array $sent = [];

    public static function reset(): void
    {
        self::$hooks = [];
        self::$options = [1 => ['admin_email' => 'admin@example.org', 'active_plugins' => []]];
        self::$siteOptions = [];
        self::$userMeta = [];
        self::$multisite = false;
        self::$blogId = 1;
        self::$blogStack = [];
        self::$homeUrls = [1 => 'https://example.org'];
        self::$networkHome = 'https://example.org';
        self::$blogNames = [1 => 'Example Site'];
        self::$superAdmin = false;
        self::$caps = ['manage_options' => true, 'manage_network_options' => true];
        self::$userId = 1;
        self::$isAdmin = false;
        self::$networkAdmin = false;
        self::$settingsErrors = [];
        self::$settingsFields = [];
        self::$registeredSettings = [];
        self::$nonceValid = true;
        self::$redirects = [];
        self::$sendResult = true;
        self::$sent = [];
        $GLOBALS['phpmailer'] = null;
        $_GET = [];
        $_POST = [];
    }

    public static function addSite(int $id, string $home, string $name, string $adminEmail): void
    {
        self::$homeUrls[$id] = $home;
        self::$blogNames[$id] = $name;
        self::$options[$id] = ['admin_email' => $adminEmail, 'active_plugins' => []];
    }

    /**
     * @return list<callable>
     */
    public static function callbacks(string $tag): array
    {
        if (!isset(self::$hooks[$tag])) {
            return [];
        }
        ksort(self::$hooks[$tag]);
        $out = [];
        foreach (self::$hooks[$tag] as $list) {
            foreach ($list as $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return int|false
     */
    public static function priorityOf(string $tag, callable $callback): int|false
    {
        foreach (self::$hooks[$tag] ?? [] as $priority => $list) {
            foreach ($list as $entry) {
                if ($entry[0] == $callback) {
                    return $priority;
                }
            }
        }

        return false;
    }
}
