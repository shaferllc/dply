<?php

namespace Tests\Feature\SiteCronDaemonsRoutesTest;

use App\Livewire\Servers\WorkspaceCron;
use App\Livewire\Servers\WorkspaceDaemons;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingOrgUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function readyServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);
}

test('site cron route renders and sets site context', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    $this->actingAs($user)
        ->get(route('sites.cron', [$server, $site]))
        ->assertOk()
        ->assertSee($site->name, false);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server, 'site' => $site])
        ->assertSet('context_site_id', $site->id)
        ->assertSet('cron_list_scope', 'site')
        ->assertSet('new_site_id', $site->id);
});

test('site daemons route renders and sets site context', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    $this->actingAs($user)
        ->get(route('sites.daemons', [$server, $site]))
        ->assertOk()
        ->assertSee($site->name, false);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server, 'site' => $site])
        ->assertSet('context_site_id', $site->id)
        ->assertSet('programs_list_scope', 'site')
        ->assertSet('new_sv_site_id', $site->id);
});
