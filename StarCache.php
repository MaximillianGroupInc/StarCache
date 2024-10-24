<?php

namespace StarCache;

use StarExceptionHandler;
use Exception;
use Memcache;
use Redis;

/**
 * StarCache Class
 *
 * This class provides a flexible and well-structured approach to managing caching in PHP/WordPress.
 * It utilizes dependency injection for cache adapters like Memcache and Redis, allowing for
 * flexibility in choosing the desired caching mechanism. The class also includes
 * methods for managing the cache, such as getting, setting, deleting, and flushing
 * cache data.
 *
 * @package StarCache
 * @author MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 1.0.0
 * @license Apache 2.0
 * @since 10/24/2024
 * @see https://github.com/MaximillianGroupInc/StarCache
 */
class StarCache
{
    /**
     * @var Memcache|Redis|null The cache adapter instance (Memcache or Redis).
     */
    private Memcache|Redis|null $cacheAdapter = null;

    /**
     * @var string|null The type of cache adapter used (e.g., 'memcache', 'redis').
     */
    private ?string $cacheAdapterType = null;

    // Expiration Constants
    const CACHE_EXPIRATION_STATIC = YEAR_IN_SECONDS;
    const CACHE_EXPIRATION_DYNAMIC = 3600; // 1 hour for dynamic cache
    const WINCACHE_EXPIRATION = 3600; // 1 hour expiration for WinCache

    /**
     * Constructor for the StarCache class.
     *
     * @param Memcache|Redis|null $cacheAdapter The cache adapter instance (Memcache or Redis).
     * @param string|null $cacheAdapterType The type of cache adapter used (e.g., 'memcache', 'redis').
     */
    public function __construct(Memcache|Redis|null $cacheAdapter = null, string $cacheAdapterType = null)
    {
        $this->cacheAdapter = $cacheAdapter;
        $this->cacheAdapterType = $cacheAdapterType;
    }

    /**
     * Generates a cache group based on the table name and user ID.
     *
     * @param string $reference The name of the reference.
     * @param string|null $userId The user ID (optional).
     * @return string The generated cache group.
     */
    public function star_getUserGroup(string $reference, string $userId = null): string
    {
        return $userId ? 'user_' . $userId : $reference;
    }

    /**
     * Flushes and reloads cached data for the specified reference and optional user ID.
     *
     * @param string $reference The name of the reference.
     * @param string|null $userId The user ID (optional).
     */
    public function star_flushReloadCachedData(string $reference, string $userId = null): void
    {
        $locksmith = new StarCacheKey();
        $cacheKey = $locksmith->star_getCacheKey($reference, $userId);
        $userGroup = $this->star_getUserGroup($reference, $userId);

        try {
            $this->star_deleteCache($cacheKey, $userGroup);
        } catch (Exception $e) {
            $this->star_logError('Error flushing cache', $e);
        }
    }

    /**
     * Retrieves cached data based on the reference name and optional user ID.
     *
     * @param string $reference The name of the reference.
     * @param string|null $userId The user ID (optional).
     * @return array|false The retrieved cached data or false if not found.
     */
    public function star_getCachedData(string $reference, string $userId = null): array|false
    {
        $locksmith = new StarCacheKey();
        $cacheKey = $locksmith->star_getCacheKey($reference, $userId);
        $userGroup = $this->star_getUserGroup($reference, $userId);

        try {
            return $this->star_getCache($cacheKey, $userGroup);
        } catch (Exception $e) {
            $this->star_logError('Error getting cached data', $e);
            return false;
        }
    }

    /**
     * Sets cached data with a specific reference, user ID, and static/dynamic flag.
     *
     * @param array $data The data to cache.
     * @param string $reference The name of the reference.
     * @param string|null $userId The user ID (optional).
     * @param bool $isStatic Whether to set static (long-term) or dynamic (short-term) cache.
     * @return bool True if the data was successfully cached, false otherwise.
     */
    public function star_setCachedData(array $data, string $reference, string $userId = null, bool $isStatic = false): bool
    {
        $locksmith = new StarCacheKey();
        $cacheKey = $locksmith->star_getCacheKey($reference, $userId);
        $userGroup = $this->star_getUserGroup($reference, $userId);
        $expiration = $isStatic ? self::CACHE_EXPIRATION_STATIC : self::CACHE_EXPIRATION_DYNAMIC;

        try {
            if (!$this->star_setCache($cacheKey, $data, $userGroup, $expiration)) {
                throw new Exception('Failed to set object cache');
            }

            return true;
        } catch (Exception $e) {
            $this->star_logError('Error setting cache', $e);
            return false;
        }
    }

