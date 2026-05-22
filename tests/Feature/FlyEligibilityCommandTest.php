<?php

declare(strict_types=1);

namespace Tests\Feature\FlyEligibilityCommandTest;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('lists node and static sites excluding php', function () {
    [$user, $orgA, $orgB] = makeTwoOrgs();
    $serverA = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $orgA->id]);
    $serverB = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $orgB->id]);

    Site::factory()->create([
        'name' => 'Marketing site',
        'server_id' => $serverA->id,
        'user_id' => $user->id,
        'organization_id' => $orgA->id,
        'runtime' => 'node',
    ]);
    Site::factory()->create([
        'name' => 'Docs landing',
        'server_id' => $serverB->id,
        'user_id' => $user->id,
        'organization_id' => $orgB->id,
        'runtime' => 'static',
    ]);
    Site::factory()->create([
        'name' => 'Laravel admin',
        'server_id' => $serverA->id,
        'user_id' => $user->id,
        'organization_id' => $orgA->id,
        'runtime' => 'php',
    ]);

    $payload = runJsonCommand([]);

    expect($payload['total_eligible'])->toBe(2);
    $names = array_column($payload['sites'], 'site');
    expect($names)->toContain('Marketing site');
    expect($names)->toContain('Docs landing');
    expect($names)->not->toContain('Laravel admin');
});
test('json payload shape', function () {
    [$user, $orgA, $orgB] = makeTwoOrgs();
    $serverA = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $orgA->id]);

    Site::factory()->create([
        'name' => 'NodeApp',
        'server_id' => $serverA->id,
        'user_id' => $user->id,
        'organization_id' => $orgA->id,
        'runtime' => 'node',
    ]);
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $orgB->id,
        'provider' => 'fly_io',
        'name' => 'Fly',
        'credentials' => ['api_token' => 't'],
    ]);

    $payload = runJsonCommand([]);

    expect($payload['total_eligible'])->toBe(1);
    expect($payload['orgs_connected_to_fly'])->toBe(1);
    expect($payload['sites'][0]['site'])->toBe('NodeApp');
    expect($payload['sites'][0]['org_has_fly'])->toBeFalse();
});
test('connected only filters to orgs with fly', function () {
    [$user, $orgA, $orgB] = makeTwoOrgs();
    $serverA = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $orgA->id]);
    $serverB = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $orgB->id]);

    Site::factory()->create([
        'name' => 'Disconnected node',
        'server_id' => $serverA->id,
        'user_id' => $user->id,
        'organization_id' => $orgA->id,
        'runtime' => 'node',
    ]);
    Site::factory()->create([
        'name' => 'Connected static',
        'server_id' => $serverB->id,
        'user_id' => $user->id,
        'organization_id' => $orgB->id,
        'runtime' => 'static',
    ]);
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $orgB->id,
        'provider' => 'fly_io',
        'name' => 'Fly',
        'credentials' => ['api_token' => 't'],
    ]);

    $payload = runJsonCommand(['--connected-only' => true]);

    expect($payload['total_eligible'])->toBe(1);
    $names = array_column($payload['sites'], 'site');
    expect($names)->toBe(['Connected static']);
});
test('no eligible sites message', function () {
    $this->artisan('dply:fly:eligibility')
        ->expectsOutputToContain('No eligible sites found')
        ->assertExitCode(0);
});
/**
 * @param  array<string, mixed>  $extraOptions
 * @return array<string, mixed>
 */
function runJsonCommand(array $extraOptions): array
{
    $exit = Artisan::call(
        'dply:fly:eligibility',
        array_merge(['--json' => true], $extraOptions),
    );
    expect($exit)->toBe(0);

    $output = Artisan::output();
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray('Command JSON should decode: '.$output);

    return $decoded;
}
/**
 * @return array{0: User, 1: Organization, 2: Organization}
 */
function makeTwoOrgs(): array
{
    $user = User::factory()->create();
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $a->users()->attach($user->id, ['role' => 'owner']);
    $b->users()->attach($user->id, ['role' => 'owner']);

    return [$user, $a, $b];
}
