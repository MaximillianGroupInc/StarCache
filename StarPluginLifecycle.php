<?php

declare(strict_types=1);

namespace StarCache;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lifecycle helpers for regular-plugin deactivation and uninstall cleanup.
 */
final class StarPluginLifecycle
{
    /**
     * Temporary cleanup for plugin deactivation.
     */
    public static function deactivate(): void
    {
        self::forEachSite(static function (): void {
            StarAssetMinifier::clearScheduledBuilds();
            StarAssetMinifier::flushAssets();
        });
    }

    /**
     * Permanent cleanup for plugin uninstall.
     */
    public static function uninstall(): void
    {
        self::forEachSite(static function (): void {
            StarAssetMinifier::clearScheduledBuilds();
            StarAssetMinifier::removeAssetCacheDirectory();
        });
    }

    /**
     * Run a cleanup callback for the current site or every site in a network.
     */
    private static function forEachSite(callable $callback): void
    {
        if (
            !function_exists('is_multisite')
            || !is_multisite()
            || !function_exists('get_sites')
            || !function_exists('switch_to_blog')
            || !function_exists('restore_current_blog')
        ) {
            $callback();
            return;
        }

        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);

        if (!is_array($siteIds) || $siteIds === []) {
            $callback();
            return;
        }

        foreach ($siteIds as $siteId) {
            switch_to_blog((int) $siteId);

            try {
                $callback();
            } finally {
                restore_current_blog();
            }
        }
    }
}
