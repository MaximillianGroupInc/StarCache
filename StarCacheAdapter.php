<?php

declare(strict_types=1);

namespace StarCache;

use Exception;

/**
 * StarCacheAdapter
 *
 * Auto-detects and initialises the best available cache backend:
 * Redis → Memcached → Memcache → WordPress object cache (APCu / file / DB).
 * OPcache is reported but managed by PHP itself; this class exposes a helper
 * to check its status.
 *
 * Connection parameters are read from WordPress constants when defined:
 *   WP_REDIS_HOST, WP_REDIS_PORT, WP_REDIS_PASSWORD, WP_REDIS_DATABASE
 *   MEMCACHED_SERVERS (array of ['host', 'port'] pairs)
 *   MEMCACHE_SERVER_HOST / MEMCACHE_SERVER_PORT
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.0.0
 * @license Apache 2.0
 */
class StarCacheAdapter
{
    public const BACKEND_REDIS     = 'redis';
    public const BACKEND_MEMCACHED = 'memcached';
    public const BACKEND_MEMCACHE  = 'memcache';
    public const BACKEND_WP        = 'wp';

    /** @var \Redis|\Memcached|\Memcache|null */
    private static $connection = null;

    /** @var string */
    private static string $detectedBackend = self::BACKEND_WP;

    /** @var bool */
    private static bool $initialised = false;

    /**
     * Initialise the adapter (idempotent – safe to call multiple times).
     */
    public static function init(): void
    {
        if (self::$initialised) {
            return;
        }

        self::$initialised = true;

        try {
            if (self::tryRedis()) {
                return;
            }
            if (self::tryMemcached()) {
                return;
            }
            if (self::tryMemcache()) {
                return;
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter init error', $e);
        }

        // Fallback: WordPress built-in object cache (wp_cache_*)
        self::$detectedBackend = self::BACKEND_WP;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns the name of the active backend.
     */
    public static function getBackend(): string
    {
        return self::$detectedBackend;
    }

    /**
     * Returns the raw connection object (Redis / Memcached / Memcache) or null
     * when the WordPress object cache is used.
     *
     * @return \Redis|\Memcached|\Memcache|null
     */
    public static function getConnection()
    {
        return self::$connection;
    }

    /**
     * Returns true when OPcache is enabled and functioning.
     */
    public static function isOpcacheEnabled(): bool
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }
        $status = @opcache_get_status(false);
        return is_array($status) && !empty($status['opcache_enabled']);
    }

    /**
     * Retrieve a value from the active cache backend.
     *
     * @param string      $key
     * @param string|null $group  Used only by the WP object cache.
     * @return mixed|false
     */
    public static function get(string $key, string $group = '')
    {
        try {
            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    $value = self::$connection->get($key);
                    return ($value === false) ? false : @unserialize($value);

                case self::BACKEND_MEMCACHED:
                case self::BACKEND_MEMCACHE:
                    $value = self::$connection->get($key);
                    return ($value === false) ? false : $value;

                default:
                    return wp_cache_get($key, $group);
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::get', $e);
            return false;
        }
    }

    /**
     * Store a value in the active cache backend.
     *
     * @param string      $key
     * @param mixed       $value
     * @param int         $expiration  Seconds (0 = no expiry for WP/Redis).
     * @param string|null $group       Used only by the WP object cache.
     * @return bool
     */
    public static function set(string $key, $value, int $expiration = 3600, string $group = ''): bool
    {
        try {
            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    $serialised = serialize($value);
                    if ($expiration > 0) {
                        return (bool) self::$connection->setEx($key, $expiration, $serialised);
                    }
                    return (bool) self::$connection->set($key, $serialised);

                case self::BACKEND_MEMCACHED:
                    return self::$connection->set($key, $value, $expiration);

                case self::BACKEND_MEMCACHE:
                    return self::$connection->set($key, $value, false, $expiration);

                default:
                    return wp_cache_set($key, $value, $group, $expiration);
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::set', $e);
            return false;
        }
    }

