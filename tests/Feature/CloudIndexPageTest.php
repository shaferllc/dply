<?php

declare(strict_types=1);

namespace Tests\Feature\CloudIndexPageTest;
use App\Enums\SiteType;
use App\Livewire\Cloud\Index as CloudIndex;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

test('empty state with warning when no credentials', function () {
    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('cloud.index'));

    $response->assertOk()
        ->assertSee('Cloud sites')
        ->assertSee('No container backend connected')
        ->assertSee('No cloud sites found');
});
test('lists only container sites for current org', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $otherOrg = Organization::factory()->create();

    makeContainerSite($user, $org, 'My Container', 'digitalocean_app_platform');
    makeContainerSite($user, $otherOrg, 'Other Org Container', 'digitalocean_app_platform');
    makeVmSite($user, $org, 'PHP Site');

    $response = $this->actingAs($user)->get(route('cloud.index'));

    $response->assertOk()
        ->assertSee('My Container')
        ->assertDontSee('Other Org Container')
        ->assertDontSee('PHP Site');
});
test('filter by backend', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    makeContainerSite($user, $org, 'DO Site', 'digitalocean_app_platform');
    makeContainerSite($user, $org, 'AWS Site', 'aws_app_runner');

    Livewire::actingAs($user)
        ->test(CloudIndex::class)
        ->set('filter', 'aws_app_runner')
        ->assertSee('AWS Site')
        ->assertDontSee('DO Site');
});
test('filter by failed status', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    makeContainerSite($user, $org, 'Healthy', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
    makeContainerSite($user, $org, 'Broken', 'digitalocean_app_platform', Site::STATUS_CONTAINER_FAILED);

    Livewire::actingAs($user)
        ->test(CloudIndex::class)
        ->set('filter', 'failed')
        ->assertSee('Broken')
        ->assertDontSee('Healthy');
});
test('status pill renders for active site with live url', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $site = makeContainerSite($user, $org, 'Live App', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
    $site->update(['meta' => ['container' => ['live_url' => 'https://live-app.ondigitalocean.app']]]);

    $response = $this->actingAs($user)->get(route('cloud.index'));

    $response->assertSee('Active')
        ->assertSee('live-app.ondigitalocean.app');
});
test('warning hides when a backend credential is connected', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    $response = $this->actingAs($user)->get(route('cloud.index'));

    $response->assertDontSee('No container backend connected');
});
test('lists source mode site with repo branch', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Source Site',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
    ]);

    $response = $this->actingAs($user)->get(route('cloud.index'));

    $response->assertOk()
        ->assertSee('Source Site')
        ->assertSee('acme/api@main')
        ->assertSee('Image / source');
});
test('lists preview with pr badge', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'PR #42 — API',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/api', 'branch' => 'feature/x'],
                'preview_branch' => 'feature/x',
                'preview_pr_number' => 42,
            ],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('cloud.index'));

    $response->assertOk()
        ->assertSee('PR #42');
});
test('filter source only shows source sites', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    makeContainerSite($user, $org, 'image-only', 'digitalocean_app_platform');

    $sourceServer = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    Site::factory()->create([
        'server_id' => $sourceServer->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'source-only',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
    ]);

    Livewire::actingAs($user)
        ->test(CloudIndex::class)
        ->set('filter', 'source')
        ->assertSee('source-only')
        ->assertDontSee('image-only');
});
test('filter previews only shows preview sites', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    $parent = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'parent-prod',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'pr-preview',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/api', 'branch' => 'feature/x'],
                'preview_parent_site_id' => $parent->id,
                'preview_branch' => 'feature/x',
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(CloudIndex::class)
        ->set('filter', 'previews')
        ->assertSee('pr-preview')
        ->assertDontSee('parent-prod');
});
test('filter counts match actual sites', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    makeContainerSite($user, $org, 'A', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
    makeContainerSite($user, $org, 'B', 'digitalocean_app_platform', Site::STATUS_CONTAINER_PROVISIONING);
    makeContainerSite($user, $org, 'C', 'aws_app_runner', Site::STATUS_CONTAINER_FAILED);

    Livewire::actingAs($user)
        ->test(CloudIndex::class)
        ->assertViewHas('totals', fn ($t): bool => $t['all'] === 3
            && $t['digitalocean_app_platform'] === 2
            && $t['aws_app_runner'] === 1
            && $t['failed'] === 1
            && $t['provisioning'] === 1);
});
function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
function makeContainerSite(User $user, Organization $org, string $name, string $backend, string $status = Site::STATUS_CONTAINER_PROVISIONING): Site
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
        'name' => $name,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => $backend,
        'container_region' => 'nyc',
        'status' => $status,
    ]);
}
function makeVmSite(User $user, Organization $org, string $name): Site
{
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => $name,
        'type' => SiteType::Php,
    ]);
}
