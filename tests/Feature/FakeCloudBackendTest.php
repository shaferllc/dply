<?php

declare(strict_types=1);

namespace Tests\Feature\FakeCloudBackendTest;

use App\Enums\SiteType;
use App\Jobs\ProvisionCloudSiteJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloud\CloudRouter;
use App\Services\Cloud\DigitalOceanAppPlatformBackend;
use App\Services\Cloud\FakeCloudBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['server_provision_fake.env_flag' => true]);
});
test('router returns fake backend when no real credential', function () {
    [$user, $org, $server] = scaffold();
    $site = makeContainerSite($user, $org, $server, 'digitalocean_app_platform');

    $backend = CloudRouter::backendFor($site);

    expect($backend)->toBeInstanceOf(FakeCloudBackend::class);
});
test('router returns real backend when credential exists', function () {
    [$user, $org, $server] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    $site = makeContainerSite($user, $org, $server, 'digitalocean_app_platform');

    $backend = CloudRouter::backendFor($site);

    expect($backend)->toBeInstanceOf(DigitalOceanAppPlatformBackend::class);
});
test('aws routes to fake backend when no real credential', function () {
    [$user, $org, $server] = scaffold();
    $site = makeContainerSite($user, $org, $server, 'aws_app_runner');

    $backend = CloudRouter::backendFor($site);

    expect($backend)->toBeInstanceOf(FakeCloudBackend::class);
});
test('credential for synthesizes placeholder in fake mode', function () {
    [$user, $org, $server] = scaffold();
    $site = makeContainerSite($user, $org, $server, 'digitalocean_app_platform');

    $credential = CloudRouter::credentialFor($site);

    expect($credential)->not->toBeNull();
    expect($credential->organization_id)->toBe($org->id);
    expect($credential->provider)->toBe('digitalocean');

    // Placeholder is not persisted — id will be null/empty.
    expect($credential->id)->toBeNull();
});
test('pick auto backend returns default in fake mode', function () {
    $org = Organization::factory()->create();

    expect(CloudRouter::pickAutoBackend($org->id))->toBe('digitalocean_app_platform');
});
test('provision job brings site active via fake backend', function () {
    [$user, $org, $server] = scaffold();
    $site = makeContainerSite($user, $org, $server, 'digitalocean_app_platform', 'fake-app');

    (new ProvisionCloudSiteJob($site->id))->handle();

    $fresh = $site->fresh();
    expect($fresh->status)->toBe(Site::STATUS_CONTAINER_ACTIVE);
    expect($fresh->container_backend_id)->not->toBeEmpty();
    expect((string) $fresh->container_backend_id)->toStartWith('fake-app-');
    $this->assertStringContainsString('.fake-edge.dply.local', (string) $fresh->meta['container']['live_url']);
});
/**
 * @return array{0: User, 1: Organization, 2: Server}
 */
function scaffold(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return [$user, $org, $server];
}
function makeContainerSite(User $user, Organization $org, Server $server, string $backend, ?string $name = null): Site
{
    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => $name ?? 'fake-app',
        'slug' => $name ?? 'fake-app',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => $backend,
        'container_region' => 'nyc',
        'status' => Site::STATUS_PENDING,
    ]);
}
