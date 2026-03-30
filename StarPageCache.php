<?php

namespace StarCache;

use Exception;

/**
 * StarPageCache
 *
 * Provides full-page output-buffer caching and fragment (partial) caching for
 * WordPress, with first-class Varnish integration.
 *
 * Features
 * --------
 * - Full-page HTML caching via PHP output buffering; cached responses are
 *   served from StarCacheAdapter before WordPress fully boots.
 * - Fragment caching: capture, store, and replay chunks of template output.
 * - Varnish integration: sends Cache-Control / X-Cache-Tags headers and
 *   dispatches HTTP PURGE requests on post save / publish.
 * - Bypasses cache for: logged-in users, admins, POST requests, WooCommerce
 *   cart/checkout pages, and when a DONOTCACHEPAGE constant is set.
 * - Multisite-aware: each blog's pages are cached under their own namespace.
 *
 * Usage (from starcache.php)
 * --------------------------
 *   add_action('init',               [\StarCache\StarPageCache::class, 'startPageCache'], 1);
 *   add_action('wp',                 [\StarCache\StarPageCache::class, 'maybeServeCachedPage'], 1);
 *   add_action('save_post',          [\StarCache\StarPageCache::class, 'purgeOnSave'], 10, 2);
 *   add_action('transition_post_status', [\StarCache\StarPageCache::class, 'purgeOnStatusChange'], 10, 3);
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.0.0
 * @license Apache 2.0
 */
class StarPageCache
{
    /** Cache group for full pages. */
    private const GROUP_PAGE = 'starcache_page';

    /** Cache group for fragments. */
    private const GROUP_FRAG = 'starcache_frag';

    /** Default full-page TTL in seconds (10 minutes). */
    public const TTL_PAGE = 600;

    /** Default fragment TTL in seconds (1 hour). */
    public const TTL_FRAG = 3600;

    /** Varnish host/port – override with VARNISH_HOST / VARNISH_PORT constants. */
    private const VARNISH_HOST = '127.0.0.1';
    private const VARNISH_PORT = 6081;

    /** @var string|null Key for the page currently being buffered. */
    private static ?string $currentPageKey = null;

    // -------------------------------------------------------------------------
    // Full-page cache
    // -------------------------------------------------------------------------

    /**
     * Start output buffering for pages that should be cached.
     * Called on the 'init' hook (priority 1).
     */
    public static function startPageCache(): void
    {
        if (self::shouldBypass()) {
            return;
        }

        $key = self::buildPageKey();

        // Serve from cache if available
        $cached = StarCacheAdapter::get($key, self::GROUP_PAGE);
        if ($cached !== false && is_array($cached)) {
            self::sendCachedPage($cached);
            exit;
        }

        // Begin buffering
        self::$currentPageKey = $key;
        ob_start([self::class, 'capturePageOutput']);
    }

    /**
     * Output-buffer callback: stores the captured HTML and emits it.
     *
     * @param string $html
     * @return string
     */
    public static function capturePageOutput(string $html): string
    {
        if (self::$currentPageKey === null || strlen(trim($html)) < 10) {
            return $html;
        }

        $ttl      = (int) apply_filters('starcache_page_ttl', self::TTL_PAGE);
        $headers  = self::collectSafeHeaders();
        $payload  = ['html' => $html, 'headers' => $headers, 'time' => time()];

        StarCacheAdapter::set(self::$currentPageKey, $payload, $ttl, self::GROUP_PAGE);
        self::sendVarnishHeaders();

        return $html;
    }

    /**
     * Serve a previously cached full page and set Varnish / Cache-Control headers.
     *
     * @param array $cached
     */
    private static function sendCachedPage(array $cached): void
    {
        if (!headers_sent()) {
            foreach ($cached['headers'] ?? [] as $header) {
                header($header);
            }
            self::sendVarnishHeaders(true);
        }
        echo $cached['html'] ?? '';
    }

