<?php

namespace Tests\Feature\Livewire\Sites\EnvironmentCliFooterTest;

use App\Livewire\Sites\SiteEnvironment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function envFixture(bool $functions): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $organization->id]);
    session(['current_organization_id' => $organization->id]);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'meta' => $functions ? ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS] : [],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => $functions ? Site::STATUS_FUNCTIONS_ACTIVE : Site::STATUS_NGINX_ACTIVE,
        'meta' => $functions ? ['runtime_profile' => 'digitalocean_functions_web'] : [],
    ]);

    return [$user, $server, $site];
}

it('offers the serverless env commands on a function site', function () {
    [$user, $server, $site] = envFixture(functions: true);

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->assertSee("dply serverless env {$site->slug} list")
        ->assertSee("dply serverless env {$site->slug} push --file .env")
        // The old footer advertised four `dply sites:env:*` commands that the
        // CLI has never had.
        ->assertDontSee('sites:env');
});

it('keeps the site env commands on a VM site', function () {
    [$user, $server, $site] = envFixture(functions: false);

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->assertSee("dply site env {$site->slug} list")
        ->assertDontSee('sites:env');
});
