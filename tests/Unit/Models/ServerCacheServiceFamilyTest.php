<?php

declare(strict_types=1);

namespace Tests\Unit\Models\ServerCacheServiceFamilyTest;

use App\Models\ServerCacheService;

test('redis family engines share a family identifier', function () {
    foreach (['redis', 'valkey', 'keydb', 'dragonfly'] as $engine) {
        expect(ServerCacheService::familyOf($engine))->toBe(ServerCacheService::FAMILY_REDIS, "Expected {$engine} to be in the redis family.");
    }
});
test('memcached is its own family', function () {
    expect(ServerCacheService::familyOf('memcached'))->toBe(ServerCacheService::FAMILY_MEMCACHED);
    $this->assertNotSame(ServerCacheService::FAMILY_REDIS, ServerCacheService::familyOf('memcached'));
});
test('redis family engines constant covers redis family only', function () {
    // The constant is the source of truth the migration's partial unique index uses.
    // Every engine in ENGINES must be classified — either in FAMILY_REDIS_ENGINES
    // (key-value, shared 6379-family wire protocol) or as its own non-redis family
    // (memcached, varnish, …) — otherwise the install action's coexistence check
    // has nothing to compare against.
    $redisFamily = ServerCacheService::FAMILY_REDIS_ENGINES;
    $supported = ServerCacheService::ENGINES;
    $nonRedis = array_values(array_diff($supported, $redisFamily));

    // memcached + varnish today; the assertion is "every non-redis engine is classified",
    // not a literal list — sort both sides so new entries don't trip this on order.
    sort($nonRedis);
    expect(count($nonRedis))->toBeGreaterThan(0, 'At least one non-redis-family engine must exist.');
    foreach ($nonRedis as $engine) {
        $this->assertNotSame(
            ServerCacheService::FAMILY_REDIS,
            ServerCacheService::familyOf($engine),
            $engine.' must not be classified as redis-family.',
        );
    }
});
test('family of throws for unknown engine', function () {
    $this->expectException(\InvalidArgumentException::class);
    ServerCacheService::familyOf('postgres');
});
