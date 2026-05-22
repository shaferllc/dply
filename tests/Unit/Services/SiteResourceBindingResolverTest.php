<?php

namespace Tests\Unit\Services\SiteResourceBindingResolverTest;

use App\Models\Site;
use App\Services\Deploy\SiteResourceBindingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('redis is configured when redis host is set', function () {
    $site = Site::factory()->create([
        'env_file_content' => "REDIS_HOST=127.0.0.1\n",
    ]);

    $bindings = app(SiteResourceBindingResolver::class)->forSite($site->fresh());
    $redis = collect($bindings)->firstWhere('type', 'redis');

    expect($redis)->not->toBeNull();
    expect($redis->status)->toBe('configured');
    expect($redis->source)->toBe('environment');
});

test('redis is pending with reason when drivers target redis without connection', function () {
    $site = Site::factory()->create([
        'env_file_content' => "CACHE_STORE=redis\n",
    ]);

    $bindings = app(SiteResourceBindingResolver::class)->forSite($site->fresh());
    $redis = collect($bindings)->firstWhere('type', 'redis');

    expect($redis)->not->toBeNull();
    expect($redis->status)->toBe('pending');
    expect($redis->source)->toBe('environment');
    expect($redis->config['reason'] ?? null)->toBe('drivers_reference_redis_without_connection');
});

test('queue is configured for non sync driver', function () {
    $site = Site::factory()->create([
        'meta' => [
            'docker_runtime' => [
                'detected' => ['framework' => 'laravel'],
            ],
        ],
        'env_file_content' => "QUEUE_CONNECTION=database\n",
    ]);

    $bindings = app(SiteResourceBindingResolver::class)->forSite($site->fresh());
    $queue = collect($bindings)->firstWhere('type', 'queue');

    expect($queue)->not->toBeNull();
    expect($queue->status)->toBe('configured');
    expect($queue->source)->toBe('environment');
    expect($queue->config['driver'] ?? null)->toBe('database');
});

test('storage is configured when bucket and aws keys are set', function () {
    $site = Site::factory()->create([
        'env_file_content' => implode("\n", [
            'AWS_BUCKET=my-bucket',
            'AWS_ACCESS_KEY_ID=AKIA_TEST',
            'AWS_SECRET_ACCESS_KEY=secret',
        ]),
    ]);

    $bindings = app(SiteResourceBindingResolver::class)->forSite($site->fresh());
    $storage = collect($bindings)->firstWhere('type', 'storage');

    expect($storage)->not->toBeNull();
    expect($storage->status)->toBe('configured');
    expect($storage->source)->toBe('environment');
});

test('storage is pending when s3 disk without bucket', function () {
    $site = Site::factory()->create([
        'env_file_content' => "FILESYSTEM_DISK=s3\n",
    ]);

    $bindings = app(SiteResourceBindingResolver::class)->forSite($site->fresh());
    $storage = collect($bindings)->firstWhere('type', 'storage');

    expect($storage)->not->toBeNull();
    expect($storage->status)->toBe('pending');
    expect($storage->source)->toBe('environment');
    expect($storage->config['reason'] ?? null)->toBe('s3_disk_without_bucket');
});
