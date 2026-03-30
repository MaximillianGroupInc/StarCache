<?php
/**
 * Plugin Name:  StarCache
 * Plugin URI:   https://github.com/MaximillianGroupInc/StarCache
 * Description:  Advanced caching MU-Plugin for WordPress. Auto-detects Redis, Memcached, Memcache,
 *               and OPcache; provides full-page caching, partial/fragment caching, Varnish integration,
 *               WP_Query caching, transient optimisation, and CSS/JS minification. Multisite-aware.
 * Version:      2.0.0
 * Author:       MaximillianGroup (Max Barrett)
 * Author URI:   https://github.com/MaximillianGroupInc
 * License:      Apache 2.0
 * Network:      true
 *
 * ---
 * INSTALLATION
 * ---
 * Copy this file (and the companion class files) into wp-content/mu-plugins/.
 * As an MU-Plugin it is loaded automatically on every WordPress request – no
 * activation step is required.  It is also safe to load via Composer autoload:
 *
 *   require_once WP_CONTENT_DIR . '/mu-plugins/starcache.php';
 *
 * ---
 * OPTIONAL CONFIGURATION  (add to wp-config.php)
 * ---
 *   define('WP_REDIS_HOST',        '127.0.0.1');
 *   define('WP_REDIS_PORT',        6379);
 *   define('WP_REDIS_PASSWORD',    'secret');
 *   define('WP_REDIS_DATABASE',    0);
 *
 *   define('MEMCACHED_SERVERS', [['host' => '127.0.0.1', 'port' => 11211]]);
 *
 *   define('VARNISH_HOST', '127.0.0.1');   // enables Varnish PURGE requests
 *   define('VARNISH_PORT', 6081);
 *
 *   define('STARCACHE_ASSET_DIR', '/var/www/html/wp-content/cache/starcache/assets');
 *   define('STARCACHE_ASSET_URL', 'https://example.com/wp-content/cache/starcache/assets');
 *   define('STARCACHE_MINIFY', false);     // set to false to disable asset minification
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.0.0
 * @license Apache 2.0
 */

namespace StarCache;

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Load class files
// (When installed via Composer the autoloader already handles this.)
// ---------------------------------------------------------------------------
$_starCacheDir = __DIR__;

if (!class_exists(__NAMESPACE__ . '\StarCacheKey')) {
    require_once $_starCacheDir . '/StarCacheKey.php';
}
if (!class_exists(__NAMESPACE__ . '\StarCacheAdapter')) {
    require_once $_starCacheDir . '/StarCacheAdapter.php';
}
if (!class_exists(__NAMESPACE__ . '\StarCache')) {
    require_once $_starCacheDir . '/StarCache.php';
}
if (!class_exists(__NAMESPACE__ . '\StarTransientCache')) {
    require_once $_starCacheDir . '/StarTransientCache.php';
}
if (!class_exists(__NAMESPACE__ . '\StarPageCache')) {
    require_once $_starCacheDir . '/StarPageCache.php';
}
if (!class_exists(__NAMESPACE__ . '\StarQueryCache')) {
    require_once $_starCacheDir . '/StarQueryCache.php';
}
if (!class_exists(__NAMESPACE__ . '\StarAssetMinifier')) {
    require_once $_starCacheDir . '/StarAssetMinifier.php';
}

unset($_starCacheDir);

// ---------------------------------------------------------------------------
// Bootstrap – initialise the cache adapter as early as possible
// ---------------------------------------------------------------------------
add_action('plugins_loaded', function () {
    StarCacheAdapter::init();
}, 1);

// ---------------------------------------------------------------------------
// Full-page cache
// ---------------------------------------------------------------------------
add_action('init', [StarPageCache::class, 'startPageCache'], 1);

// Purge page cache on post save / status change
add_action('save_post',              [StarPageCache::class, 'purgeOnSave'],         10, 2);
add_action('transition_post_status', [StarPageCache::class, 'purgeOnStatusChange'], 10, 3);

// Also purge when a post is trashed or permanently deleted
add_action('trashed_post',  function (int $postId) {
    $post = get_post($postId);
    if ($post instanceof \WP_Post) {
        StarPageCache::purgeOnSave($postId, $post);
    }
});
add_action('before_delete_post', function (int $postId) {
    $post = get_post($postId);
    if ($post instanceof \WP_Post) {
        StarPageCache::purgeOnSave($postId, $post);
    }
});

