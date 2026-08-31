<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\ServerDatabaseInventoryTest;

use App\Livewire\Servers\WorkspaceDatabases;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\User;
use App\Modules\Deploy\Services\SiteBindingManager;
use App\Services\Servers\ServerDatabaseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function inventoryServer(): Server
{
    $org = Organization::factory()->create();

    return Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => 'test-key',
        'meta' => ['host_kind' => 'vm'],
    ]);
}

/** @param array<string, array{ok: bool, databases: list<string>}> $engines */
function seedInventory(Server $server, array $engines): void
{
    $meta = is_array($server->meta) ? $server->meta : [];
    $meta[ServerDatabaseInventory::META_KEY] = [
        'scanned_at' => now()->toIso8601String(),
        'engines' => $engines,
    ];
    $server->forceFill(['meta' => $meta])->save();
    $server->refresh();
}

function trackDb(Server $server, string $name, string $engine = 'postgres'): ServerDatabase
{
    return ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => $name,
        'engine' => $engine,
        'username' => $name.'_u',
        'password' => 'x',
        'host' => '127.0.0.1',
    ]);
}

test('a database on the server with no row is reported untracked', function () {
    $server = inventoryServer();
    trackDb($server, 'databio');
    seedInventory($server, ['postgres' => ['ok' => true, 'databases' => ['databio', 'orders_legacy']]]);

    expect(app(ServerDatabaseInventory::class)->untracked($server))
        ->toBe([['engine' => 'postgres', 'name' => 'orders_legacy']]);
});

test('a failed engine scan reports nothing rather than claiming the engine is empty', function () {
    $server = inventoryServer();
    trackDb($server, 'databio');

    // Auth rejected / client missing / engine down. "Not checked" must never be
    // read as "no databases here" — that would mark every tracked row missing.
    seedInventory($server, ['postgres' => ['ok' => false, 'databases' => [], 'error' => 'auth failed']]);

    $inventory = app(ServerDatabaseInventory::class);

    expect($inventory->untracked($server))->toBe([])
        ->and($inventory->missing($server))->toBe([]);
});

test('a tracked row whose database is gone is reported missing', function () {
    $server = inventoryServer();
    $gone = trackDb($server, 'deleted_by_hand');
    trackDb($server, 'databio');
    seedInventory($server, ['postgres' => ['ok' => true, 'databases' => ['databio']]]);

    $missing = app(ServerDatabaseInventory::class)->missing($server);

    expect($missing)->toHaveCount(1)
        ->and($missing[0]->id)->toBe($gone->id);
});

test('missing rows are never removed automatically', function () {
    $server = inventoryServer();
    $gone = trackDb($server, 'deleted_by_hand');
    seedInventory($server, ['postgres' => ['ok' => true, 'databases' => []]]);

    app(ServerDatabaseInventory::class)->missing($server);

    // The row is the only record of the credentials and site link.
    expect(ServerDatabase::query()->whereKey($gone->id)->exists())->toBeTrue();
});

test('the same name on two engines is tracked and reported independently', function () {
    $server = inventoryServer();
    trackDb($server, 'app', 'postgres');
    seedInventory($server, [
        'postgres' => ['ok' => true, 'databases' => ['app']],
        'mysql' => ['ok' => true, 'databases' => ['app']],
    ]);

    // Only the mysql one is untracked — the widened unique key makes both rows
    // possible in the first place.
    expect(app(ServerDatabaseInventory::class)->untracked($server))
        ->toBe([['engine' => 'mysql', 'name' => 'app']]);
});

test('a sqlite row is never reported missing — it is a file, not a catalog entry', function () {
    $server = inventoryServer();
    trackDb($server, 'notes', 'sqlite');
    seedInventory($server, ['postgres' => ['ok' => true, 'databases' => []]]);

    expect(app(ServerDatabaseInventory::class)->missing($server))->toBe([]);
});

test('an unscanned server reports neither untracked nor missing', function () {
    $server = inventoryServer();
    trackDb($server, 'databio');

    $inventory = app(ServerDatabaseInventory::class);

    expect($inventory->cached($server))->toBeNull()
        ->and($inventory->untracked($server))->toBe([])
        ->and($inventory->missing($server))->toBe([]);
});

