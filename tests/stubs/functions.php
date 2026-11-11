<?php

declare(strict_types=1);

// Hooks.

function add_filter(string $tag, callable $cb, int $priority = 10, int $args = 1): bool
{
    WP::$hooks[$tag][$priority][] = [$cb, $args];

    return true;
}

function add_action(string $tag, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($tag, $cb, $priority, $args);
}

function has_filter(string $tag, mixed $cb = false): bool|int
{
    if ($cb === false) {
        return !empty(WP::$hooks[$tag]);
    }

    return WP::priorityOf($tag, $cb);
}

function remove_all_filters(string $tag): bool
{
    unset(WP::$hooks[$tag]);

    return true;
}

function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
{
    foreach (WP::callbacks($tag) as [$cb, $n]) {
        $value = $cb(...array_slice([$value, ...$args], 0, max(1, $n)));
    }

    return $value;
}

function do_action(string $tag, mixed ...$args): void
{
    foreach (WP::callbacks($tag) as [$cb, $n]) {
        $cb(...array_slice($args, 0, $n));
    }
}

/**
 * @param array<int, mixed> $args
 */
function do_action_ref_array(string $tag, array $args): void
{
    foreach (WP::callbacks($tag) as [$cb, $n]) {
        $cb(...array_slice($args, 0, $n));
    }
}

// Options.

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WP::$options[WP::$blogId] ?? []) ? WP::$options[WP::$blogId][$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    $value = apply_filters('sanitize_option_' . $name, $value, $name, $value);
    $exists = array_key_exists($name, WP::$options[WP::$blogId] ?? []);
    WP::$options[WP::$blogId][$name] = $value;
    do_action($exists ? 'updated_option' : 'added_option', $name, $value, $value);

    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    return update_option($name, $value);
}

function delete_option(string $name): bool
{
    unset(WP::$options[WP::$blogId][$name]);
    do_action('deleted_option', $name);

    return true;
}

function get_site_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WP::$siteOptions) ? WP::$siteOptions[$name] : $default;
}

function update_site_option(string $name, mixed $value): bool
{
    WP::$siteOptions[$name] = $value;
    do_action('update_site_option_' . $name, $name, $value);

    return true;
}

function delete_site_option(string $name): bool
{
    unset(WP::$siteOptions[$name]);
    do_action('delete_site_option_' . $name, $name);

    return true;
}

function register_setting(string $group, string $name, array $args = []): void
{
    WP::$registeredSettings[$name] = ['group' => $group] + $args;
    if (isset($args['sanitize_callback'])) {
        add_filter('sanitize_option_' . $name, $args['sanitize_callback']);
    }
}

function add_settings_field(string $id, string $title, callable $cb, string $page, string $section = 'default', array $args = []): void
{
    WP::$settingsFields[$id] = ['title' => $title, 'callback' => $cb, 'page' => $page, 'section' => $section, 'args' => $args];
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    WP::$settingsErrors[] = ['setting' => $setting, 'code' => $code, 'message' => $message];
}

// Multisite and URLs.

function is_multisite(): bool
{
    return WP::$multisite;
}

function get_current_blog_id(): int
{
    return WP::$blogId;
}

function switch_to_blog(int $id): bool
{
    WP::$blogStack[] = WP::$blogId;
    WP::$blogId = $id;
    do_action('switch_blog', $id);

    return true;
}

function restore_current_blog(): bool
{
    WP::$blogId = array_pop(WP::$blogStack) ?? 1;

    return true;
}

/**
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    return array_keys(WP::$homeUrls);
}

function home_url(string $path = ''): string
{
    return (WP::$homeUrls[WP::$blogId] ?? 'https://example.org') . $path;
}

function network_home_url(string $path = ''): string
{
    return WP::$networkHome . $path;
}

function admin_url(string $path = ''): string
{
    return home_url('/wp-admin/' . $path);
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function get_bloginfo(string $show = ''): string
{
    if ($show === 'name') {
        return WP::$blogNames[WP::$blogId] ?? '';
    }

    return $show === 'charset' ? 'UTF-8' : '';
}

// Users and capabilities.

function is_super_admin(?int $user = null): bool
{
    return WP::$superAdmin;
}

function current_user_can(string $cap, mixed ...$args): bool
{
    return WP::$caps[$cap] ?? false;
}

function get_current_user_id(): int
{
    return WP::$userId;
}

function get_user_meta(int $user, string $key = '', bool $single = false): mixed
{
    return WP::$userMeta[$user][$key] ?? ($single ? '' : []);
}

function update_user_meta(int $user, string $key, mixed $value): bool
{
    WP::$userMeta[$user][$key] = $value;

    return true;
}

function delete_user_meta(int $user, string $key): bool
{
    unset(WP::$userMeta[$user][$key]);

    return true;
}

function delete_metadata(string $type, int $objectId, string $key, mixed $value = '', bool $all = false): bool
{
    foreach (WP::$userMeta as $user => $meta) {
        unset(WP::$userMeta[$user][$key]);
    }

    return true;
}

function is_admin(): bool
{
    return WP::$isAdmin;
}

function is_network_admin(): bool
{
    return WP::$networkAdmin;
}

// Nonces and requests.

function wp_create_nonce(string $action = ''): string
{
    return 'nonce-' . $action;
}

function wp_nonce_url(string $url, string $action = '', string $name = '_wpnonce'): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . $name . '=' . wp_create_nonce($action);
}

function check_admin_referer(string $action = '', string $arg = '_wpnonce'): int
{
    if (!WP::$nonceValid) {
        throw new RuntimeException('nonce check failed for ' . $action);
    }

    return 1;
}

function add_query_arg(mixed ...$args): string
{
    $params = is_array($args[0]) ? $args[0] : [$args[0] => $args[1]];

    return '/wp-admin/options-general.php?' . http_build_query($params);
}

function remove_query_arg(mixed $keys, string $url = ''): string
{
    return '/wp-admin/options-general.php';
}

function wp_safe_redirect(string $location): bool
{
    WP::$redirects[] = $location;

    return true;
}

function wp_unslash(mixed $value): mixed
{
    return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

// Formatting and i18n.

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags);
}

/**
 * A close model of core's is_email(): the checks that matter here.
 */
function is_email(string $email): string|false
{
    if (strlen($email) < 6 || !str_contains($email, '@')) {
        return false;
    }
    [$local, $domain] = explode('@', $email, 2);
    if (preg_match('/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local) !== 1) {
        return false;
    }
    if (preg_match('/\.{2,}/', $domain) === 1 || trim($domain, " \t\n\r\0\x0B.") !== $domain) {
        return false;
    }
    $subs = explode('.', $domain);
    if (count($subs) < 2) {
        return false;
    }
    foreach ($subs as $sub) {
        if (trim($sub, " \t\n\r\0\x0B-") !== $sub || preg_match('/^[a-z0-9-]+$/i', $sub) !== 1) {
            return false;
        }
    }

    return $email;
}

class WP_Error
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public string $code = '', public string $message = '', public array $data = [])
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function __return_true(): bool
{
    return true;
}

function __return_false(): bool
{
    return false;
}
