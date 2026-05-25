<?php

declare(strict_types=1);

namespace StarCache;

/**
 * StarAssetMinifier
 *
 * Minifies and caches CSS and JavaScript assets for WordPress.
 *
 * Features
 * --------
 * - Hooks into `wp_print_styles` and `wp_print_scripts` (via `wp_enqueue_scripts`)
 *   to intercept registered stylesheets and scripts.
 * - Each asset is fetched from its local path, minified in PHP (no external
 *   dependencies), and stored in the filesystem cache directory.
 * - The original `src` URL in the registered handle is replaced with the
 *   cached, minified version so browsers and CDNs receive smaller files.
 * - Minified files are named `{handle}-{hash}.min.{ext}` and are served
 *   directly by the web server – WordPress PHP is not involved on cache hits.
 * - Skips already-minified files (`.min.css`, `.min.js`) and external URLs.
 * - Multisite-aware: each site stores its minified assets in its own sub-dir.
 *
 * Configuration constants (optional, defined in wp-config.php)
 * ------------------------------------------------------------
 *   STARCACHE_ASSET_DIR   Absolute path to the cache directory.
 *                         Default: WP_CONTENT_DIR . '/cache/starcache/assets'
 *   STARCACHE_ASSET_URL   Public URL for the cache directory.
 *                         Default: WP_CONTENT_URL . '/cache/starcache/assets'
 *   STARCACHE_MINIFY      Set to false to disable asset minification globally.
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.1
 * @license Apache 2.0
 */
class StarAssetMinifier
{
    /**
     * WP-Cron hook name used to schedule asynchronous asset builds.
     *
     * Arguments passed to the event: (string $localPath, string $destPath, string $type).
     * The cron callback {@see self::buildAssetFromCron()} is the only place that
     * performs blocking file I/O for minification — it never runs during a
     * frontend page request.
     */
    public const CRON_HOOK = 'starcache_build_asset';

    /** Run stale hashed-file cleanup in ~5% of requests. */
    private const CLEANUP_PROBABILITY_DIVISOR = 20;

    /** @var string Filesystem path to the asset cache directory. */
    private static string $cacheDir = '';

    /** @var string Public URL of the asset cache directory. */
    private static string $cacheUrl = '';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    /**
     * Initialise the minifier: ensure the cache directory exists.
     * Called once from starcache.php on the 'init' hook.
     */
    public static function init(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;

        $baseDir = defined('STARCACHE_ASSET_DIR')
            ? rtrim(STARCACHE_ASSET_DIR, '/')
            : (defined('WP_CONTENT_DIR')
                ? WP_CONTENT_DIR . '/cache/starcache/assets'
                : sys_get_temp_dir() . '/starcache/assets');

        $baseUrl = defined('STARCACHE_ASSET_URL')
            ? rtrim(STARCACHE_ASSET_URL, '/')
            : (defined('WP_CONTENT_URL') ? WP_CONTENT_URL . '/cache/starcache/assets' : '');

        self::$cacheDir = $baseDir . '/' . $blogId;
        self::$cacheUrl = $baseUrl . '/' . $blogId;

        if (!is_dir(self::$cacheDir)) {
            wp_mkdir_p(self::$cacheDir);
        }
    }

    /**
     * Process all enqueued stylesheets.
     * Hooked to `wp_print_styles` (priority 5).
     */
    public static function processStyles(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        global $wp_styles;
        if (!($wp_styles instanceof \WP_Styles)) {
            return;
        }

        foreach ($wp_styles->queue as $handle) {
            self::processAsset($wp_styles, $handle, 'css');
        }
    }

    /**
     * Process all enqueued scripts.
     * Hooked to `wp_print_scripts` (priority 5).
     */
    public static function processScripts(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        global $wp_scripts;
        if (!($wp_scripts instanceof \WP_Scripts)) {
            return;
        }

        foreach ($wp_scripts->queue as $handle) {
            self::processAsset($wp_scripts, $handle, 'js');
        }
    }

    // -------------------------------------------------------------------------
    // Minification
    // -------------------------------------------------------------------------

    /**
     * Minify a CSS string.
     *
     * Removes comments, collapses whitespace, strips unnecessary characters.
     *
     * @param string $css  Raw CSS input.
     * @return string      Minified CSS.
     */
    public static function minifyCss(string $css): string
    {
        // Remove block comments /* … */
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css) ?? $css;

        // Remove line comments starting with //  (non-standard in CSS but occasionally used)
        $css = preg_replace('!//[^\r\n]*[\r\n]!', '', $css) ?? $css;

        // Collapse whitespace (spaces, tabs, newlines)
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;

