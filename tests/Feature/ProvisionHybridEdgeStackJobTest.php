<?php

declare(strict_types=1);

namespace Tests\Feature\ProvisionHybridEdgeStackJobTest;

use App\Modules\Edge\Actions\CreateHybridEdgeStack;
use App\Enums\SiteType;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Jobs\ProvisionCloudSiteJob;
use App\Modules\Edge\Jobs\ProvisionHybridEdgeStackJob;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);
usesFeatures('surface.cloud');

beforeEach(function (): void {
    Feature::define('surface.cloud', fn (): bool => true);
    Feature::flushCache();
});

test('creates hybrid edge site when cloud origin is live', function () {
    Queue::fake();
    config([
        'server_provision_fake.env_flag' => true,
        'edge.testing_domains' => ['on-dply.site'],
    ]);
    [$user, $org] = scaffold();

    $result = (new CreateHybridEdgeStack)->handle($user, $org, [
        'name' => 'SSR App',
        'repo' => 'acme/next-app',
        'branch' => 'main',
        'detected_plan' => [
            'framework' => 'next',
            'start_command' => 'next start',
        ],
    ]);

    $cloudSite = $result['cloud_site'];
    Queue::getFacadeRoot()->except([ProvisionCloudSiteJob::class, ProvisionHybridEdgeStackJob::class]);
    (new ProvisionCloudSiteJob($cloudSite->id))->handle();
    (new ProvisionHybridEdgeStackJob($cloudSite->id))->handle();

    $cloudSite->refresh();
    $stack = $cloudSite->meta['container']['hybrid_edge_stack'] ?? [];
    expect($stack['status'] ?? null)->toBe('complete');
    expect($stack['edge_site_id'] ?? null)->not->toBeEmpty();

    $edgeSite = Site::query()->find($stack['edge_site_id']);
    expect($edgeSite)->not->toBeNull();
    expect($edgeSite->meta['edge']['runtime_mode'] ?? null)->toBe('hybrid');
    expect($edgeSite->meta['edge']['origin']['cloud_site_id'] ?? null)->toBe((string) $cloudSite->id);
    expect($edgeSite->meta['edge']['origin']['managed'] ?? false)->toBeTrue();
});

test('is idempotent when edge site already linked', function () {
    Queue::fake();
    config([
        'server_provision_fake.env_flag' => true,
        'edge.testing_domains' => ['on-dply.site'],
    ]);
    [$user, $org] = scaffold();

    $result = (new CreateHybridEdgeStack)->handle($user, $org, [
        'name' => 'SSR App',
        'repo' => 'acme/next-app',
        'detected_plan' => [
            'framework' => 'next',
            'start_command' => 'next start',
        ],
    ]);

    $cloudSite = $result['cloud_site'];
    Queue::getFacadeRoot()->except([ProvisionCloudSiteJob::class, ProvisionHybridEdgeStackJob::class, BuildEdgeSiteJob::class]);
    (new ProvisionCloudSiteJob($cloudSite->id))->handle();
    (new ProvisionHybridEdgeStackJob($cloudSite->id))->handle();

    $edgeCount = Site::query()->whereNotNull('edge_backend')->count();
    (new ProvisionHybridEdgeStackJob($cloudSite->id))->handle();

    expect(Site::query()->whereNotNull('edge_backend')->count())->toBe($edgeCount);
});

test('marks stack failed when cloud provisioning fails', function () {
    config(['server_provision_fake.env_flag' => false]);
    [$user, $org] = scaffold();

    $cloudSite = Site::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'container_backend' => 'digitalocean_app_platform',
        'status' => Site::STATUS_CONTAINER_FAILED,
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/next-app', 'branch' => 'main'],
                'hybrid_edge_stack' => [
                    'status' => 'awaiting_origin',
                    'edge_name' => 'SSR App',
                    'edge_payload' => [
                        'name' => 'SSR App',
                        'repo' => 'acme/next-app',
                        'branch' => 'main',
                        'build_command' => 'npm run build',
                        'output_dir' => 'dist',
                    ],
                    'edge_site_id' => null,
                    'poll_attempts' => 0,
                ],
            ],
        ],
    ]);

    (new ProvisionHybridEdgeStackJob($cloudSite->id))->handle();

    $stack = $cloudSite->fresh()->meta['container']['hybrid_edge_stack'] ?? [];
    expect($stack['status'] ?? null)->toBe('failed');
    expect($stack['error'] ?? '')->not->toBe('');
});

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
