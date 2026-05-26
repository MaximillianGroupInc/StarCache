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
use StarCache\StarQueryCache;
use StarCache\StarCacheAdapter;
use StarCache\StarTransientCache;

class FakeRedisConnection
{
    /** @var array<string,string> */
    private array $store = [];

    public function get(string $key): string|false
    {
        return $this->store[$key] ?? false;
    }

    public function setEx(string $key, int $expiration, string $value): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function set(string $key, mixed $value, mixed ...$options): bool|string
    {
        if (
            is_array($options[0] ?? null)
            && (in_array('NX', $options[0], true) || in_array('nx', $options[0], true))
            && array_key_exists($key, $this->store)
        ) {
            return false;
        }
        $this->store[$key] = (string) $value;
        return true;
    }

    public function del(string $key): int
    {
        if (!array_key_exists($key, $this->store)) {
            return 0;
        }
        unset($this->store[$key]);
        return 1;
    }
}

class FakeMemcachedConnection
{
    /** @var array<string,string> */
    private array $store = [];

    private int $resultCode = \Memcached::RES_NOTFOUND;

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->store)) {
            $this->resultCode = \Memcached::RES_NOTFOUND;
            return false;
        }
        $this->resultCode = \Memcached::RES_SUCCESS;
        return $this->store[$key];
    }

    public function getResultCode(): int
    {
        return $this->resultCode;
    }

    public function set(string $key, mixed $value, int $expiration = 0): bool
    {
        $this->store[$key] = (string) $value;
        $this->resultCode  = \Memcached::RES_SUCCESS;
        return true;
    }

    public function add(string $key, mixed $value, int $expiration = 0): bool
    {
        if (array_key_exists($key, $this->store)) {
            return false;
        }
        $this->store[$key] = (string) $value;
        $this->resultCode  = \Memcached::RES_SUCCESS;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }
}

class FakePredisPongResponse
{
    public function getPayload(): string
    {
        return 'PONG';
    }
}

class FakePredisErrorResponse
{
    public function getPayload(): string
    {
        return 'NOAUTH Authentication required.';
    }
}

class FakePredisOkResponse
{
    public function getPayload(): string
    {
        return 'OK';
    }
}

class FakePredisStringOkResponse
{
    public function __toString(): string
    {
        return 'OK';
    }
}

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
 * - StarQueryCache (legacy utility): key determinism, version invalidation
 */
class UnitTests extends TestCase
{
    private const SOFT_TTL_WAIT_MICROSECONDS = 1100000;

