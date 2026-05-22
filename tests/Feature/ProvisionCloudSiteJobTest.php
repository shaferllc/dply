<?php

declare(strict_types=1);

namespace Tests\Feature\ProvisionCloudSiteJobTest;
use App\Enums\SiteType;
use App\Jobs\ProvisionCloudSiteJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('provisions via do app platform and persists backend id', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => [
                'id' => 'do-app-12345',
                'default_ingress' => 'https://acme-api.ondigitalocean.app',
            ],
        ], 201),
    ]);

    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    $site = makeContainerSite($user, $org, 'digitalocean_app_platform', 'nyc');

    (new ProvisionCloudSiteJob($site->id))->handle();

    $fresh = $site->fresh();
    expect($fresh->container_backend_id)->toBe('do-app-12345');
    expect($fresh->status)->toBe(Site::STATUS_CONTAINER_ACTIVE);
    expect($fresh->meta['container']['live_url'])->toBe('https://acme-api.ondigitalocean.app');
});
test('marks failed on backend error', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response(['message' => 'invalid spec'], 422),
    ]);

    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    $site = makeContainerSite($user, $org, 'digitalocean_app_platform', 'nyc');

    try {
        (new ProvisionCloudSiteJob($site->id))->handle();
        $this->fail('Expected exception');
    } catch (\Throwable) {
        // expected — job rethrows so the queue can retry
    }

    expect($site->fresh()->status)->toBe(Site::STATUS_CONTAINER_FAILED);
    expect($site->fresh()->meta['container']['last_error'])->not->toBeEmpty();
});
test('no credential marks failed without throwing', function () {
    config(['server_provision_fake.env_flag' => false]);
    [$user, $org] = scaffold();
    $site = makeContainerSite($user, $org, 'digitalocean_app_platform', 'nyc');

    (new ProvisionCloudSiteJob($site->id))->handle();

    expect($site->fresh()->status)->toBe(Site::STATUS_CONTAINER_FAILED);
});
test('missing site is no op', function () {
    (new ProvisionCloudSiteJob('01nope0000000000000000nope'))->handle();
    expect(true)->toBeTrue();
    // no exception
});
test('source mode calls create app from source', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => [
                'id' => 'do-app-src-1',
                'default_ingress' => 'https://src.ondigitalocean.app',
            ],
        ], 201),
    ]);

    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_PENDING,
        'meta' => [
            'container' => [
                'source' => [
                    'repo' => 'acme/api',
                    'branch' => 'main',
                    'deploy_on_push' => true,
                ],
            ],
        ],
    ]);

    (new ProvisionCloudSiteJob($site->id))->handle();

    $fresh = $site->fresh();
    expect($fresh->container_backend_id)->toBe('do-app-src-1');
    expect($fresh->status)->toBe(Site::STATUS_CONTAINER_ACTIVE);

    Http::assertSent(function ($req) {
        $svc = $req->data()['spec']['services'][0] ?? [];

        return ($svc['github']['repo'] ?? null) === 'acme/api'
            && ($svc['github']['branch'] ?? null) === 'main'
            && ($svc['github']['deploy_on_push'] ?? null) === true;
    });
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
function makeContainerSite(User $user, Organization $org, string $backend, string $region): Site
{
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1.27',
        'container_port' => 80,
        'container_backend' => $backend,
        'container_region' => $region,
        'status' => Site::STATUS_PENDING,
    ]);
}