    // -------------------------------------------------------------------------
    // Fragment / partial cache
    // -------------------------------------------------------------------------

    /**
     * Return a cached fragment, or start output buffering so the caller can
     * generate and cache it.
     *
     * Pattern:
     *   if (!StarPageCache::getFragment('sidebar')) {
     *       // … render sidebar …
     *       StarPageCache::saveFragment('sidebar');
     *   }
     *
     * @param string $name    Unique fragment identifier.
     * @param int    $ttl     Time-to-live in seconds.
     * @return bool  True if cached content was echoed; false if caller must render.
     */
    public static function getFragment(string $name, int $ttl = self::TTL_FRAG): bool
    {
        $key    = self::buildFragmentKey($name);
        $cached = StarCacheAdapter::get($key, self::GROUP_FRAG);

        if ($cached !== false) {
            echo $cached;
            return true;
        }

        // Store key + ttl so saveFragment() can pick it up
        ob_start();
        return false;
    }

    /**
     * End output buffering, cache the output under $name, and echo it.
     *
     * @param string $name  Same identifier used in the matching getFragment() call.
     * @param int    $ttl   Time-to-live in seconds.
     */
    public static function saveFragment(string $name, int $ttl = self::TTL_FRAG): void
    {
        $output = ob_get_clean();
        if ($output === false) {
            return;
        }

        $key = self::buildFragmentKey($name);
        StarCacheAdapter::set($key, $output, $ttl, self::GROUP_FRAG);
        echo $output;
    }

    /**
     * Invalidate a single cached fragment.
     *
     * @param string $name
     */
    public static function deleteFragment(string $name): void
    {
        $key = self::buildFragmentKey($name);
        StarCacheAdapter::delete($key, self::GROUP_FRAG);
    }

    // -------------------------------------------------------------------------
    // Cache invalidation
    // -------------------------------------------------------------------------

    /**
     * Purge the cached page for a post when it is saved.
     *
     * @param int      $postId
     * @param \WP_Post $post
     */
    public static function purgeOnSave(int $postId, \WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $url = get_permalink($postId);
        if ($url) {
            self::purgeUrl($url);
        }

        // Purge archive / home page as well
        self::purgeUrl(home_url('/'));

        do_action('starcache_after_purge', $postId, $post);
    }

    /**
     * Purge on post status transition (e.g. draft → publish).
     *
     * @param string   $new
     * @param string   $old
     * @param \WP_Post $post
     */
    public static function purgeOnStatusChange(string $new, string $old, \WP_Post $post): void
    {
        if ($new === $old) {
            return;
        }
        if (in_array($new, ['publish', 'trash'], true) || $old === 'publish') {
            self::purgeOnSave($post->ID, $post);
        }
    }

    /**
     * Purge the object-cache entry for a URL and optionally send a Varnish PURGE.
     *
     * @param string $url
     */
    public static function purgeUrl(string $url): void
    {
        $key = self::buildPageKeyFromUrl($url);
        StarCacheAdapter::delete($key, self::GROUP_PAGE);

        if (self::isVarnishEnabled()) {
            self::varnishPurge($url);
        }
    }

    // -------------------------------------------------------------------------
    // Varnish helpers
    // -------------------------------------------------------------------------

    /**
     * Send Cache-Control and X-Cache-Tags headers for Varnish.
     *
     * @param bool $fromCache  True when serving from cache (adds X-Cache: HIT).
     */
    public static function sendVarnishHeaders(bool $fromCache = false): void
    {
        if (headers_sent() || !self::isVarnishEnabled()) {
            return;
        }

        $ttl = (int) apply_filters('starcache_page_ttl', self::TTL_PAGE);
        header('Cache-Control: public, max-age=' . $ttl . ', s-maxage=' . $ttl);
        header('Vary: Accept-Encoding');
        header('X-Cache: ' . ($fromCache ? 'HIT' : 'MISS'));

        // Optionally tag the response for targeted purging
        if (is_singular()) {
            $postId = get_queried_object_id();
            header('X-Cache-Tags: post-' . $postId);
        } elseif (is_archive() || is_home() || is_front_page()) {
            header('X-Cache-Tags: archive');
        }
    }