// ---------------------------------------------------------------------------
// Query cache
// ---------------------------------------------------------------------------
add_filter('posts_pre_query', [StarQueryCache::class, 'postsPreQuery'], 10, 2);
add_filter('the_posts',       [StarQueryCache::class, 'thePosts'],      10, 2);
add_action('clean_post_cache', function (int $postId) {
    StarQueryCache::invalidatePostCaches($postId);
});

// ---------------------------------------------------------------------------
// Asset minification
// ---------------------------------------------------------------------------
add_action('init',            [StarAssetMinifier::class, 'init'],           5);
add_action('wp_print_styles', [StarAssetMinifier::class, 'processStyles'],  5);
add_action('wp_print_scripts',[StarAssetMinifier::class, 'processScripts'], 5);

// Flush minified assets whenever a theme or plugin is updated
add_action('upgrader_process_complete', [StarAssetMinifier::class, 'flushAssets']);
add_action('switch_theme',              [StarAssetMinifier::class, 'flushAssets']);

// ---------------------------------------------------------------------------
// Admin bar integration (shows active backend)
// ---------------------------------------------------------------------------
add_action('admin_bar_menu', function (\WP_Admin_Bar $bar) {
    if (!current_user_can('manage_options')) {
        return;
    }

    $backend  = StarCacheAdapter::getBackend();
    $opcache  = StarCacheAdapter::isOpcacheEnabled() ? ' + OPcache' : '';
    $label    = 'StarCache: ' . strtoupper($backend) . $opcache;

    $bar->add_menu([
        'id'    => 'starcache',
        'title' => esc_html($label),
        'href'  => admin_url('tools.php?page=starcache'),
        'meta'  => ['title' => __('StarCache – Active cache backend', 'starcache')],
    ]);
}, 100);

// ---------------------------------------------------------------------------
// WP-CLI support: flush all StarCache data
// ---------------------------------------------------------------------------
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('starcache flush', function () {
        StarCacheAdapter::flush();
        StarAssetMinifier::flushAssets();
        StarQueryCache::flushQueryCache();
        \WP_CLI::success('StarCache flushed.');
    });
}

// ---------------------------------------------------------------------------
// Public helper functions (callable by themes / plugins without namespacing)
// ---------------------------------------------------------------------------

if (!function_exists('star_cache')) {
    /**
     * Return the singleton StarCache instance.
     *
     * @return \StarCache\StarCache
     */
    function star_cache(): StarCache
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new StarCache();
        }
        return $instance;
    }
}

if (!function_exists('star_cache_get')) {
    /**
     * Retrieve a cached value.
     *
     * @param  string      $reference
     * @param  string|null $userId
     * @return mixed|false
     */
    function star_cache_get(string $reference, ?string $userId = null)
    {
        return star_cache()->star_getCachedData($reference, $userId);
    }
}

if (!function_exists('star_cache_set')) {
    /**
     * Store a value in cache.
     *
     * @param  mixed       $data
     * @param  string      $reference
     * @param  int         $ttl        Seconds; 0 = use default (1 hour).
     * @param  string|null $userId
     * @return bool
     */
    function star_cache_set($data, string $reference, int $ttl = 0, ?string $userId = null): bool
    {
        if ($ttl > 0) {
            return star_cache()->star_setCachedDataWithTtl($data, $reference, $ttl, $userId);
        }
        return star_cache()->star_setCachedData($data, $reference, $userId);
    }
}

if (!function_exists('star_cache_delete')) {
    /**
     * Delete a cached value.
     *
     * @param  string      $reference
     * @param  string|null $userId
     * @return bool
     */
    function star_cache_delete(string $reference, ?string $userId = null): bool
    {
        return star_cache()->star_deleteCachedData($reference, $userId);
    }
}

if (!function_exists('star_cache_remember')) {
    /**
     * Get-or-set cache (cache-aside pattern).
     *
     * @param  string      $reference
     * @param  callable    $callback   Called on cache miss; its return value is stored and returned.
     * @param  int         $ttl
     * @param  string|null $userId
     * @return mixed
     */
    function star_cache_remember(string $reference, callable $callback, int $ttl = 3600, ?string $userId = null)
    {
        return star_cache()->star_remember($reference, $callback, $ttl, $userId);
    }
}
