<?php

namespace StarCache;

/**
 * StarCacheKey
 *
 * Generates deterministic, secure, multisite-aware cache keys.
 *
 * Keys are built from a namespace, optional user ID, reference string, and a
 * salt derived from WordPress authentication keys (or a configurable fallback).
 * The composite is hashed with SHA-256 so keys are always a fixed length and
 * never expose raw data.
 *
 * Multisite: the current blog ID is embedded in the key so each site in a
 * network receives its own isolated cache namespace without extra configuration.
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.0.0
 * @license Apache 2.0
 */
class StarCacheKey
{
    /** @var string Namespace prefix for all keys produced by this instance. */
    private string $namespace;

    /** @var string Salt appended before hashing to prevent key collisions. */
    private string $salt;

    /**
     * @param string|null $salt      Custom salt; defaults to AUTH_KEY + SECURE_AUTH_SALT
     *                               or 'default_salt' when WP constants are absent.
     * @param string      $namespace Namespace prefix (default: 'star_cache').
     */
    public function __construct(?string $salt = null, string $namespace = 'star_cache')
    {
        $this->salt = $salt ?? (
            defined('AUTH_KEY') && defined('SECURE_AUTH_SALT')
                ? AUTH_KEY . SECURE_AUTH_SALT
                : 'default_salt'
        );
        $this->namespace = $namespace;
    }

    /**
     * SHA-256 hash a raw string.
     *
     * @param string $key  Raw string to hash.
     * @return string      64-character hex digest.
     */
    public static function star_hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Generate a secure, multisite-aware cache key.
     *
     * The key incorporates:
     *   - The current blog ID (multisite isolation)
     *   - The configured namespace
     *   - An optional user ID (per-user personalisation)
     *   - The reference/table name
     *   - The salt (security)
     *
     * @param string      $reference  Logical name for the cached data (e.g. table name, feature slug).
     * @param string|null $userId     Optional user identifier for personalised caches.
     * @return string                 64-character hex cache key.
     */
    public function star_getCacheKey(string $reference, ?string $userId = null): string
    {
        if (!is_string($reference) || $reference === '') {
            throw new \InvalidArgumentException('StarCacheKey: $reference must be a non-empty string.');
        }

        $userId = ($userId !== null) ? (string) $userId : '';

        // Include blog ID for multisite isolation
        $blogId = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '1';

        $rawKey      = $this->namespace . '_' . $blogId . '_' . $userId . '_' . $reference;
        $keyWithSalt = $rawKey . $this->salt;

        return self::star_hashKey($keyWithSalt);
    }

    /**
     * Convenience: generate a network-wide (blog-agnostic) cache key for
     * data that should be shared across all sites in a multisite network.
     *
     * @param string      $reference
     * @param string|null $userId
     * @return string  64-character hex cache key.
     */
    public function star_getNetworkKey(string $reference, ?string $userId = null): string
    {
        if (!is_string($reference) || $reference === '') {
            throw new \InvalidArgumentException('StarCacheKey: $reference must be a non-empty string.');
        }

        $userId      = ($userId !== null) ? (string) $userId : '';
        $rawKey      = $this->namespace . '_network_' . $userId . '_' . $reference;
        $keyWithSalt = $rawKey . $this->salt;

        return self::star_hashKey($keyWithSalt);
    }
}