    protected function setUp(): void
    {
        parent::setUp();
        StarCacheContext::reset();
        StarResponseController::reset();
        $_SERVER['REQUEST_METHOD']  = 'GET';
        // Standard Chrome User-Agent (kept long intentionally — PSR-12 line-length: warning only)
        // phpcs:ignore Generic.Files.LineLength
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        // Reset the blog-ID stub to 1 so tests that modify it don't pollute later tests.
        $GLOBALS['_starcache_test_blog_id'] = 1;
        $GLOBALS['_starcache_wp_cache_flush_calls'] = 0;
        $GLOBALS['_starcache_scheduled_events'] = [];
        $GLOBALS['_starcache_wpcache'] = [];
        $GLOBALS['_starcache_transients'] = [];
        $this->resetAdapterToWpFallback();
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

    public function testContextRegistrationNormalizesNameToLowercase(): void
    {
        // Uppercase letters in the name should be folded to lowercase, making
        // 'Locale' and 'locale' the same registered dimension.
        StarCacheContext::register('Locale', ['en', 'fr']);
        StarCacheContext::resolve();
        StarCacheContext::set('locale', 'fr');
        $this->assertSame('fr', StarCacheContext::get('locale'));
    }

    public function testSetAndGetNormalizeDimensionNameToLowercase(): void
    {
        // set() and get() must normalize $dimension to lowercase so that
        // 'Locale', 'LOCALE', and 'locale' all refer to the same dimension.
        StarCacheContext::register('locale', ['en', 'fr']);
        StarCacheContext::resolve();

        StarCacheContext::set('LOCALE', 'fr');
        $this->assertSame('fr', StarCacheContext::get('LOCALE'), 'get() with uppercase name must match lowercase key');
        $this->assertSame('fr', StarCacheContext::get('locale'), 'get() with lowercase name must return same value');
    }

    public function testContextRegistrationRejectsInvalidCharactersInName(): void
    {
        // Names with characters outside [a-z0-9_:-] must be silently rejected.
        StarCacheContext::register('my dimension!', []);
        StarCacheContext::resolve();
        // The invalid name was not registered, so set() is a no-op.
        StarCacheContext::set('my dimension!', 'val');
        $this->assertSame('', StarCacheContext::get('my dimension!'));
    }

    public function testContextRegistrationRejectsEmptyName(): void
    {
        // Empty string is not a valid dimension name.
        StarCacheContext::register('', []);
        StarCacheContext::resolve();
        // Nothing was registered; calling get('') returns the default.
        $this->assertSame('', StarCacheContext::get(''));
    }

    public function testContextRegistrationRejectsNameExceedingMaxLength(): void
    {
        $longName = str_repeat('a', 65); // > 64 chars
        StarCacheContext::register($longName, []);
        StarCacheContext::resolve();
        StarCacheContext::set($longName, 'val');
        $this->assertSame('', StarCacheContext::get($longName));
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

    public function testResponseControllerSanitizeDirectiveSecondsFallsBackForNegativeValue(): void
    {
        $method = new \ReflectionMethod(StarResponseController::class, 'sanitizeDirectiveSeconds');
        $method->setAccessible(true);

        $maxAge = $method->invoke(null, -15, StarResponseController::DEFAULT_MAX_AGE);
        $swr    = $method->invoke(null, -3, StarResponseController::DEFAULT_STALE_WHILE_REVALIDATE);

        $this->assertSame(StarResponseController::DEFAULT_MAX_AGE, $maxAge);
        $this->assertSame(StarResponseController::DEFAULT_STALE_WHILE_REVALIDATE, $swr);
    }

    public function testResponseControllerSanitizeDirectiveSecondsFallsBackForInvalidType(): void
    {
        $method = new \ReflectionMethod(StarResponseController::class, 'sanitizeDirectiveSeconds');
        $method->setAccessible(true);

        $this->assertSame(120, $method->invoke(null, null, 120));
        $this->assertSame(90, $method->invoke(null, 'not-a-number', 90));
        $this->assertSame(0, $method->invoke(null, false, -10));
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

    public function testVersionBumpAdvancesVersion(): void
    {
        $group  = 'test_group_' . uniqid();
        $before = StarVersionStore::get($group);
        $newVer = StarVersionStore::bump($group);
        $this->assertGreaterThan($before, $newVer);
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

    /**
     * minifyJs() performs safe-only normalisation (trim only).
     * It deliberately does NOT strip comments or collapse whitespace because
     * regex-based stripping breaks valid JS that contains `//` or `/*` inside
     * strings, template literals, or regex literals.
     */
    public function testMinifyJsPreservesContent(): void
    {
        // Content inside a line comment must survive — stripping it would be unsafe
        $input    = "var x = 1; // this is a comment\nvar y = 2;";
        $minified = StarAssetMinifier::minifyJs($input);
        $this->assertStringContainsString('this is a comment', $minified);
    }

    public function testMinifyJsPreservesBlockComments(): void
    {
        // Block comment content is preserved intact
        $input    = "/* block comment */\nvar a = 1;";
        $minified = StarAssetMinifier::minifyJs($input);
        $this->assertStringContainsString('block comment', $minified);
    }

    public function testMinifyJsPreservesLicenseComments(): void
    {
        $input    = "/*! License header */\nvar a = 1;";
        $minified = StarAssetMinifier::minifyJs($input);
        $this->assertStringContainsString('License header', $minified);
    }

    public function testMinifyJsTrimsSurroundingWhitespace(): void
    {
        // Only leading/trailing whitespace is removed
        $input    = "  \n  var x = 1;  \n  ";
        $minified = StarAssetMinifier::minifyJs($input);
        $this->assertSame('var x = 1;', $minified);
    }

    public function testBuildAssetFromCronWritesMinifiedCssFile(): void
    {
        $dir      = sys_get_temp_dir() . '/starcache_test_' . uniqid('', true);
        $this->assertTrue(mkdir($dir, 0755, true));

        $srcPath  = $dir . '/style.css';
        $destPath = $dir . '/style.min.css';

        $this->assertNotFalse(file_put_contents($srcPath, "/* comment */ body { color : red ; } "));

        StarAssetMinifier::buildAssetFromCron($srcPath, $destPath, 'css');

        $this->assertFileExists($destPath);
        $content = (string) file_get_contents($destPath);
        $this->assertStringNotContainsString('/* comment */', $content);
        $this->assertStringContainsString('color:red', $content);

        // Clean up
        unlink($srcPath);
        unlink($destPath);
        rmdir($dir);
    }

    public function testBuildAssetFromCronWritesNormalizedJsFile(): void
    {
        $dir      = sys_get_temp_dir() . '/starcache_test_' . uniqid('', true);
        $this->assertTrue(mkdir($dir, 0755, true));

        $srcPath  = $dir . '/app.js';
        $destPath = $dir . '/app.min.js';

        $this->assertNotFalse(file_put_contents($srcPath, "  var x = 1;  \n"));

        StarAssetMinifier::buildAssetFromCron($srcPath, $destPath, 'js');

        $this->assertFileExists($destPath);
        $content = (string) file_get_contents($destPath);
        $this->assertSame('var x = 1;', $content);

        // Clean up
        unlink($srcPath);
        unlink($destPath);
        rmdir($dir);
    }

    public function testBuildAssetFromCronSkipsWhenDestAlreadyExists(): void
    {
        $dir      = sys_get_temp_dir() . '/starcache_test_' . uniqid('', true);
        $this->assertTrue(mkdir($dir, 0755, true));

        $srcPath  = $dir . '/style.css';
        $destPath = $dir . '/style.min.css';

        $this->assertNotFalse(file_put_contents($srcPath, 'body { color: blue }'));
        $this->assertNotFalse(file_put_contents($destPath, 'original_content'));

        StarAssetMinifier::buildAssetFromCron($srcPath, $destPath, 'css');

        // The existing file should not be overwritten
        $this->assertSame('original_content', (string) file_get_contents($destPath));

        // Clean up
        unlink($srcPath);
        unlink($destPath);
        rmdir($dir);
    }

    public function testBuildAssetFromCronSkipsUnreadableSource(): void
    {
        StarAssetMinifier::buildAssetFromCron('/nonexistent/path/style.css', '/tmp/out.min.css', 'css');
        $this->assertFileDoesNotExist('/tmp/out.min.css');
    }

    // =========================================================================
    // StarPageCache — bypass detection
    // =========================================================================

    public function testShouldBypassReturnsTrueForNonGetRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertTrue(StarPageCache::shouldBypass());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testShouldBypassReturnsTrueWhenDoNotCachePage(): void
    {
        define('DONOTCACHEPAGE', true);
        $this->assertTrue(StarPageCache::shouldBypass());
    }

    // =========================================================================
    // StarResponseController — upstream header detection
    // =========================================================================

    public function testUpstreamHeadersExistReturnsFalseWhenNoHeaders(): void
    {
        // headers_list() returns an empty array in the CLI/test environment;
        // upstreamHeadersExist() should therefore report no upstream headers.
        $this->assertFalse(StarResponseController::upstreamHeadersExist());
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

    public function testRememberCachesFalseValue(): void
    {
        $cache     = new StarCache();
        $callCount = 0;
        $callback  = static function () use (&$callCount): bool {
            $callCount++;
            return false;
        };

        $first  = $cache->star_remember('test_remember_false', $callback, 60);
        $second = $cache->star_remember('test_remember_false', $callback, 60);

        $this->assertFalse($first);
        $this->assertFalse($second);
        $this->assertSame(1, $callCount, 'False values must be cached and reused.');
    }

    public function testRememberLockContentionServesStaleWithoutSecondCallbackRun(): void
    {
        $cache     = new StarCache();
        $callCount = 0;
        $callback  = static function () use (&$callCount): array {
            $callCount++;
            return ['computed' => $callCount];
        };

        $first = $cache->star_remember('remember_lock_contention', $callback, 2);
        usleep(self::SOFT_TTL_WAIT_MICROSECONDS); // Let soft TTL (floor(2 * 0.8) = 1s) become stale.

        $keyMethod = new \ReflectionMethod(StarCache::class, 'buildKey');
        $keyMethod->setAccessible(true);
        $key = $keyMethod->invoke($cache, 'remember_lock_contention', null);

        $lockMethod = new \ReflectionMethod(StarCache::class, 'buildRememberLockKey');
        $lockMethod->setAccessible(true);
        $lockKey = $lockMethod->invoke($cache, $key);

        $group = $cache->star_getUserGroup('remember_lock_contention', null);
        $this->assertTrue(StarCacheAdapter::add($lockKey, 1, 30, $group));

        $second = $cache->star_remember('remember_lock_contention', $callback, 2);

        $this->assertSame($first, $second);
        $this->assertSame(1, $callCount, 'Callback should not run again while another lock holder is refreshing.');
    }

    public function testGetCachedDataFoundFlagDistinguishesFalseHitFromMiss(): void
    {
        $cache = new StarCache();
        $cache->star_setCachedData(false, 'test_false_value');

        $found = null;
        $value = $cache->star_getCachedData('test_false_value', null, $found);
        $this->assertFalse($value);
        $this->assertTrue($found);

        $foundMiss = null;
        $missValue = $cache->star_getCachedData('test_false_miss', null, $foundMiss);
        $this->assertFalse($missValue);
        $this->assertFalse($foundMiss);
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

    // =========================================================================
    // StarTransientCache — site/network key spaces and lifecycle
    // =========================================================================

    public function testTransientCacheSetGetAndDeleteRoundTrip(): void
    {
        $this->assertTrue(StarTransientCache::star_setCachedData(['ok' => true], 'transient_roundtrip'));
        $this->assertSame(['ok' => true], StarTransientCache::star_getCachedData('transient_roundtrip'));
        StarTransientCache::star_deleteCache('transient_roundtrip');
        $this->assertFalse(StarTransientCache::star_getCachedData('transient_roundtrip'));
    }

    public function testTransientCacheIsolatedByBlogId(): void
    {
        $GLOBALS['_starcache_test_blog_id'] = 1;
        $this->assertTrue(StarTransientCache::star_setCachedData('site-1', 'transient_blog_isolation'));

        $GLOBALS['_starcache_test_blog_id'] = 2;
        $this->assertFalse(StarTransientCache::star_getCachedData('transient_blog_isolation'));
        $this->assertTrue(StarTransientCache::star_setCachedData('site-2', 'transient_blog_isolation'));
        $this->assertSame('site-2', StarTransientCache::star_getCachedData('transient_blog_isolation'));

        $GLOBALS['_starcache_test_blog_id'] = 1;
        $this->assertSame('site-1', StarTransientCache::star_getCachedData('transient_blog_isolation'));
    }

    public function testNetworkTransientSharedAcrossBlogIdsAndDeletable(): void
    {
        $GLOBALS['_starcache_test_blog_id'] = 1;
        $this->assertTrue(StarTransientCache::star_setNetworkCachedData('network-value', 'network_transient'));

        $GLOBALS['_starcache_test_blog_id'] = 2;
        $this->assertSame('network-value', StarTransientCache::star_getNetworkCachedData('network_transient'));

        StarTransientCache::star_deleteNetworkCache('network_transient');
        $this->assertFalse(StarTransientCache::star_getNetworkCachedData('network_transient'));
    }

    // =========================================================================
    // StarCacheAdapter — add/flush safety behavior
    // =========================================================================

    public function testAdapterAddStoresOnlyWhenAbsentInWpFallback(): void
    {
        $this->assertTrue(StarCacheAdapter::add('adapter_add', 'first', 30, 'group'));
        $this->assertFalse(StarCacheAdapter::add('adapter_add', 'second', 30, 'group'));
        $this->assertSame('first', StarCacheAdapter::get('adapter_add', 'group'));
    }

    public function testAdapterFlushBlockedWithoutDangerousFlushConstant(): void
    {
        $this->assertFalse(StarCacheAdapter::flush());
        $this->assertSame(0, (int) ($GLOBALS['_starcache_wp_cache_flush_calls'] ?? -1));
    }

    // =========================================================================
    // StarQueryCache (legacy utility) — key determinism + version invalidation
    // =========================================================================

    public function testCachedWpdbQueryKeyIsDeterministic(): void
    {
        // Two identical SQL queries must resolve to the same cache entry.
        global $wpdb;
        $wpdb->callCount = 0;
        wp_cache_delete_group('starcache_wpdb');

        $sql = 'SELECT ID FROM wp_posts WHERE post_status = "publish" LIMIT 10';

        StarQueryCache::cachedWpdbQuery($sql); // miss → populates cache
        StarQueryCache::cachedWpdbQuery($sql); // hit  → no additional wpdb call

        $this->assertSame(1, $wpdb->callCount, 'Second call with same SQL must be served from cache.');
    }

    public function testCachedWpdbQueryKeyVariesBySql(): void
    {
        // Two different SQL statements must use independent cache entries.
        // We verify this by checking that the second SQL causes a fresh wpdb hit
        // while the first SQL is served from cache (call count = 1 not 2).
        global $wpdb;
        $wpdb->callCount = 0;
        wp_cache_delete_group('starcache_wpdb');

        $sql1 = 'SELECT ID FROM wp_posts WHERE post_status = "publish" LIMIT 10';
        $sql2 = 'SELECT ID FROM wp_posts WHERE post_status = "draft" LIMIT 10';

        StarQueryCache::cachedWpdbQuery($sql1); // miss → wpdb call 1
        StarQueryCache::cachedWpdbQuery($sql1); // hit  → no new wpdb call
        StarQueryCache::cachedWpdbQuery($sql2); // miss → wpdb call 2

        $this->assertSame(2, $wpdb->callCount, 'Each distinct SQL string must produce a separate cache key.');
    }

    public function testQueryCacheInvalidatedByVersionBump(): void
    {
        // Use a fresh group so no prior test state interferes.
        $group = 'test_q_invalidate_' . uniqid();

        // Version starts at 1 for any unknown group.
        $this->assertSame(1, StarVersionStore::get($group));

        // Key at version 1
        $keyV1 = StarCacheKey::build('homepage_posts', null, $group);

        // Bump makes all keys embedding the old version unreachable.
        StarVersionStore::bump($group);
        $keyV2 = StarCacheKey::build('homepage_posts', null, $group);

        $this->assertNotSame($keyV1, $keyV2, 'Bumped version must produce a different key.');

        // Resetting back to version 1 must reproduce the original key.
        StarVersionStore::reset($group);
        $keyV1again = StarCacheKey::build('homepage_posts', null, $group);

        $this->assertSame($keyV1, $keyV1again, 'Same version must produce the same key.');
    }

    public function testQueryCacheKeyIncludesBlogId(): void
    {
        // StarQueryCache::cachedWpdbQuery() embeds the blog ID in its cache key
        // so that identical SQL on different sites never shares entries.
        // We verify this by:
        //   1. Running a query on site 1 → populates the site-1 key.
        //   2. Confirming the second call on site 1 is a cache hit (wpdb called once).
        //   3. Switching to site 2 → the same SQL must produce a different key,
        //      causing a cache MISS and a fresh wpdb call.
        global $wpdb;

        // Start fresh with site 1.
        $GLOBALS['_starcache_test_blog_id'] = 1;
        $wpdb->callCount = 0;
        wp_cache_delete_group('starcache_wpdb');

        $sql = 'SELECT ID FROM wp_posts WHERE post_status = "publish" ORDER BY ID LIMIT 5';

        StarQueryCache::cachedWpdbQuery($sql); // MISS on site 1 — populates key sc_sql_1_*
        StarQueryCache::cachedWpdbQuery($sql); // HIT  on site 1 — served from cache
        $this->assertSame(1, $wpdb->callCount, 'Second call on the same site must be served from cache.');

        // Switch to site 2 — same SQL, different key prefix.
        $GLOBALS['_starcache_test_blog_id'] = 2;

        StarQueryCache::cachedWpdbQuery($sql); // MISS on site 2 — sc_sql_2_* is cold
        $this->assertSame(2, $wpdb->callCount, 'Same SQL on a different site must be a cache miss due to different blog-ID key prefix.');
    }

    public function testQueryCacheVersionGroupQueriesDefaultsToOne(): void
    {
        // GROUP_QUERIES must start at version 1 in a fresh environment.
        // (setUp() calls StarCacheContext::reset() but not StarVersionStore::reset()
        //  so we use a unique group name to avoid cross-test interference.)
        $uniqueGroup = 'test_qcache_' . uniqid();
        $this->assertSame(1, StarVersionStore::get($uniqueGroup));
    }

    // =========================================================================
    // StarAssetMinifier — asynchronous stored-file model
    // =========================================================================

    public function testAssetFirstRequestServesOriginalThenServesStoredMinifiedFile(): void
    {
        $uniqueSuffix = uniqid('', true);
        $themeName    = 'starcache-test-' . $uniqueSuffix;
        $assetHandle  = 'theme-style-' . $uniqueSuffix;
        $assetDir     = WP_CONTENT_DIR . '/themes/' . $themeName;
        $assetPath    = $assetDir . '/style.css';
        if (!is_dir($assetDir)) {
            $this->assertTrue(mkdir($assetDir, 0755, true), 'Failed to create asset directory: ' . $assetDir);
        }
        $this->assertNotFalse(file_put_contents($assetPath, '/* c */ body { color : red ; }'));

        $blogId  = (int) $GLOBALS['_starcache_test_blog_id'];
        $baseDir = WP_CONTENT_DIR . '/cache/starcache/assets/' . $blogId;
        if (!is_dir($baseDir)) {
            $this->assertTrue(mkdir($baseDir, 0755, true), 'Failed to create cache base directory: ' . $baseDir);
        }

        // Remove any leftover cached file for this handle so the first request is always a miss.
        $cachedPattern = $baseDir . '/' . $assetHandle . '.*';
        foreach (glob($cachedPattern) ?: [] as $staleFile) {
            @unlink($staleFile);
        }

        $styles = new \WP_Styles();
        $styles->queue            = [$assetHandle];
        $styles->registered[$assetHandle] = (object) [
            'src' => WP_CONTENT_URL . '/themes/' . $themeName . '/style.css',
            'ver' => null,
        ];
        $GLOBALS['wp_styles'] = $styles;

        StarAssetMinifier::init();
        StarAssetMinifier::processStyles();
        $this->assertSame(
            WP_CONTENT_URL . '/themes/' . $themeName . '/style.css',
            $styles->registered[$assetHandle]->src,
            'First request must keep original asset URL while build is pending.'
        );

        $scheduled = $GLOBALS['_starcache_scheduled_events'];
        $this->assertNotEmpty($scheduled, 'First request should schedule an async asset build.');
        $event = end($scheduled);
        $args  = $event['args'];
        StarAssetMinifier::buildAssetFromCron($args[0], $args[1], $args[2]);

        StarAssetMinifier::processStyles();
        $this->assertStringContainsString('.min.css', $styles->registered[$assetHandle]->src);
        $this->assertStringContainsString('/cache/starcache/assets/' . $blogId . '/', $styles->registered[$assetHandle]->src);
    }

    // =========================================================================
    // StarCacheAdapter — backend round-trip semantics
    // =========================================================================

    public function testBackendRoundTripRedisValues(): void
    {
        $this->assertBackendRoundTripValues(
            StarCacheAdapter::BACKEND_REDIS,
            new FakeRedisConnection(),
            [false, ['a' => 1], 'hello', null]
        );
    }

    public function testBackendRoundTripMemcachedValues(): void
    {
        $this->assertBackendRoundTripValues(
            StarCacheAdapter::BACKEND_MEMCACHED,
            new FakeMemcachedConnection(),
            [false, ['a' => 1], 'hello', null]
        );
    }

    public function testPredisPingHelperAcceptsValidPongResponses(): void
    {
        $method = new \ReflectionMethod(StarCacheAdapter::class, 'isSuccessfulPredisPing');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, 'PONG'));
        $this->assertTrue($method->invoke(null, '+PONG'));
        $this->assertTrue($method->invoke(null, new FakePredisPongResponse()));
    }

    public function testPredisPingHelperRejectsErrorLikeResponses(): void
    {
        $method = new \ReflectionMethod(StarCacheAdapter::class, 'isSuccessfulPredisPing');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, null));
        $this->assertFalse($method->invoke(null, false));
        $this->assertFalse($method->invoke(null, 'NOAUTH Authentication required.'));
        $this->assertFalse($method->invoke(null, new FakePredisErrorResponse()));
    }

    public function testPredisSetResultHelperAcceptsStatusResponses(): void
    {
        $method = new \ReflectionMethod(StarCacheAdapter::class, 'isSuccessfulSetResult');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, 'OK'));
        $this->assertTrue($method->invoke(null, '+OK'));
        $this->assertTrue($method->invoke(null, new FakePredisOkResponse()));
        $this->assertTrue($method->invoke(null, new FakePredisStringOkResponse()));
    }

