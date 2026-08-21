<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessAppBucketTest;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\ServerlessEnvironmentPreparer;
use App\Modules\Serverless\Services\ServerlessAppBucketProvisioner;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.digitalocean.token' => 'do-token',
        'serverless.assets.app_buckets.enabled' => true,
        'serverless.assets.app_buckets.region' => 'nyc3',
        'serverless.assets.app_buckets.disk' => 'uploads',
        'serverless.assets.app_buckets.name_prefix' => 'dply-fn',
    ]);

    // The environment merge is the deploy's business, not this one's.
    $this->instance(ServerlessEnvironmentPreparer::class, Mockery::mock(ServerlessEnvironmentPreparer::class)
        ->shouldIgnoreMissing());
});

/** A function site with a slug, so the bucket can be named. */
function functionSite(): Site
{
    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'shop-a1b2c3d4']],
    ]);

    return $site->fresh();
}

/** Spaces keys API: two creates, then whatever deletes follow. */
function fakeSpacesKeys(): void
{
    Http::fake([
        'api.digitalocean.com/v2/spaces/keys/*' => Http::response([], 204),
        'api.digitalocean.com/v2/spaces/keys' => Http::sequence()
            ->push(['key' => ['access_key' => 'PLATFORM', 'secret_key' => 'platform-secret']])
            ->push(['key' => ['access_key' => 'APPKEY', 'secret_key' => 'app-secret']])
            ->whenEmpty(Http::response(['key' => ['access_key' => 'EXTRA', 'secret_key' => 'extra-secret']])),
    ]);
}

it('provisions a bucket, applies the upload policy, and revokes the full-access key', function () {
    fakeSpacesKeys();

    $buckets = Mockery::mock(ObjectStorageBucketProvisioner::class);
    $buckets->shouldReceive('create')
        ->once()
        ->with('digitalocean_spaces', 'nyc3', 'PLATFORM', 'platform-secret', 'dply-fn-shop-a1b2c3d4', Mockery::any())
        ->andReturn(['endpoint' => 'https://nyc3.digitaloceanspaces.com']);
    $buckets->shouldReceive('applyUploadPolicy')
        ->once()
        ->with('digitalocean_spaces', 'nyc3', 'PLATFORM', 'platform-secret', 'dply-fn-shop-a1b2c3d4', 'tmp/', 1);
    $this->instance(ObjectStorageBucketProvisioner::class, $buckets);

    $site = functionSite();
    $binding = app(ServerlessAppBucketProvisioner::class)->ensure($site);

    expect($binding->config['bucket'])->toBe('dply-fn-shop-a1b2c3d4')
        ->and($binding->config['managed_by'])->toBe(ServerlessAppBucketProvisioner::MANAGED_BY)
        ->and($binding->config['key_id'])->toBe('APPKEY')
        ->and($binding->config['upload_policy_at'])->not->toBeNull()
        // The app gets the scoped key, never the one that made the bucket.
        ->and($binding->connectionEnv()['AWS_UPLOADS_ACCESS_KEY_ID'])->toBe('APPKEY')
        ->and($binding->connectionEnv()['AWS_UPLOADS_BUCKET'])->toBe('dply-fn-shop-a1b2c3d4');

    // The whole point: the full-access key does not outlive provisioning.
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/spaces/keys/PLATFORM'));
});

it('revokes the full-access key even when provisioning fails', function () {
    fakeSpacesKeys();

    $buckets = Mockery::mock(ObjectStorageBucketProvisioner::class);
    $buckets->shouldReceive('create')->once()->andThrow(new RuntimeException('provider exploded'));
    $this->instance(ObjectStorageBucketProvisioner::class, $buckets);

    $site = functionSite();

    expect(fn () => app(ServerlessAppBucketProvisioner::class)->ensure($site))
        ->toThrow(RuntimeException::class);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/spaces/keys/PLATFORM'));
});

it('reuses an existing binding without touching the provider', function () {
    Http::fake();

    $buckets = Mockery::mock(ObjectStorageBucketProvisioner::class);
    $buckets->shouldReceive('create')->never();
    $this->instance(ObjectStorageBucketProvisioner::class, $buckets);

    $site = functionSite();
    SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'storage',
        'name' => 'uploads',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'target_type' => 'object_storage',
        'injected_env' => ['AWS_UPLOADS_BUCKET' => 'dply-fn-shop-a1b2c3d4'],
        'config' => [
            'bucket' => 'dply-fn-shop-a1b2c3d4',
            'region' => 'nyc3',
            'managed_by' => ServerlessAppBucketProvisioner::MANAGED_BY,
            'upload_policy_at' => '2026-08-20T00:00:00+00:00',
        ],
    ]);

    app(ServerlessAppBucketProvisioner::class)->ensure($site);

    Http::assertNothingSent();
});

it('destroys the bucket and revokes the app key on teardown', function () {
    fakeSpacesKeys();

    $buckets = Mockery::mock(ObjectStorageBucketProvisioner::class);
    $buckets->shouldReceive('delete')
        ->once()
        ->with('digitalocean_spaces', 'nyc3', 'PLATFORM', 'platform-secret', 'dply-fn-shop-a1b2c3d4', Mockery::any());
    $this->instance(ObjectStorageBucketProvisioner::class, $buckets);

    $site = functionSite();
    SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'storage',
        'name' => 'uploads',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'target_type' => 'object_storage',
        'injected_env' => ['AWS_UPLOADS_ACCESS_KEY_ID' => 'APPKEY'],
        'config' => [
            'bucket' => 'dply-fn-shop-a1b2c3d4',
            'region' => 'nyc3',
            'key_id' => 'APPKEY',
            'managed_by' => ServerlessAppBucketProvisioner::MANAGED_BY,
        ],
    ]);

    expect(app(ServerlessAppBucketProvisioner::class)->destroy($site))->toBeTrue()
        ->and(SiteBinding::query()->where('site_id', $site->id)->count())->toBe(0);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/spaces/keys/APPKEY'));
});

it('never deletes a bucket dply did not provision', function () {
    Http::fake();

    $buckets = Mockery::mock(ObjectStorageBucketProvisioner::class);
    $buckets->shouldReceive('delete')->never();
    $this->instance(ObjectStorageBucketProvisioner::class, $buckets);

    $site = functionSite();
    SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'storage',
        'name' => 'uploads',
        'mode' => 'use_existing',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'target_type' => 'object_storage',
        'injected_env' => ['AWS_UPLOADS_BUCKET' => 'the-operators-own-bucket'],
        'config' => ['bucket' => 'the-operators-own-bucket', 'region' => 'nyc3'],
    ]);

    expect(app(ServerlessAppBucketProvisioner::class)->destroy($site))->toBeFalse();

    Http::assertNothingSent();
});
