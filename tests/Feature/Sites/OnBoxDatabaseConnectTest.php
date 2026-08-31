<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\OnBoxDatabaseConnectTest;

use App\Livewire\Sites\Database as DatabaseTab;
use App\Livewire\Sites\DatabaseConnect;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseConnectionTargetResolver;
use App\Support\Servers\DatabaseWorkspaceEngines;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The Connect panel is addressed by binding id and its resolver rejected any
 * loopback host as "not remote configurable" — so an on-box site database, the
 * simplest case of all (the jump host IS the database host), had no way to get
 * connection details at all.
 *
 * @return array{0: User, 1: Server, 2: Site, 3: ServerDatabase, 4: SiteBinding}
 */
function onBoxFixture(string $engine = 'postgres', string $host = '127.0.0.1'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    // ready() only sets status; a tunnel also needs a reachable, key-holding box.
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => 'test-key',
        'meta' => ['host_kind' => 'vm', 'webserver' => 'nginx'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $db = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'name' => 'databio',
        'engine' => $engine,
        'username' => 'databio_r0ld',
        'password' => 'sekret',
        'host' => $host,
    ]);
    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'target_type' => 'server_database',
        'target_id' => (string) $db->id,
        'injected_env' => [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $host,
            'DB_DATABASE' => 'databio',
            'DB_USERNAME' => 'databio_r0ld',
            'DB_PASSWORD' => 'sekret',
        ],
        'config' => ['engine' => $engine, 'connection' => ''],
    ]);

    return [$user, $server, $site, $db, $binding];
}

test('an on-box database resolves a connection target', function () {
    [, , , $db, $binding] = onBoxFixture();

    $target = app(DatabaseConnectionTargetResolver::class)->forBinding($binding);

    expect($target)->not->toBeNull()
        ->and($target->kind)->toBe(DatabaseConnectionTarget::KIND_ON_BOX)
        ->and($target->host)->toBe('127.0.0.1')
        ->and($target->port)->toBe(5432)
        ->and($target->database)->toBe('databio')
        ->and($target->username)->toBe('databio_r0ld')
        // Never reachable from the internet — the panel must offer a tunnel,
        // not a direct link.
        ->and($target->publiclyReachable)->toBeFalse();
});

test('a tunnel is available because the jump host is the database host', function () {
    [, $server, , , $binding] = onBoxFixture();
    $resolver = app(DatabaseConnectionTargetResolver::class);

    $target = $resolver->forBinding($binding);

    expect($resolver->tunnelUnavailableReason($target, $server))->toBeNull();
});

test('sqlite gets no connection target — there is no daemon to tunnel to', function () {
    [, , , , $binding] = onBoxFixture('sqlite', '/var/lib/dply/sqlite/app.db');

    expect(app(DatabaseConnectionTargetResolver::class)->forBinding($binding))->toBeNull();
});

test('a database on a peer server is not treated as on-box', function () {
    [$user, $server, $site, $db, $binding] = onBoxFixture();

    // Same loopback host string, but the row lives on a different server — that
    // is the dedicated-DB-VM case, which the remote arm handles over the
    // private network. Claiming loopback would tunnel to the wrong machine.
    $peer = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $site->organization_id,
    ]);
    $db->forceFill(['server_id' => $peer->id])->save();

    $target = app(DatabaseConnectionTargetResolver::class)->forBinding($binding->fresh());

    expect($target?->host)->not->toBe('127.0.0.1');
});

test('the Database tab exposes the binding id so the Connect panel can mount', function () {
    [$user, $server, $site, $db, $binding] = onBoxFixture();

    $component = Livewire::actingAs($user)
        ->test(DatabaseTab::class, ['server' => $server, 'site' => $site]);

    expect($component->instance()->connectBindingIdFor($db))->toBe((string) $binding->id);
});

test('a database with no binding gets no Connect action rather than a broken one', function () {
    [$user, $server, $site, $db, $binding] = onBoxFixture();
    $binding->delete();

    $component = Livewire::actingAs($user)
        ->test(DatabaseTab::class, ['server' => $server, 'site' => $site]);

    expect($component->instance()->connectBindingIdFor($db->fresh()))->toBeNull();
});

