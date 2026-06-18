<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\PloiInventoryPageTest;

use App\Modules\Imports\Jobs\SyncPloiInventoryJob;
use App\Models\ImportServerMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\PloiSite;
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
test('guest is redirected to login', function () {
    $this->get('/imports/ploi')->assertRedirect(route('login', absolute: false));
});
test('page shows empty state when no credential connected', function () {
    $user = userWithOrganization();

    $this->actingAs($user)->get('/imports/ploi')
        ->assertOk()
        ->assertSee('Connect Ploi to see your inventory');
});
test('page lists servers and sites', function () {
    Queue::fake();
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_xxx'],
    ]);

    $server = PloiServer::create([
        'provider_credential_id' => $credential->id,
        'source_id' => 42,
        'name' => 'prod-web-01',
        'ip_address' => '203.0.113.10',
        'provider_label' => 'digital-ocean',
        'server_type' => 's-2vcpu-4gb',
        'php_versions' => ['8.3'],
        'status' => 'active',
        'last_synced_at' => now(),
        'removed_from_source' => false,
        'source_snapshot' => null,
    ]);

    PloiSite::create([
        'ploi_server_id' => $server->id,
        'source_id' => 100,
        'domain' => 'app.example.com',
        'site_type' => 'laravel',
        'php_version' => '8.3',
        'repository_url' => 'git@github.com:acme/app.git',
        'repository_branch' => 'main',
        'web_directory' => '/public',
        'status' => 'installed',
        'removed_from_source' => false,
        'source_snapshot' => null,
    ]);
    PloiSite::create([
        'ploi_server_id' => $server->id,
        'source_id' => 101,
        'domain' => 'wp.example.com',
        'site_type' => 'wordpress',
        'php_version' => '8.3',
        'repository_url' => null,
        'repository_branch' => null,
        'web_directory' => null,
        'status' => 'installed',
        'removed_from_source' => false,
        'source_snapshot' => null,
    ]);

    $response = $this->actingAs($user)->get('/imports/ploi');

    $response->assertOk()
        ->assertSee('prod-web-01')
        ->assertSee('203.0.113.10')
        ->assertSee('app.example.com')
        ->assertSee('wp.example.com')
        ->assertSee('Eligible')
        ->assertSee('Unsupported in v1')
        ->assertSee('Migrate this server');
});
test('lazy sync dispatches when no synced at is stale', function () {
    Queue::fake();
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_xxx'],
    ]);

    // No PloiServer rows yet → last_synced_at is null → stale.
    $this->actingAs($user)->get('/imports/ploi')->assertOk();

    Queue::assertPushed(SyncPloiInventoryJob::class, function (SyncPloiInventoryJob $job) use ($credential): bool {
        return $job->providerCredentialId === $credential->id && $job->onlySourceServerId === null;
    });
});
test('lazy sync skips when recently synced', function () {
    Queue::fake();
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_xxx'],
    ]);
    PloiServer::create([
        'provider_credential_id' => $credential->id,
        'source_id' => 42,
        'name' => 'fresh',
        'ip_address' => null,
        'provider_label' => null,
        'server_type' => null,
        'php_versions' => [],
        'status' => null,
        'last_synced_at' => now()->subSeconds(30),
        'removed_from_source' => false,
        'source_snapshot' => null,
    ]);

    $this->actingAs($user)->get('/imports/ploi')->assertOk();

    Queue::assertNotPushed(SyncPloiInventoryJob::class);
});
test('active migration replaces migrate cta with view link', function () {
    Queue::fake();
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
    ]);
    $server = PloiServer::create([
        'provider_credential_id' => $credential->id,
        'source_id' => 42,
        'name' => 'prod-web-01',
        'ip_address' => '203.0.113.10',
        'provider_label' => 'digital-ocean',
        'server_type' => null,
        'php_versions' => [],
        'status' => 'active',
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
        'status' => ImportServerMigration::STATUS_STAGING,
    ]);

    $response = $this->actingAs($user)->get('/imports/ploi');

    $response->assertOk()
        ->assertSee('1 migration in progress')
        ->assertSee('View migration in progress')
        ->assertDontSee('Migrate this server');
});
test('credentials sidebar shows ploi tab', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('organizations.credentials', ['organization' => $org, 'provider' => 'ploi']));

    $response->assertOk()
        ->assertSee('Migrate sites from Ploi to dply')
        ->assertSee('Connect Ploi')
        ->assertSee('Migrate from');
    // sidebar group label
});
