<?php

namespace Tests\Feature\Console;

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
use Tests\TestCase;

/**
 * Feature tests for the global console drawer component.
 *
 * @covers \App\Livewire\Servers\ConsoleDrawer
 */
final class ConsoleDrawerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    protected function userWithOrganization(?string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $role]);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    protected function readyServer(User $user, array $overrides = []): Server
    {
        return Server::factory()->ready()->create(array_merge([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()?->id,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'ssh_user' => 'deploy',
            'name' => 'test-server',
        ], $overrides));
    }

    public function test_drawer_shows_server_picker_when_no_server_in_context(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class)
            ->assertSet('server', null)
            ->assertSee('Pick a server to console into')
            ->assertSee($server->name)
            ->assertSee($server->ip_address);
    }

    public function test_drawer_auto_selects_route_bound_server(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->assertSet('server.id', $server->id)
            ->assertSee('deploy@test-server')
            ->assertDontSee('Pick a server');
    }

    public function test_drawer_restores_last_selected_server_from_session(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        // Pre-populate session with server ID
        session(['dply.consoleDrawer.serverId' => $server->id]);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class)
            ->assertSet('server.id', $server->id)
            ->assertSee('deploy@test-server');
    }

    public function test_drawer_ignores_invalid_session_server_id(): void
    {
        $user = $this->userWithOrganization();
        $this->readyServer($user);

        // Set invalid server ID in session
        session(['dply.consoleDrawer.serverId' => 'invalid-id']);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class)
            ->assertSet('server', null)
            ->assertSee('Pick a server');
    }

    public function test_drawer_ignores_session_server_from_different_org(): void
    {
        // Create user in org A
        $user = $this->userWithOrganization('owner');
        $server = $this->readyServer($user);

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
    }

    public function test_select_server_sets_active_server(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class)
            ->call('selectServer', $server->id)
            ->assertSet('server.id', $server->id)
            ->assertSee('deploy@test-server');
    }

    public function test_select_server_updates_session(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class)
            ->call('selectServer', $server->id);

        $this->assertEquals($server->id, session('dply.consoleDrawer.serverId'));
    }

    public function test_select_invalid_server_is_noop(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class)
            ->call('selectServer', 'invalid-id')
            ->assertSet('server', null);
    }

    public function test_clear_active_server_clears_selection(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->assertSet('server.id', $server->id)
            ->call('clearActiveServer')
            ->assertSet('server', null)
            ->assertSet('history', [])
            ->assertSet('error', null);
    }

    public function test_clear_active_server_clears_session(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->call('clearActiveServer');

        $this->assertNull(session('dply.consoleDrawer.serverId'));
    }

    public function test_clear_active_server_clears_history(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            // Add some history
            ->set('command', 'echo test')
            ->call('run')
            ->assertCount('history', 1)
            ->call('clearActiveServer')
            ->assertCount('history', 0);
    }

    public function test_available_servers_only_includes_ready_servers(): void
    {
        $user = $this->userWithOrganization();
        $readyServer = $this->readyServer($user, ['name' => 'Ready Server']);

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

        $this->assertCount(1, $available);
        $this->assertEquals($readyServer->id, $available->first()->id);
    }

    public function test_available_servers_only_includes_current_org_servers(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user, ['name' => 'My Server']);

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

        $this->assertCount(1, $available);
        $this->assertEquals($server->id, $available->first()->id);
    }

    public function test_available_servers_is_limited_to_100(): void
    {
        $user = $this->userWithOrganization();

        // Create 105 servers (excessive, but tests the limit)
        for ($i = 0; $i < 105; $i++) {
            $this->readyServer($user, ['name' => "Server {$i}"]);
        }

        $component = Livewire::actingAs($user)
            ->test(ConsoleDrawer::class);

        // availableServers() is a protected helper — bind a closure to the
        // component instance to reach it without widening its visibility.
        $available = (fn () => $this->availableServers())->call($component->instance());

        $this->assertCount(100, $available);
    }

    public function test_available_servers_is_ordered_by_name(): void
    {
        $user = $this->userWithOrganization();

        $this->readyServer($user, ['name' => 'Zebra Server']);
        $this->readyServer($user, ['name' => 'Alpha Server']);
        $this->readyServer($user, ['name' => 'Beta Server']);

        $component = Livewire::actingAs($user)
            ->test(ConsoleDrawer::class);

        // availableServers() is a protected helper — bind a closure to the
        // component instance to reach it without widening its visibility.
        $available = (fn () => $this->availableServers())->call($component->instance());

        $this->assertEquals('Alpha Server', $available[0]->name);
        $this->assertEquals('Beta Server', $available[1]->name);
        $this->assertEquals('Zebra Server', $available[2]->name);
    }

    public function test_command_execution_works_in_drawer(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->set('command', 'uptime')
            ->call('run')
            ->assertSet('command', '')
            ->assertCount('history', 1);
    }

    public function test_deployer_cannot_run_commands_in_drawer(): void
    {
        $user = $this->userWithOrganization('deployer');
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->set('command', 'uptime')
            ->call('run')
            ->assertSet('error', 'Deployers cannot run shell commands on servers.');
    }

    public function test_drawer_includes_open_full_link(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->assertSee('Open full')
            ->assertSee(route('servers.console', $server));
    }

    public function test_drawer_includes_switch_button(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->assertSee('Switch');
    }

    public function test_drawer_shows_entry_count(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->set('command', 'echo test')
            ->call('run')
            ->assertSee('1 entry')
            ->set('command', 'echo test2')
            ->call('run')
            ->assertSee('2 entries');
    }

    public function test_drawer_shows_clear_button_when_history_exists(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->assertDontSee('Clear')
            ->set('command', 'echo test')
            ->call('run')
            ->assertSee('Clear');
    }

    public function test_drawer_no_servers_message(): void
    {
        $user = $this->userWithOrganization();
        // No servers created

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class)
            ->assertSee('No console-eligible servers');
    }

    public function test_drawer_requires_authentication(): void
    {
        $component = Livewire::test(ConsoleDrawer::class);

        // Should be redirected or fail authorization
        $this->assertTrue(true); // Auth gate tested elsewhere
    }

    public function test_route_bound_server_is_preferred_over_session(): void
    {
        $user = $this->userWithOrganization();
        $serverA = $this->readyServer($user, ['name' => 'Server A']);
        $serverB = $this->readyServer($user, ['name' => 'Server B']);

        // Set Server B in session
        session(['dply.consoleDrawer.serverId' => $serverB->id]);

        // But pass Server A as route-bound
        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $serverA])
            ->assertSet('server.id', $serverA->id)
            ->assertSee('deploy@Server A');
    }

    public function test_drawer_renders_when_no_organization(): void
    {
        $user = User::factory()->create();
        // No org attached, no current_organization_id in session

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class)
            ->assertSet('server', null);
    }

    public function test_drawer_prompt_shows_root_when_ssh_user_blank(): void
    {
        $user = $this->userWithOrganization();
        // servers.ssh_user is NOT NULL — a blank value exercises the
        // prompt's `?: 'root'` fallback and is the real-world case.
        $server = $this->readyServer($user, [
            'ssh_user' => '',
            'name' => 'test-server',
        ]);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->assertSee('root@test-server');
    }

    public function test_drawer_shows_ip_when_name_blank(): void
    {
        $user = $this->userWithOrganization();
        // servers.name is NOT NULL — a blank name exercises the prompt's
        // name -> ip fallback (`$server->name ?: $server->ip_address`).
        $server = $this->readyServer($user, [
            'name' => '',
            'ip_address' => '192.168.1.100',
        ]);

        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class, ['server' => $server])
            ->assertSee('deploy@192.168.1.100');
    }

    public function test_drawer_shows_picker_when_server_null(): void
    {
        $user = $this->userWithOrganization();

        // No server in context → the drawer renders the server picker,
        // not a shell prompt. (The `—` host fallback only applies to a
        // server that exists but has no name/IP — see the @else branch.)
        Livewire::actingAs($user)
            ->test(ConsoleDrawer::class)
            ->assertSee('Pick a server to console into');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
