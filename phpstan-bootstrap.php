<?php

declare(strict_types=1);

/**
 * PHPStan bootstrap — WordPress function/class stubs only.
 * Does not load the Composer autoloader (not required for static analysis).
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
defined('ABSPATH')          || define('ABSPATH', sys_get_temp_dir() . '/');
defined('WP_CONTENT_DIR')   || define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content');
defined('WP_CONTENT_URL')   || define('WP_CONTENT_URL', 'http://localhost/wp-content');
defined('WP_SITEURL')       || define('WP_SITEURL', 'http://localhost');
defined('AUTH_KEY')         || define('AUTH_KEY', 'phpstan_key');
defined('SECURE_AUTH_SALT') || define('SECURE_AUTH_SALT', 'phpstan_salt');
defined('ARRAY_A')          || define('ARRAY_A', 'ARRAY_A');
defined('OBJECT')           || define('OBJECT', 'OBJECT');
// Note: WP_CLI, DOING_AJAX, DOING_CRON deliberately NOT defined here so
// PHPStan does not resolve `defined('WP_CLI') && WP_CLI` as always-false.

// ---------------------------------------------------------------------------
// WordPress stub classes
// ---------------------------------------------------------------------------

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_status = 'publish';
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var array<string,mixed> */
        public array $query_vars = [];
        public int $found_posts = 0;
        public int $max_num_pages = 1;

        public function get(string $var): mixed { return null; }
        public function is_singular(): bool { return false; }
    }
}

if (!class_exists('WP_Admin_Bar')) {
    class WP_Admin_Bar
    {
        /** @param array<string,mixed> $node */
        public function add_menu(array $node): void {}
    }
}

if (!class_exists('WP_CLI')) {
    class WP_CLI
    {
        public static function add_command(string $name, callable $callable): void {}
        public static function success(string $message): void {}
        public static function line(string $message): void {}
    }
}

if (!class_exists('WP_Dependencies')) {
    class WP_Dependency_Stub
    {
        public string $src = '';
        public int|string|null $ver = null;
    }

    class WP_Dependencies
    {
        /** @var array<string,WP_Dependency_Stub> */
        public array $registered = [];
    }
}

if (!class_exists('WP_Styles')) {
    class WP_Styles extends WP_Dependencies
    {
        /** @var string[] */
        public array $queue = [];
    }
}

if (!class_exists('WP_Scripts')) {
    class WP_Scripts extends WP_Dependencies
    {
        /** @var string[] */
        public array $queue = [];
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function get_error_message(string $code = ''): string { return ''; }
    }
}

if (!class_exists('Redis')) {
    class Redis
    {
        public function connect(string $host, int $port, float $timeout = 0): bool { return false; }
        public function auth(string $password): bool { return false; }
        public function select(int $database): bool { return true; }
        public function get(string $key): string|false { return false; }
        public function setEx(string $key, int $ttl, string $value): bool { return true; }
        public function set(string $key, mixed $value, mixed ...$options): bool|string { return true; }
        public function del(string $key): int { return 1; }
        public function flushDB(): bool { return true; }
        public function close(): bool { return true; }
    }
}

if (!class_exists('Memcached')) {
    class Memcached
    {
        public const RES_SUCCESS = 0;
        public const RES_NOTFOUND = 16;
        public function addServer(string $host, int $port): bool { return true; }
        public function set(string $key, mixed $value, int $expiration = 0): bool { return true; }
        public function get(string $key): mixed { return false; }
        public function getResultCode(): int { return self::RES_NOTFOUND; }
        public function delete(string $key): bool { return true; }
        public function flush(): bool { return true; }
        public function add(string $key, mixed $value, int $expiration = 0): bool { return true; }
        public function close(): bool { return true; }
    }
}

if (!class_exists('Memcache')) {
    class Memcache
    {
        public function connect(string $host, int $port): bool { return false; }
        public function set(string $key, mixed $value, int $flags = 0, int $expiration = 0): bool { return true; }
        public function get(string $key): mixed { return false; }
        public function delete(string $key): bool { return true; }
        public function flush(): bool { return true; }
        public function add(string $key, mixed $value, int $flags = 0, int $expiration = 0): bool { return true; }
        public function close(): bool { return true; }
    }
}

