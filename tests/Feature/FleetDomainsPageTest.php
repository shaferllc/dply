<?php

declare(strict_types=1);

namespace Tests\Feature\FleetDomainsPageTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

test('lists all domains for current org', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'jobs']);
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);
    $site->domains()->create(['hostname' => 'alias.example.com', 'is_primary' => false]);

    $response = $this->actingAs($user)->get(route('fleet.domains'));

    $response->assertOk()
        ->assertSee('jobs.example.com')
        ->assertSee('alias.example.com')
        ->assertSee('jobs');
});
test('search narrows to matching hostname', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
    $site->domains()->create(['hostname' => 'jobs.example.com']);
    $site->domains()->create(['hostname' => 'careers.test.io']);

    $response = $this->actingAs($user)->get(route('fleet.domains').'?q=example');

    $response->assertOk()
        ->assertSee('jobs.example.com')
        ->assertDontSee('careers.test.io');
});
test('primary only filter', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
    $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true]);
    $site->domains()->create(['hostname' => 'alias.example.com', 'is_primary' => false]);

    $response = $this->actingAs($user)->get(route('fleet.domains').'?primary_only=1');

    $response->assertOk()
        ->assertSee('primary.example.com')
        ->assertDontSee('alias.example.com');
});
test('runtime filter', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $php = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'runtime' => 'php']);
    $node = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'runtime' => 'node']);
    $php->domains()->create(['hostname' => 'php.example.com']);
    $node->domains()->create(['hostname' => 'node.example.com']);

    $response = $this->actingAs($user)->get(route('fleet.domains').'?runtime=node');

    $response->assertOk()
        ->assertSee('node.example.com')
        ->assertDontSee('php.example.com');
});
test('does not show other org domains', function () {
    [$user, $org] = makeUserOrg();
    $otherOrg = Organization::factory()->create();
    $otherServer = Server::factory()->create(['organization_id' => $otherOrg->id]);
    $otherSite = Site::factory()->create(['server_id' => $otherServer->id, 'organization_id' => $otherOrg->id]);
    $otherSite->domains()->create(['hostname' => 'private.other.com']);

    $response = $this->actingAs($user)->get(route('fleet.domains'));

    $response->assertOk()
        ->assertDontSee('private.other.com');
});
/**
 * @return array{0: User, 1: Organization}
 */
function makeUserOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}
