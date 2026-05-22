<?php

declare(strict_types=1);

namespace Tests\Feature\FleetEnvSearchPageTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

test('landing state has no results until query', function () {
    [$user] = makeUserOrg();

    $response = $this->actingAs($user)->get(route('fleet.env-search'));

    $response->assertOk()
        ->assertSee('Fleet env search')
        ->assertSee('Enter a key', false);
});
test('finds exact key across sites', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'name' => 'alpha',
        'env_file_content' => "DATABASE_URL=postgres://a\nOTHER=noise",
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'name' => 'bravo',
        'env_file_content' => 'DATABASE_URL=postgres://b',
    ]);

    $response = $this->actingAs($user)->get(route('fleet.env-search').'?q=DATABASE_URL');

    $response->assertOk()
        ->assertSee('alpha')
        ->assertSee('bravo')
        ->assertSee('DATABASE_URL')
        ->assertDontSee('OTHER');
});
test('prefix mode', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'env_file_content' => "AWS_REGION=us-east-1\nAWS_BUCKET=data\nOTHER=x",
    ]);

    $response = $this->actingAs($user)->get(route('fleet.env-search').'?q=AWS_&mode=prefix');

    $response->assertOk()
        ->assertSee('AWS_REGION')
        ->assertSee('AWS_BUCKET')
        ->assertDontSee('OTHER');
});
test('values masked by default', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'env_file_content' => 'API_KEY=super-secret-token',
    ]);

    $response = $this->actingAs($user)->get(route('fleet.env-search').'?q=API_KEY');

    $response->assertOk()
        ->assertDontSee('super-secret-token');
});
test('no match message', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);

    $response = $this->actingAs($user)->get(route('fleet.env-search').'?q=NOPE');

    $response->assertOk()
        ->assertSee('No matches across the fleet');
});
test('only searches within current org', function () {
    [$user, $org] = makeUserOrg();
    $otherOrg = Organization::factory()->create();
    $otherServer = Server::factory()->create(['organization_id' => $otherOrg->id]);
    Site::factory()->create([
        'server_id' => $otherServer->id,
        'organization_id' => $otherOrg->id,
        'name' => 'sneaky',
        'env_file_content' => 'CROSS_ORG_KEY=leak',
    ]);

    $response = $this->actingAs($user)->get(route('fleet.env-search').'?q=CROSS_ORG_KEY');

    $response->assertOk()
        ->assertDontSee('sneaky')
        ->assertSee('No matches');
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
