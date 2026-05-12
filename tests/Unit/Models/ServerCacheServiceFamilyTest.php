<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ServerCacheService;
use Tests\TestCase;

/**
 * Lock in the engine-family classification used by the coexistence rule. The redis-family group
 * (redis/valkey/keydb/dragonfly) shares wire protocol + port 6379, so at most one of them can
 * run on a server; memcached is its own family because it has a different protocol and a
 * different default port and can coexist with a redis-family engine.
 *
 * The Livewire install action, the partial unique index in the collapse migration, and the
 * cross-family-switch guard in `SwitchCacheServiceJob` all key on `familyOf()`. Pinning the
 * truth table here so a future engine addition that quietly hits the `default` arm — instead of
 * being added to one of the family branches — fails loudly in CI.
 */
class ServerCacheServiceFamilyTest extends TestCase
{
    public function test_redis_family_engines_share_a_family_identifier(): void
    {
        foreach (['redis', 'valkey', 'keydb', 'dragonfly'] as $engine) {
            $this->assertSame(
                ServerCacheService::FAMILY_REDIS,
                ServerCacheService::familyOf($engine),
                "Expected {$engine} to be in the redis family.",
            );
        }
    }

    public function test_memcached_is_its_own_family(): void
    {
        $this->assertSame(ServerCacheService::FAMILY_MEMCACHED, ServerCacheService::familyOf('memcached'));
        $this->assertNotSame(ServerCacheService::FAMILY_REDIS, ServerCacheService::familyOf('memcached'));
    }

    public function test_redis_family_engines_constant_matches_supported_engines_minus_memcached(): void
    {
        // The constant is the source of truth the migration's partial unique index uses. If a
        // new engine is ever added to ENGINES it MUST also land in either FAMILY_REDIS_ENGINES
        // or be classified as its own non-redis family — otherwise the install action's
        // coexistence check has nothing to compare against.
        $redisFamily = ServerCacheService::FAMILY_REDIS_ENGINES;
        $supported = ServerCacheService::ENGINES;
        $nonRedis = array_values(array_diff($supported, $redisFamily));

        $this->assertSame(['memcached'], $nonRedis, 'Every non-memcached supported engine must be in FAMILY_REDIS_ENGINES.');
    }

    public function test_family_of_throws_for_unknown_engine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerCacheService::familyOf('postgres');
    }
}
