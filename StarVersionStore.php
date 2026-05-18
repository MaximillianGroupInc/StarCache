<?php

declare(strict_types=1);

namespace StarCache;

/**
 * StarVersionStore — Version-based cache invalidation
 *
 * Maintains monotonically-increasing integer version counters for named groups.
 * When a version is bumped the counter increments, which causes all subsequent
 * cache-key lookups (which embed the version) to miss automatically.
 * Stale entries expire naturally on their own TTL — no flush required.
 *
 * This avoids the thundering-herd problem caused by global cache flushes and
 * is consistent with how WordPress VIP handles cache invalidation without
 * relying on `wp_cache_flush()`.
 *
 * Built-in groups
 * ---------------
 *   GROUP_PAGES   — full-page cache entries (bumped on save_post / status change)
 *   GROUP_QUERIES — WP_Query results       (bumped on clean_post_cache)
 *   GROUP_OBJECTS — arbitrary object cache (bumped for fragment / data cache)
 *
 * WP-CLI integration
 * ------------------
 *   wp starcache flush  → bumps all groups (NOT a global flush)
 *   wp starcache status → shows backend + context
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.1
 * @license Apache 2.0
 */
class StarVersionStore
{
    // -------------------------------------------------------------------------
    // Group name constants
    // -------------------------------------------------------------------------

    /** Full-page cache version group. */
    public const GROUP_PAGES   = 'pages';

    /** WP_Query / SQL result cache version group. */
    public const GROUP_QUERIES = 'queries';

    /** Arbitrary object / fragment cache version group. */
    public const GROUP_OBJECTS = 'objects';

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private const KEY_PREFIX  = 'sc_ver_';
    private const CACHE_GROUP = 'starcache_version';
    private const INITIAL_VER = 1;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the current version number for a named group.
     *
     * @param  string $group  Logical group name (use GROUP_* constants).
     * @return int            Version number (≥ 1).
     */
    public static function get(string $group): int
    {
        $key     = self::buildKey($group);
        $version = StarCacheAdapter::get($key, self::CACHE_GROUP);
        return ($version !== false && is_int($version)) ? $version : self::INITIAL_VER;
    }

    /**
     * Increment the version for a named group by 1.
     *
     * All cache entries whose keys embedded the previous version number are
     * now effectively unreachable and will expire naturally.
     *
     * @param  string $group  Logical group name.
     * @return int            The new (incremented) version number.
     */
    public static function bump(string $group): int
    {
        $key        = self::buildKey($group);
        $newVersion = self::atomicBump($key);
        do_action('starcache_version_bumped', $group, $newVersion);
        return $newVersion;
    }

    /**
     * Reset a group's version back to the initial value (1).
     *
     * Use only for testing or after a full cache flush on deploy.
     *
     * @param string $group
     */
    public static function reset(string $group): void
    {
        StarCacheAdapter::set(self::buildKey($group), self::INITIAL_VER, 0, self::CACHE_GROUP);
    }

    /**
     * Bump all built-in groups at once.
     * Called by WP-CLI `wp starcache flush` — NOT a global cache flush.
     */
    public static function bumpAll(): void
    {
        self::bump(self::GROUP_PAGES);
        self::bump(self::GROUP_QUERIES);
        self::bump(self::GROUP_OBJECTS);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build the raw (non-hashed) storage key for a version counter.
     *
     * Keys are scoped to the current blog ID for multisite isolation.
     */
    private static function buildKey(string $group): string
    {
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        return self::KEY_PREFIX . $blogId . '_' . hash('sha256', $group);
    }

    /**
     * Atomically bump a version key when backend support exists.
     * Falls back to hrtime(true) without read-modify-write.
     */
    private static function atomicBump(string $key): int
    {
        $backend    = StarCacheAdapter::getBackend();
        $connection = StarCacheAdapter::getConnection();

        if ($backend === StarCacheAdapter::BACKEND_REDIS && $connection instanceof \Redis) {
            $connection->setNx($key, self::INITIAL_VER);
            $result = $connection->incr($key);
            if (is_int($result)) {
                return $result;
            }
        }

        if ($backend === StarCacheAdapter::BACKEND_MEMCACHED && $connection instanceof \Memcached) {
            $result = $connection->increment($key, 1, self::INITIAL_VER + 1, 0);
            if (is_int($result)) {
                return $result;
            }
        }

        if ($backend === StarCacheAdapter::BACKEND_MEMCACHE && $connection instanceof \Memcache) {
            $connection->add($key, self::INITIAL_VER, 0, 0);
            $result = $connection->increment($key, 1);
            if ($result !== false) {
                return (int) $result;
            }
        }

        if (function_exists('wp_cache_incr')) {
            $result = wp_cache_incr($key, 1, self::CACHE_GROUP);
            if (is_int($result)) {
                return $result;
            }
        }

        // Fallback remains race-free without read-modify-write: each writer stores
        // a high-resolution timestamp version (nanoseconds), not a strict +1 counter.
        $newVersion = hrtime(true);
        StarCacheAdapter::set($key, $newVersion, 0, self::CACHE_GROUP);
        return $newVersion;
    }
}
