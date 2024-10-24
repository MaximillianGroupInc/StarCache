<?php

namespace StarCache\Tests;

use PHPUnit\Framework\TestCase;
use StarCache\StarCache;
use Memcache;

class StarCacheTest extends TestCase
{
    /**
     * @var StarCache
     */
    private $starCache;

    /**
     * @var Memcache
     */
    private $memcache;

    protected function star_setUp(): void
    {
        parent::setUp();

        $this->memcache = new Memcache();
        $this->memcache->connect('localhost', 11211); // Connect to your Memcache server

        $this->starCache = new StarCache($this->memcache, 'memcache');
    }

    protected function star_tearDown(): void
    {
        parent::tearDown();

        $this->memcache->close();
        $this->starCache->star_closeConnections();
    }

    public function star_testGetCacheKey(): void
    {
        $expectedKey = 'star_cache_user1_my_table_catalog';
        $actualKey = $this->starCache->star_getCacheKey('my_table', 'user1');
        $this->assertEquals($expectedKey, $actualKey);
    }

    public function star_testGetUserGroup(): void
    {
        $this->assertEquals('user_user2', $this->starCache->star_getUserGroup('my_table', 'user2'));
        $this->assertEquals('my_table', $this->starCache->star_getUserGroup('my_table'));
    }

    public function star_testSetCachedData(): void
    {
        $data = ['name' => 'John Doe', 'age' => 30];
        $this->assertTrue($this->starCache->star_setCachedData($data, 'user_profile'));

        $cachedData = $this->starCache->star_getCachedData('user_profile');
        $this->assertEquals($data, $cachedData);
    }

    public function star_testGetCachedData(): void
    {
        $this->starCache->star_setCachedData(['name' => 'Jane Doe'], 'user_profile');

        $cachedData = $this->starCache->star_getCachedData('user_profile');
        $this->assertEquals(['name' => 'Jane Doe'], $cachedData);
    }

    public function star_testDeleteCache(): void
    {
        $this->starCache->star_setCachedData(['name' => 'Test User'], 'test_user');
        $this->assertTrue($this->starCache->star_deleteCache('test_user'));

        $cachedData = $this->starCache->star_getCachedData('test_user');
        $this->assertFalse($cachedData);
    }

    public function star_testFlushReloadCachedData(): void
    {
        $this->starCache->star_setCachedData(['name' => 'Test User'], 'test_user');
        $this->starCache->star_flushReloadCachedData('test_user');

        $cachedData = $this->starCache->star_getCachedData('test_user');
        $this->assertFalse($cachedData);
    }

    // Add more tests for other methods (getCache, setCache, closeConnections, etc.)
}
