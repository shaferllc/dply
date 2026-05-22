<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\ReMigrationHistoryTest;

use App\Livewire\Imports\Ploi\Inventory;
use App\Models\ForgeServer;
use App\Models\ImportServerMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
test('ploi inventory shows last migration link and migrate again cta after completion', function () {
    Queue::fake();
    $user = userWithOrganization();
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
});
test('inventory uses most recent terminal migration when multiple exist', function () {
    Queue::fake();
    $user = userWithOrganization();
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
    $inventory = $this->app->make(Inventory::class);
    $ref = new \ReflectionMethod($inventory, 'mostRecentTerminalMigrationsForServers');
    $ref->setAccessible(true);
    $servers = PloiServer::query()->get();
    $map = $ref->invoke($inventory, $servers);
    expect($map[42]->id)->toBe($newer->id, 'Newer aborted migration wins despite older completed one');

    // And the page renders the newer URL + label.
    $response = $this->actingAs($user)->get('/imports/ploi');
    $response->assertOk()
        ->assertSee('Aborted')
        ->assertSee(route('imports.ploi.migration.progress', $newer));
});
test('inventory omits history link when active migration present', function () {
    Queue::fake();
    $user = userWithOrganization();
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
});
test('forge inventory shows last migration link too', function () {
    Queue::fake();
    $user = userWithOrganization();
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
});
