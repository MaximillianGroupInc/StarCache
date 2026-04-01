<?php

namespace StarCache\Tests;

use PHPUnit\Framework\TestCase;
use StarCache\StarCacheKey;
use StarCache\StarCache;
use StarCache\StarTransientCache;
use StarCache\StarAssetMinifier;
use StarCache\StarPageCache;
use StarCache\StarQueryCache;
use StarCache\StarCacheContext;
use StarCache\StarResponseController;
use StarCache\StarVersionStore;

/**
 * StarCache Test Suite
 *
 * These tests cover the core logic that can be exercised without a live
 * WordPress or cache-server environment:
 *
 * - StarCacheKey: key generation, multisite isolation, network keys, context/version params
 * - StarCache: public API delegating to a stubbed adapter
 * - StarAssetMinifier: CSS and JS minification logic
 * - StarPageCache: bypass detection (delegates to StarResponseController)
 * - StarCacheContext: context resolution, locking, hash stability
 * - StarResponseController: eligibility checks
 * - StarVersionStore: version get/bump/reset
 */
class UnitTests extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset context and response controller state between tests
        StarCacheContext::reset();
        StarResponseController::reset();
    }

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
        $a   = $key->star_getCacheKey('users', 'user1');
        $b   = $key->star_getCacheKey('users', 'user1');
        $this->assertSame($a, $b);
    }

    public function testGetCacheKeyVariesByReference(): void
    {
        $key = new StarCacheKey('testsalt', 'ns');
        $this->assertNotSame($key->star_getCacheKey('users'), $key->star_getCacheKey('posts'));
    }

    public function testGetCacheKeyVariesByUserId(): void
    {
        $key = new StarCacheKey('testsalt', 'ns');
        $this->assertNotSame(
            $key->star_getCacheKey('profile', 'user1'),
            $key->star_getCacheKey('profile', 'user2')
        );
    }

    public function testGetCacheKeyWithoutUserId(): void
    {
        $key = new StarCacheKey('testsalt', 'ns');
        $this->assertSame($key->star_getCacheKey('data'), $key->star_getCacheKey('data', null));
    }

    public function testGetNetworkKeyDiffersFromSiteKey(): void
    {
        $key = new StarCacheKey('testsalt', 'ns');
        $this->assertNotSame($key->star_getCacheKey('settings'), $key->star_getNetworkKey('settings'));
    }

    public function testGetCacheKeyThrowsOnEmptyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StarCacheKey())->star_getCacheKey('');
    }

    public function testNetworkKeyThrowsOnEmptyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StarCacheKey())->star_getNetworkKey('');
    }

    public function testDefaultSaltFallback(): void
    {
        $key    = new StarCacheKey();
        $result = $key->star_getCacheKey('test');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    public function testKeyVariesByContextHash(): void
    {
        $key = new StarCacheKey('salt', 'ns');
        $a   = $key->star_getCacheKey('page', null, 'contexthashA', 1);
        $b   = $key->star_getCacheKey('page', null, 'contexthashB', 1);
        $this->assertNotSame($a, $b, 'Different context hashes must produce different keys.');
    }

    public function testKeyVariesByVersion(): void
    {
        $key = new StarCacheKey('salt', 'ns');
        $v1  = $key->star_getCacheKey('page', null, '', 1);
        $v2  = $key->star_getCacheKey('page', null, '', 2);
        $this->assertNotSame($v1, $v2, 'Different versions must produce different keys.');
    }

    public function testKeyWithContextAndVersionIsDeterministic(): void
    {
        $key = new StarCacheKey('salt', 'ns');
        $a   = $key->star_getCacheKey('page', null, 'ctxhash', 3);
        $b   = $key->star_getCacheKey('page', null, 'ctxhash', 3);
        $this->assertSame($a, $b);
    }

    // -------------------------------------------------------------------------
    // StarCacheContext
    // -------------------------------------------------------------------------

    public function testContextResolvesAuth(): void
    {
        StarCacheContext::resolve();
        $auth = StarCacheContext::get(StarCacheContext::DIM_AUTH);
        $this->assertContains($auth, [
            StarCacheContext::AUTH_AUTHENTICATED,
            StarCacheContext::AUTH_ANONYMOUS,
        ]);
    }

    public function testContextResolvesDevice(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120';
        StarCacheContext::resolve();
        $device = StarCacheContext::get(StarCacheContext::DIM_DEVICE);
        $this->assertSame(StarCacheContext::DEVICE_DESKTOP, $device);
    }

    public function testContextDetectsMobile(): void
    {
        StarCacheContext::reset();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17)';
        StarCacheContext::resolve();
        $this->assertSame(StarCacheContext::DEVICE_MOBILE, StarCacheContext::get(StarCacheContext::DIM_DEVICE));
    }

    public function testContextHashIsStable(): void
    {
        StarCacheContext::resolve();
        $h1 = StarCacheContext::hash();
        $h2 = StarCacheContext::hash();
        $this->assertSame($h1, $h2);
    }

    public function testContextHashExcludesAuthDimension(): void
    {
        // Create two contexts identical except for auth dimension
        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_ANONYMOUS);
        StarCacheContext::set(StarCacheContext::DIM_DEVICE, StarCacheContext::DEVICE_DESKTOP);
        StarCacheContext::set(StarCacheContext::DIM_EXPERIMENT, '');
        $hashAnon = StarCacheContext::hash();

        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_AUTHENTICATED);
        StarCacheContext::set(StarCacheContext::DIM_DEVICE, StarCacheContext::DEVICE_DESKTOP);
        StarCacheContext::set(StarCacheContext::DIM_EXPERIMENT, '');
        $hashAuth = StarCacheContext::hash();

        // Auth dimension is excluded from hash, so same device + experiment → same hash
        $this->assertSame($hashAnon, $hashAuth, 'Auth dimension must not affect context hash.');
    }

    public function testContextSetIsRejectedAfterLock(): void
    {
        // Ensure we start with a known state
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120';
        StarCacheContext::reset();
        StarCacheContext::resolve();

        // Confirm device is desktop before lock
        $this->assertSame(StarCacheContext::DEVICE_DESKTOP, StarCacheContext::get(StarCacheContext::DIM_DEVICE));

        // Lock the context
        StarCacheContext::lock();

        // Attempt to override after lock – must be silently rejected
        StarCacheContext::set(StarCacheContext::DIM_DEVICE, StarCacheContext::DEVICE_MOBILE);

        // Should still be desktop
        $this->assertSame(StarCacheContext::DEVICE_DESKTOP, StarCacheContext::get(StarCacheContext::DIM_DEVICE));
    }

    public function testContextShouldBypassForAnonymous(): void
    {
        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_ANONYMOUS);
        $this->assertFalse(StarCacheContext::shouldBypass());
    }

    public function testContextShouldBypassForAuthenticated(): void
    {
        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_AUTHENTICATED);
        $this->assertTrue(StarCacheContext::shouldBypass());
    }

    // -------------------------------------------------------------------------
    // StarResponseController
    // -------------------------------------------------------------------------

    public function testResponseControllerIsEligibleForGetRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_ANONYMOUS);
        $this->assertTrue(StarResponseController::isEligible());
    }

    public function testResponseControllerNotEligibleForPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertFalse(StarResponseController::isEligible());
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testResponseControllerEligibleForHead(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_ANONYMOUS);
        $this->assertTrue(StarResponseController::isEligible());
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testResponseControllerNotEligibleWhenAuthenticated(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_AUTHENTICATED);
        $this->assertFalse(StarResponseController::isEligible());
    }

    // -------------------------------------------------------------------------
    // StarVersionStore
    // -------------------------------------------------------------------------

    public function testVersionStoreDefaultsToOne(): void
    {
        $version = StarVersionStore::get('test_group_' . uniqid());
        $this->assertSame(1, $version);
    }

    public function testVersionBumpIncrementsVersion(): void
    {
        $group   = 'test_group_' . uniqid();
        $before  = StarVersionStore::get($group);
        $newVer  = StarVersionStore::bump($group);
        $after   = StarVersionStore::get($group);

        $this->assertSame($before + 1, $newVer);
        $this->assertSame($newVer, $after);
    }

    public function testVersionResetRestoresOne(): void
    {
        $group = 'test_group_' . uniqid();
        StarVersionStore::bump($group);
        StarVersionStore::bump($group);
        StarVersionStore::reset($group);

        $this->assertSame(1, StarVersionStore::get($group));
    }

    public function testVersionBumpAllBumpsBuiltInGroups(): void
    {
        $before = [
            StarVersionStore::get(StarVersionStore::GROUP_CONTENT),
            StarVersionStore::get(StarVersionStore::GROUP_FRAGMENTS),
            StarVersionStore::get(StarVersionStore::GROUP_QUERIES),
        ];

        StarVersionStore::bumpAll();

        $this->assertGreaterThan($before[0], StarVersionStore::get(StarVersionStore::GROUP_CONTENT));
        $this->assertGreaterThan($before[1], StarVersionStore::get(StarVersionStore::GROUP_FRAGMENTS));
        $this->assertGreaterThan($before[2], StarVersionStore::get(StarVersionStore::GROUP_QUERIES));
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
    // StarPageCache – bypass detection (now delegates to ResponseController)
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

        $this->assertTrue($cache->star_setCachedData($data, 'test_roundtrip'));
        $this->assertSame($data, $cache->star_getCachedData('test_roundtrip'));
    }

    public function testDeleteCachedData(): void
    {
        $cache = new StarCache();
        $cache->star_setCachedData(['x' => 1], 'test_delete');
        $this->assertTrue($cache->star_deleteCachedData('test_delete'));
        $this->assertFalse($cache->star_getCachedData('test_delete'));
    }

    public function testFlushReloadClearsEntry(): void
    {
        $cache = new StarCache();
        $cache->star_setCachedData(['a' => 'b'], 'test_flush');
        $cache->star_flushReloadCachedData('test_flush');
        $this->assertFalse($cache->star_getCachedData('test_flush'));
    }

    public function testRememberReturnsCachedValue(): void
    {
        $cache     = new StarCache();
        $callCount = 0;
        $callback  = function () use (&$callCount) { $callCount++; return ['computed' => true]; };

        $first  = $cache->star_remember('test_remember', $callback, 60);
        $second = $cache->star_remember('test_remember', $callback, 60);

        $this->assertSame(['computed' => true], $first);
        $this->assertSame(['computed' => true], $second);
        $this->assertSame(1, $callCount, 'Callback must not be called twice.');
    }

    public function testSetWithExplicitTtl(): void
    {
        $cache = new StarCache();
        $this->assertTrue($cache->star_setCachedDataWithTtl(['ttl' => 'test'], 'test_ttl', 120));
        $this->assertSame(['ttl' => 'test'], $cache->star_getCachedData('test_ttl'));
    }

    public function testGetBackendReturnsString(): void
    {
        $this->assertIsString((new StarCache())->star_getBackend());
    }

    public function testIsOpcacheEnabledReturnsBool(): void
    {
        $this->assertIsBool((new StarCache())->star_isOpcacheEnabled());
    }
}
