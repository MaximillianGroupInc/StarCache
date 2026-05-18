<?php

declare(strict_types=1);

namespace StarCache;

/**
 * StarQueryCache — DEPRECATED
 *
 * @deprecated This hook-based query cache system will be removed in v3.0.
 *             Use star_cache_remember() instead:
 *
 *             $posts = star_cache_remember(
 *                 'homepage_posts',
 *                 fn() => get_posts(['numberposts' => 10]),
 *                 3600
 *             );
 *
 * The posts_pre_query and the_posts hooks registered by this class have been
 * removed from starcache.php. This class is retained only for the
 * cachedWpdbQuery() utility and for any third-party code that may still call
 * its static methods directly.
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.1
 * @license Apache 2.0
 */
class StarQueryCache
{
    /** Object-cache group for query result sets. */
    private const GROUP_QUERY = 'starcache_query';

    /** Object-cache group for raw wpdb queries. */
    private const GROUP_WPDB  = 'starcache_wpdb';

    /** Default TTL for query caches (5 minutes). */
    public const TTL_QUERY = 300;

    /** Minimum query length to be worth caching (avoid caching trivial queries). */
    private const MIN_QUERY_LENGTH = 20;

    // -------------------------------------------------------------------------
    // WP_Query hooks
    // -------------------------------------------------------------------------

    /**
     * `posts_pre_query` filter.
     *
     * Return cached posts array to short-circuit the database query.
     *
     * @param  \WP_Post[]|null $posts   NULL by default; returning an array short-circuits.
     * @param  \WP_Query       $query
     * @return \WP_Post[]|null
     */
    public static function postsPreQuery(?array $posts, \WP_Query $query): ?array
    {
        if (!self::isCacheableQuery($query)) {
            return $posts;
        }

        $key    = self::buildQueryKey($query);
        $cached = StarCacheAdapter::get($key, self::GROUP_QUERY);

        if ($cached === false || !is_array($cached)) {
            return $posts;
        }

        // Restore found_posts and max_num_pages from cached meta
        if (isset($cached['found_posts'])) {
            $query->found_posts   = (int) $cached['found_posts'];
            $query->max_num_pages = (int) ($cached['max_num_pages'] ?? 1);
        }

        return $cached['posts'] ?? [];
    }

    /**
     * `the_posts` filter.
     *
     * Store the posts returned by WP_Query into the object cache.
     *
     * @param  \WP_Post[] $posts
     * @param  \WP_Query  $query
     * @return \WP_Post[]
     */
    public static function thePosts(array $posts, \WP_Query $query): array
    {
        if (!self::isCacheableQuery($query) || empty($posts)) {
            return $posts;
        }

        $key = self::buildQueryKey($query);
        $ttl = (int) apply_filters('starcache_query_ttl', self::TTL_QUERY);

        $payload = [
            'posts'         => $posts,
            'found_posts'   => $query->found_posts,
            'max_num_pages' => $query->max_num_pages,
        ];

        StarCacheAdapter::set($key, $payload, $ttl, self::GROUP_QUERY);

        return $posts;
    }

    // -------------------------------------------------------------------------
    // Raw wpdb query cache
    // -------------------------------------------------------------------------

    /**
     * Cache the results of an arbitrary wpdb SELECT query.
     *
     * Call this as a wrapper around $wpdb->get_results() / $wpdb->get_col()
     * when you want to cache the result.
     *
     * @deprecated 2.1.1 Use star_cache_remember() instead, which provides the same
     *             cache-aside pattern and integrates with the version-bump invalidation
     *             system.  Direct replacement example:
     *
     *             // Before (StarQueryCache):
     *             $rows = StarQueryCache::cachedWpdbQuery(
     *                 $wpdb->prepare('SELECT ID FROM wp_posts WHERE post_status=%s', 'publish'),
     *                 ARRAY_A,
     *                 300
     *             );
     *
     *             // After (star_cache_remember):
     *             $rows = star_cache_remember('my_posts', static function () use ($wpdb): array {
     *                 return $wpdb->get_results(
     *                     $wpdb->prepare('SELECT ID FROM wp_posts WHERE post_status=%s', 'publish'),
     *                     ARRAY_A
     *                 ) ?? [];
     *             }, 300);
     *
     *             This method will be removed in v3.0.
     *
     * @param  string   $sql        Prepared SQL string.
     * @param  string   $output     WPDB output constant (ARRAY_A, OBJECT, etc.).
     * @param  int      $ttl
     * @return mixed
     */
    public static function cachedWpdbQuery(string $sql, string $output = ARRAY_A, int $ttl = self::TTL_QUERY): mixed
    {
        global $wpdb;

        if (strlen($sql) < self::MIN_QUERY_LENGTH) {
            return $wpdb->get_results($sql, $output);
        }

        $key    = self::buildSqlKey($sql);
        $cached = StarCacheAdapter::get($key, self::GROUP_WPDB);

        if ($cached !== false) {
            return $cached;
        }

        $results = $wpdb->get_results($sql, $output);

        if ($wpdb->last_error === '' && $results !== null) {
            StarCacheAdapter::set($key, $results, $ttl, self::GROUP_WPDB);
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Cache invalidation
    // -------------------------------------------------------------------------

    /**
     * Invalidate all query caches that reference the given post.
     * Hooked to `clean_post_cache`.
     *
     * @param int $postId
     */
    public static function invalidatePostCaches(int $postId): void
    {
        // WordPress object cache already handles per-post object cache groups.
        // We flush the broader query group to keep things consistent.
        if (function_exists('wp_cache_delete_group')) {
            wp_cache_delete_group(self::GROUP_QUERY);
        }

        do_action('starcache_after_query_invalidate', $postId);
    }

    /**
     * Invalidate all cached raw SQL queries.
     * Call this after bulk data changes, imports, etc.
     */
    public static function flushQueryCache(): void
    {
        if (function_exists('wp_cache_delete_group')) {
            wp_cache_delete_group(self::GROUP_QUERY);
            wp_cache_delete_group(self::GROUP_WPDB);
        }
    }

    // -------------------------------------------------------------------------
    // Key builders
    // -------------------------------------------------------------------------

    /**
     * Build a deterministic, multisite-aware cache key for a WP_Query.
     *
     * @param \WP_Query $query
     * @return string
     */
    private static function buildQueryKey(\WP_Query $query): string
    {
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        $vars   = $query->query_vars;

        // Normalise – sort so equivalent queries always produce the same key
        ksort($vars);
        return 'sc_q_' . $blogId . '_' . md5(serialize($vars));
    }

    /**
     * Build a cache key for a raw SQL statement.
     *
     * @param string $sql
     * @return string
     */
    private static function buildSqlKey(string $sql): string
    {
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        return 'sc_sql_' . $blogId . '_' . md5($sql);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Decide whether a given WP_Query result should be cached.
     *
     * We skip caching for:
     * - Admin queries
     * - Queries with suppress_filters
     * - Singular pages for logged-in users (personalised)
     * - WP-Cron
     *
     * @param \WP_Query $query
     * @return bool
     */
    private static function isCacheableQuery(\WP_Query $query): bool
    {
        if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) {
            return false;
        }

        if (!empty($query->get('suppress_filters'))) {
            return false;
        }

        if (is_user_logged_in() && $query->is_singular()) {
            return false;
        }

        return (bool) apply_filters('starcache_cache_query', true, $query);
    }
}
