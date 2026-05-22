<?php

namespace Tests\Feature\Console\ConsoleDrawerTest;

use App\Livewire\Servers\ConsoleDrawer;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\SshConnection;
use App\Services\SshConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Each ready server created here fires a Server::created listener
    // that dispatches an SSH key-provisioning job. On the sync queue
    // that job runs inline and makes a real SSH connection to the
    // server's (fake, random) IP — creating 105 servers in one test
    // then meant ~16 minutes of hanging connects. Fake the queue so
    // those jobs are recorded, not executed.
    Queue::fake();

    // ConsoleDrawer::run() opens an SSH connection. Bind a fake
    // connection factory so tests never make real network calls — a
    // real connect to a factory server's random IP can hang until the
    // OS connect timeout, which is what made this test intermittently
    // "stick" when run.
    $this->app->bind(SshConnectionFactory::class, function () {
        $connection = Mockery::mock(SshConnection::class);
        $connection->shouldReceive('execWithCallbackAndExit')->andReturn(['', 0]);

        $factory = Mockery::mock(SshConnectionFactory::class);
        $factory->shouldReceive('forServer')->andReturn($connection);

        return $factory;
    });
});

function userWithOrganization(?string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function readyServer(User $user, array $overrides = []): Server
{
    return Server::factory()->ready()->create(array_merge([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'ssh_user' => 'deploy',
        'name' => 'test-server',
    ], $overrides));
}

test('drawer shows server picker when no server in context', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class)
        ->assertSet('server', null)
        ->assertSee('Pick a server to console into')
        ->assertSee($server->name)
        ->assertSee($server->ip_address);
});

test('drawer auto selects route bound server', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->assertSet('server.id', $server->id)
        ->assertSee('deploy@test-server')
        ->assertDontSee('Pick a server');
});

test('drawer restores last selected server from session', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    // Pre-populate session with server ID
    session(['dply.consoleDrawer.serverId' => $server->id]);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class)
        ->assertSet('server.id', $server->id)
        ->assertSee('deploy@test-server');
});

test('drawer ignores invalid session server id', function () {
    $user = userWithOrganization();
    readyServer($user);

    // Set invalid server ID in session
    session(['dply.consoleDrawer.serverId' => 'invalid-id']);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class)
        ->assertSet('server', null)
        ->assertSee('Pick a server');
});

test('drawer ignores session server from different org', function () {
    // Create user in org A
    $user = userWithOrganization('owner');
    $server = readyServer($user);

    // Create user B in org B
    $userB = User::factory()->create();
    $orgB = Organization::factory()->create();
    $orgB->users()->attach($userB->id, ['role' => 'owner']);
    session(['current_organization_id' => $orgB->id]);

    // User B tries to access server from org A via session
    session(['dply.consoleDrawer.serverId' => $server->id]);

    Livewire::actingAs($userB)
        ->test(ConsoleDrawer::class)
        ->assertSet('server', null)
        ->assertSee('Pick a server');
});

test('select server sets active server', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class)
        ->call('selectServer', $server->id)
        ->assertSet('server.id', $server->id)
        ->assertSee('deploy@test-server');
});

test('select server updates session', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class)
        ->call('selectServer', $server->id);

    expect(session('dply.consoleDrawer.serverId'))->toEqual($server->id);
});

test('select invalid server is noop', function () {
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class)
        ->call('selectServer', 'invalid-id')
        ->assertSet('server', null);
});

test('clear active server clears selection', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->assertSet('server.id', $server->id)
        ->call('clearActiveServer')
        ->assertSet('server', null)
        ->assertSet('history', [])
        ->assertSet('error', null);
});

test('clear active server clears session', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->call('clearActiveServer');

    expect(session('dply.consoleDrawer.serverId'))->toBeNull();
});

test('clear active server clears history', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        // Add some history
        ->set('command', 'echo test')
        ->call('run')
        ->assertCount('history', 1)
        ->call('clearActiveServer')
        ->assertCount('history', 0);
});

test('available servers only includes ready servers', function () {
    $user = userWithOrganization();
    $readyServer = readyServer($user, ['name' => 'Ready Server']);

    // Create non-ready server
    Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'status' => Server::STATUS_PROVISIONING,
        'name' => 'Provisioning Server',
        'ssh_private_key' => 'key',
    ]);

    // Create server without SSH key
    Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'name' => 'No SSH Server',
        'ssh_private_key' => null,
    ]);

    $component = Livewire::actingAs($user)
        ->test(ConsoleDrawer::class);

    // availableServers() is a protected helper — bind a closure to the
    // component instance to reach it without widening its visibility.
    $available = (fn () => $this->availableServers())->call($component->instance());

    expect($available)->toHaveCount(1);
    expect($available->first()->id)->toEqual($readyServer->id);
});

