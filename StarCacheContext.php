<?php

declare(strict_types=1);

namespace StarCache;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * StarCacheContext — Context Engine
 *
 * Resolves and holds the dimensions that determine how a request is cached.
 * Produces a stable, deterministic hash embedded in every cache key.
 *
 * Three built-in dimensions resolved on every request
 * ---------------------------------------------------
 *   device     — 'mobile' | 'tablet' | 'desktop'  (User-Agent derived)
 *   auth       — 'authenticated' | 'anonymous'     (is_user_logged_in())
 *   experiment — [a-z0-9_:-] string               (cookie-derived, sanitized)
 *
 * Lifecycle
 * ---------
 * 1. resolve()   — called at `plugins_loaded` priority 0. Idempotent.
 * 2. set()       — adds or overrides a registered dimension. No-op after lock().
 * 3. lock()      — called at `send_headers` priority 1. Freezes all dimensions.
 * 4. hash()      — SHA-256 of all non-auth dimensions (ksort for determinism).
 * 5. shouldBypass() — returns true when auth = 'authenticated'.
 * 6. reset()     — test use only. Clears all state.
 *
 * Dimension registration model
 * ----------------------------
 * Custom dimensions MUST be registered before they can be set or injected via
 * the `starcache_context_dimensions` filter. Unregistered dimensions injected
 * through the filter are silently dropped. This prevents cache key explosion
 * from untrusted filter callbacks.
 *
 *   // Register a dimension with an optional allow-list of values
 *   StarCacheContext::register('locale', ['en', 'fr', 'es', 'de']);
 *
 * Constraints (all enforced after the filter runs)
 * -----------------------------------------------
 *   MAX_DIMENSIONS            = 7     — total dimension count cap
 *   MAX_DIMENSION_VALUE_LENGTH = 64   — per-value character limit (truncated)
 *   Allowed value characters   = [a-z0-9_:-] only (values are sanitized)
 *
 * Rules
 * -----
 * - Context is NEVER stored as cached data.
 * - Context influences cache keys, not the storage layer.
 * - Authenticated requests bypass ALL caches (shouldBypass() = true).
 * - The auth dimension is excluded from the hash.
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.1
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
    // Constraint constants
    // -------------------------------------------------------------------------

    /** Maximum number of dimensions allowed (guards against cache explosion). */
    public const MAX_DIMENSIONS = 7;

    /** Maximum length of a dimension value in characters (values are truncated). */
    public const MAX_DIMENSION_VALUE_LENGTH = 64;

    /** Maximum length of a dimension name in characters. */
    public const MAX_DIMENSION_NAME_LENGTH = 64;

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var array<string,string> Resolved dimension name → value map. */
    private static array $dimensions = [];

    /**
     * @var array<string,list<string>> Registered dimension name → allowed values.
     * Empty list = any sanitized value accepted for that dimension.
     */
    private static array $registered = [];

    /** @var bool True once resolve() has run. */
    private static bool $resolved = false;

    /** @var bool True once lock() has been called. */
    private static bool $locked = false;

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register a custom dimension before resolve() is called.
     *
     * Must be called during `plugins_loaded` at priority < 0 so it completes
     * before StarCacheContext::resolve() runs at priority 0.
     *
     * @param string        $name          Dimension name (lowercase [a-z0-9_:-]).
     * @param list<string>  $allowedValues Optional allow-list. Empty = any sanitized value accepted.
     */
    public static function register(string $name, array $allowedValues = []): void
    {
        // Only register if not locked; built-in dimensions are always registered.
        if (self::$locked) {
            return;
        }

        // Normalize to lowercase, then validate the name against the documented
        // character set [a-z0-9_:-] with a max length of 64.  Silently reject
        // invalid names so callers don't need to catch exceptions.
        $name = strtolower($name);
        if ($name === '' || strlen($name) > self::MAX_DIMENSION_NAME_LENGTH || !preg_match('/^[a-z0-9_:-]+$/', $name)) {
            return;
        }

        // Normalize allowed values to the same canonical form used for runtime
        // values so allow-list checks and fallback values remain consistent.
        $normalizedAllowedValues = [];
        foreach ($allowedValues as $allowedValue) {
            $allowedValue = strtolower($allowedValue);
            $allowedValue = preg_replace('/[^a-z0-9_:-]/', '', $allowedValue);

            if ($allowedValue === '') {
                continue;
            }

            $allowedValue = substr($allowedValue, 0, self::MAX_DIMENSION_VALUE_LENGTH);
            if ($allowedValue === '') {
                continue;
            }

            $normalizedAllowedValues[] = $allowedValue;
        }

        $normalizedAllowedValues = array_values(array_unique($normalizedAllowedValues));

        // If the caller explicitly provided an allow-list, require at least one
        // valid normalized value rather than silently broadening acceptance.
        if ($allowedValues !== [] && $normalizedAllowedValues === []) {
            return;
        }

        self::$registered[$name] = $normalizedAllowedValues;
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Resolve all context dimensions for the current request.
     *
     * Idempotent — safe to call many times; only the first call does work.
     * Must be called BEFORE any cache key is built.
     */
    public static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }

        self::$resolved = true;

        // Ensure built-in dimensions are registered.
        if (!array_key_exists(self::DIM_AUTH, self::$registered)) {
            self::$registered[self::DIM_AUTH] = [self::AUTH_AUTHENTICATED, self::AUTH_ANONYMOUS];
        }
        if (!array_key_exists(self::DIM_DEVICE, self::$registered)) {
            self::$registered[self::DIM_DEVICE] = [self::DEVICE_MOBILE, self::DEVICE_TABLET, self::DEVICE_DESKTOP];
        }
        if (!array_key_exists(self::DIM_EXPERIMENT, self::$registered)) {
            self::$registered[self::DIM_EXPERIMENT] = []; // any sanitized value
        }

        // Server-derived dimensions (highest priority)
        self::$dimensions[self::DIM_AUTH]   = self::resolveAuth();
        self::$dimensions[self::DIM_DEVICE] = self::resolveDevice();

        // Cookie-derived dimensions (lower priority)
        self::$dimensions[self::DIM_EXPERIMENT] = self::resolveExperiment();

        // Allow themes / plugins to inject additional registered dimensions via filter.
        // The filter output is passed through the constraint pipeline before use.
        $filtered = (array) apply_filters('starcache_context_dimensions', self::$dimensions);

        // Enforce constraints: only registered dimensions, within value bounds.
        self::$dimensions = self::applyConstraints($filtered);
    }

    /**
     * Lock the context. Called on `send_headers` (priority 1).
     * After lock(), set() is a no-op for all callers.
     */
    public static function lock(): void
    {
        if (!self::$resolved) {
            self::resolve();
        }
        self::$locked = true;
    }

    /**
     * Reset context state — test use only. Clears all state including registrations.
     */
    public static function reset(): void
    {
        self::$dimensions = [];
        self::$registered = [];
        self::$resolved   = false;
        self::$locked     = false;
    }

    // -------------------------------------------------------------------------
    // Dimension accessors
    // -------------------------------------------------------------------------

    /**
     * Set a dimension value. No-op if the dimension is not registered or if
     * context is already locked.
     *
     * @param string $dimension Dimension name — must already be registered.
     * @param string $value     Dimension value.
     */
    public static function set(string $dimension, string $value): void
    {
        if (self::$locked) {
            return;
        }
        if (!self::$resolved) {
            self::resolve();
        }
        $dimension = strtolower($dimension);
        if (!array_key_exists($dimension, self::$registered)) {
            // Silently reject unregistered dimensions.
            return;
        }

        $sanitized = self::sanitizeValue($value, $dimension);

        // Enforce allowed-value list if defined for this dimension.
        $allowedValues = self::$registered[$dimension];
        if (!empty($allowedValues) && !in_array($sanitized, $allowedValues, true)) {
            $sanitized = $allowedValues[0];
        }

        self::$dimensions[$dimension] = $sanitized;
    }

    /**
     * Get the resolved value for a dimension.
     *
     * @param string $dimension
     * @param string $default Returned when the dimension is not set.
     */
    public static function get(string $dimension, string $default = ''): string
    {
        if (!self::$resolved) {
            self::resolve();
        }
        $dimension = strtolower($dimension);
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
     * Return a stable, deterministic SHA-256 hash of the current context for
     * embedding in cache keys.
     *
     * The 'auth' dimension is intentionally EXCLUDED: authenticated requests
     * bypass all caches, so their context hash is never used in a key.
     *
     * @return string 64-character hex SHA-256 digest.
     */
    public static function hash(): string
    {
        if (!self::$resolved) {
            self::resolve();
        }

        $keyDimensions = self::$dimensions;
        unset($keyDimensions[self::DIM_AUTH]); // Auth = bypass signal, not a cache variant

        ksort($keyDimensions, SORT_STRING); // Deterministic order
        // JSON provides a more portable, cross-version-stable representation than
        // serialize(); this intentionally changes context hashes (cold-cache once).
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($keyDimensions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($keyDimensions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            self::logMessage('Failed to encode context dimensions as JSON, using empty object fallback: ' . json_last_error_msg());
            $json = '{}';
        }
        return hash('sha256', $json);
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
    // Dimension resolvers
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
        $ua = '';
        if (array_key_exists('HTTP_USER_AGENT', $_SERVER) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $ua = $_SERVER['HTTP_USER_AGENT'];
        }
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
     * The value is sanitised to [a-z0-9_:-] so it cannot poison cache keys.
     */
    private static function resolveExperiment(): string
    {
        $cookieName = (string) apply_filters('starcache_experiment_cookie', 'starcache_experiment');
        $raw = '';
        if (array_key_exists($cookieName, $_COOKIE) && is_string($_COOKIE[$cookieName])) {
            $raw = $_COOKIE[$cookieName];
        }
        return self::sanitizeValue($raw, self::DIM_EXPERIMENT);
    }

    // -------------------------------------------------------------------------
    // Constraint enforcement
    // -------------------------------------------------------------------------

    /**
     * Apply all constraints to a dimension map produced by the filter.
     *
     * Rules applied (in order):
     *  1. Drop dimensions not in the registered set.
     *  2. Sanitize values to [a-z0-9_:-], truncate to MAX_DIMENSION_VALUE_LENGTH.
     *  3. Enforce allowed-value lists where defined.
     *  4. Cap total dimension count at MAX_DIMENSIONS (excess dropped with log).
     *
     * @param  array<string,mixed> $raw
     * @return array<string,string>
     */
    private static function applyConstraints(array $raw): array
    {
        $clean = [];

        foreach ($raw as $name => $value) {
            // 1. Reject unregistered dimensions injected via filter.
            if (!array_key_exists((string) $name, self::$registered)) {
                continue;
            }

            // 2. Sanitize value.
            $sanitized = self::sanitizeValue((string) $value, (string) $name);

            // 3. Enforce allowed-value list if defined.
            $allowedValues = self::$registered[(string) $name];
            if (!empty($allowedValues) && !in_array($sanitized, $allowedValues, true)) {
                // Value not in allow-list: use the first allowed value as the default.
                $sanitized = $allowedValues[0];
            }

            $clean[(string) $name] = $sanitized;
        }

        // 4. Cap dimension count.
        if (count($clean) > self::MAX_DIMENSIONS) {
            self::logMessage(sprintf(
                'Context dimension count (%d) exceeds MAX_DIMENSIONS (%d). Excess dropped.',
                count($clean),
                self::MAX_DIMENSIONS
            ));
            ksort($clean, SORT_STRING);
            $clean = array_slice($clean, 0, self::MAX_DIMENSIONS, true);
        }

        return $clean;
    }

    /**
     * Sanitize a dimension value to [a-z0-9_:-], then truncate.
     *
     * @param  string $value
     * @param  string $dimensionName Used in log messages only.
     * @return string
     */
    private static function sanitizeValue(string $value, string $dimensionName): string
    {
        $sanitized = preg_replace('/[^a-z0-9_:-]/', '', strtolower($value)) ?? '';

        if (strlen($sanitized) > self::MAX_DIMENSION_VALUE_LENGTH) {
            $sanitized = substr($sanitized, 0, self::MAX_DIMENSION_VALUE_LENGTH);
        }

        return $sanitized;
    }

    /**
     * Log a context warning via StarExceptionHandler when available.
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
