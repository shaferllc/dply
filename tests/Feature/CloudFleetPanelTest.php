<?php

declare(strict_types=1);

namespace Tests\Feature\CloudFleetPanelTest;
use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

test('panel hidden when no cloud sites', function () {
    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertDontSee('cloud container site');
});
test('panel shows total count with link to index', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    makeContainerSite($user, $org, 'A', 'digitalocean_app_platform');
    makeContainerSite($user, $org, 'B', 'aws_app_runner');

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('2 cloud container sites')
        ->assertSee('Open /cloud')
        ->assertSee(route('cloud.index'), escape: false);
});
test('panel breakdown by backend', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    makeContainerSite($user, $org, 'A', 'digitalocean_app_platform');
    makeContainerSite($user, $org, 'B', 'digitalocean_app_platform');
    makeContainerSite($user, $org, 'C', 'aws_app_runner');

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertSee('DO App Platform')
        ->assertSee('AWS App Runner');
});
test('panel surfaces failed sites in red', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    makeContainerSite($user, $org, 'Healthy', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
    makeContainerSite($user, $org, 'Broken Container', 'digitalocean_app_platform', Site::STATUS_CONTAINER_FAILED);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertSee('1 cloud site failed')
        ->assertSee('Broken Container');
});
test('panel shows source mode and preview breakdown', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    // Two source-mode parents + one preview deploy.
    makeContainerSite($user, $org, 'src-1', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
        'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
        'container_image' => null,
    ]);
    $parent = makeContainerSite($user, $org, 'src-2', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
        'meta' => ['container' => ['source' => ['repo' => 'acme/web', 'branch' => 'main']]],
        'container_image' => null,
    ]);
    makeContainerSite($user, $org, 'preview-pr-9', 'digitalocean_app_platform', Site::STATUS_CONTAINER_PROVISIONING, [
        'container_image' => null,
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'feature/x'],
                'preview_parent_site_id' => $parent->id,
                'preview_branch' => 'feature/x',
            ],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('3 source-mode sites')
        ->assertSee('1 preview deploy');
});
test('panel replaces old fly upsell when cloud sites exist', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    // Have a Node site (would normally trigger old Fly upsell)
    // AND a cloud site — the cloud panel should win.
    $vmServer = Server::factory()->create(['organization_id' => $org->id]);
    Site::factory()->create([
        'server_id' => $vmServer->id,
        'organization_id' => $org->id,
        'runtime' => 'node',
    ]);
    makeContainerSite($user, $org, 'My Edge', 'digitalocean_app_platform');

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertSee('1 cloud container site')
        ->assertDontSee('Deploy a container app on dply cloud');
    // old upsell hidden
});
function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
/**
 * @param  array<string, mixed>  $overrides
 */
function makeContainerSite(User $user, Organization $org, string $name, string $backend, string $status = Site::STATUS_CONTAINER_PROVISIONING, array $overrides = []): Site
{
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create(array_merge([
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
    ], $overrides));
}
