<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Decorators\CachedResultDecorator;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

test('cache stores and retrieves results', function () {
    Cache::flush();
    $action = new TestCacheAction;
    $decorator = new CachedResultDecorator($action);

    $result1 = $decorator->handle('test');
    $result2 = $decorator->handle('test');

    expect($result1)->toBe('cached: test')
        ->and($result2)->toBe('cached: test')
        ->and($action->executions)->toBe(1);
});

test('cache uses custom key', function () {
    Cache::flush();
    $action = new TestCacheWithCustomKeyAction;
    $decorator = new CachedResultDecorator($action);

    $decorator->handle('123');

    expect(Cache::has('custom:key:123'))->toBeTrue();
});

test('cache respects custom TTL', function () {
    if (! method_exists(Cache::getStore(), 'getRedis')) {
        test()->markTestSkipped('TTL inspection requires Redis cache driver');
    }

    Cache::flush();
    $action = new TestCacheWithCustomTtlAction;
    $decorator = new CachedResultDecorator($action);

    $decorator->handle();

    $cacheKey = 'cached_result:'.TestCacheWithCustomTtlAction::class.':'.hash('sha256', serialize([]));
    $ttl = Cache::getStore()->getRedis()->ttl($cacheKey);

    expect($ttl)->toBeGreaterThan(3500) // Close to 3600
        ->and($ttl)->toBeLessThanOrEqual(3600);
});

test('cache forever stores without expiration', function () {
    Cache::flush();
    $action = new TestCacheForeverAction;
    $decorator = new CachedResultDecorator($action);

    $decorator->handle();

    $cacheKey = 'cached_result:'.TestCacheForeverAction::class.':'.hash('sha256', serialize([]));
    expect(Cache::has($cacheKey))->toBeTrue();
});

test('cache different arguments produce different cache entries', function () {
    Cache::flush();
    $action = new TestCacheAction;
    $decorator = new CachedResultDecorator($action);

    $decorator->handle('test1');
    $decorator->handle('test2');

    expect($action->executions)->toBe(2);
});
