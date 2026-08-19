<?php

declare(strict_types=1);

namespace Tests\Feature\Databases;

use App\Livewire\Sites\Database;
use App\Livewire\Sites\DatabaseConnect;
use App\Models\CloudDatabase;
use App\Models\CloudDatabaseTrustedSource;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Site, 2: SiteBinding, 3: CloudDatabase} */
function connectFixture(array $serverOverrides = [], string $role = 'admin'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user, ['role' => $role]);
    $user->update(['current_organization_id' => $org->id]);

    $server = Server::factory()->create(array_merge([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.10',
        'ssh_user' => 'dply',
        'ssh_private_key' => 'fake-key',
        'meta' => ['host_kind' => Server::HOST_KIND_VM],
    ], $serverOverrides));

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $database = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'managed',
        'status' => 'active',
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => $database->id,
        'config' => ['placement' => 'managed'],
        'injected_env' => [],
    ]);

    return [$user, $site, $binding, $database];
}

test('the connect modal renders a tunnel command and never the password', function (): void {
    [$user, $site, $binding] = connectFixture();

    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->assertOk()
        ->assertSee('ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 -L 15432:db.example.ondigitalocean.com:25060 dply@203.0.113.10')
        ->assertSee('db.example.ondigitalocean.com')
        // The factory's password must never reach the rendered page.
        ->assertDontSee('secret-pass');
});

test('changing the local port rewrites the emitted commands', function (): void {
    [$user, $site, $binding] = connectFixture();

    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->set('localPort', 15999)
        ->assertSee('ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 -L 15999:db.example.ondigitalocean.com:25060 dply@203.0.113.10')
        ->assertSee('127.0.0.1:15999');
});

test('a non-standard ssh port is carried into the tunnel command', function (): void {
    [$user, $site, $binding] = connectFixture(['ssh_port' => 2222]);

    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->assertSee('ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 -p 2222 -L 15432:');
});

test('a synthetic host explains itself instead of emitting a broken command', function (): void {
    [$user, $site, $binding] = connectFixture([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->assertSee('no server to tunnel through', escape: false)
        ->assertDontSee('ssh -L');
});

test('a deployer cannot allow an ip', function (): void {
    [$user, $site, $binding] = connectFixture(role: 'deployer');

    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->assertDontSee('Allow my IP');
});

test('the kill switch hides the allow-ip action', function (): void {
    config(['server_database.trusted_source_writes' => false]);

    [$user, $site, $binding] = connectFixture();

    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->assertDontSee('Allow my IP');
});

test('the databases tab renders the connect button on a hosted row', function (): void {
    [$user, $site, $binding] = connectFixture();

    // Livewire does not deep-render children in a parent component test, so the
    // assertion is that the child is MOUNTED with the right key — its contents
    // are covered by the component tests above.
    Livewire::actingAs($user)
        ->test(Database::class, [
            'server' => $site->server,
            'site' => $site,
        ])
        ->set('dbTab', 'databases')
        ->assertOk()
        ->assertSee('Hosted databases')
        ->assertSeeHtml('wire:name="sites.database-connect"')
        ->assertSeeHtml('db-connect-'.$binding->id)
        ->assertDontSee('secret-pass');
});

test('the launch command carries no fake password placeholder', function (): void {
    [$user, $site, $binding] = connectFixture();

    // A literal "PASSWORD" string used to be emitted here, producing a command
    // that looked copy-pasteable and silently failed to authenticate.
    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->assertDontSee('PASSWORD')
        ->assertSee('postgresql://doadmin@127.0.0.1:15432/defaultdb', escape: false);
});

test('the allow-ip field is editable when no public ip is detected', function (): void {
    [$user, $site, $binding] = connectFixture();

    // request()->ip() is 127.0.0.1 under test, exactly as in local development.
    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->assertSee('Allow this IP')
        ->assertSee('could not be detected', escape: false)
        ->assertSeeHtml('wire:model.live.debounce.500ms="allowIp"');
});

test('a private address is refused', function (): void {
    [$user, $site, $binding] = connectFixture();

    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->set('allowIp', '10.0.0.5')
        ->call('allowMyIp')
        ->assertDispatched('toast');

    expect(CloudDatabaseTrustedSource::query()->count())->toBe(0);
});

test('the tunnel command pins the ssh identity', function (): void {
    [$user, $site, $binding] = connectFixture();

    // Without -o IdentitiesOnly=yes -i, ssh walks every agent key and trips the
    // server's MaxAuthTries, which surfaces as "Too many authentication failures".
    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->assertSee('ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 -L 15432:');
});

test('a live allowance unlocks the direct one-click link', function (): void {
    [$user, $site, $binding, $database] = connectFixture();

    // Direct used to be hidden whenever a tunnel was possible, so the only
    // "Open in TablePlus" pointed at 127.0.0.1 and refused the connection.
    CloudDatabaseTrustedSource::query()->create([
        'cloud_database_id' => $database->id,
        'ip_address' => '203.0.113.7',
        'created_by_user_id' => $user->id,
        'expires_at' => now()->addHours(4),
    ]);

    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        ->assertSee('Open in TablePlus')
        ->assertSee('203.0.113.7');
});

test('allow and open is offered when no allowance exists yet', function (): void {
    [$user, $site, $binding] = connectFixture();

    Livewire::actingAs($user)
        ->test(DatabaseConnect::class, [
            'site' => $site,
            'server' => $site->server,
            'bindingId' => (string) $binding->id,
        ])
        // The one-click grant needs a routable address before it can be offered;
        // under test request()->ip() is loopback, as in local development.
        ->set('allowIp', '203.0.113.7')
        ->assertSee('Allow my IP and open directly');
});