test('the Connect panel renders connection details for an on-box database', function () {
    [$user, $server, $site, , $binding] = onBoxFixture();

    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $server,
            'bindingId' => (string) $binding->id,
        ])
        ->call('openConnect')
        ->assertOk()
        ->assertSee('databio')
        ->assertSee('5432')
        // The password never reaches the DOM — it travels via the one-time
        // credential link only.
        ->assertDontSee('sekret');
});

test('an unbound database can still hand out credentials on demand', function () {
    [$user, $server, $site, $db, $binding] = onBoxFixture();
    $binding->delete();

    Livewire::actingAs($user)
        ->test(DatabaseTab::class, ['server' => $server, 'site' => $site])
        ->call('shareCredentials', (string) $db->id)
        ->assertSet('share_context', 'shared')
        ->assertSet('share_link_db_name', 'databio');

    expect($db->credentialShares()->count())->toBe(1);
});

test('sharing credentials is refused for sqlite', function () {
    [$user, $server, $site, $db] = onBoxFixture('sqlite', '/var/lib/dply/sqlite/app.db');

    Livewire::actingAs($user)
        ->test(DatabaseTab::class, ['server' => $server, 'site' => $site])
        ->call('shareCredentials', (string) $db->id)
        ->assertSet('share_link_url', null);

    expect($db->credentialShares()->count())->toBe(0);
});

test('sharing credentials refuses a database belonging to another site', function () {
    [$user, $server, $site] = onBoxFixture();
    $otherSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $site->organization_id,
    ]);
    $theirs = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'site_id' => $otherSite->id,
        'name' => 'theirs',
        'engine' => 'postgres',
        'username' => 'theirs_u',
        'password' => 'nope',
        'host' => '127.0.0.1',
    ]);

    Livewire::actingAs($user)
        ->test(DatabaseTab::class, ['server' => $server, 'site' => $site])
        ->call('shareCredentials', (string) $theirs->id)
        ->assertSet('share_link_url', null);

    expect($theirs->credentialShares()->count())->toBe(0);
});

/**
 * Renders the actual row list, which the other tests skip: it is gated behind
 * the deferred capability probe, so nothing else here compiles that markup.
 */
function withInstalledPostgres(): void
{
    $caps = \Mockery::mock(ServerDatabaseHostCapabilities::class);
    $caps->shouldReceive('forServer')->andReturn(array_merge(
        DatabaseWorkspaceEngines::defaultCapabilities(),
        ['postgres' => true],
    ));
    app()->instance(ServerDatabaseHostCapabilities::class, $caps);
}

test('row actions collapse into an overflow menu with Connect left inline', function () {
    [$user, $server, $site] = onBoxFixture();
    withInstalledPostgres();

    Livewire::actingAs($user)
        ->test(DatabaseTab::class, ['server' => $server, 'site' => $site])
        ->call('loadDatabaseCapabilities')
        ->assertOk()
        ->assertSee('databio')
        // Primary action stays visible…
        ->assertSee(__('Connect'))
        // …the rest move behind the kebab.
        ->assertSee(__('More database actions'))
        ->assertSee(__('Add user'))
        ->assertSee(__('Rotate password'))
        ->assertSee(__('Back up now'))
        ->assertSee(__('Drop'));
});

test('a bound row offers the credential link in the menu and Connect inline', function () {
    [$user, $server, $site] = onBoxFixture();
    withInstalledPostgres();

    $html = Livewire::actingAs($user)
        ->test(DatabaseTab::class, ['server' => $server, 'site' => $site])
        ->call('loadDatabaseCapabilities')
        ->assertSee(__('Credential link'))
        ->html();

    // Exactly one handoff entry point per row: the menu item here, the inline
    // button on an unbound row — never both. Counted structurally because the
    // label "Credentials" also appears inside the wire:click method name.
    expect(substr_count($html, 'shareCredentials('))->toBe(1);
});

test('an unbound row shows Credentials inline and no duplicate in the menu', function () {
    [$user, $server, $site, , $binding] = onBoxFixture();
    $binding->delete();
    withInstalledPostgres();

    $html = Livewire::actingAs($user)
        ->test(DatabaseTab::class, ['server' => $server, 'site' => $site])
        ->call('loadDatabaseCapabilities')
        ->assertSee(__('Credentials'))
        ->assertDontSee(__('Credential link'))
        ->assertSee(__('More database actions'))
        ->html();

    expect(substr_count($html, 'shareCredentials('))->toBe(1);
});
