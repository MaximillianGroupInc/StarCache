<?php

namespace StarCache;

/**
 * StarVersionStore — Version-based cache invalidation
 *
 * Instead of flushing or directly deleting cache entries, this class
 * maintains a monotonically-increasing integer version counter for named
 * groups.  When a version is bumped the counter increments, which causes
 * all subsequent cache-key lookups (which embed the version) to miss
 * automatically.  Stale entries are left to expire on their own TTL.
 *
 * This avoids the thundering-herd problem associated with global flushes
 * and is consistent with how WordPress VIP handles cache invalidation
 * without relying on `wp_cache_flush()`.
 *
 * Built-in groups
 * ---------------
 *   'content'   — bumped when any post is saved/published/deleted
 *   'fragments' — bumped when a global fragment refresh is needed
 *   'queries'   — bumped when query caches should be globally invalidated
 *
 * Custom groups can be created by calling get() / bump() with any string.
 *
 * Usage
 * -----
 *   // Embed version in a cache key:
 *   $version = StarVersionStore::get('content');
 *   $key = 'my_key_v' . $version;
 *
 *   // Invalidate all entries that used the old version:
 *   StarVersionStore::bump('content');
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.0
 * @license Apache 2.0
 */
class StarVersionStore
{
    // -------------------------------------------------------------------------
    // Built-in group name constants
    // -------------------------------------------------------------------------

    public const GROUP_CONTENT   = 'content';
    public const GROUP_FRAGMENTS = 'fragments';
    public const GROUP_QUERIES   = 'queries';

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private const KEY_PREFIX   = 'sc_ver_';
    private const CACHE_GROUP  = 'starcache_version';
    private const INITIAL_VER  = 1;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the current version number for a named group.
     *
     * @param  string $group  Logical group name.
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
        $newVersion = self::get($group) + 1;
        StarCacheAdapter::set(self::buildKey($group), $newVersion, 0, self::CACHE_GROUP);
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
     * Used by WP-CLI's `wp starcache flush` command.
     */
    public static function bumpAll(): void
    {
        self::bump(self::GROUP_CONTENT);
        self::bump(self::GROUP_FRAGMENTS);
        self::bump(self::GROUP_QUERIES);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build the raw (non-hashed) storage key for a version counter.
     *
     * Keys are scoped to the current blog ID for multisite isolation.
     *
     * @param  string $group
     * @return string
     */
    private static function buildKey(string $group): string
    {
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        return self::KEY_PREFIX . $blogId . '_' . md5($group);
    }
}
