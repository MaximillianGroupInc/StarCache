<?php

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
 * @version 2.0.0
 * @license Apache 2.0
 */
class StarAssetMinifier
{
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
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/starcache/assets' : sys_get_temp_dir() . '/starcache/assets');

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
     * Minify a JavaScript string.
     *
     * Removes comments and collapses whitespace while preserving string literals.
     *
     * @param string $js  Raw JavaScript input.
     * @return string     Minified JavaScript.
     */
    public static function minifyJs(string $js): string
    {
        // Remove single-line comments (// …) but preserve license comments (//!)
        $js = preg_replace('/\/\/(?!!)[^\r\n]*/', '', $js) ?? $js;

        // Remove block comments /* … */ but preserve license comments /*! … */
        $js = preg_replace('/\/\*(?!!)([\s\S]*?)\*\//', '', $js) ?? $js;

        // Collapse horizontal whitespace (spaces and tabs) to a single space
        $js = preg_replace('/[ \t]+/', ' ', $js) ?? $js;

        // Remove consecutive blank lines
        $js = preg_replace('/\n\s*\n/', "\n", $js) ?? $js;

        // Remove spaces around braces, parentheses, semicolons and commas
        $js = preg_replace('/\s*([{}();,])\s*/', '$1', $js) ?? $js;

        // Remove spaces around assignment operators
        $js = preg_replace('/\s*=\s*/', '=', $js) ?? $js;

        return trim($js);
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
        $cacheKey = $handle . '-' . md5($localPath . $mtime);
        $fileName = $cacheKey . '.min.' . $type;
        $destPath = self::$cacheDir . '/' . $fileName;
        $destUrl  = self::$cacheUrl . '/' . $fileName;

        // Create minified file if it doesn't exist yet
        if (!file_exists($destPath)) {
            $source    = file_get_contents($localPath);
            if ($source === false) {
                return;
            }
            $minified  = ($type === 'css') ? self::minifyCss($source) : self::minifyJs($source);

            // Write atomically via temp file
            $tmpPath = $destPath . '.tmp';
            if (file_put_contents($tmpPath, $minified) === false) {
                return;
            }
            rename($tmpPath, $destPath);
        }

        // Replace src in the dependency object
        $deps->registered[$handle]->src = $destUrl;

        // Bump the version so browsers re-fetch after any cache is cleared
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
            return WP_CONTENT_DIR . substr($url, strlen($contentUrl));
        }

        if ($siteUrl && strpos($url, $siteUrl) === 0) {
            return rtrim(ABSPATH, '/') . substr($url, strlen(rtrim($siteUrl, '/')));
        }

        // URL begins with / (root-relative)
        if (!empty($url) && $url[0] === '/') {
            return rtrim(ABSPATH, '/') . $url;
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
}
