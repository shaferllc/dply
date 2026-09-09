<?php

namespace Tests\Feature\ProvisionJourneyRedirectTest;

use App\Livewire\Servers\ProvisionJourney;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function readyUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('a poll that observes a finished provision dispatches the redirect', function () {
    $user = readyUser();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'status' => Server::STATUS_PROVISIONING,
        'setup_status' => Server::SETUP_STATUS_PENDING,
    ]);

    $component = Livewire::actingAs($user)->test(ProvisionJourney::class, ['server' => $server]);

    // Mid-provision: no redirect yet.
    $component->assertNotDispatched('provision-journey-complete');

    // Provisioning finishes out-of-band, exactly as the queue would do it.
    $server->update([
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    // The next poll is the one that has to notice and forward. A real Livewire
    // redirect, not a custom event a hand-written JS listener has to honour.
    $component->call('pollProvisionJourney')
        ->assertRedirect(route('servers.overview', $server));
});

test('a server already ready on first load redirects too', function () {
    $user = readyUser();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    // Not a Livewire request: this arm has to use a real redirect, since there is
    // no client-side listener yet on a cold page load.
    Livewire::actingAs($user)
        ->test(ProvisionJourney::class, ['server' => $server])
        ->assertRedirect(route('servers.overview', $server));
});
