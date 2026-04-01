<?php
/**
 * PHPUnit bootstrap – WordPress function stubs
 *
 * Provides the minimal set of WordPress functions / constants needed by
 * StarCache classes so the test suite can run outside a full WP environment.
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
defined('ABSPATH')          || define('ABSPATH', sys_get_temp_dir() . '/');
defined('WP_CONTENT_DIR')   || define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content');
defined('WP_CONTENT_URL')   || define('WP_CONTENT_URL', 'http://localhost/wp-content');
defined('WP_SITEURL')       || define('WP_SITEURL', 'http://localhost');
defined('AUTH_KEY')         || define('AUTH_KEY', 'test_auth_key');
defined('SECURE_AUTH_SALT') || define('SECURE_AUTH_SALT', 'test_auth_salt');
defined('ARRAY_A')          || define('ARRAY_A', 'ARRAY_A');
defined('OBJECT')           || define('OBJECT', 'OBJECT');

// ---------------------------------------------------------------------------
// In-memory WP object cache shim
// ---------------------------------------------------------------------------
$GLOBALS['_starcache_wpcache'] = [];

if (!function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = '', bool $force = false, &$found = null)
    {
        $cacheKey = $group . ':' . $key;
        if (array_key_exists($cacheKey, $GLOBALS['_starcache_wpcache'])) {
            $found = true;
            return $GLOBALS['_starcache_wpcache'][$cacheKey];
        }
        $found = false;
        return false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, $data, string $group = '', int $expire = 0): bool
    {
        $GLOBALS['_starcache_wpcache'][$group . ':' . $key] = $data;
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        unset($GLOBALS['_starcache_wpcache'][$group . ':' . $key]);
        return true;
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush(): bool
    {
        $GLOBALS['_starcache_wpcache'] = [];
        return true;
    }
}

if (!function_exists('wp_cache_delete_group')) {
    function wp_cache_delete_group(string $group): bool
    {
        foreach (array_keys($GLOBALS['_starcache_wpcache']) as $cacheKey) {
            if (strpos($cacheKey, $group . ':') === 0) {
                unset($GLOBALS['_starcache_wpcache'][$cacheKey]);
            }
        }
        return true;
    }
}

// ---------------------------------------------------------------------------
// Transient shim
// ---------------------------------------------------------------------------
$GLOBALS['_starcache_transients'] = [];

if (!function_exists('set_transient')) {
    function set_transient(string $transient, $value, int $expiration = 0): bool
    {
        $GLOBALS['_starcache_transients'][$transient] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $transient)
    {
        return $GLOBALS['_starcache_transients'][$transient] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        unset($GLOBALS['_starcache_transients'][$transient]);
        return true;
    }
}

if (!function_exists('set_site_transient')) {
    function set_site_transient(string $transient, $value, int $expiration = 0): bool
    {
        return set_transient('_network_' . $transient, $value, $expiration);
    }
}

if (!function_exists('get_site_transient')) {
    function get_site_transient(string $transient)
    {
        return get_transient('_network_' . $transient);
    }
}

if (!function_exists('delete_site_transient')) {
    function delete_site_transient(string $transient): bool
    {
        return delete_transient('_network_' . $transient);
    }
}

// ---------------------------------------------------------------------------
// WordPress utility stubs
// ---------------------------------------------------------------------------
if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return 1;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return false;
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return false;
    }
}

if (!function_exists('is_ssl')) {
    function is_ssl(): bool
    {
        return false;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1)
    {
        return $component === -1 ? parse_url($url) : parse_url($url, $component);
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool
    {
        return is_dir($target) || mkdir($target, 0755, true);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args)
    {
        return $value;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $tag, $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $tag, $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $tag, ...$args): void {}
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'http://localhost' . $path;
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = '', string $scheme = null): string
    {
        return 'http://localhost' . $path;
    }
}

if (!function_exists('wp_generate_password')) {
    /**
     * Test-only stub – uses str_shuffle which is NOT cryptographically secure.
     * In production WordPress, wp_generate_password() uses wp_rand().
     */
    function wp_generate_password(int $length = 12, bool $specialChars = true): string
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = [])
    {
        return ['response' => ['code' => 200]];
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function get_error_message(): string { return ''; }
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return false;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'http://localhost/wp-admin/' . ltrim($path, '/');
    }
}

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/vendor/autoload.php';
