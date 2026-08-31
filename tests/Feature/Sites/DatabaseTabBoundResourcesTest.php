<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\DatabaseTabBoundResourcesTest;

use App\Livewire\Sites\Database;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A database reaches a site by one of two independent routes — owned
 * (server_databases.site_id) or attached (a `database` SiteBinding). The
 * Database tab read only the first, so a database attached from the
 * Environment tab rendered "No databases are linked to this site yet" while
 * the resource map showed it configured.
 *
 * @return array{0: User, 1: Server, 2: Site}
 */
function dbTabFixture(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => 'vm', 'webserver' => 'nginx'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $server, $site];
}

function serverDb(Server $server, string $name, ?Site $owner = null): ServerDatabase
{
    return ServerDatabase::query()->create([
        'server_id' => $server->id,
        'site_id' => $owner?->id,
        'name' => $name,
        'engine' => 'postgres',
        'username' => $name.'_u',
        'password' => 'sekret',
        'host' => '127.0.0.1',
    ]);
}

function bindDatabase(Site $site, ServerDatabase $db): SiteBinding
{
    return SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'attach_existing',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'target_type' => 'server_database',
        'target_id' => (string) $db->id,
        'injected_env' => ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $db->name],
        'config' => ['engine' => 'postgres', 'connection' => ''],
    ]);
}

test('a database attached through a binding shows on the Database tab', function () {
    [$user, $server, $site] = dbTabFixture();
    // site_id stays null — this is what the Environment tab's attach produces.
    $db = serverDb($server, 'databio');
    bindDatabase($site, $db);

    $component = Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site]);

    expect($component->instance()->linkedDatabases->pluck('name')->all())->toBe(['databio']);
});

test('a database owned outright still shows', function () {
    [$user, $server, $site] = dbTabFixture();
    serverDb($server, 'owned', $site);

    $component = Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site]);

    expect($component->instance()->linkedDatabases->pluck('name')->all())->toBe(['owned']);
});

test('both routes appear once each, not twice', function () {
    [$user, $server, $site] = dbTabFixture();
    $owned = serverDb($server, 'owned', $site);
    bindDatabase($site, $owned);

    $component = Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site]);

    expect($component->instance()->linkedDatabases->pluck('name')->all())->toBe(['owned']);
});

test('one database shared by two sites shows on both', function () {
    [$user, $server, $siteA] = dbTabFixture();
    $siteB = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $siteA->organization_id,
    ]);

    // site_id can only ever name one site, which is exactly why bindings exist.
    $db = serverDb($server, 'shared');
    bindDatabase($siteA, $db);
    bindDatabase($siteB, $db);

    foreach ([$siteA, $siteB] as $site) {
        $component = Livewire::actingAs($user)
            ->test(Database::class, ['server' => $server, 'site' => $site]);

        expect($component->instance()->linkedDatabases->pluck('name')->all())->toBe(['shared']);
    }
});

test('another site database is not shown', function () {
    [$user, $server, $site] = dbTabFixture();
    $otherSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $site->organization_id,
    ]);
    serverDb($server, 'theirs', $otherSite);

    $component = Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site]);

    expect($component->instance()->linkedDatabases)->toHaveCount(0);
});

test('a bound database is not offered again in the link picker', function () {
    [$user, $server, $site] = dbTabFixture();
    $bound = serverDb($server, 'bound');
    bindDatabase($site, $bound);
    serverDb($server, 'spare');

    $component = Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site]);

    expect($component->instance()->linkableDatabases->pluck('name')->all())->toBe(['spare']);
});

test('actions resolve for a bound database instead of silently no-opping', function () {
    [$user, $server, $site] = dbTabFixture();
    $db = serverDb($server, 'databio');
    bindDatabase($site, $db);

    $component = Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site])
        ->call('openAddUserModal', (string) $db->id);

    // Resolving through ownedDatabase() is what every mutating action gates on.
    $component->assertSet('extra_user_db_id', (string) $db->id);
});

test('detach on a bound database explains where to actually detach it', function () {
    [$user, $server, $site] = dbTabFixture();
    $db = serverDb($server, 'databio');
    bindDatabase($site, $db);

    Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site])
        ->call('unlinkDatabase', (string) $db->id);

    // Clearing site_id would be a no-op here and would leave DB_* injected.
    expect($db->fresh()->site_id)->toBeNull()
        ->and($site->bindings()->where('type', 'database')->count())->toBe(1);
});

test('detach still works on a database owned outright', function () {
    [$user, $server, $site] = dbTabFixture();
    $db = serverDb($server, 'owned', $site);

    Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site])
        ->call('unlinkDatabase', (string) $db->id);

    expect($db->fresh()->site_id)->toBeNull();
});

test('a database that is both owned and bound cannot be detached from this tab', function () {
    // The normal shape after a create: site_id is set AND a binding adopted it.
    // Clearing site_id would leave the binding injecting DB_*, so the app would
    // still point at a database this tab claims is detached.
    [$user, $server, $site] = dbTabFixture();
    $db = serverDb($server, 'databio', $site);
    bindDatabase($site, $db);

    Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site])
        ->call('unlinkDatabase', (string) $db->id);

    expect($db->fresh()->site_id)->toBe((string) $site->id);
});

test('the row is flagged as binding-managed whether or not site_id is set', function () {
    [$user, $server, $site] = dbTabFixture();
    $ownedAndBound = serverDb($server, 'both', $site);
    bindDatabase($site, $ownedAndBound);
    $ownedOnly = serverDb($server, 'owned_only', $site);

    $component = Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site]);

    expect($component->instance()->bindingManagesDatabase($ownedAndBound))->toBeTrue()
        ->and($component->instance()->bindingManagesDatabase($ownedOnly))->toBeFalse();
});
