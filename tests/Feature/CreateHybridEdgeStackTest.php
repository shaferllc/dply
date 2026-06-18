<?php

declare(strict_types=1);

namespace Tests\Feature\CreateHybridEdgeStackTest;

use App\Modules\Edge\Actions\CreateHybridEdgeStack;
use App\Enums\SiteType;
use App\Jobs\ProvisionCloudSiteJob;
use App\Modules\Edge\Jobs\ProvisionHybridEdgeStackJob;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use RuntimeException;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);
uses(WithFeatures::class);
usesFeatures('surface.cloud');

test('creates cloud site stack meta and dispatches hybrid job', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    [$user, $org] = scaffold();

    $result = (new CreateHybridEdgeStack)->handle($user, $org, [
        'name' => 'SSR App',
        'repo' => 'acme/next-app',
        'branch' => 'main',
        'detected_plan' => [
            'framework' => 'next',
            'start_command' => 'next start',
            'build_command' => 'npm run build',
        ],
    ]);

    expect($result['redirect_to'])->toBe('cloud');
    expect($result['edge_site'])->toBeNull();

    $cloudSite = $result['cloud_site'];
    expect($cloudSite->usesContainerRuntime())->toBeTrue();
    expect($cloudSite->meta['container']['source']['repo'] ?? null)->toBe('acme/next-app');
    expect($cloudSite->meta['container']['hybrid_edge_stack']['status'] ?? null)->toBe('awaiting_origin');
    expect($cloudSite->meta['container']['hybrid_edge_stack']['edge_name'] ?? null)->toBe('SSR App');

    Queue::assertPushed(ProvisionCloudSiteJob::class);
    Queue::assertPushed(ProvisionHybridEdgeStackJob::class);
});

test('skips cloud create when matching origin with live url exists', function () {
    Queue::fake();
    config([
        'server_provision_fake.env_flag' => true,
        'edge.testing_domains' => ['on-dply.site'],
    ]);
    [$user, $org] = scaffold();

    Site::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Existing Origin',
        'type' => SiteType::Container,
        'container_backend' => 'digitalocean_app_platform',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/next-app', 'branch' => 'main'],
                'live_url' => 'https://existing.ondigitalocean.app',
            ],
        ],
    ]);

    $result = (new CreateHybridEdgeStack)->handle($user, $org, [
        'name' => 'SSR App',
        'repo' => 'acme/next-app',
        'branch' => 'main',
        'detected_plan' => [
            'framework' => 'next',
            'start_command' => 'next start',
        ],
    ]);

    expect($result['redirect_to'])->toBe('edge');
    expect($result['edge_site'])->not->toBeNull();
    expect($result['edge_site']->meta['edge']['runtime_mode'] ?? null)->toBe('hybrid');
    expect($result['edge_site']->meta['edge']['origin']['url'] ?? null)->toBe('https://existing.ondigitalocean.app');
    expect(Site::query()->where('type', SiteType::Container)->count())->toBe(1);

    Queue::assertNotPushed(ProvisionHybridEdgeStackJob::class);
});

test('rejects without cloud backend when fake cloud disabled', function () {
    config(['server_provision_fake.env_flag' => false]);
    [$user, $org] = scaffold();

    (new CreateHybridEdgeStack)->handle($user, $org, [
        'name' => 'SSR App',
        'repo' => 'acme/next-app',
        'detected_plan' => [
            'framework' => 'next',
            'start_command' => 'next start',
        ],
    ]);
})->throws(RuntimeException::class);

test('rejects when surface cloud inactive', function () {
    Feature::define('surface.cloud', fn () => false);
    Feature::flushCache();

    [$user, $org] = scaffold();

    (new CreateHybridEdgeStack)->handle($user, $org, [
        'name' => 'SSR App',
        'repo' => 'acme/next-app',
        'detected_plan' => [
            'framework' => 'next',
            'start_command' => 'next start',
        ],
    ]);
})->throws(RuntimeException::class);

/**
 * @return array{0: User, 1: Organization}
 */
function scaffold(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return [$user, $org];
}
