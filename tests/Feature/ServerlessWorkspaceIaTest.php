<?php

declare(strict_types=1);

namespace Tests\Feature\ServerlessWorkspaceIaTest;

use App\Livewire\Servers\Index as ServersIndex;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Serverless\ServerlessWorkspaceUrl;
use App\Support\Sites\SiteWorkspaceBreadcrumbs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.serverless');

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeServerlessFunctionSite(array $siteOverrides = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'functions-laravel-demo',
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Laravel demo',
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'last_deploy_at' => now()->subHour(),
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ], $siteOverrides));

    return [$user, $server, $site];
}

test('servers index excludes serverless function hosts', function () {
    [$user, $server, $site] = makeServerlessFunctionSite();

    $vm = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'real-vm-box',
        'status' => Server::STATUS_READY,
    ]);

    Livewire::actingAs($user)
        ->test(ServersIndex::class)
        ->assertSee('real-vm-box')
        ->assertDontSee('functions-laravel-demo')
        ->assertDontSee($site->name);
});

test('serverless breadcrumbs use serverless not servers path', function () {
    [$user, $server, $site] = makeServerlessFunctionSite();

    $labels = array_column(
        SiteWorkspaceBreadcrumbs::items($server, $site, __('Overview')),
        'label'
    );

    expect($labels)
        ->toContain(__('Serverless'))
        ->not->toContain(__('Servers'))
        ->not->toContain(__('Sites'))
        ->not->toContain($server->name);
});

test('byo sites.show redirects function sites to serverless product url', function () {
    [$user, $server, $site] = makeServerlessFunctionSite();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']))
        ->assertRedirect(ServerlessWorkspaceUrl::show($site));
});

test('serverless show renders function workspace under product url', function () {
    [$user, $server, $site] = makeServerlessFunctionSite();

    $this->actingAs($user)
        ->get(ServerlessWorkspaceUrl::show($site))
        ->assertOk()
        ->assertSee('Serverless', false)
        ->assertSee('Laravel demo', false)
        ->assertDontSee(__('Back to sites'), false);
});

test('legacy byo deploying url redirects to serverless journey', function () {
    [$user, $server, $site] = makeServerlessFunctionSite([
        'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
        'last_deploy_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('serverless.journey.legacy', ['server' => $server, 'site' => $site]))
        ->assertRedirect(ServerlessWorkspaceUrl::journey($site));
});
