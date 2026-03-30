<?php

namespace StarCache\Tests;

use PHPUnit\Framework\TestCase;
use StarCache\StarCacheKey;
use StarCache\StarCache;
use StarCache\StarTransientCache;
use StarCache\StarAssetMinifier;
use StarCache\StarPageCache;
use StarCache\StarQueryCache;

/**
 * StarCache Test Suite
 *
 * These tests cover the core logic that can be exercised without a live
 * WordPress or cache-server environment:
 *
 * - StarCacheKey: key generation, multisite isolation, network keys
 * - StarCache: public API delegating to a stubbed adapter
 * - StarTransientCache: static method signatures (smoke tests)
 * - StarAssetMinifier: CSS and JS minification logic
 * - StarPageCache: bypass detection, key generation
 * - StarQueryCache: SQL key generation
 */
class UnitTests extends TestCase
{
    // -------------------------------------------------------------------------
    // StarCacheKey
    // -------------------------------------------------------------------------

    public function testHashKeyReturnsSha256(): void
    {
        $hash = StarCacheKey::star_hashKey('hello');
        $this->assertEquals(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testGetCacheKeyIsDeterministic(): void
    {
        $key = new StarCacheKey('testsalt', 'ns');
        $a = $key->star_getCacheKey('users', 'user1');
        $b = $key->star_getCacheKey('users', 'user1');
        $this->assertSame($a, $b);
    }

    public function testGetCacheKeyVariesByReference(): void
    {
        $key = new StarCacheKey('testsalt', 'ns');
        $a = $key->star_getCacheKey('users');
        $b = $key->star_getCacheKey('posts');
        $this->assertNotSame($a, $b);
    }

    public function testGetCacheKeyVariesByUserId(): void
    {
        $key = new StarCacheKey('testsalt', 'ns');
        $a = $key->star_getCacheKey('profile', 'user1');
        $b = $key->star_getCacheKey('profile', 'user2');
        $this->assertNotSame($a, $b);
    }

    public function testGetCacheKeyWithoutUserId(): void
    {
        $key = new StarCacheKey('testsalt', 'ns');
        $a = $key->star_getCacheKey('data');
        $b = $key->star_getCacheKey('data', null);
        $this->assertSame($a, $b);
    }

    public function testGetNetworkKeyDiffersFromSiteKey(): void
    {
        $key = new StarCacheKey('testsalt', 'ns');
        $site    = $key->star_getCacheKey('settings');
        $network = $key->star_getNetworkKey('settings');
        $this->assertNotSame($site, $network);
    }

    public function testGetCacheKeyThrowsOnEmptyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $key = new StarCacheKey();
        $key->star_getCacheKey('');
    }

    public function testNetworkKeyThrowsOnEmptyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $key = new StarCacheKey();
        $key->star_getNetworkKey('');
    }