test('adopting records the database with credentials marked unknown', function () {
    $server = inventoryServer();

    $db = app(ServerDatabaseInventory::class)->adopt($server, 'postgres', 'orders_legacy');

    expect($db->name)->toBe('orders_legacy')
        ->and($db->engine)->toBe('postgres')
        ->and($db->host)->toBe('127.0.0.1')
        ->and($db->credentials_known)->toBeFalse()
        ->and($db->hasUsableCredentials())->toBeFalse()
        ->and($db->site_id)->toBeNull();
});

test('adopting with a site links by site_id and creates no binding', function () {
    $server = inventoryServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
    ]);

    $db = app(ServerDatabaseInventory::class)->adopt($server, 'postgres', 'orders_legacy', $site);

    expect($db->site_id)->toBe($site->id)
        // A binding would inject DB_PASSWORD='' into a live app's .env.
        ->and($site->bindings()->where('type', 'database')->count())->toBe(0);
});

test('an adopted database stops being reported as untracked', function () {
    $server = inventoryServer();
    seedInventory($server, ['postgres' => ['ok' => true, 'databases' => ['orders_legacy']]]);
    $inventory = app(ServerDatabaseInventory::class);

    expect($inventory->untracked($server))->toHaveCount(1);

    $inventory->adopt($server, 'postgres', 'orders_legacy');

    expect($inventory->untracked($server->fresh()))->toBe([]);
});

test('a dply-created database keeps usable credentials', function () {
    $server = inventoryServer();
    $db = trackDb($server, 'databio');

    // Default true, so the migration leaves every existing row fully managed.
    expect($db->credentials_known)->toBeTrue()
        ->and($db->hasUsableCredentials())->toBeTrue();
});

test('adopting is refused for a database the last scan did not see', function () {
    $server = inventoryServer();
    $user = User::factory()->create();
    $server->organization->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $server->organization_id]);
    seedInventory($server, ['postgres' => ['ok' => true, 'databases' => []]]);

    // Never trust an engine/name pair posted back from the browser.
    Livewire::actingAs($user)
        ->test(WorkspaceDatabases::class, ['server' => $server])
        ->call('adoptUntrackedDatabase', 'postgres', 'someone_elses_db');

    expect(ServerDatabase::query()->where('name', 'someone_elses_db')->exists())->toBeFalse();
});

test('adopting from the server workspace records the row and clears it from the list', function () {
    $server = inventoryServer();
    $user = User::factory()->create();
    $server->organization->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $server->organization_id]);
    seedInventory($server, ['postgres' => ['ok' => true, 'databases' => ['orders_legacy']]]);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceDatabases::class, ['server' => $server])
        ->call('adoptUntrackedDatabase', 'postgres', 'orders_legacy')
        ->assertHasNoErrors();

    $db = ServerDatabase::query()->where('name', 'orders_legacy')->first();

    expect($db)->not->toBeNull()
        ->and($db->credentials_known)->toBeFalse()
        ->and($component->get('untrackedDatabases'))->toBe([]);
});

test('forgetting is refused while the database is still on the server', function () {
    $server = inventoryServer();
    $user = User::factory()->create();
    $server->organization->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $server->organization_id]);
    $db = trackDb($server, 'databio');
    seedInventory($server, ['postgres' => ['ok' => true, 'databases' => ['databio']]]);

    Livewire::actingAs($user)
        ->test(WorkspaceDatabases::class, ['server' => $server])
        ->call('forgetMissingDatabase', (string) $db->id);

    expect(ServerDatabase::query()->whereKey($db->id)->exists())->toBeTrue();
});

test('an adopted database cannot be attached as a resource binding', function () {
    $server = inventoryServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
    ]);
    $db = app(ServerDatabaseInventory::class)->adopt($server, 'postgres', 'orders_legacy', $site);

    // A binding injects DB_* at deploy; with no known password that would write
    // DB_PASSWORD='' into a live app's .env.
    $binding = app(SiteBindingManager::class)
        ->adoptServerDatabase($site, $db);

    expect($binding)->toBeNull()
        ->and($site->bindings()->where('type', 'database')->count())->toBe(0);
});

test('a database with known credentials still attaches as a binding', function () {
    $server = inventoryServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
    ]);
    $db = trackDb($server, 'databio');

    expect(app(SiteBindingManager::class)->adoptServerDatabase($site, $db))
        ->not->toBeNull();
});
