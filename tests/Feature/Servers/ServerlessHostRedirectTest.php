<?php

namespace Tests\Feature\Servers\ServerlessHostRedirectTest;

use App\Livewire\Servers\WorkspaceOverview;
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
 * @param  array<string, mixed>  $serverOverrides
 * @param  array<string, mixed>|null  $siteOverrides  null = leftover host with no function
 * @return array{0: User, 1: Server, 2: Site|null}
 */
function makeFunctionsHost(array $serverOverrides = [], ?array $siteOverrides = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create(array_merge([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'functions-cachet',
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ], $serverOverrides));

    if ($siteOverrides === null) {
        return [$user, $server, null];
    }

    $function = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'cachet',
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'last_deploy_at' => now()->subHour(),
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ], $siteOverrides));

    return [$user, $server, $function];
}

test('serverless host overview redirects to the function workspace', function () {
    [$user, $server, $function] = makeFunctionsHost();

    // The serverless namespace host is an implementation detail — the
    // server overview must bounce to the function workspace.
    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertRedirect(ServerlessWorkspaceUrl::show($function));
});

test('never-live serverless host overview redirects to the deploy journey', function () {
    [$user, $server, $function] = makeFunctionsHost([], [
        'status' => Site::STATUS_FUNCTIONS_FAILED,
        'last_deploy_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertRedirect(ServerlessWorkspaceUrl::journey($function));
});

test('leftover functions host overview redirects to the serverless index without looping', function () {
    [$user, $server] = makeFunctionsHost([], null);

    expect($server->sites()->exists())->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertRedirect(route('serverless.index'));

    // Following the hop must not bounce back to Servers overview.
    $this->actingAs($user)
        ->get(route('serverless.index'))
        ->assertOk()
        ->assertSee('Serverless', false)
        ->assertDontSee('functions-cachet', false);
});

test('leftover functions host show and workspace leaves redirect to the serverless index', function () {
    [$user, $server] = makeFunctionsHost([], null);

    $this->actingAs($user)
        ->get(route('servers.show', $server))
        ->assertRedirect(route('serverless.index'));

    $this->actingAs($user)
        ->get(route('servers.sites', $server))
        ->assertRedirect(route('serverless.index'));
});

test('leftover functions host overview Livewire mount does not bounce to servers show', function () {
    [$user, $server] = makeFunctionsHost([], null);

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->assertRedirect(route('serverless.index'));
});

test('functions host overview uses serverless IA not servers chrome', function () {
    [$user, $server, $function] = makeFunctionsHost();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertRedirect(ServerlessWorkspaceUrl::show($function));

    $this->actingAs($user)
        ->followingRedirects()
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee('Serverless', false)
        ->assertSee('cachet', false)
        ->assertDontSee('functions-cachet', false);

    $labels = array_column(
        SiteWorkspaceBreadcrumbs::items($server, $function, __('Overview')),
        'label'
    );

    expect($labels)
        ->toContain(__('Serverless'))
        ->not->toContain(__('Servers'))
        ->not->toContain($server->name);
});