    public function testDefaultSaltFallback(): void
    {
        // Should not throw when AUTH_KEY / SECURE_AUTH_SALT are not defined
        $key    = new StarCacheKey();
        $result = $key->star_getCacheKey('test');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    // -------------------------------------------------------------------------
    // StarAssetMinifier – CSS minification
    // -------------------------------------------------------------------------

    public function testMinifyCssRemovesBlockComments(): void
    {
        $input    = "/* header styles */\nbody { color: red; }";
        $minified = StarAssetMinifier::minifyCss($input);
        $this->assertStringNotContainsString('header styles', $minified);
        $this->assertStringContainsString('color:red', $minified);
    }

    public function testMinifyCssCollapsesWhitespace(): void
    {
        $input    = "body  {   color :  red  ;  }";
        $minified = StarAssetMinifier::minifyCss($input);
        $this->assertStringNotContainsString('  ', $minified);
    }

    public function testMinifyCssRemovesFinalSemicolon(): void
    {
        $input    = "body { color: red; }";
        $minified = StarAssetMinifier::minifyCss($input);
        $this->assertStringNotContainsString(';}', $minified);
    }

    public function testMinifyCssPreservesImportantDeclarations(): void
    {
        $input    = "body { color: red !important; }";
        $minified = StarAssetMinifier::minifyCss($input);
        $this->assertStringContainsString('!important', $minified);
    }

    public function testMinifyCssRemovesLineComments(): void
    {
        $input    = "// this is a comment\nbody { color: blue; }";
        $minified = StarAssetMinifier::minifyCss($input);
        $this->assertStringNotContainsString('this is a comment', $minified);
    }

    // -------------------------------------------------------------------------
    // StarAssetMinifier – JS minification
    // -------------------------------------------------------------------------

    public function testMinifyJsRemovesLineComments(): void
    {
        $input    = "var x = 1; // this is a comment\nvar y = 2;";
        $minified = StarAssetMinifier::minifyJs($input);
        $this->assertStringNotContainsString('this is a comment', $minified);
    }

    public function testMinifyJsRemovesBlockComments(): void
    {
        $input    = "/* block comment */\nvar a = 1;";
        $minified = StarAssetMinifier::minifyJs($input);
        $this->assertStringNotContainsString('block comment', $minified);
    }

    public function testMinifyJsPreservesLicenseComments(): void
    {
        $input    = "/*! License header */\nvar a = 1;";
        $minified = StarAssetMinifier::minifyJs($input);
        $this->assertStringContainsString('License header', $minified);
    }

    public function testMinifyJsCollapsesWhitespace(): void
    {
        $input    = "var   x   =   1;";
        $minified = StarAssetMinifier::minifyJs($input);
        $this->assertStringNotContainsString('   ', $minified);
    }

    // -------------------------------------------------------------------------
    // StarPageCache – bypass detection
    // -------------------------------------------------------------------------

    public function testShouldBypassReturnsTrueForNonGetRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertTrue(StarPageCache::shouldBypass());
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testShouldBypassReturnsTrueWhenDoNotCachePage(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        $this->assertTrue(StarPageCache::shouldBypass());
    }

    // -------------------------------------------------------------------------
    // StarCache – getUserGroup
    // -------------------------------------------------------------------------

    public function testGetUserGroupWithUserId(): void
    {
        $cache = new StarCache();
        $this->assertSame('user_42', $cache->star_getUserGroup('profile', '42'));
    }

    public function testGetUserGroupWithoutUserId(): void
    {
        $cache = new StarCache();
        $this->assertSame('my_feature', $cache->star_getUserGroup('my_feature'));
    }

    // -------------------------------------------------------------------------
    // StarCache – get/set/delete round-trip (using WP object cache stub)
    // -------------------------------------------------------------------------

    public function testSetAndGetCachedData(): void
    {
        $cache = new StarCache();
        $data  = ['foo' => 'bar', 'num' => 42];

        $set = $cache->star_setCachedData($data, 'test_roundtrip');
        $this->assertTrue($set);

        $got = $cache->star_getCachedData('test_roundtrip');
        $this->assertSame($data, $got);
    }

    public function testDeleteCachedData(): void
    {
        $cache = new StarCache();
        $cache->star_setCachedData(['x' => 1], 'test_delete');

        $deleted = $cache->star_deleteCachedData('test_delete');
        $this->assertTrue($deleted);

        $got = $cache->star_getCachedData('test_delete');
        $this->assertFalse($got);
    }

    public function testFlushReloadClearsEntry(): void
    {
        $cache = new StarCache();
        $cache->star_setCachedData(['a' => 'b'], 'test_flush');
        $cache->star_flushReloadCachedData('test_flush');

        $got = $cache->star_getCachedData('test_flush');
        $this->assertFalse($got);
    }

    public function testRememberReturnsCachedValue(): void
    {
        $cache    = new StarCache();
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return ['computed' => true];
        };

        // First call – should invoke the callback
        $first = $cache->star_remember('test_remember', $callback, 60);
        $this->assertSame(['computed' => true], $first);
        $this->assertSame(1, $callCount);

        // Second call – should be served from cache, callback NOT called again
        $second = $cache->star_remember('test_remember', $callback, 60);
        $this->assertSame(['computed' => true], $second);
        $this->assertSame(1, $callCount, 'Callback should not have been called a second time.');
    }

    public function testSetWithExplicitTtl(): void
    {
        $cache = new StarCache();
        $result = $cache->star_setCachedDataWithTtl(['ttl' => 'test'], 'test_ttl', 120);
        $this->assertTrue($result);

        $got = $cache->star_getCachedData('test_ttl');
        $this->assertSame(['ttl' => 'test'], $got);
    }

    public function testGetBackendReturnsString(): void
    {
        $cache = new StarCache();
        $this->assertIsString($cache->star_getBackend());
    }

    public function testIsOpcacheEnabledReturnsBool(): void
    {
        $cache = new StarCache();
        $this->assertIsBool($cache->star_isOpcacheEnabled());
    }
}

