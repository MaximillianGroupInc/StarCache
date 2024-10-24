<?php

namespace StarCache;

use StarExceptionHandler;
use StarCacheKey;
use Exception;

class StarTransientCache
{

    // Set Transient Cache
    public static function star_setCachedData(array $data, string $table, string $userId = null, bool $isStatic = false): bool
    {
        $locksmith = new StarCacheKey();
        $cacheKey = $locksmith->star_getCacheKey($table, $userId);
        $expiration = $isStatic ? YEAR_IN_SECONDS : 3600; // Set expiration time

        try {
            if (!set_transient($cacheKey, $data, $expiration)) {
                throw new Exception('Failed to set transient cache');
            }

            return true;
        } catch (Exception $e) {
            self::star_logError('Error setting transient cache', $e);
            return false;
        }
    }

    // Get Transient Cache
    public static function star_getCachedData(string $table, string $userId = null): array|false
    {
        $locksmith = new StarCacheKey();
        $cacheKey = $locksmith->star_getCacheKey($table, $userId);

        try {
            return get_transient($cacheKey);
        } catch (Exception $e) {
            self::star_logError('Error getting transient cache', $e);
            return false;
        }
    }

    // Delete Transient Cache
    public static function star_deleteCache(string $table, string $userId = null): void
    {
        $locksmith = new StarCacheKey();
        $cacheKey = $locksmith->star_getCacheKey($table, $userId);

        try {
            delete_transient($cacheKey);
        } catch (Exception $e) {
            self::star_logError('Error deleting transient cache', $e);
        }
    }

    // Error Logging
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
}