    /**
     * Send an HTTP PURGE request to Varnish for the given URL.
     *
     * @param string $url
     */
    private static function varnishPurge(string $url): void
    {
        $host = defined('VARNISH_HOST') ? VARNISH_HOST : self::VARNISH_HOST;
        $port = defined('VARNISH_PORT') ? (int) VARNISH_PORT : self::VARNISH_PORT;

        $parsed = wp_parse_url($url);
        $path   = ($parsed['path'] ?? '/');
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        $requestHost = $parsed['host'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $args = [
            'method'    => 'PURGE',
            'timeout'   => 5,
            'sslverify' => false,
            'headers'   => ['Host' => $requestHost],
        ];

        $purgeUrl = 'http://' . $host . ':' . $port . $path;
        $response = wp_remote_request($purgeUrl, $args);

        if (is_wp_error($response)) {
            error_log('[StarCache] Varnish PURGE failed for ' . $url . ': ' . $response->get_error_message());
        }
    }

    // -------------------------------------------------------------------------
    // Bypass detection
    // -------------------------------------------------------------------------

    /**
     * Returns true when the current request must not be served from cache.
     */
    public static function shouldBypass(): bool
    {
        // Honor explicit no-cache flag
        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            return true;
        }

        // POST / PUT / DELETE / HEAD – only GET requests are cached
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET') {
            return true;
        }

        // Logged-in users always get fresh responses
        if (is_user_logged_in()) {
            return true;
        }

        // WordPress admin area
        if (is_admin()) {
            return true;
        }

        // AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return true;
        }

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        // WooCommerce dynamic pages
        if (function_exists('is_woocommerce')) {
            if (is_cart() || is_checkout() || is_account_page()) {
                return true;
            }
        }

        // Allow plugins / themes to opt out
        return (bool) apply_filters('starcache_bypass_page_cache', false);
    }

    // -------------------------------------------------------------------------
    // Key construction
    // -------------------------------------------------------------------------

    /**
     * Build the cache key for the current request URL.
     */
    private static function buildPageKey(): string
    {
        $url = self::currentUrl();
        return self::buildPageKeyFromUrl($url);
    }

    /**
     * Build the cache key for a given URL.
     *
     * @param string $url
     */
    private static function buildPageKeyFromUrl(string $url): string
    {
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        return 'sc_page_' . $blogId . '_' . md5($url);
    }

    /**
     * Build the cache key for a named fragment.
     *
     * @param string $name
     */
    private static function buildFragmentKey(string $name): string
    {
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        return 'sc_frag_' . $blogId . '_' . md5($name);
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Returns the full URL of the current request.
     */
    private static function currentUrl(): string
    {
        if (function_exists('home_url')) {
            $scheme = (is_ssl() ? 'https' : 'http');
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $uri    = $_SERVER['REQUEST_URI'] ?? '/';
            return $scheme . '://' . $host . $uri;
        }
        return (isset($_SERVER['HTTPS']) ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    /**
     * Collect headers that are safe to replay from cache (skip set-cookie, etc.).
     *
     * @return string[]
     */
    private static function collectSafeHeaders(): array
    {
        if (!function_exists('headers_list')) {
            return [];
        }

        $skip = ['set-cookie', 'x-cache', 'x-cache-tags'];
        $safe = [];

        foreach (headers_list() as $header) {
            $lower = strtolower(explode(':', $header, 2)[0] ?? '');
            if (!in_array($lower, $skip, true)) {
                $safe[] = $header;
            }
        }

        return $safe;
    }

    /**
     * Returns true when Varnish integration is enabled.
     */
    private static function isVarnishEnabled(): bool
    {
        return (bool) apply_filters('starcache_varnish_enabled', defined('VARNISH_HOST'));
    }
}
