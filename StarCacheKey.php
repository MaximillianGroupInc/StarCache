<?php

declare(strict_types=1);

namespace StarCache;

/**
 * StarCacheKey — Key Builder
 *
 * Constructs deterministic, collision-resistant, multisite-aware cache keys.
 *
 * Every key is a SHA-256 digest of the concatenation of well-defined segments.
 * SHA-256 is used so that raw key length (which can be substantial when context
 * hashes and version strings are included) never exceeds backend limits
 * (Memcached: 250 bytes; Redis: effectively unlimited but consistency matters).
 *
 * Key segments (in order)
 * -----------------------
 *   namespace  — 'starcache' prefix
 *   blog       — get_current_blog_id() for multisite isolation
 *   user       — optional caller-supplied user identifier
 *   reference  — caller-supplied identifier (≤ 250 chars enforced)
 *   context    — StarCacheContext::hash() (device / experiment bucket)
 *   version    — StarVersionStore::get($group) (logical invalidation)
 *   salt       — AUTH_KEY + SECURE_AUTH_SALT (prevents key guessing)
 *
 * Static API (preferred)
 * ----------------------
 *   $key = StarCacheKey::build('my_reference');
 *   $key = StarCacheKey::build('my_reference', $userId, StarVersionStore::GROUP_PAGES);
 *
 * Instance API (backward-compatible)
 * -----------------------------------
 *   $keyBuilder = new StarCacheKey($salt, $namespace);
 *   $key        = $keyBuilder->star_getCacheKey($reference, $userId);
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.1
 * @license Apache 2.0
 */