    /**
     * Retrieves cached data from the chosen adapter based on the cache key and group.
     *
     * @param string $cacheKey The cache key.
     * @param string|null $userGroup The cache group (optional).
     * @return array|false The retrieved cached data or false if not found.
     */
    private function star_getCache(string $cacheKey, string $userGroup = null): array|false
    {
        try {
            if ($this->cacheAdapterType === 'memcache') {
                return $this->cacheAdapter->get($cacheKey) ?: get_transient($cacheKey);
            }

            if ($this->cacheAdapterType === 'redis') {
                return $this->cacheAdapter->get($cacheKey) ?: get_transient($cacheKey);
            }

            if (function_exists('wp_cache_get')) {
                $cachedData = wp_cache_get($cacheKey, $userGroup);
                return $cachedData ?: get_transient($cacheKey);
            }

            if (function_exists('wincache_ucache_get')) {
                $cachedData = wincache_ucache_get($cacheKey, $success);
                return $success ? $cachedData : get_transient($cacheKey);
            }

            return wp_cache_get($cacheKey, $userGroup) ?: get_transient($cacheKey);
        } catch (Exception $e) {
            $this->star_logError('Error getting cache', $e);
            return false;
        }
    }

    /**
     * Sets cached data using the chosen adapter with the cache key, data, group, and expiration.
     *
     * @param string $cacheKey The cache key.
     * @param array $data The data to cache.
     * @param string $userGroup The cache group.
     * @param int $expiration The expiration time in seconds (default: 3600).
     * @return bool True if the data was successfully cached, false otherwise.
     */
    private function star_setCache(string $cacheKey, array $data, string $userGroup, int $expiration = 3600): bool
    {
        try {
            if ($this->cacheAdapterType === 'memcache') {
                return $this->cacheAdapter->set($cacheKey, $data, false, $expiration);
            }

            if ($this->cacheAdapterType === 'redis') {
                return $this->cacheAdapter->set($cacheKey, $data, ['EX' => $expiration]);
            }

            if (function_exists('wp_cache_set')) {
                return wp_cache_set($cacheKey, $data, $userGroup, $expiration);
            }

            if (function_exists('wincache_ucache_set')) {
                return wincache_ucache_set($cacheKey, $data, self::WINCACHE_EXPIRATION);
            }

            return wp_cache_set($cacheKey, $data, $userGroup, $expiration);
        } catch (Exception $e) {
            $this->star_logError('Error setting cache', $e);
            return false;
        }
    }

    /**
     * Deletes cached data from the chosen adapter using the cache key and group.
     *
     * @param string $cacheKey The cache key.
     * @param string $userGroup The cache group.
     */
    private function star_deleteCache(string $cacheKey, string $userGroup): void
    {
        try {
            if ($this->cacheAdapterType === 'memcache') {
                $this->cacheAdapter->delete($cacheKey);
            }

            if ($this->cacheAdapterType === 'redis') {
                $this->cacheAdapter->del($cacheKey);
            }

            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($cacheKey, $userGroup);
            }

            if (function_exists('wincache_ucache_delete')) {
                wincache_ucache_delete($cacheKey);
            }

            wp_cache_delete($cacheKey, $userGroup);
        } catch (Exception $e) {
            $this->star_logError('Error deleting cache', $e);
        }
    }

     /**
     * Logs errors using either StarExceptionHandler or error_log() if StarExceptionHandler is not available.
     *
     * @param string $message The error message to log.
     * @param Exception $e The exception object.
     */
    private static function star_logError(string $message, Exception $e): void
    {
        if (class_exists('StarExceptionHandler')) {
            $logger = StarExceptionHandler::star_getInstance();
            $logger->star_handleException($e);
        } else {
            $errorMessage = "{$message}: {$e->getMessage()}\n{$e->getTraceAsString()}";
            error_log($errorMessage, 0); // Use error_log for logging when StarExceptionHandler is not available
        }
    }

    /**
     * Closes the connections to the cache adapter and optionally deletes transients.
     *
     * @param bool $isStatic Whether to delete transients (false) or not (true).
     * @param string|null $cacheKey The cache key (optional).
     */
    public function star_closeConnections(bool $isStatic = false, ?string $cacheKey = null): void
    {
        if ($this->cacheAdapterType === 'memcache' || $this->cacheAdapterType === 'redis') {
            $this->cacheAdapter->close();
            $this->cacheAdapter = null;
        }

        if (!$isStatic && $cacheKey) {
            global $wpdb;
            $transients = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
                $cacheKey
            ));

            foreach ($transients as $transient) {
                delete_transient($transient);
            }
        }
    }
}