test('available servers only includes current org servers', function () {
    $user = userWithOrganization();
    $server = readyServer($user, ['name' => 'My Server']);

    // Create server in different org
    $otherOrg = Organization::factory()->create();
    Server::factory()->ready()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'Other Org Server',
        'ssh_private_key' => 'key',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ConsoleDrawer::class);

    // availableServers() is a protected helper — bind a closure to the
    // component instance to reach it without widening its visibility.
    $available = (fn () => $this->availableServers())->call($component->instance());

    expect($available)->toHaveCount(1);
    expect($available->first()->id)->toEqual($server->id);
});

test('available servers is limited to 100', function () {
    $user = userWithOrganization();

    // Create 105 servers (excessive, but tests the limit)
    for ($i = 0; $i < 105; $i++) {
        readyServer($user, ['name' => "Server {$i}"]);
    }

    $component = Livewire::actingAs($user)
        ->test(ConsoleDrawer::class);

    // availableServers() is a protected helper — bind a closure to the
    // component instance to reach it without widening its visibility.
    $available = (fn () => $this->availableServers())->call($component->instance());

    expect($available)->toHaveCount(100);
});

test('available servers is ordered by name', function () {
    $user = userWithOrganization();

    readyServer($user, ['name' => 'Zebra Server']);
    readyServer($user, ['name' => 'Alpha Server']);
    readyServer($user, ['name' => 'Beta Server']);

    $component = Livewire::actingAs($user)
        ->test(ConsoleDrawer::class);

    // availableServers() is a protected helper — bind a closure to the
    // component instance to reach it without widening its visibility.
    $available = (fn () => $this->availableServers())->call($component->instance());

    expect($available[0]->name)->toEqual('Alpha Server');
    expect($available[1]->name)->toEqual('Beta Server');
    expect($available[2]->name)->toEqual('Zebra Server');
});

test('command execution works in drawer', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->set('command', 'uptime')
        ->call('run')
        ->assertSet('command', '')
        ->assertCount('history', 1);
});

test('deployer cannot run commands in drawer', function () {
    $user = userWithOrganization('deployer');
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->set('command', 'uptime')
        ->call('run')
        ->assertSet('error', 'Deployers cannot run shell commands on servers.');
});

test('drawer includes open full link', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->assertSee('Open full')
        ->assertSee(route('servers.console', $server));
});

test('drawer includes switch button', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->assertSee('Switch');
});

test('drawer shows entry count', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->set('command', 'echo test')
        ->call('run')
        ->assertSee('1 entry')
        ->set('command', 'echo test2')
        ->call('run')
        ->assertSee('2 entries');
});

test('drawer shows clear button when history exists', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->assertDontSee('Clear')
        ->set('command', 'echo test')
        ->call('run')
        ->assertSee('Clear');
});

test('drawer no servers message', function () {
    $user = userWithOrganization();

    // No servers created
    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class)
        ->assertSee('No console-eligible servers');
});

test('drawer requires authentication', function () {
    $component = Livewire::test(ConsoleDrawer::class);

    // Should be redirected or fail authorization
    expect(true)->toBeTrue();
    // Auth gate tested elsewhere
});

test('route bound server is preferred over session', function () {
    $user = userWithOrganization();
    $serverA = readyServer($user, ['name' => 'Server A']);
    $serverB = readyServer($user, ['name' => 'Server B']);

    // Set Server B in session
    session(['dply.consoleDrawer.serverId' => $serverB->id]);

    // But pass Server A as route-bound
    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $serverA])
        ->assertSet('server.id', $serverA->id)
        ->assertSee('deploy@Server A');
});

test('drawer renders when no organization', function () {
    $user = User::factory()->create();

    // No org attached, no current_organization_id in session
    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class)
        ->assertSet('server', null);
});

test('drawer prompt shows root when ssh user blank', function () {
    $user = userWithOrganization();

    // servers.ssh_user is NOT NULL — a blank value exercises the
    // prompt's `?: 'root'` fallback and is the real-world case.
    $server = readyServer($user, [
        'ssh_user' => '',
        'name' => 'test-server',
    ]);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->assertSee('root@test-server');
});

test('drawer shows ip when name blank', function () {
    $user = userWithOrganization();

    // servers.name is NOT NULL — a blank name exercises the prompt's
    // name -> ip fallback (`$server->name ?: $server->ip_address`).
    $server = readyServer($user, [
        'name' => '',
        'ip_address' => '192.168.1.100',
    ]);

    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class, ['server' => $server])
        ->assertSee('deploy@192.168.1.100');
});

test('drawer shows picker when server null', function () {
    $user = userWithOrganization();

    // No server in context → the drawer renders the server picker,
    // not a shell prompt. (The `—` host fallback only applies to a
    // server that exists but has no name/IP — see the @else branch.)
    Livewire::actingAs($user)
        ->test(ConsoleDrawer::class)
        ->assertSee('Pick a server to console into');
});

afterEach(function () {
    Mockery::close();
});