    public function testPredisSetResultHelperRejectsErrorResponses(): void
    {
        $method = new \ReflectionMethod(StarCacheAdapter::class, 'isSuccessfulSetResult');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, null));
        $this->assertFalse($method->invoke(null, false));
        $this->assertFalse($method->invoke(null, 'NOAUTH Authentication required.'));
        $this->assertFalse($method->invoke(null, new FakePredisErrorResponse()));
    }

    // =========================================================================
    // Version invalidation — no backend flush
    // =========================================================================

    public function testVersionBumpInvalidatesWithoutBackendFlush(): void
    {
        $group = 'vtest_' . uniqid('', true);
        $v1Key = StarCacheKey::build('invalidate-key', null, $group);
        $this->assertTrue(StarCacheAdapter::set($v1Key, 'payload-v1', 3600, 'vtest'));

        $this->assertSame('payload-v1', StarCacheAdapter::get($v1Key, 'vtest'));
        StarVersionStore::bump($group);
        $v2Key = StarCacheKey::build('invalidate-key', null, $group);
        $this->assertNotSame($v1Key, $v2Key);
        $this->assertFalse(StarCacheAdapter::get($v2Key, 'vtest'));
        $this->assertSame('payload-v1', StarCacheAdapter::get($v1Key, 'vtest'));
        $this->assertSame(0, (int) ($GLOBALS['_starcache_wp_cache_flush_calls'] ?? 0));
    }

    // =========================================================================
    // Multisite isolation — page, object, query, asset
    // =========================================================================

    public function testMultisiteBlogIdIsolatesPageObjectDeprecatedQueryAndAssetCacheSpaces(): void
    {
        // Object cache space
        $GLOBALS['_starcache_test_blog_id'] = 1;
        $obj1 = StarCacheKey::build('obj-test');
        $GLOBALS['_starcache_test_blog_id'] = 2;
        $obj2 = StarCacheKey::build('obj-test');
        $this->assertNotSame($obj1, $obj2);

        // Query cache space (existing StarQueryCache key model)
        global $wpdb;
        $wpdb->callCount = 0;
        $sql = 'SELECT ID FROM wp_posts WHERE post_status = "publish" LIMIT 3';
        $GLOBALS['_starcache_test_blog_id'] = 1;
        StarQueryCache::cachedWpdbQuery($sql);
        StarQueryCache::cachedWpdbQuery($sql);
        $this->assertSame(1, $wpdb->callCount);
        $GLOBALS['_starcache_test_blog_id'] = 2;
        StarQueryCache::cachedWpdbQuery($sql);
        $this->assertSame(2, $wpdb->callCount);

        // Page cache key space
        $GLOBALS['_starcache_test_blog_id'] = 1;
        $page1 = $this->buildPageKeyForTest('http://localhost/sample');
        $GLOBALS['_starcache_test_blog_id'] = 2;
        $page2 = $this->buildPageKeyForTest('http://localhost/sample');
        $this->assertNotSame($page1, $page2);

        // Asset cache directory space
        $GLOBALS['_starcache_test_blog_id'] = 1;
        StarAssetMinifier::init();
        $dir1 = $this->readAssetCacheDir();
        $GLOBALS['_starcache_test_blog_id'] = 2;
        StarAssetMinifier::init();
        $dir2 = $this->readAssetCacheDir();
        $this->assertNotSame($dir1, $dir2);
    }

    // =========================================================================
    // Test helpers
    // =========================================================================

    private function resetAdapterToWpFallback(): void
    {
        $ref = new \ReflectionClass(StarCacheAdapter::class);
        foreach (
            [
                'connection'      => null,
                'detectedBackend' => StarCacheAdapter::BACKEND_WP,
                'initialised'     => true,
            ] as $name => $value
        ) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private function setAdapterBackendForTest(string $backend, object $connection): void
    {
        $ref = new \ReflectionClass(StarCacheAdapter::class);

        $connectionProperty = $ref->getProperty('connection');
        $connectionProperty->setAccessible(true);
        $connectionProperty->setValue(null, $connection);

        $backendProperty = $ref->getProperty('detectedBackend');
        $backendProperty->setAccessible(true);
        $backendProperty->setValue(null, $backend);

        $initialisedProperty = $ref->getProperty('initialised');
        $initialisedProperty->setAccessible(true);
        $initialisedProperty->setValue(null, true);
    }

    /**
     * @param list<mixed> $values
     */
    private function assertBackendRoundTripValues(string $backend, object $connection, array $values): void
    {
        $this->setAdapterBackendForTest($backend, $connection);
        foreach ($values as $index => $value) {
            $key = 'roundtrip_' . $backend . '_' . $index;
            $this->assertTrue(StarCacheAdapter::set($key, $value, 120, 'grp'));
            $hit = StarCacheAdapter::getWithFound($key, 'grp');
            $this->assertTrue($hit['found'], 'Expected cache hit for ' . $backend . ' value index ' . $index);
            $this->assertSame($value, $hit['value']);
        }
    }

    private function buildPageKeyForTest(string $url): string
    {
        $method = new \ReflectionMethod(StarPageCache::class, 'buildPageKeyFromUrl');
        $method->setAccessible(true);
        return $method->invoke(null, $url);
    }

    private function readAssetCacheDir(): string
    {
        $prop = new \ReflectionProperty(StarAssetMinifier::class, 'cacheDir');
        $prop->setAccessible(true);
        return (string) $prop->getValue();
    }
}