class StarCacheKey
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** Default namespace embedded in every key. */
    public const DEFAULT_NAMESPACE = 'starcache';

    /** Maximum allowed length for the $reference argument. */
    public const MAX_REFERENCE_LENGTH = 250;

    // -------------------------------------------------------------------------
    // Instance state (kept for backward-compatible constructor signature)
    // -------------------------------------------------------------------------

    /**
     * @param string|null $salt      Custom salt (ignored — static build() uses its own salt).
     * @param string      $namespace Namespace prefix (ignored — static build() uses DEFAULT_NAMESPACE).
     *
     * @deprecated Instantiating StarCacheKey is deprecated. Use StarCacheKey::build() directly.
     *
     * @phpstan-ignore constructor.unusedParameter, constructor.unusedParameter
     */
    public function __construct(?string $salt = null, string $namespace = self::DEFAULT_NAMESPACE)
    {
        // Instance properties are not stored — the static build() method resolves
        // salt and namespace independently for all key construction.
    }

    // =========================================================================
    // Static API (primary — preferred by all internal components)
    // =========================================================================

    /**
     * Build a fully-qualified, context-aware, versioned cache key.
     *
     * Segments are assembled in order and hashed with SHA-256:
     *
     *   namespace | blog | user | reference | ctx:{contextHash} | v:{version} | salt
     *
     * @param  string      $reference    Logical identifier for the cached data. Max 250 chars.
     * @param  string|null $userId       Optional user identifier for user-scoped keys.
     * @param  string      $versionGroup Version group from StarVersionStore (e.g. GROUP_PAGES).
     *                                   Defaults to GROUP_OBJECTS for the data-API layer.
     * @return string 64-character hex SHA-256 digest.
     *
     * @throws \InvalidArgumentException If $reference is empty or exceeds MAX_REFERENCE_LENGTH.
     */
    public static function build(
        string $reference,
        ?string $userId = null,
        string $versionGroup = StarVersionStore::GROUP_OBJECTS
    ): string {
        self::guardReference($reference);

        $segments = [
            self::namespaceSegment(),
            self::blogSegment(),
            self::userSegment($userId),
            self::referenceSegment($reference),
            self::contextSegment(),
            self::versionSegment($versionGroup),
            self::saltSegment(),
        ];

        $encodedSegments = array_map(
            static fn (string $segment): string => strlen($segment) . ':' . $segment,
            $segments
        );

        return hash('sha256', implode('|', $encodedSegments));
    }

    // =========================================================================
    // Private segment methods
    // =========================================================================

    private static function namespaceSegment(): string
    {
        return self::DEFAULT_NAMESPACE;
    }

    private static function blogSegment(): string
    {
        return (string) (function_exists('get_current_blog_id') ? get_current_blog_id() : 1);
    }

    private static function userSegment(?string $userId): string
    {
        return $userId !== null ? 'u:' . $userId : 'u:anon';
    }

    private static function referenceSegment(string $reference): string
    {
        self::guardReference($reference);
        return 'ref:' . $reference;
    }

    /**
     * Returns the context hash from StarCacheContext.
     * Empty string when context has not been resolved yet (e.g. in tests).
     */
    private static function contextSegment(): string
    {
        if (!class_exists(StarCacheContext::class)) {
            return 'ctx:';
        }
        return 'ctx:' . StarCacheContext::hash();
    }

    /**
     * Returns the version token from StarVersionStore for the given group.
     * Includes the group name so keys for different groups are distinct even
     * when both groups are at the same version number (e.g. version 1).
     */
    private static function versionSegment(string $group): string
    {
        if (!class_exists(StarVersionStore::class)) {
            return 'v:' . $group . ':1';
        }
        return 'v:' . $group . ':' . StarVersionStore::get($group);
    }

    private static function saltSegment(): string
    {
        return self::resolveSalt();
    }

    // =========================================================================
    // Guards and helpers
    // =========================================================================

    /**
     * Throw if $reference is empty or exceeds MAX_REFERENCE_LENGTH.
     *
     * @throws \InvalidArgumentException
     */
    private static function guardReference(string $reference): void
    {
        if ($reference === '') {
            throw new \InvalidArgumentException('StarCacheKey: $reference must be a non-empty string.');
        }
        if (strlen($reference) > self::MAX_REFERENCE_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'StarCacheKey: $reference exceeds maximum length of %d characters.',
                self::MAX_REFERENCE_LENGTH
            ));
        }
    }

    /**
     * Resolve the salt from WordPress constants, falling back to 'default_salt'.
     */
    private static function resolveSalt(): string
    {
        if (defined('AUTH_KEY') && defined('SECURE_AUTH_SALT')) {
            return AUTH_KEY . SECURE_AUTH_SALT;
        }
        return 'default_salt';
    }

    // =========================================================================
    // Static utility
    // =========================================================================

    /**
     * SHA-256 hash a raw string.
     *
     * @param  string $key Raw string to hash.
     * @return string 64-character hex digest.
     */
    public static function star_hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    // =========================================================================
    // Instance API (backward-compatible — delegates to static build())
    // =========================================================================

    /**
     * Generate a secure, multisite-aware cache key.
     *
     * Delegates to the static build() method. The $contextHash and $version
     * parameters are ignored — context and version are now resolved
     * automatically by StarCacheContext and StarVersionStore respectively.
     * Passing non-default values for these parameters has no effect and will
     * trigger a deprecation notice to help callers migrate.
     *
     * @deprecated Use StarCacheKey::build() directly.
     *
     * @param string      $reference
     * @param string|null $userId
     * @param string      $contextHash  Ignored. Context is read from StarCacheContext.
     * @param int         $version      Ignored. Version is read from StarVersionStore.
     * @return string 64-character hex cache key.
     */
    public function star_getCacheKey(
        string $reference,
        ?string $userId = null,
        string $contextHash = '',
        int $version = 1
    ): string {
        if ($contextHash !== '' || $version !== 1) {
            trigger_error(
                'StarCacheKey::star_getCacheKey() $contextHash and $version parameters are ignored. '
                . 'Use StarCacheKey::build() with a $versionGroup argument instead.',
                \E_USER_DEPRECATED
            );
        }
        return self::build($reference, $userId);
    }

    /**
     * Convenience: generate a network-wide (blog-agnostic) cache key for
     * data that should be shared across all sites in a multisite network.
     *
     * Intentionally omits blogSegment(), contextSegment(), and versionSegment()
     * because those are all site/request-dependent: including them would produce
     * a different key on every blog or in every context bucket, defeating the
     * network-wide sharing purpose.
     *
     * @param string      $reference
     * @param string|null $userId
     * @return string 64-character hex cache key.
     */
    public function star_getNetworkKey(string $reference, ?string $userId = null): string
    {
        $segments = [
            self::DEFAULT_NAMESPACE . ':network',
            self::userSegment($userId),
            self::referenceSegment($reference),
            self::saltSegment(),
        ];

        $encodedSegments = array_map(
            static fn (string $segment): string => strlen($segment) . ':' . $segment,
            $segments
        );

        return hash('sha256', implode('|', $encodedSegments));
    }
}