        // Remove spaces around punctuation that does not need them
        $css = preg_replace('/\s*([:;{},>~+])\s*/', '$1', $css) ?? $css;

        // Remove last semicolon before closing brace
        $css = str_replace(';}', '}', $css);

        // Remove quotes around font/url values where safe
        $css = preg_replace('/url\(["\'](.+?)["\']\)/', 'url($1)', $css) ?? $css;

        return trim($css);
    }

    /**
     * Normalize a JavaScript string (trim leading/trailing whitespace only).
     *
     * JavaScript cannot be safely minified with regular expressions because
     * comment markers and whitespace-sensitive tokens may appear inside valid
     * strings, template literals, and regular expression literals.  Regex-based
     * stripping corrupts valid JS, so this method intentionally limits itself
     * to trimming file-level whitespace.
     *
     * Full AST-based minification (e.g. via an external tool such as terser) is
     * the correct approach and is planned for the v3.0 companion plugin.
     *
     * @param string $js  Raw JavaScript input.
     * @return string     Whitespace-normalized JavaScript (content is unchanged).
     */
    public static function normalizeJs(string $js): string
    {
        return trim($js);
    }

    /**
     * @deprecated 2.1.1 Use {@see self::normalizeJs()} instead.
     *             This alias will be removed in v3.0.
     * @param string $js
     * @return string
     */
    public static function minifyJs(string $js): string
    {
        return self::normalizeJs($js);
    }

    // -------------------------------------------------------------------------
    // Asynchronous build (WP-Cron callback)
    // -------------------------------------------------------------------------

    /**
     * WP-Cron callback: minify a single asset and write it to the cache dir.
     *
     * This is the **only** method in StarAssetMinifier that performs blocking
     * file I/O for minification.  It runs in a background WP-Cron request that
     * is spawned after the first cache miss, never during a live frontend page
     * request.  Subsequent frontend requests for the same asset will find the
     * file already on disk and swap the src immediately (the hot path in
     * {@see self::processAsset()}).
     *
     * @param string $localPath  Absolute filesystem path of the source asset.
     * @param string $destPath   Absolute filesystem path of the minified target.
     * @param string $type       'css' or 'js'.
     */
    public static function buildAssetFromCron(string $localPath, string $destPath, string $type): void
    {
        if (!is_readable($localPath)) {
            return;
        }

        // Bail if another process already wrote the file between scheduling and now.
        if (file_exists($destPath)) {
            return;
        }

        $source = file_get_contents($localPath);
        if ($source === false) {
            return;
        }

        $minified = ($type === 'css') ? self::minifyCss($source) : self::normalizeJs($source);

        // Write atomically via unique temp file so partial writes are never visible.
        try {
            $tmpPath = $destPath . '.tmp.' . bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            error_log('[StarCache] random_bytes() failed for asset temp name, falling back to uniqid(): ' . $e->getMessage());
            $tmpPath = $destPath . '.tmp.' . uniqid('', true);
        }
        if (file_put_contents($tmpPath, $minified, LOCK_EX) === false) {
            return;
        }

        if (!@rename($tmpPath, $destPath)) {
            @unlink($tmpPath);
            return;
        }
    }

    // -------------------------------------------------------------------------
    // Cache management
    // -------------------------------------------------------------------------

    /**
     * Delete all cached minified assets for the current site.
     */
    public static function flushAssets(): void
    {
        if (!is_dir(self::$cacheDir)) {
            return;
        }

        $files = glob(self::$cacheDir . '/*.min.{css,js}', GLOB_BRACE);
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            @unlink($file);
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Minify a single registered asset and update its src URL.
     *
     * @param \WP_Dependencies $deps
     * @param string           $handle
     * @param string           $type   'css' or 'js'
     */
    private static function processAsset(\WP_Dependencies $deps, string $handle, string $type): void
    {
        $registered = $deps->registered[$handle] ?? null;
        if (!$registered || empty($registered->src)) {
            return;
        }

        $src = $registered->src;

        // Skip external URLs
        if (self::isExternalUrl($src)) {
            return;
        }

        // Skip already-minified files
        if (strpos($src, '.min.') !== false) {
            return;
        }

        $localPath = self::urlToLocalPath($src);
        if (!$localPath || !is_readable($localPath)) {
            return;
        }

        $mtime    = (int) @filemtime($localPath);
        // Hash the handle so that arbitrary plugin/theme strings (including any
        // path-traversal sequences like "../") can never influence the filename.
        $safeHandle = md5($handle);
        $cacheKey   = $safeHandle . '-' . md5($localPath . $mtime);
        $fileName   = $cacheKey . '.min.' . $type;
        $destPath = self::$cacheDir . '/' . $fileName;
        $destUrl  = self::$cacheUrl . '/' . $fileName;

        if (!file_exists($destPath)) {
            // Hot path not yet warm — schedule a background WP-Cron build so
            // the minified file will be ready for the next request.  Serve the
            // original, unmodified asset for this request (no blocking I/O).
            if (!wp_next_scheduled(self::CRON_HOOK, [$localPath, $destPath, $type])) {
                wp_schedule_single_event(time(), self::CRON_HOOK, [$localPath, $destPath, $type]);
            }
            return;
        }

        self::cleanupStaleHashedAssets($safeHandle, $fileName);

        // Minified file already exists — swap src and bump the version so
        // browsers and CDNs re-fetch after any cache is cleared.
        $deps->registered[$handle]->src = $destUrl;
        $deps->registered[$handle]->ver = $mtime;
    }

    /**
     * Attempt to resolve an asset's public URL to a local filesystem path.
     *
     * Handles WordPress multisite mapped domains.
     *
     * @param  string $url
     * @return string|null  Absolute path or null if not resolvable.
     */
    private static function urlToLocalPath(string $url): ?string
    {
        if (!defined('ABSPATH')) {
            return null;
        }

        // Strip query string
        $url = strtok($url, '?');

        $contentUrl = defined('WP_CONTENT_URL') ? WP_CONTENT_URL : '';
        $siteUrl    = defined('WP_SITEURL')     ? WP_SITEURL    : (function_exists('site_url') ? site_url() : '');

        if ($contentUrl && strpos($url, $contentUrl) === 0) {
            $candidate = WP_CONTENT_DIR . substr($url, strlen($contentUrl));
        } elseif ($siteUrl && strpos($url, $siteUrl) === 0) {
            $candidate = rtrim(ABSPATH, '/') . substr($url, strlen(rtrim($siteUrl, '/')));
        } elseif (!empty($url) && $url[0] === '/') {
            // URL begins with / (root-relative)
            $candidate = rtrim(ABSPATH, '/') . $url;
        } else {
            return null;
        }

        // Resolve symlinks / `..` segments and validate the path stays inside
        // an allowed base directory to prevent path-traversal attacks.
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return null;
        }

        $resolvedAbspath = realpath(ABSPATH);
        if ($resolvedAbspath === false) {
            // Cannot validate safely without a canonical ABSPATH.
            return null;
        }

        $allowedBases = [rtrim($resolvedAbspath, '/\\')];
        if (defined('WP_CONTENT_DIR')) {
            $resolvedContent = realpath(WP_CONTENT_DIR);
            if ($resolvedContent !== false) {
                $allowedBases[] = rtrim($resolvedContent, '/\\');
            }
        }

        // Normalise separators so the check works on Windows too.
        $resolvedNorm = str_replace('\\', '/', $resolved);
        foreach ($allowedBases as $base) {
            $baseNorm = str_replace('\\', '/', $base);
            if (
                strpos($resolvedNorm, $baseNorm . '/') === 0
                || $resolvedNorm === $baseNorm
            ) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Returns true when the URL belongs to an external domain.
     *
     * @param string $url
     * @return bool
     */
    private static function isExternalUrl(string $url): bool
    {
        if (strpos($url, '//') === 0 || preg_match('#^https?://#', $url)) {
            $siteHost  = defined('WP_SITEURL') ? wp_parse_url(WP_SITEURL, PHP_URL_HOST) : '';
            $assetHost = wp_parse_url($url, PHP_URL_HOST);
            return $siteHost && $assetHost && $assetHost !== $siteHost;
        }
        return false;
    }

    /**
     * Returns false when minification is explicitly disabled.
     */
    private static function isEnabled(): bool
    {
        if (defined('STARCACHE_MINIFY') && STARCACHE_MINIFY === false) {
            return false;
        }
        return (bool) apply_filters('starcache_minify_enabled', true);
    }

    /**
     * Remove old hashed files for the same handle to keep cache growth bounded.
     */
    private static function cleanupStaleHashedAssets(string $safeHandle, string $currentFileName): void
    {
        // Keep cleanup lightweight in frontend hot paths.
        // With divisor=20, this runs 1/20 requests (~5% sampling).
        // TODO: Move this cleanup path to a scheduled cron job.
        if (mt_rand(1, self::CLEANUP_PROBABILITY_DIVISOR) !== 1) {
            return;
        }

        $pattern = self::$cacheDir . '/' . $safeHandle . '-*.min.{css,js}';
        $files   = glob($pattern, GLOB_BRACE);
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            if (basename($file) === $currentFileName) {
                continue;
            }
            @unlink($file);
        }
    }
}
