<?php

declare(strict_types=1);

namespace StarCache;

if (!defined('ABSPATH')) {
    exit;
}

use Exception;

/**
 * StarPageCache
 *
 * Provides full-page output-buffer caching and fragment (partial) caching for
 * WordPress, with first-class Varnish integration.
 *
 * Architecture alignment
 * ----------------------
 * - Cache eligibility is determined by StarResponseController::isEligible()
 *   (single gate, no duplicate logic).
 * - Cache-Control headers are set ONLY by StarResponseController::apply().
 * - Context (device / experiment) is resolved by StarCacheContext::resolve()
 *   BEFORE startPageCache() is called.  The context hash is embedded in every
 *   page and fragment cache key so different context buckets are served
 *   independently without cache poisoning.
 * - Invalidation uses StarVersionStore::bump() rather than direct entry
 *   deletion.  A version bump makes all keys built with the old version
 *   unreachable; entries expire naturally on their TTL.
 * - Varnish PURGE requests are still sent for edge-cache invalidation.
 * - X-Cache-Tags headers are emitted for targeted CDN tag-based purging.
 *
 * Fragment / partial cache
 * ------------------------
 *   if (!\StarCache\StarPageCache::getFragment('sidebar')) {
 *       get_sidebar();
 *       \StarCache\StarPageCache::saveFragment('sidebar');
 *   }
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.1
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

    /** @var string|null Cache key for the page currently being buffered. */
    private static ?string $currentPageKey = null;

    // -------------------------------------------------------------------------
    // Full-page cache
    // -------------------------------------------------------------------------

    /**
     * Start output buffering for pages that are eligible for caching.
     *
     * Must be called AFTER StarCacheContext::resolve() has run.
     * Called on the 'init' hook (priority 1) via starcache.php.
     */
    public static function startPageCache(): void
    {
        if (!StarResponseController::isEligible()) {
            // Signal to downstream observers (CDN, ops tools) that this response
            // was not eligible for the page cache.
            if (!headers_sent()) {
                header('X-Cache: BYPASS');
            }
            return;
        }

        $key = self::buildPageKey();

        // Serve from cache if available
        $cached = StarCacheAdapter::get($key, self::GROUP_PAGE);
        if ($cached !== false && is_array($cached)) {
            self::serveCachedPage($cached);
            exit;
        }

        // Begin buffering so capturePageOutput() is called at shutdown
        self::$currentPageKey = $key;
        ob_start([self::class, 'capturePageOutput']);
    }

    /**
     * Output-buffer callback: persist the captured HTML and return it.
     *
     * Note: Cache-Control headers are NOT set here – that is StarResponseController's
     * responsibility, which runs on the 'send_headers' / 'wp' action.
     *
     * @param string $html  The complete page HTML.
     * @return string       The same HTML (unmodified).
     */
    public static function capturePageOutput(string $html): string
    {
        if (self::$currentPageKey === null || strlen(trim($html)) < 10) {
            return $html;
        }

        // Only cache successful, non-redirect responses.
        // http_response_code() can return false in CLI / before headers are sent;
        // treat that as 200 (cacheable) to avoid skipping valid buffered output.
        $statusCode = http_response_code();
        if ($statusCode !== false && $statusCode !== 200) {
            return $html;
        }

        // Do not cache responses that carry a Location header (redirects that
        // PHP already sent before the output buffer flushed).
        $headers = self::collectSafeHeaders();
        foreach ($headers as $h) {
            if (stripos($h, 'Location:') === 0) {
                return $html;
            }
        }

        // Do not cache when another plugin/framework set Cache-Control or Expires
        // BEFORE StarResponseController ran. We check the state captured at apply()
        // time rather than calling upstreamHeadersExist() directly, because by the
        // time this ob callback fires (PHP shutdown), StarCache's own Cache-Control
        // header is already in headers_list() — a direct check would always return
        // true and prevent any page from being stored.
        if (StarResponseController::hadUpstreamHeadersBeforeApply()) {
            return $html;
        }

        // Final eligibility gate immediately before persist so any late request
        // state changes (method flags, auth state, bypass filters) are respected.
        if (!StarResponseController::isEligible()) {
            return $html;
        }

        $filteredTtl = apply_filters('starcache_page_ttl', self::TTL_PAGE);
        $ttl         = is_numeric($filteredTtl) ? (int) $filteredTtl : self::TTL_PAGE;
        $payload = ['html' => $html, 'headers' => $headers, 'time' => time()];

        StarCacheAdapter::set(self::$currentPageKey, $payload, $ttl, self::GROUP_PAGE);

        // Emit cache-tag header for targeted CDN/Varnish purging
        self::sendCacheTags();

        // Signal to downstream observers that this response was a cache miss
        // (freshly generated and stored).
        if (!headers_sent()) {
            header('X-Cache: MISS');
        }

        return $html;
    }

    /**
     * Serve a previously cached page, replaying its safe headers.
     *
     * @param array<mixed,mixed> $cached  Payload stored by capturePageOutput().
     */
    private static function serveCachedPage(array $cached): void
    {
        if (!headers_sent()) {
            $headers = $cached['headers'] ?? [];
            if (is_array($headers)) {
                foreach ($headers as $header) {
                    if (is_string($header)) {
                        header($header);
                    }
                }
            }
            // Response controller applies Cache-Control; tag header goes here
            StarResponseController::apply();
            self::sendCacheTags();

            // Override X-Cache to indicate a cache HIT
            header('X-Cache: HIT');
        }
        $html = $cached['html'] ?? '';
        echo is_string($html) ? $html : '';
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
     * The TTL is specified on the paired saveFragment() call, not here.
     *
     * @param string $name  Unique fragment identifier.
     * @return bool  True if cached content was echoed; false if caller must render.
     */
    public static function getFragment(string $name): bool
    {
        $key    = self::buildFragmentKey($name);
        $cached = StarCacheAdapter::get($key, self::GROUP_FRAG);

        if (is_string($cached)) {
            echo $cached;
            return true;
        }

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
     * Invalidate a single cached fragment by name.
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
     * Invalidate page and fragment caches when a post is saved.
     *
     * Uses version bumping (preferred) so that all cache entries that embedded
     * the old content version naturally become unreachable.  Also sends a
     * targeted Varnish PURGE request for the specific post URL.
     *
     * @param int      $postId
     * @param \WP_Post $post
     */
    public static function purgeOnSave(int $postId, \WP_Post $post): void
    {
        if (
            (function_exists('wp_is_post_revision')  && wp_is_post_revision($postId)) ||
            (function_exists('wp_is_post_autosave') && wp_is_post_autosave($postId))
        ) {
            return;
        }

        // Version bump makes ALL pages/fragments built with the old version
        // unreachable – no need to enumerate individual keys.
        StarVersionStore::bump(StarVersionStore::GROUP_PAGES);
        StarVersionStore::bump(StarVersionStore::GROUP_OBJECTS);

        // Send Varnish PURGE for the specific URL as well (edge cache)
        if (self::isVarnishEnabled()) {
            $url = function_exists('get_permalink') ? get_permalink($postId) : null;
            if ($url) {
                self::varnishPurge($url);
            }
            $homeUrl = function_exists('home_url') ? home_url('/') : null;
            if ($homeUrl) {
                self::varnishPurge($homeUrl);
            }
        }

        do_action('starcache_after_purge', $postId, $post);
    }

    /**
     * Invalidate on post status transition (e.g. draft → publish).
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
     * Purge a specific URL from the object cache and Varnish.
     *
     * This is kept for backward compatibility and for callers that need
     * targeted invalidation of a known URL.
     *
     * @param string $url
     */
    public static function purgeUrl(string $url): void
    {
        // Build the key that would have been used for this URL at the current
        // version – note this will NOT clear entries built with older versions,
        // but those will expire naturally.
        $key = self::buildPageKeyFromUrl($url);
        StarCacheAdapter::delete($key, self::GROUP_PAGE);

        if (self::isVarnishEnabled()) {
            self::varnishPurge($url);
        }
    }

    // -------------------------------------------------------------------------
    // Backward-compatible bypass helper
    // -------------------------------------------------------------------------

    /**
     * Returns true when the current request must not be served from cache.
     *
     * Delegates to StarResponseController::isEligible() so the eligibility
     * logic lives in exactly one place.
     */
    public static function shouldBypass(): bool
    {
        return !StarResponseController::isEligible();
    }

    // -------------------------------------------------------------------------
    // Varnish helpers
    // -------------------------------------------------------------------------

    /**
     * Emit X-Cache-Tags header for targeted CDN / Varnish purging.
     *
     * This method only emits informational tag headers – it does NOT set
     * Cache-Control (that is StarResponseController's job).
     */
    public static function sendCacheTags(): void
    {
        if (headers_sent()) {
            return;
        }

        if (!function_exists('is_singular') || !function_exists('is_archive')) {
            return;
        }

        if (is_singular()) {
            $postId = function_exists('get_queried_object_id') ? get_queried_object_id() : 0;
            if ($postId) {
                header('X-Cache-Tags: post-' . (int) $postId);
            }
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

        $parsed      = wp_parse_url($url);
        if (!is_array($parsed)) {
            self::logMessage('Varnish PURGE skipped: malformed URL – ' . $url);
            return;
        }
        $path        = ($parsed['path'] ?? '/');
        $requestHost = 'localhost';
        if (array_key_exists('host', $parsed) && is_string($parsed['host']) && $parsed['host'] !== '') {
            $requestHost = self::sanitizeHost($parsed['host']);
        } else {
            $requestHost = self::currentRequestHost();
        }

        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        if ($requestHost === '') {
            $requestHost = 'localhost';
        }

        $args = [
            'method'    => 'PURGE',
            'timeout'   => 5,
            'sslverify' => false,
            'headers'   => ['Host' => $requestHost],
        ];

        $purgeUrl = 'http://' . $host . ':' . $port . $path;
        $response = wp_remote_request($purgeUrl, $args);

        if ($response instanceof \WP_Error) {
            self::logMessage('Varnish PURGE failed for ' . $url . ': ' . $response->get_error_message());
        }
    }

    // -------------------------------------------------------------------------
    // Key construction
    // -------------------------------------------------------------------------

    /**
     * Build the context-aware, versioned cache key for the current request URL.
     */
    private static function buildPageKey(): string
    {
        return self::buildPageKeyFromUrl(self::currentUrl());
    }

    /**
     * Build the context-aware, versioned cache key for a given URL.
     * Uses StarCacheKey::build() so that context and version are automatically
     * included and all key construction rules are applied consistently.
     *
     * @param string $url
     */
    private static function buildPageKeyFromUrl(string $url): string
    {
        return StarCacheKey::build('page|' . $url, null, StarVersionStore::GROUP_PAGES);
    }

    /**
     * Build the context-aware, versioned cache key for a named fragment.
     *
     * @param string $name
     */
    private static function buildFragmentKey(string $name): string
    {
        return StarCacheKey::build('fragment|' . $name, null, StarVersionStore::GROUP_OBJECTS);
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Returns the full URL of the current request.
     */
    private static function currentUrl(): string
    {
        $scheme = (function_exists('is_ssl') && is_ssl()) ? 'https' : 'http';
        $host   = self::currentRequestHost();
        $uri    = self::currentRequestUri();

        return $scheme . '://' . $host . $uri;
    }

    /**
     * Collect headers that are safe to replay from cache (skip Set-Cookie, etc.).
     *
     * @return string[]
     */
    private static function collectSafeHeaders(): array
    {
        if (!function_exists('headers_list')) {
            return [];
        }

        // Skip headers that StarResponseController owns (to avoid duplicates on replays),
        // plus headers that must never be cached.
        $skip = [
            'set-cookie',
            'cache-control',
            'vary',
            'x-cache',
            'x-cache-tags',
            'x-starcache-context',
        ];
        $safe = [];

        foreach (headers_list() as $header) {
            $lower = strtolower(explode(':', $header, 2)[0]);
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

    /**
     * Return a safe host value for cache keys and PURGE headers.
     */
    private static function currentRequestHost(): string
    {
        if (array_key_exists('HTTP_HOST', $_SERVER) && is_string($_SERVER['HTTP_HOST'])) {
            $host = self::sanitizeHost($_SERVER['HTTP_HOST']);
            if ($host !== '') {
                return $host;
            }
        }

        if (function_exists('home_url')) {
            $homeHost = wp_parse_url(home_url('/'), PHP_URL_HOST);
            if (is_string($homeHost)) {
                $homeHost = self::sanitizeHost($homeHost);
                if ($homeHost !== '') {
                    return $homeHost;
                }
            }
        }

        return 'localhost';
    }

    /**
     * Return a safe request URI for cache keys.
     */
    private static function currentRequestUri(): string
    {
        if (array_key_exists('REQUEST_URI', $_SERVER) && is_string($_SERVER['REQUEST_URI'])) {
            $uri = preg_replace('/[\x00-\x1F\x7F]/', '', $_SERVER['REQUEST_URI']) ?? '';
            if ($uri !== '' && str_starts_with($uri, '/')) {
                return $uri;
            }
        }

        return '/';
    }

    /**
     * Normalize a host/header value to a safe subset.
     */
    private static function sanitizeHost(string $host): string
    {
        $host = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $host) ?? '');
        if ($host === '' || strpbrk($host, "/\\?#@\t\n\r\0\x0B ") !== false) {
            return '';
        }

        $parsedHost = wp_parse_url('http://' . $host, PHP_URL_HOST);
        if (!is_string($parsedHost) || $parsedHost === '') {
            return '';
        }

        $validatedHost = '';
        $ipAddress     = filter_var($parsedHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
        if ($ipAddress !== false) {
            $validatedHost = str_contains($ipAddress, ':')
                ? '[' . strtolower($ipAddress) . ']'
                : strtolower($ipAddress);
        } else {
            $domain = filter_var($parsedHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
            if ($domain === false) {
                return '';
            }
            $validatedHost = strtolower($domain);
        }

        $parsedPort = wp_parse_url('http://' . $host, PHP_URL_PORT);
        if ($parsedPort !== null) {
            if (!is_int($parsedPort) || $parsedPort < 1 || $parsedPort > 65535) {
                return '';
            }
            $validatedHost .= ':' . $parsedPort;
        }

        return $validatedHost;
    }

    /**
     * Log a page-cache warning via StarExceptionHandler when available.
     */
    private static function logMessage(string $message): void
    {
        $exception = new \RuntimeException($message);
        if (class_exists('\StarExceptionHandler')) {
            $logger = \StarExceptionHandler::star_getInstance();
            $logger->star_handleException($exception);
        } else {
            error_log("[StarCache] {$message}");
        }
    }
}