// ---------------------------------------------------------------------------
// WordPress stub functions
// ---------------------------------------------------------------------------

if (!function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = '', bool $force = false, mixed &$found = null): mixed
    {
        return false;
    }
}
if (!function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool { return true; }
}
if (!function_exists('wp_cache_add')) {
    function wp_cache_add(string $key, mixed $data, string $group = '', int $expire = 0): bool { return true; }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool { return true; }
}
if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush(): bool { return true; }
}
if (!function_exists('wp_cache_delete_group')) {
    function wp_cache_delete_group(string $group): bool { return true; }
}
if (!function_exists('get_transient')) {
    function get_transient(string $transient): mixed { return false; }
}
if (!function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool { return true; }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool { return true; }
}
if (!function_exists('get_site_transient')) {
    function get_site_transient(string $transient): mixed { return false; }
}
if (!function_exists('set_site_transient')) {
    function set_site_transient(string $transient, mixed $value, int $expiration = 0): bool { return true; }
}
if (!function_exists('delete_site_transient')) {
    function delete_site_transient(string $transient): bool { return true; }
}
if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int { return 1; }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool { return false; }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool { return false; }
}
if (!function_exists('is_ssl')) {
    function is_ssl(): bool { return false; }
}
if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache(): bool { return false; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
}
if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void {}
}
if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): true { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): true { return true; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key) ?? $key); }
}
if (!function_exists('esc_html')) {
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES); }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string { return 'http://localhost' . $path; }
}
if (!function_exists('site_url')) {
    function site_url(string $path = ''): string { return 'http://localhost' . $path; }
}
if (!function_exists('get_permalink')) {
    function get_permalink(int|WP_Post $post = 0): string|false { return 'http://localhost/post/'; }
}
if (!function_exists('get_post')) {
    function get_post(int|WP_Post|null $post = null): WP_Post|null { return null; }
}
if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision(int|WP_Post $post): int|false { return false; }
}
if (!function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave(int|WP_Post $post): int|false { return false; }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool { return false; }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string { return 'http://localhost/wp-admin/' . $path; }
}
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string { return $text; }
}
if (!function_exists('get_posts')) {
    function get_posts(mixed ...$args): array { return []; }
}
if (!function_exists('is_woocommerce')) {
    function is_woocommerce(): bool { return false; }
}
if (!function_exists('is_cart')) {
    function is_cart(): bool { return false; }
}
if (!function_exists('is_checkout')) {
    function is_checkout(): bool { return false; }
}
if (!function_exists('is_account_page')) {
    function is_account_page(): bool { return false; }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true): string { return 'stubpassword'; }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool { return true; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags, $depth);
    }
}
if (!function_exists('wp_parse_url')) {
    /** @return array<string,string|int>|string|int|null|false */
    function wp_parse_url(string $url, int $component = -1): array|string|int|null|false
    {
        return parse_url($url, $component);
    }
}
if (!function_exists('wp_remote_request')) {
    /** @return array<string,mixed>|WP_Error */
    function wp_remote_request(string $url, array $args = []): array|WP_Error
    {
        return [];
    }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool { return true; }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): int|false { return false; }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook): int { return 0; }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool { return false; }
}
if (!function_exists('get_sites')) {
    /** @return array<int,int> */
    function get_sites(array $args = []): array { return []; }
}
if (!function_exists('switch_to_blog')) {
    function switch_to_blog(int $new_blog_id, bool $deprecated = true): true { return true; }
}
if (!function_exists('restore_current_blog')) {
    function restore_current_blog(): true { return true; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool { return $thing instanceof WP_Error; }
}
if (!function_exists('is_front_page')) {
    function is_front_page(): bool { return false; }
}
if (!function_exists('is_home')) {
    function is_home(): bool { return false; }
}
if (!function_exists('is_archive')) {
    function is_archive(): bool { return false; }
}
if (!function_exists('is_singular')) {
    function is_singular(): bool { return false; }
}
if (!function_exists('get_queried_object_id')) {
    function get_queried_object_id(): int { return 0; }
}
