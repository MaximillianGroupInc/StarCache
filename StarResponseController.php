<?php

declare(strict_types=1);

namespace StarCache;

/**
 * StarResponseController — Response Header Manager
 *
 * The ONLY place in StarCache that sets Cache-Control response headers.
 * Inspired by WordPress VIP's TTL_Manager, but self-contained and
 * WordPress-VIP-free.
 *
 * Eligibility rules (strict, evaluated in order)
 * -----------------------------------------------
 * 1. Authenticated user  → no-cache (hard rule, no exceptions)
 * 2. Method is not GET or HEAD → no-cache
 * 3. DONOTCACHEPAGE defined and truthy → no-cache
 * 4. Cache-Control or Expires header already present → do nothing
 *    (respects upstream decisions made by plugins, WooCommerce, REST API, etc.)
 * 5. Otherwise → apply the configured public-cache policy
 *
 * Default policy
 * --------------
 *   Cache-Control: public, max-age=300, stale-while-revalidate=30
 *   Vary: Cookie, Accept-Encoding
 *   X-StarCache-Context: {context-hash}
 *   X-Cache: MISS
 *
 * Overriding TTL values
 * ---------------------
 * Use WP filters before headers are sent:
 *   add_filter('starcache_max_age',                fn() => 600);
 *   add_filter('starcache_stale_while_revalidate', fn() => 60);
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.0
 * @license Apache 2.0
 */
class StarResponseController
{
    // -------------------------------------------------------------------------
    // Default policy values
    // -------------------------------------------------------------------------

    /** Default public max-age in seconds (5 minutes). */
    public const DEFAULT_MAX_AGE = 300;

    /** Default stale-while-revalidate window in seconds. */
    public const DEFAULT_STALE_WHILE_REVALIDATE = 30;

    /** @var bool Whether headers have been applied for this request. */
    private static bool $applied = false;

    /**
     * Whether upstream Cache-Control/Expires headers existed at the moment
     * apply() ran — captured BEFORE StarCache sets its own headers.
     *
     * null = apply() has not been called yet.
     *
     * @var bool|null
     */
    private static ?bool $hadUpstreamHeaders = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Apply the appropriate cache headers for the current response.
     *
     * Idempotent – subsequent calls are no-ops once headers have been applied
     * or once headers_sent() returns true.
     *
     * Called on the WordPress 'send_headers' action at priority 999 so it runs
     * AFTER default-priority (10) plugin/theme callbacks. This ensures that
     * upstream Cache-Control/Expires decisions made by other plugins are already
     * present in headers_list() when Gate 4 executes, making the upstream-header
     * gate reliable.
     */
    public static function apply(): void
    {
        if (self::$applied || headers_sent()) {
            return;
        }

        // Capture upstream state BEFORE StarCache sets any headers of its own.
        // StarPageCache::capturePageOutput() (ob callback, runs at PHP shutdown)
        // uses hadUpstreamHeadersBeforeApply() so it does not mistake StarCache's
        // own Cache-Control for an upstream no-cache decision.
        self::$hadUpstreamHeaders = self::upstreamHeadersExist();

        self::$applied = true;

        // Gate 1: authenticated users are NEVER served cached content
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            self::sendNoCache();
            return;
        }

        // Gate 2: only GET and HEAD are cacheable
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            self::sendNoCache();
            return;
        }

        // Gate 3: explicit no-cache flag
        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            self::sendNoCache();
            return;
        }

        // Gate 4: respect upstream headers – if Cache-Control or Expires are
        // already set by another plugin / framework, do not override them
        if (self::upstreamHeadersExist()) {
            return;
        }

        // Gate 5: plugin/theme opt-out
        if ((bool) apply_filters('starcache_bypass_page_cache', false)) {
            self::sendNoCache();
            return;
        }

        // All gates passed – apply the public-cache policy
        self::sendPublicCacheHeaders();
    }

    /**
     * Explicitly mark the current response as not cacheable.
     *
     * Call this from any plugin or theme that produces uncacheable output.
     * Safe to call at any point before headers_sent().
     */
    public static function doNotCache(): void
    {
        if (!headers_sent()) {
            self::sendNoCache();
        }
        self::$applied = true;
    }

    /**
     * Returns true when the current request is eligible for page caching.
     *
     * This method does NOT set any headers; it is a pure eligibility check
     * used by StarPageCache to decide whether to start output buffering.
     */
    public static function isEligible(): bool
    {
        // Hard rule: authenticated users bypass cache
        if (StarCacheContext::shouldBypass()) {
            return false;
        }

        // Only GET and HEAD
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        // Explicit no-cache flag
        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            return false;
        }

        // WordPress admin area
        if (function_exists('is_admin') && is_admin()) {
            return false;
        }

        // AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return false;
        }

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }

        // WooCommerce dynamic pages
        if (function_exists('is_woocommerce')) {
            if (function_exists('is_cart') && is_cart()) {
                return false;
            }
            if (function_exists('is_checkout') && is_checkout()) {
                return false;
            }
            if (function_exists('is_account_page') && is_account_page()) {
                return false;
            }
        }

        // Plugin/theme opt-out
        return !(bool) apply_filters('starcache_bypass_page_cache', false);
    }

    /**
     * Reset internal state (used in tests / when switching blogs).
     */
    public static function reset(): void
    {
        self::$applied = false;
        self::$hadUpstreamHeaders = null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Emit the default public-cache policy headers.
     * This is the ONLY place Cache-Control is written for cacheable responses.
     */
    private static function sendPublicCacheHeaders(): void
    {
        $maxAge = (int) apply_filters('starcache_max_age', self::DEFAULT_MAX_AGE);
        $swr    = (int) apply_filters('starcache_stale_while_revalidate', self::DEFAULT_STALE_WHILE_REVALIDATE);

        header('Cache-Control: public, max-age=' . $maxAge . ', stale-while-revalidate=' . $swr);

        // Vary on Cookie + Accept-Encoding so that CDNs serve correct context buckets.
        header('Vary: Cookie, Accept-Encoding');
        header('X-Cache: MISS');

        // Debug header: context hash for cache-bucket verification.
        header('X-StarCache-Context: ' . StarCacheContext::hash());
    }

    /**
     * Emit no-cache headers.
     */
    private static function sendNoCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Cache: BYPASS');
    }

    /**
     * Return true when Cache-Control or Expires was already set by an upstream
     * plugin at the time apply() ran — i.e. BEFORE StarCache set its own headers.
     *
     * Use this in ob callbacks (e.g. capturePageOutput) where StarCache's own
     * Cache-Control is already in headers_list() and calling upstreamHeadersExist()
     * directly would always return true.
     *
     * Returns false if apply() has not been called yet.
     */
    public static function hadUpstreamHeadersBeforeApply(): bool
    {
        return self::$hadUpstreamHeaders ?? false;
    }

    /**
     * Return true when Cache-Control or Expires is already set (upstream decision).
     *
     * Public so that StarPageCache can consult the same gate before persisting
     * a captured response – preventing HTML from being stored when another plugin
     * has already declared the response non-cacheable.
     */
    public static function upstreamHeadersExist(): bool
    {
        foreach (headers_list() as $header) {
            $lower = strtolower($header);
            if (str_starts_with($lower, 'cache-control:') || str_starts_with($lower, 'expires:')) {
                return true;
            }
        }
        return false;
    }
}
