<?php

declare(strict_types=1);

namespace StarCache\Tests;

use PHPUnit\Framework\TestCase;
use StarCache\StarCacheKey;
use StarCache\StarCache;
use StarCache\StarAssetMinifier;
use StarCache\StarPageCache;
use StarCache\StarCacheContext;
use StarCache\StarResponseController;
use StarCache\StarVersionStore;

/**
 * StarCache v2.1.1 Test Suite
 *
 * These tests cover the core logic exercisable without a live WordPress or
 * cache-server environment:
 *
 * - StarCacheKey: static build(), segment methods, reference length guard
 * - StarCache: public API
 * - StarAssetMinifier: CSS and JS minification
 * - StarPageCache: bypass detection (delegates to StarResponseController)
 * - StarCacheContext: registration, resolution, constraints, locking, hash
 * - StarResponseController: eligibility checks
 * - StarVersionStore: version get/bump/reset with renamed groups
 */
class UnitTests extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        StarCacheContext::reset();
        StarResponseController::reset();
        $_SERVER['REQUEST_METHOD']  = 'GET';
        // Standard Chrome User-Agent (kept long intentionally — PSR-12 line-length: warning only)
        // phpcs:ignore Generic.Files.LineLength
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }

    // =========================================================================
    // StarCacheKey — static build()
    // =========================================================================

    public function testHashKeyReturnsSha256(): void
    {
        $hash = StarCacheKey::star_hashKey('hello');
        $this->assertEquals(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testBuildIsDeterministic(): void
    {
        $a = StarCacheKey::build('my_reference');
        $b = StarCacheKey::build('my_reference');
        $this->assertSame($a, $b);
    }

    public function testBuildVariesByReference(): void
    {
        $this->assertNotSame(StarCacheKey::build('users'), StarCacheKey::build('posts'));
    }

    public function testBuildVariesByUser(): void
    {
        $this->assertNotSame(
            StarCacheKey::build('profile', 'user1'),
            StarCacheKey::build('profile', 'user2')
        );
    }

    public function testBuildWithoutUserEqualsNullUser(): void
    {
        $this->assertSame(StarCacheKey::build('data'), StarCacheKey::build('data', null));
    }

    public function testBuildVariesByVersionGroup(): void
    {
        $a = StarCacheKey::build('ref', null, StarVersionStore::GROUP_PAGES);
        $b = StarCacheKey::build('ref', null, StarVersionStore::GROUP_OBJECTS);
        $this->assertNotSame($a, $b);
    }

    public function testBuildThrowsOnEmptyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StarCacheKey::build('');
    }

    public function testBuildThrowsOnTooLongReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StarCacheKey::build(str_repeat('x', StarCacheKey::MAX_REFERENCE_LENGTH + 1));
    }

    public function testBuildAtMaxReferenceLengthDoesNotThrow(): void
    {
        $key = StarCacheKey::build(str_repeat('x', StarCacheKey::MAX_REFERENCE_LENGTH));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    // =========================================================================
    // StarCacheKey — backward-compatible instance API
    // =========================================================================

    public function testGetCacheKeyIsDeterministic(): void
    {
        $key = new StarCacheKey('testsalt', 'ns');
        $this->assertSame($key->star_getCacheKey('users', 'user1'), $key->star_getCacheKey('users', 'user1'));
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

    public function testGetCacheKeyWithoutUserIdIsStable(): void
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
        $result = (new StarCacheKey())->star_getCacheKey('test');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    // =========================================================================
    // StarCacheContext — registration + constraints
    // =========================================================================

    public function testContextResolvesAuth(): void
    {
        StarCacheContext::resolve();
        $auth = StarCacheContext::get(StarCacheContext::DIM_AUTH);
        $this->assertContains($auth, [
            StarCacheContext::AUTH_AUTHENTICATED,
            StarCacheContext::AUTH_ANONYMOUS,
        ]);
    }

    public function testContextResolvesDesktopDevice(): void
    {
        StarCacheContext::resolve();
        $this->assertSame(StarCacheContext::DEVICE_DESKTOP, StarCacheContext::get(StarCacheContext::DIM_DEVICE));
    }

    public function testContextDetectsMobileUA(): void
    {
        StarCacheContext::reset();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17)';
        StarCacheContext::resolve();
        $this->assertSame(StarCacheContext::DEVICE_MOBILE, StarCacheContext::get(StarCacheContext::DIM_DEVICE));
    }

    public function testContextHashIsStable(): void
    {
        StarCacheContext::resolve();
        $this->assertSame(StarCacheContext::hash(), StarCacheContext::hash());
    }

    public function testContextHashExcludesAuth(): void
    {
        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_ANONYMOUS);
        StarCacheContext::set(StarCacheContext::DIM_DEVICE, StarCacheContext::DEVICE_DESKTOP);
        $hashAnon = StarCacheContext::hash();

        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_AUTHENTICATED);
        StarCacheContext::set(StarCacheContext::DIM_DEVICE, StarCacheContext::DEVICE_DESKTOP);
        $hashAuth = StarCacheContext::hash();

        $this->assertSame($hashAnon, $hashAuth, 'Auth dimension must not affect context hash.');
    }

    public function testContextSetIsRejectedAfterLock(): void
    {
        StarCacheContext::resolve();
        $before = StarCacheContext::get(StarCacheContext::DIM_DEVICE);
        StarCacheContext::lock();
        StarCacheContext::set(StarCacheContext::DIM_DEVICE, StarCacheContext::DEVICE_MOBILE);
        $this->assertSame($before, StarCacheContext::get(StarCacheContext::DIM_DEVICE));
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

    public function testContextRegistrationBlocksUnregisteredDimension(): void
    {
        StarCacheContext::resolve();
        // 'my_custom' is not registered — set() should be silently rejected.
        StarCacheContext::set('my_custom', 'value');
        $this->assertSame('', StarCacheContext::get('my_custom'));
    }

    public function testContextRegistrationAllowsRegisteredDimension(): void
    {
        // Register before resolve
        StarCacheContext::register('locale', ['en', 'fr', 'es']);
        StarCacheContext::resolve();
        StarCacheContext::set('locale', 'fr');
        $this->assertSame('fr', StarCacheContext::get('locale'));
    }

    public function testContextRegistrationEnforcesAllowedValues(): void
    {
        StarCacheContext::register('plan', ['free', 'pro', 'enterprise']);
        StarCacheContext::resolve();
        // 'unknown' is not in the allowed list — should default to 'free' (first allowed)
        StarCacheContext::set('plan', 'unknown');
        $this->assertSame('free', StarCacheContext::get('plan'));
    }

    public function testContextSanitizesValueCharacters(): void
    {
        StarCacheContext::register('campaign', []);
        StarCacheContext::resolve();
        // Characters outside [a-z0-9_:-] should be stripped
        StarCacheContext::set('campaign', 'Hello World! @#$');
        $this->assertSame('helloworld', StarCacheContext::get('campaign'));
    }

    public function testContextTruncatesLongValues(): void
    {
        StarCacheContext::register('longdim', []);
        StarCacheContext::resolve();
        $longValue = str_repeat('a', StarCacheContext::MAX_DIMENSION_VALUE_LENGTH + 10);
        StarCacheContext::set('longdim', $longValue);
        $this->assertSame(StarCacheContext::MAX_DIMENSION_VALUE_LENGTH, strlen(StarCacheContext::get('longdim')));
    }

    // =========================================================================
    // StarResponseController
    // =========================================================================

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
    }

    public function testResponseControllerEligibleForHead(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_ANONYMOUS);
        $this->assertTrue(StarResponseController::isEligible());
    }

    public function testResponseControllerNotEligibleWhenAuthenticated(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        StarCacheContext::reset();
        StarCacheContext::set(StarCacheContext::DIM_AUTH, StarCacheContext::AUTH_AUTHENTICATED);
        $this->assertFalse(StarResponseController::isEligible());
    }

    // =========================================================================
    // StarVersionStore — renamed groups
    // =========================================================================

    public function testVersionStoreGroupPagesDefaultsToOne(): void
    {
        $this->assertSame(1, StarVersionStore::get(StarVersionStore::GROUP_PAGES));
    }

    public function testVersionStoreGroupObjectsDefaultsToOne(): void
    {
        $this->assertSame(1, StarVersionStore::get(StarVersionStore::GROUP_OBJECTS));
    }

    public function testVersionStoreGroupQueriesDefaultsToOne(): void
    {
        $this->assertSame(1, StarVersionStore::get(StarVersionStore::GROUP_QUERIES));
    }

    public function testVersionBumpIncrementsVersion(): void
    {
        $group  = 'test_group_' . uniqid();
        $before = StarVersionStore::get($group);
        $newVer = StarVersionStore::bump($group);
        $this->assertSame($before + 1, $newVer);
        $this->assertSame($newVer, StarVersionStore::get($group));
    }

    public function testVersionResetRestoresOne(): void
    {
        $group = 'test_group_' . uniqid();
        StarVersionStore::bump($group);
        StarVersionStore::bump($group);
        StarVersionStore::reset($group);
        $this->assertSame(1, StarVersionStore::get($group));
    }

    public function testVersionBumpAllBumpsAllGroups(): void
    {
        $before = [
            StarVersionStore::get(StarVersionStore::GROUP_PAGES),
            StarVersionStore::get(StarVersionStore::GROUP_QUERIES),
            StarVersionStore::get(StarVersionStore::GROUP_OBJECTS),
        ];

        StarVersionStore::bumpAll();

        $this->assertGreaterThan($before[0], StarVersionStore::get(StarVersionStore::GROUP_PAGES));
        $this->assertGreaterThan($before[1], StarVersionStore::get(StarVersionStore::GROUP_QUERIES));
        $this->assertGreaterThan($before[2], StarVersionStore::get(StarVersionStore::GROUP_OBJECTS));
    }

    // =========================================================================
    // StarAssetMinifier — CSS
    // =========================================================================

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

    // =========================================================================
    // StarAssetMinifier — JS
    // =========================================================================

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

    // =========================================================================
    // StarPageCache — bypass detection
    // =========================================================================

    public function testShouldBypassReturnsTrueForNonGetRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertTrue(StarPageCache::shouldBypass());
    }

    public function testShouldBypassReturnsTrueWhenDoNotCachePage(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        $this->assertTrue(StarPageCache::shouldBypass());
    }

    // =========================================================================
    // StarCache — getUserGroup
    // =========================================================================

    public function testGetUserGroupWithUserId(): void
    {
        $this->assertSame('user_42', (new StarCache())->star_getUserGroup('profile', '42'));
    }

    public function testGetUserGroupWithoutUserId(): void
    {
        $this->assertSame('my_feature', (new StarCache())->star_getUserGroup('my_feature'));
    }

    // =========================================================================
    // StarCache — get/set/delete round-trip (WP object cache stub)
    // =========================================================================

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
        $callback  = static function () use (&$callCount): array {
            $callCount++;
            return ['computed' => true];
        };

        $first  = $cache->star_remember('test_remember', $callback, 60);
        $second = $cache->star_remember('test_remember', $callback, 60);

        $this->assertSame(['computed' => true], $first);
        $this->assertSame(['computed' => true], $second);
        $this->assertSame(1, $callCount, 'Callback must not be called twice.');
    }

    public function testRememberPropagatesCallbackException(): void
    {
        $cache = new StarCache();
        $this->expectException(\RuntimeException::class);
        $cache->star_remember('test_ex', static function (): never {
            throw new \RuntimeException('callback error');
        }, 60);
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
