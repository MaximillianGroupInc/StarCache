<?php 
namespace StarCache;

use Respect\Validation\Validator as v;

class StarCacheKey
{
    private string $namespace; // Namespace property
    private string $salt;      // Salt property

    /**
     * Constructor for the StarCacheKey class.
     *
     * @param string $salt The salt to use for generating cache keys.
     * @param string $namespace The namespace to use for the cache keys.
     */
    public function __construct(string $salt = null, string $namespace = 'star_cache')
    {
        // Use provided salt or fallback to AUTH_KEY and SECURE_AUTH_SALT if they are defined
        $this->salt = $salt ?? (defined('AUTH_KEY') && defined('SECURE_AUTH_SALT') ? AUTH_KEY . SECURE_AUTH_SALT : 'default_salt');
        $this->namespace = $namespace;
    }

    /**
     * Hashes the provided key with SHA-256
     */
    public static function star_hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Generates a secure cache key by combining the table, userId, and salt.
     *
     * @param string $table The name of the database table.
     * @param string|null $userId Optional user ID to personalize the cache key.
     * @return string The generated, hashed cache key.
     */
    public function star_getCacheKey(string $table, ?string $userId = null): string
    {
        // Validate table name
        v::stringType()->assert($table);
        
        // If userId is provided, validate it; otherwise, default to an empty string
        if ($userId !== null) {
            v::stringType()->assert($userId);
        } else {
            $userId = ''; // Default to an empty string if no userId
        }

        // Concatenate namespace, userId, table, and salt to create the raw key
        $rawKey = $this->namespace . '_' . $userId . '_' . $table;

        // Hash the key with the salt for security
        $keyWithSalt = $rawKey . $this->salt; // Use the salt from the class

        // Return the hashed key
        return self::star_hashKey($keyWithSalt);
    }
}
