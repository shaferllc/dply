<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDeploymentsDashboardTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['server_provision_fake.env_flag' => true]);
});
test('button renders on dashboard', function () {
    [$user, $server, $site] = scaffoldSite();

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Recent deployments')
        ->assertSee('Fetch deployments');
});
test('fetch populates synthetic entries via fake backend', function () {
    [$user, $server, $site] = scaffoldSite();

    $component = Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSet('container_deployments_result', null)
        ->call('fetchContainerDeployments');

    $set = $component->get('container_deployments_result');
    expect($set)->toBeArray();
    expect(count($set))->toBeGreaterThanOrEqual(1);
    expect((string) $set[0]['phase'])->toBe('ACTIVE');
});
/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function scaffoldSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'API service',
        'slug' => 'api-service',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'container_backend_id' => null,
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);

    return [$user, $server, $site];
}
