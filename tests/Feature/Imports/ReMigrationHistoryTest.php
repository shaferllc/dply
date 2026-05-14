<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Models\ForgeServer;
use App\Models\ImportServerMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Q18 says re-migration is allowed and creates a new run row; the prior
 * run stays as historical record. The inventory pages need to surface
 * that history so users can navigate back to it after a completed /
 * aborted / expired run, plus relabel the primary CTA "Migrate again".
 */
class ReMigrationHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_ploi_inventory_shows_last_migration_link_and_migrate_again_cta_after_completion(): void
    {
        Queue::fake();
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);
        $ploiServer = PloiServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'agency-prod',
            'ip_address' => '203.0.113.10',
            'provider_label' => 'digital-ocean',
            'server_type' => null,
            'php_versions' => ['8.3'],
            'status' => 'active',
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        $previous = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_COMPLETED,
            'completed_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($user)->get('/imports/ploi');

        $response->assertOk()
            ->assertSee('Migrate again')
            ->assertSee('Last migration')
            ->assertSee('Completed')
            ->assertSee(route('imports.ploi.migration.progress', $previous));
    }

    public function test_inventory_uses_most_recent_terminal_migration_when_multiple_exist(): void
    {
        Queue::fake();
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);
        $ploiServer = PloiServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'srv',
            'ip_address' => null,
            'provider_label' => null,
            'server_type' => null,
            'php_versions' => [],
            'status' => null,
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        // Two prior migrations: older completed, newer aborted.
        // created_at isn't fillable on the model, so set it explicitly after create
        // to give the helper distinct timestamps to sort by.
        $older = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_COMPLETED,
            'completed_at' => now()->subDays(10),
        ]);
        $older->created_at = now()->subDays(11);
        $older->save();
        $newer = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_ABORTED,
            'completed_at' => now()->subDays(2),
        ]);
        $newer->created_at = now()->subDays(3);
        $newer->save();

        // Verify the helper picks the most-recent terminal migration directly,
        // since HTML-level assertDontSee is too coarse (the rendered page may
        // contain unrelated substrings).
        $inventory = $this->app->make(\App\Livewire\Imports\Ploi\Inventory::class);
        $ref = new \ReflectionMethod($inventory, 'mostRecentTerminalMigrationsForServers');
        $ref->setAccessible(true);
        $servers = \App\Models\PloiServer::query()->get();
        $map = $ref->invoke($inventory, $servers);
        $this->assertSame($newer->id, $map[42]->id, 'Newer aborted migration wins despite older completed one');

        // And the page renders the newer URL + label.
        $response = $this->actingAs($user)->get('/imports/ploi');
        $response->assertOk()
            ->assertSee('Aborted')
            ->assertSee(route('imports.ploi.migration.progress', $newer));
    }

    public function test_inventory_omits_history_link_when_active_migration_present(): void
    {
        Queue::fake();
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);
        $ploiServer = PloiServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'srv',
            'ip_address' => null,
            'provider_label' => null,
            'server_type' => null,
            'php_versions' => [],
            'status' => null,
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_COMPLETED,
            'completed_at' => now()->subDays(3),
        ]);
        ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_STAGING,
            'started_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)->get('/imports/ploi');

        $response->assertOk()
            ->assertSee('View migration in progress')
            ->assertDontSee('Last migration')
            ->assertDontSee('Migrate again');
    }

    public function test_forge_inventory_shows_last_migration_link_too(): void
    {
        Queue::fake();
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'forge',
        ]);
        ForgeServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'agency-forge',
            'ip_address' => '203.0.113.10',
            'provider_label' => 'digitalocean',
            'server_type' => null,
            'php_versions' => ['8.3'],
            'status' => 'active',
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'forge',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_PARTIAL,
            'completed_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($user)->get('/imports/forge');

        $response->assertOk()
            ->assertSee('Migrate again')
            ->assertSee('Last migration')
            ->assertSee('Partial');
    }
}
