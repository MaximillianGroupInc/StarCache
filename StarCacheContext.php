<?php

namespace StarCache;

/**
 * StarCacheContext — Context Engine
 *
 * Resolves the "context dimensions" that determine how a request is cached.
 * Inspired by WordPress VIP's Vary_Cache, but implemented as a self-contained
 * component with no VIP dependency.
 *
 * Dimensions
 * ----------
 * Three built-in dimensions are resolved automatically on every request:
 *
 *   device     — mobile | tablet | desktop   (derived from User-Agent)
 *   auth       — authenticated | anonymous   (derived from is_user_logged_in())
 *   experiment — free-form string            (derived from a cookie)
 *
 * Custom dimensions can be added at any point before lock() is called:
 *   StarCacheContext::set('my_dim', 'value');
 *
 * Lifecycle
 * ---------
 * 1. resolve() is called during `plugins_loaded` (priority 0) – BEFORE any
 *    cache lookup takes place.
 * 2. All dimensions can be overridden between resolve() and lock().
 * 3. lock() is called on the `wp` action (priority 1) – just before headers
 *    are sent.  After lock() no dimension changes are accepted.
 * 4. hash() returns a stable SHA-256 digest used in cache key construction.
 *    The 'auth' dimension is excluded from the hash because authenticated
 *    requests bypass the cache entirely.
 *
 * Rules
 * -----
 * - Context is NEVER stored as cached data.
 * - Context influences cache keys, not the storage layer.
 * - Authenticated requests (auth = 'authenticated') bypass ALL caches.
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.0
 * @license Apache 2.0
 */
class StarCacheContext
{
    // -------------------------------------------------------------------------
    // Dimension name constants
    // -------------------------------------------------------------------------

    public const DIM_DEVICE     = 'device';
    public const DIM_AUTH       = 'auth';
    public const DIM_EXPERIMENT = 'experiment';

    // -------------------------------------------------------------------------
    // Dimension value constants
    // -------------------------------------------------------------------------

    public const DEVICE_MOBILE  = 'mobile';
    public const DEVICE_TABLET  = 'tablet';
    public const DEVICE_DESKTOP = 'desktop';

    public const AUTH_AUTHENTICATED = 'authenticated';
    public const AUTH_ANONYMOUS     = 'anonymous';

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var array<string,string> Resolved dimension name → value map */
    private static array $dimensions = [];

    /** @var bool True once resolve() has run */
    private static bool $resolved = false;

    /** @var bool True once lock() has been called; rejects further set() calls */
    private static bool $locked = false;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Resolve all context dimensions for the current request.
     *
     * Idempotent – safe to call many times; only the first call does work.
     * Must be called BEFORE any cache key is built.
     */
    public static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }

        self::$resolved = true;

        // Server-derived dimensions (highest priority)
        self::$dimensions[self::DIM_AUTH]   = self::resolveAuth();
        self::$dimensions[self::DIM_DEVICE] = self::resolveDevice();

        // Cookie-derived dimensions (lower priority)
        self::$dimensions[self::DIM_EXPERIMENT] = self::resolveExperiment();

        // Allow themes / plugins to add or override dimensions – but only
        // before lock() is called so the context source priority is respected.
        self::$dimensions = (array) apply_filters(
            'starcache_context_dimensions',
            self::$dimensions
        );
    }

    /**
     * Lock the context.  Called just before headers are sent / response starts.
     * After this point, set() is a no-op.
     */
    public static function lock(): void
    {
        if (!self::$resolved) {
            self::resolve();
        }
        self::$locked = true;
    }

    /**
     * Reset context state – useful in tests or when switching blogs.
     */
    public static function reset(): void
    {
        self::$dimensions = [];
        self::$resolved   = false;
        self::$locked     = false;
    }

    // -------------------------------------------------------------------------
    // Dimension accessors
    // -------------------------------------------------------------------------

    /**
     * Set a dimension value.  No-op if context is already locked.
     *
     * @param string $dimension  Dimension name (use DIM_* constants or custom string).
     * @param string $value      Dimension value.
     */
    public static function set(string $dimension, string $value): void
    {
        if (self::$locked) {
            return;
        }
        if (!self::$resolved) {
            self::resolve();
        }
        self::$dimensions[$dimension] = $value;
    }

    /**
     * Get the resolved value for a dimension.
     *
     * @param string $dimension
     * @param string $default    Returned when the dimension is not set.
     * @return string
     */
    public static function get(string $dimension, string $default = ''): string
    {
        if (!self::$resolved) {
            self::resolve();
        }
        return self::$dimensions[$dimension] ?? $default;
    }

    /**
     * Return all resolved dimensions as an associative array.
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        if (!self::$resolved) {
            self::resolve();
        }
        return self::$dimensions;
    }

    // -------------------------------------------------------------------------
    // Cache-key integration
    // -------------------------------------------------------------------------

    /**
     * Return a stable, deterministic hash of the current context for embedding
     * in cache keys.
     *
     * The 'auth' dimension is intentionally EXCLUDED: authenticated requests
     * bypass the cache entirely, so their context hash is never used in keys.
     *
     * @return string  64-character hex SHA-256 digest.
     */
    public static function hash(): string
    {
        if (!self::$resolved) {
            self::resolve();
        }

        $keyDimensions = self::$dimensions;
        unset($keyDimensions[self::DIM_AUTH]); // Auth = bypass signal, not a cache variant

        ksort($keyDimensions); // Deterministic order
        return hash('sha256', serialize($keyDimensions));
    }

    /**
     * Returns true when the current request context should bypass all caches.
     * Hard rule: authenticated users NEVER receive cached responses.
     */
    public static function shouldBypass(): bool
    {
        if (!self::$resolved) {
            self::resolve();
        }
        return self::$dimensions[self::DIM_AUTH] === self::AUTH_AUTHENTICATED;
    }

    // -------------------------------------------------------------------------
    // Dimension resolvers (server-derived first, cookie-derived second)
    // -------------------------------------------------------------------------

    /**
     * Resolve the auth dimension from WordPress login state.
     */
    private static function resolveAuth(): string
    {
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return self::AUTH_AUTHENTICATED;
        }
        return self::AUTH_ANONYMOUS;
    }

    /**
     * Resolve the device dimension from the User-Agent string.
     */
    private static function resolveDevice(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/mobile|android|iphone|ipod|blackberry|opera mini|windows phone/i', $ua)) {
            return self::DEVICE_MOBILE;
        }
        if (preg_match('/tablet|ipad|kindle|silk/i', $ua)) {
            return self::DEVICE_TABLET;
        }
        return self::DEVICE_DESKTOP;
    }

    /**
     * Resolve the experiment dimension from a cookie.
     *
     * The cookie name can be changed via the 'starcache_experiment_cookie' filter.
     * The value is sanitised to a lowercase slug so it cannot poison cache keys.
     */
    private static function resolveExperiment(): string
    {
        $cookieName = (string) apply_filters('starcache_experiment_cookie', 'starcache_experiment');
        $raw        = $_COOKIE[$cookieName] ?? '';
        // Sanitise: lowercase alphanumeric + underscore only
        return preg_replace('/[^a-z0-9_]/', '', strtolower($raw)) ?? '';
    }
}