    /**
     * Delete a cached value.
     *
     * @param string      $key
     * @param string|null $group  Used only by the WP object cache.
     */
    public static function delete(string $key, string $group = ''): bool
    {
        try {
            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    return (bool) self::$connection->del($key);

                case self::BACKEND_MEMCACHED:
                    return self::$connection->delete($key);

                case self::BACKEND_MEMCACHE:
                    return self::$connection->delete($key);

                default:
                    return wp_cache_delete($key, $group);
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::delete', $e);
            return false;
        }
    }

    /**
     * Flush all cache entries (use with care in shared environments).
     */
    public static function flush(): bool
    {
        try {
            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    return (bool) self::$connection->flushAll();

                case self::BACKEND_MEMCACHED:
                    return self::$connection->flush();

                case self::BACKEND_MEMCACHE:
                    return self::$connection->flush();

                default:
                    return wp_cache_flush();
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::flush', $e);
            return false;
        }
    }

    /**
     * Close the underlying connection (no-op for WP cache).
     */
    public static function close(): void
    {
        if (self::$connection === null) {
            return;
        }
        try {
            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    self::$connection->close();
                    break;
                case self::BACKEND_MEMCACHED:
                case self::BACKEND_MEMCACHE:
                    self::$connection->close();
                    break;
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::close', $e);
        }
        self::$connection = null;
    }

    // -------------------------------------------------------------------------
    // Backend detection
    // -------------------------------------------------------------------------

    private static function tryRedis(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }

        $host     = defined('WP_REDIS_HOST')     ? WP_REDIS_HOST     : '127.0.0.1';
        $port     = defined('WP_REDIS_PORT')     ? (int) WP_REDIS_PORT : 6379;
        $password = defined('WP_REDIS_PASSWORD') ? WP_REDIS_PASSWORD  : null;
        $database = defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : 0;

        $redis = new \Redis();

        if (!@$redis->connect($host, $port, 1.0)) {
            return false;
        }

        if ($password && !$redis->auth($password)) {
            return false;
        }

        if ($database !== 0) {
            $redis->select($database);
        }

        self::$connection     = $redis;
        self::$detectedBackend = self::BACKEND_REDIS;
        return true;
    }

    private static function tryMemcached(): bool
    {
        if (!extension_loaded('memcached')) {
            return false;
        }

        $memcached = new \Memcached();

        if (defined('MEMCACHED_SERVERS') && is_array(MEMCACHED_SERVERS)) {
            foreach (MEMCACHED_SERVERS as $server) {
                $memcached->addServer(
                    $server['host'] ?? '127.0.0.1',
                    (int) ($server['port'] ?? 11211)
                );
            }
        } else {
            $memcached->addServer('127.0.0.1', 11211);
        }

        // Verify connectivity via a trivial set/get
        $testKey = 'starcache_probe_' . wp_generate_password(8, false);
        $memcached->set($testKey, 1, 5);
        if ($memcached->getResultCode() !== \Memcached::RES_SUCCESS) {
            return false;
        }
        $memcached->delete($testKey);

        self::$connection      = $memcached;
        self::$detectedBackend = self::BACKEND_MEMCACHED;
        return true;
    }

    private static function tryMemcache(): bool
    {
        if (!extension_loaded('memcache')) {
            return false;
        }

        $host = defined('MEMCACHE_SERVER_HOST') ? MEMCACHE_SERVER_HOST : '127.0.0.1';
        $port = defined('MEMCACHE_SERVER_PORT') ? (int) MEMCACHE_SERVER_PORT : 11211;

        $memcache = new \Memcache();
        if (!@$memcache->connect($host, $port)) {
            return false;
        }

        self::$connection      = $memcache;
        self::$detectedBackend = self::BACKEND_MEMCACHE;
        return true;
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private static function logError(string $context, Exception $e): void
    {
        error_log("[StarCache] {$context}: {$e->getMessage()}");
    }
}
