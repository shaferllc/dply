<?php

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Services\Deploy\SiteResourceBindingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteResourceBindingResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_redis_is_configured_when_redis_host_is_set(): void
    {
        $site = Site::factory()->create([
            'env_file_content' => "REDIS_HOST=127.0.0.1\n",
        ]);

        $bindings = app(SiteResourceBindingResolver::class)->forSite($site->fresh());
        $redis = collect($bindings)->firstWhere('type', 'redis');

        $this->assertNotNull($redis);
        $this->assertSame('configured', $redis->status);
        $this->assertSame('environment', $redis->source);
    }

    public function test_redis_is_pending_with_reason_when_drivers_target_redis_without_connection(): void
    {
        $site = Site::factory()->create([
            'env_file_content' => "CACHE_STORE=redis\n",
        ]);

        $bindings = app(SiteResourceBindingResolver::class)->forSite($site->fresh());
        $redis = collect($bindings)->firstWhere('type', 'redis');

        $this->assertNotNull($redis);
        $this->assertSame('pending', $redis->status);
        $this->assertSame('environment', $redis->source);
        $this->assertSame('drivers_reference_redis_without_connection', $redis->config['reason'] ?? null);
    }

    public function test_queue_is_configured_for_non_sync_driver(): void
    {
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

        $this->assertNotNull($queue);
        $this->assertSame('configured', $queue->status);
        $this->assertSame('environment', $queue->source);
        $this->assertSame('database', $queue->config['driver'] ?? null);
    }

    public function test_storage_is_configured_when_bucket_and_aws_keys_are_set(): void
    {
        $site = Site::factory()->create([
            'env_file_content' => implode("\n", [
                'AWS_BUCKET=my-bucket',
                'AWS_ACCESS_KEY_ID=AKIA_TEST',
                'AWS_SECRET_ACCESS_KEY=secret',
            ]),
        ]);

        $bindings = app(SiteResourceBindingResolver::class)->forSite($site->fresh());
        $storage = collect($bindings)->firstWhere('type', 'storage');

        $this->assertNotNull($storage);
        $this->assertSame('configured', $storage->status);
        $this->assertSame('environment', $storage->source);
    }

    public function test_storage_is_pending_when_s3_disk_without_bucket(): void
    {
        $site = Site::factory()->create([
            'env_file_content' => "FILESYSTEM_DISK=s3\n",
        ]);

        $bindings = app(SiteResourceBindingResolver::class)->forSite($site->fresh());
        $storage = collect($bindings)->firstWhere('type', 'storage');

        $this->assertNotNull($storage);
        $this->assertSame('pending', $storage->status);
        $this->assertSame('environment', $storage->source);
        $this->assertSame('s3_disk_without_bucket', $storage->config['reason'] ?? null);
    }
}
