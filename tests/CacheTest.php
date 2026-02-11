<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Cache\CacheInterface;
use SandraCore\Cache\MemoryCache;
use SandraCore\Cache\NullCache;
use SandraCore\EntityFactory;

final class CacheTest extends SandraTestCase
{
    public function testMemoryCacheSetAndGet(): void
    {
        $cache = new MemoryCache();
        $cache->set('key1', 'value1');
        $this->assertEquals('value1', $cache->get('key1'));
    }

    public function testMemoryCacheGetDefault(): void
    {
        $cache = new MemoryCache();
        $this->assertNull($cache->get('nonexistent'));
        $this->assertEquals('default', $cache->get('nonexistent', 'default'));
    }

    public function testMemoryCacheHas(): void
    {
        $cache = new MemoryCache();
        $this->assertFalse($cache->has('key1'));
        $cache->set('key1', 'value1');
        $this->assertTrue($cache->has('key1'));
    }

    public function testMemoryCacheDelete(): void
    {
        $cache = new MemoryCache();
        $cache->set('key1', 'value1');
        $this->assertTrue($cache->has('key1'));
        $cache->delete('key1');
        $this->assertFalse($cache->has('key1'));
    }

    public function testMemoryCacheFlush(): void
    {
        $cache = new MemoryCache();
        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');
        $cache->flush();
        $this->assertFalse($cache->has('key1'));
        $this->assertFalse($cache->has('key2'));
    }

    public function testMemoryCacheTtlExpiry(): void
    {
        $cache = new MemoryCache();
        // TTL of 1 second
        $cache->set('key1', 'value1', 1);
        $this->assertTrue($cache->has('key1'));
        $this->assertEquals('value1', $cache->get('key1'));

        // TTL of 0 means no expiry
        $cache->set('key2', 'value2', 0);
        $this->assertTrue($cache->has('key2'));
    }

    public function testMemoryCacheStoresArrays(): void
    {
        $cache = new MemoryCache();
        $data = ['entities' => ['a', 'b', 'c'], 'count' => 3];
        $cache->set('complex', $data);
        $this->assertEquals($data, $cache->get('complex'));
    }

    public function testNullCacheAlwaysMisses(): void
    {
        $cache = new NullCache();
        $cache->set('key1', 'value1');
        $this->assertFalse($cache->has('key1'));
        $this->assertNull($cache->get('key1'));
        $this->assertEquals('default', $cache->get('key1', 'default'));
    }

    public function testNullCacheOperationsReturnTrue(): void
    {
        $cache = new NullCache();
        $this->assertTrue($cache->set('key', 'value'));
        $this->assertTrue($cache->delete('key'));
        $this->assertTrue($cache->flush());
    }

    public function testCacheInterfaceImplementation(): void
    {
        $this->assertInstanceOf(CacheInterface::class, new MemoryCache());
        $this->assertInstanceOf(CacheInterface::class, new NullCache());
    }

    public function testEnableCacheReturnsSelf(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $result = $factory->enableCache(new MemoryCache());
        $this->assertSame($factory, $result);
    }

    public function testCachePopulateLocal(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->createNew(['name' => 'Earth']);
        $factory->createNew(['name' => 'Mars']);

        // Enable cache on a fresh factory
        $cachedFactory = $this->createFactory('planet', 'solarSystemFile');
        $cache = new MemoryCache();
        $cachedFactory->enableCache($cache);

        // First populate - cache miss, loads from DB
        $cachedFactory->populateLocal();
        $this->assertCount(2, $cachedFactory->getEntities());

        // Create a new fresh factory with same cache
        $cachedFactory2 = $this->createFactory('planet', 'solarSystemFile');
        $cachedFactory2->enableCache($cache);

        // Second populate - cache hit
        $cachedFactory2->populateLocal();
        $this->assertCount(2, $cachedFactory2->getEntities());
    }

    public function testCacheInvalidatedOnCreate(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $cache = new MemoryCache();
        $factory->enableCache($cache);

        $factory->populateLocal();
        $factory->createNew(['name' => 'Venus']);

        // After create, cache should be flushed.
        // A fresh factory with same cache should get fresh data
        $factory2 = $this->createFactory('planet', 'solarSystemFile');
        $factory2->enableCache($cache);
        $factory2->populateLocal();
        $this->assertCount(1, $factory2->getEntities());
    }

    public function testNoCacheDoesNotInterfere(): void
    {
        // Without cache enabled, everything should work as before
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->createNew(['name' => 'Neptune']);

        $factory2 = $this->createFactory('planet', 'solarSystemFile');
        $factory2->populateLocal();
        $this->assertCount(1, $factory2->getEntities());
    }

    public function testNullCacheDoesNotCache(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->enableCache(new NullCache());
        $factory->createNew(['name' => 'Saturn']);
        $factory->populateLocal();
        $this->assertCount(1, $factory->getEntities());
    }
}
