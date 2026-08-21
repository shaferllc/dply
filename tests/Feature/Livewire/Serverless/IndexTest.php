<?php

namespace Tests\Feature\Livewire\Serverless\IndexTest;

use App\Modules\Serverless\Livewire\Index as ServerlessIndex;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.serverless');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $this->org->id]);
});

function makeFunction(User $user, Organization $org, string $name): Site
{
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => $name,
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ]);
}

/**
 * An empty, non-production org gets the standalone starter page rather than
 * the inventory shell ($showStarter in serverless-index-page). The narrow
 * "No apps yet" fallback below it only renders for the production surface or a
 * custom empty slot, so asserting that copy here tested the wrong branch.
 */
test('it shows the starter page with no apps', function () {
    Livewire::actingAs($this->user)
        ->test(ServerlessIndex::class)
        ->assertSee('Laravel first')
        ->assertSee('How it works')
        // The starter's two calls to action, as they read today.
        ->assertSee('Deploy from Git')
        ->assertSee('Deploy the demo');
});

test('it lists the organizations apps', function () {
    makeFunction($this->user, $this->org, 'Orders API');

    Livewire::actingAs($this->user)
        ->test(ServerlessIndex::class)
        ->assertSee('Orders API')
        // Starter-only copy: 'Laravel first' would not distinguish the two,
        // since the inventory shell's own description also uses it.
        ->assertDontSee('How it works');
});

test('it does not list another organizations apps', function () {
    makeFunction($this->user, Organization::factory()->create(), 'Someone Elses Function');

    Livewire::actingAs($this->user)
        ->test(ServerlessIndex::class)
        ->assertDontSee('Someone Elses Function');
});
