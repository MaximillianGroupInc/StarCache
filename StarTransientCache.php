<?php

declare(strict_types=1);

namespace StarCache;

use Exception;

/**
 * StarTransientCache
 *
 * Thin wrapper around WordPress transients with two enhancements:
 *
 * 1. Multisite-aware keys – each site's transients are namespaced by blog ID
 *    so that a flush on site A never affects site B.
 *
 * 2. Network transients – helper methods for data that should be shared
 *    across ALL sites in a multisite network (uses set_site_transient /
 *    get_site_transient).
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.0.0
 * @license Apache 2.0
 */
class StarTransientCache
{
    /** Default expiry for dynamic transients (1 hour). */
    public const EXPIRATION_DYNAMIC = 3600;

    /** Default expiry for static transients (1 year). */
    public const EXPIRATION_STATIC = 31536000;

    // ------------------------------------------------------------------
    // Per-site transients
    // ------------------------------------------------------------------

    /**
     * Store a value as a WordPress transient.
     *
     * @param  mixed       $data       Any serialisable value.
     * @param  string      $reference  Logical name / feature slug.
     * @param  string|null $userId     Optional user identifier.
     * @param  bool        $isStatic   True = 1-year expiry; false = 1-hour expiry.
     * @return bool
     */
    public static function star_setCachedData(
        $data,
        string $reference,
        ?string $userId = null,
        bool $isStatic = false
    ): bool {
        $key        = self::buildKey($reference, $userId);
        $expiration = $isStatic ? self::EXPIRATION_STATIC : self::EXPIRATION_DYNAMIC;

        try {
            if (!set_transient($key, $data, $expiration)) {
                throw new Exception('set_transient returned false');
            }
            return true;
        } catch (Exception $e) {
            self::logError('Error setting transient cache', $e);
            return false;
        }
    }

    /**
     * Retrieve a transient value.
     *
     * @param  string      $reference
     * @param  string|null $userId
     * @return mixed|false  Cached value or false on miss / error.
     */
    public static function star_getCachedData(string $reference, ?string $userId = null)
    {
        $key = self::buildKey($reference, $userId);

        try {
            return get_transient($key);
        } catch (Exception $e) {
            self::logError('Error getting transient cache', $e);
            return false;
        }
    }

    /**
     * Delete a transient.
     *
     * @param  string      $reference
     * @param  string|null $userId
     */
    public static function star_deleteCache(string $reference, ?string $userId = null): void
    {
        $key = self::buildKey($reference, $userId);

        try {
            delete_transient($key);
        } catch (Exception $e) {
            self::logError('Error deleting transient cache', $e);
        }
    }

    // ------------------------------------------------------------------
    // Network-wide (site) transients – shared across all multisite blogs
    // ------------------------------------------------------------------

    /**
     * Store a network-wide transient (uses set_site_transient).
     *
     * @param  mixed  $data
     * @param  string $reference
     * @param  bool   $isStatic
     * @return bool
     */
    public static function star_setNetworkCachedData($data, string $reference, bool $isStatic = false): bool
    {
        $key        = self::buildNetworkKey($reference);
        $expiration = $isStatic ? self::EXPIRATION_STATIC : self::EXPIRATION_DYNAMIC;

        try {
            if (!set_site_transient($key, $data, $expiration)) {
                throw new Exception('set_site_transient returned false');
            }
            return true;
        } catch (Exception $e) {
            self::logError('Error setting network transient cache', $e);
            return false;
        }
    }

    /**
     * Retrieve a network-wide transient.
     *
     * @param  string $reference
     * @return mixed|false
     */
    public static function star_getNetworkCachedData(string $reference)
    {
        $key = self::buildNetworkKey($reference);

        try {
            return get_site_transient($key);
        } catch (Exception $e) {
            self::logError('Error getting network transient cache', $e);
            return false;
        }
    }

    /**
     * Delete a network-wide transient.
     *
     * @param string $reference
     */
    public static function star_deleteNetworkCache(string $reference): void
    {
        $key = self::buildNetworkKey($reference);

        try {
            delete_site_transient($key);
        } catch (Exception $e) {
            self::logError('Error deleting network transient cache', $e);
        }
    }

    // ------------------------------------------------------------------
    // Key helpers
    // ------------------------------------------------------------------

    /**
     * Build a per-site transient key.
     *
     * @param  string      $reference
     * @param  string|null $userId
     * @return string
     */
    private static function buildKey(string $reference, ?string $userId): string
    {
        $locksmith = new StarCacheKey();
        return $locksmith->star_getCacheKey($reference, $userId);
    }

    /**
     * Build a network-wide transient key.
     *
     * @param  string $reference
     * @return string
     */
    private static function buildNetworkKey(string $reference): string
    {
        $locksmith = new StarCacheKey();
        return $locksmith->star_getNetworkKey($reference);
    }

    // ------------------------------------------------------------------
    // Error logging
    // ------------------------------------------------------------------

    /**
     * Log errors via StarExceptionHandler when available, otherwise error_log().
     *
     * StarExceptionHandler is an optional external class expected in the global
     * namespace (not within StarCache\).  The leading backslash is intentional.
     */
    private static function logError(string $message, Exception $e): void
    {
        if (class_exists('StarExceptionHandler')) {
            $logger = \StarExceptionHandler::star_getInstance();
            $logger->star_handleException($e);
        } else {
            error_log("[StarCache] {$message}: {$e->getMessage()}\n{$e->getTraceAsString()}");
        }
    }
}
