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
function envFixture(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $organization->id]);
    session(['current_organization_id' => $organization->id]);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site];
}

it('keeps the site env commands on a VM site', function () {
    [$user, $server, $site] = envFixture();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->assertSee("dply site env {$site->slug} list")
        ->assertDontSee('sites:env');
});
