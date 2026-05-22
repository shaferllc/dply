<?php

declare(strict_types=1);

namespace Tests\Feature\Api\ImportMigrationsApiTest;
use App\Models\ApiToken;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: User, 2: string}
 */
function orgAndToken(array $abilities = ['imports.read']): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ['plaintext' => $plain] = ApiToken::createToken($user, $org, 'imports-test', null, $abilities);

    return [$org, $user, $plain];
}
function seedMigration(Organization $org, User $user, string $status = 'staging'): ImportServerMigration
{
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
    ]);
    $target = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);
    $migration = ImportServerMigration::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider_credential_id' => $credential->id,
        'source' => 'ploi',
        'source_server_id' => 42,
        'target_server_id' => $target->id,
        'status' => $status,
        'started_at' => now()->subHour(),
    ]);
    $site = ImportSiteMigration::create([
        'import_server_migration_id' => $migration->id,
        'source' => 'ploi',
        'source_site_id' => 100,
        'domain' => 'app.example.com',
        'site_type' => 'laravel',
        'status' => ImportSiteMigration::STATUS_STAGING,
        'source_snapshot' => [],
    ]);
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => ImportMigrationStep::KEY_PUSH_SSH_KEY,
        'status' => ImportMigrationStep::STATUS_SUCCEEDED,
        'finished_at' => now()->subMinutes(45),
    ]);
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $site->id,
        'sequence' => 5,
        'step_key' => ImportMigrationStep::KEY_CLONE_REPO,
        'status' => ImportMigrationStep::STATUS_FAILED,
        'error_message' => 'git clone failed',
        'finished_at' => now()->subMinutes(15),
    ]);

    return $migration;
}
test('index returns migrations for token org only', function () {
    [$orgA, $userA, $plainA] = orgAndToken();
    $migration = seedMigration($orgA, $userA);

    // Another org with its own migration — should not appear.
    $orgB = Organization::factory()->create();
    $userB = User::factory()->create();
    $orgB->users()->attach($userB->id, ['role' => 'owner']);
    seedMigration($orgB, $userB);

    $res = $this->getJson('/api/v1/imports/migrations', [
        'Authorization' => 'Bearer '.$plainA,
    ]);
    $res->assertOk();
    $data = $res->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($migration->id);
    expect($data[0]['source'])->toBe('ploi');
    expect($data[0]['source_server_id'])->toBe(42);
    expect($data[0]['step_counts']['succeeded'])->toBe(1);
    expect($data[0]['step_counts']['failed'])->toBe(1);
});
test('index supports active filter', function () {
    [$org, $user, $plain] = orgAndToken();
    seedMigration($org, $user, status: 'completed');
    $active = seedMigration($org, $user, status: 'staging');

    $res = $this->getJson('/api/v1/imports/migrations?active=1', [
        'Authorization' => 'Bearer '.$plain,
    ]);
    $res->assertOk();
    $data = $res->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($active->id);
});
test('show returns full step plan for owned migration', function () {
    [$org, $user, $plain] = orgAndToken();
    $migration = seedMigration($org, $user);

    $res = $this->getJson('/api/v1/imports/migrations/'.$migration->id, [
        'Authorization' => 'Bearer '.$plain,
    ]);
    $res->assertOk()
        ->assertJsonPath('data.id', $migration->id)
        ->assertJsonPath('data.steps.0.step_key', 'push_ssh_key')
        ->assertJsonPath('data.sites.0.domain', 'app.example.com')
        ->assertJsonPath('data.sites.0.steps.0.step_key', 'clone_repo')
        ->assertJsonPath('data.sites.0.steps.0.error_message', 'git clone failed');
});
test('show returns 403 for other org', function () {
    [, , $plainA] = orgAndToken();
    $orgB = Organization::factory()->create();
    $userB = User::factory()->create();
    $orgB->users()->attach($userB->id, ['role' => 'owner']);
    $migrationB = seedMigration($orgB, $userB);

    $this->getJson('/api/v1/imports/migrations/'.$migrationB->id, [
        'Authorization' => 'Bearer '.$plainA,
    ])->assertStatus(403);
});
test('token without imports ability is forbidden', function () {
    [$org, $user, $plain] = orgAndToken(abilities: ['servers.read']);

    $res = $this->getJson('/api/v1/imports/migrations', [
        'Authorization' => 'Bearer '.$plain,
    ]);
    $res->assertStatus(403);
});
test('unauthenticated request returns 401', function () {
    $this->getJson('/api/v1/imports/migrations')->assertStatus(401);
});
