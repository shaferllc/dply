<?php

namespace Tests\Feature\Livewire\Serverless\IndexTest;

use App\Livewire\Serverless\Index as ServerlessIndex;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'owner']);
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

test('it shows the empty state with no functions', function () {
    Livewire::actingAs($this->user)
        ->test(ServerlessIndex::class)
        ->assertSee('No functions yet');
});

test('it lists the organizations functions', function () {
    makeFunction($this->user, $this->org, 'Orders API');

    Livewire::actingAs($this->user)
        ->test(ServerlessIndex::class)
        ->assertSee('Orders API')
        ->assertDontSee('No functions yet');
});

test('it does not list another organizations functions', function () {
    makeFunction($this->user, Organization::factory()->create(), 'Someone Elses Function');

    Livewire::actingAs($this->user)
        ->test(ServerlessIndex::class)
        ->assertDontSee('Someone Elses Function');
});
