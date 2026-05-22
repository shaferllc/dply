<?php

declare(strict_types=1);

namespace Tests\Feature\CloudLogsDashboardTest;

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
    // Stay in fake-cloud so the backend is FakeCloudBackend (no
    // HTTP fakes needed); test asserts on the inline content path.
    config(['server_provision_fake.env_flag' => true]);
});
test('fetch logs populates inline content via fake backend', function () {
    [$user, $server, $site] = scaffoldSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSet('container_logs_result', null)
        ->call('fetchContainerLogs')
        ->assertHasNoErrors();

    // After the call, the property holds an array with a content key.
    $component = Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('fetchContainerLogs');
    $set = $component->get('container_logs_result');
    expect($set)->toBeArray();
    $this->assertStringContainsString('fake-edge backend', (string) $set['content']);
});
test('button is present on dashboard', function () {
    [$user, $server, $site] = scaffoldSite();

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Fetch logs')
        ->assertSee('Latest deployment logs');
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
