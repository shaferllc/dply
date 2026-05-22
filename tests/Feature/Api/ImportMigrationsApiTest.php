<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportMigrationsApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: string}
     */
    protected function orgAndToken(array $abilities = ['imports.read']): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        ['plaintext' => $plain] = ApiToken::createToken($user, $org, 'imports-test', null, $abilities);

        return [$org, $user, $plain];
    }

    protected function seedMigration(Organization $org, User $user, string $status = 'staging'): ImportServerMigration
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

    public function test_index_returns_migrations_for_token_org_only(): void
    {
        [$orgA, $userA, $plainA] = $this->orgAndToken();
        $migration = $this->seedMigration($orgA, $userA);

        // Another org with its own migration — should not appear.
        $orgB = Organization::factory()->create();
        $userB = User::factory()->create();
        $orgB->users()->attach($userB->id, ['role' => 'owner']);
        $this->seedMigration($orgB, $userB);

        $res = $this->getJson('/api/v1/imports/migrations', [
            'Authorization' => 'Bearer '.$plainA,
        ]);
        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($migration->id, $data[0]['id']);
        $this->assertSame('ploi', $data[0]['source']);
        $this->assertSame(42, $data[0]['source_server_id']);
        $this->assertSame(1, $data[0]['step_counts']['succeeded']);
        $this->assertSame(1, $data[0]['step_counts']['failed']);
    }

    public function test_index_supports_active_filter(): void
    {
        [$org, $user, $plain] = $this->orgAndToken();
        $this->seedMigration($org, $user, status: 'completed');
        $active = $this->seedMigration($org, $user, status: 'staging');

        $res = $this->getJson('/api/v1/imports/migrations?active=1', [
            'Authorization' => 'Bearer '.$plain,
        ]);
        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($active->id, $data[0]['id']);
    }

    public function test_show_returns_full_step_plan_for_owned_migration(): void
    {
        [$org, $user, $plain] = $this->orgAndToken();
        $migration = $this->seedMigration($org, $user);

        $res = $this->getJson('/api/v1/imports/migrations/'.$migration->id, [
            'Authorization' => 'Bearer '.$plain,
        ]);
        $res->assertOk()
            ->assertJsonPath('data.id', $migration->id)
            ->assertJsonPath('data.steps.0.step_key', 'push_ssh_key')
            ->assertJsonPath('data.sites.0.domain', 'app.example.com')
            ->assertJsonPath('data.sites.0.steps.0.step_key', 'clone_repo')
            ->assertJsonPath('data.sites.0.steps.0.error_message', 'git clone failed');
    }

    public function test_show_returns_403_for_other_org(): void
    {
        [, , $plainA] = $this->orgAndToken();
        $orgB = Organization::factory()->create();
        $userB = User::factory()->create();
        $orgB->users()->attach($userB->id, ['role' => 'owner']);
        $migrationB = $this->seedMigration($orgB, $userB);

        $this->getJson('/api/v1/imports/migrations/'.$migrationB->id, [
            'Authorization' => 'Bearer '.$plainA,
        ])->assertStatus(403);
    }

    public function test_token_without_imports_ability_is_forbidden(): void
    {
        [$org, $user, $plain] = $this->orgAndToken(abilities: ['servers.read']);

        $res = $this->getJson('/api/v1/imports/migrations', [
            'Authorization' => 'Bearer '.$plain,
        ]);
        $res->assertStatus(403);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/imports/migrations')->assertStatus(401);
    }
}
