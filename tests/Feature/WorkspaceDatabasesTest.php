<?php

namespace Tests\Feature;

use App\Livewire\Servers\WorkspaceDatabases;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseCredentialShare;
use App\Models\User;
use App\Support\ServerDatabaseNotificationKeys;
use App\Services\Servers\ServerDatabaseHostCapabilities;
use App\Services\Servers\ServerDatabaseProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceDatabasesTest extends TestCase
{
    use RefreshDatabase;

    protected function actingOwnerWithServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        return [$user, $server];
    }

    public function test_create_database_with_optional_credentials_calls_provisioner(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => true, 'redis' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(ServerDatabaseProvisioner::class, function ($mock): void {
            $mock->shouldReceive('createOnServer')->once()->andReturn('mysql ok');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->set('new_db_name', 'myapp_db')
            ->set('new_db_engine', 'mysql')
            ->set('new_db_username', '')
            ->set('new_db_password', '')
            ->set('new_db_description', 'Staging')
            ->call('createDatabase')
            ->assertSet('generated_database_credentials.name', 'myapp_db')
            ->assertSet('generated_database_credentials.password_generated', true)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('server_databases', [
            'server_id' => $server->id,
            'name' => 'myapp_db',
            'description' => 'Staging',
        ]);
    }

    public function test_create_database_can_reuse_existing_mysql_user(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $existing = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'shared_db',
            'engine' => 'mysql',
            'username' => 'shared_user',
            'password' => 'shared-secret',
            'host' => '127.0.0.1',
        ]);

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => false, 'redis' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(ServerDatabaseProvisioner::class, function ($mock): void {
            $mock->shouldReceive('createMysqlDatabaseForExistingUser')->once()->andReturn('grant ok');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->set('new_db_name', 'reports_db')
            ->set('new_db_engine', 'mysql')
            ->set('new_db_user_mode', 'existing')
            ->set('new_db_existing_user_reference', 'primary:'.$existing->id)
            ->call('createDatabase')
            ->assertHasNoErrors()
            ->assertSet('generated_database_credentials.username', 'shared_user')
            ->assertSet('generated_database_credentials.password_generated', false);

        $this->assertDatabaseHas('server_databases', [
            'server_id' => $server->id,
            'name' => 'reports_db',
            'engine' => 'mysql',
            'username' => 'shared_user',
        ]);
    }

    public function test_delete_database_removes_record(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => false, 'redis' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $db = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'x',
            'engine' => 'mysql',
            'username' => 'u',
            'password' => 'secret',
            'host' => '127.0.0.1',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->call('deleteDatabase', $db->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('server_databases', ['id' => $db->id]);
    }

    public function test_delete_database_can_be_confirmed_through_modal_state(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => false, 'redis' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $db = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'confirm_me',
            'engine' => 'mysql',
            'username' => 'u',
            'password' => 'secret',
            'host' => '127.0.0.1',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->call(
                'openConfirmActionModal',
                'deleteDatabase',
                [$db->id],
                'Remove database from Dply',
                'Remove this entry from Dply only? The database will stay on the server.',
                'Remove from Dply',
                true
            )
            ->assertSet('showConfirmActionModal', true)
            ->assertSet('confirmActionModalMethod', 'deleteDatabase')
            ->call('confirmActionModal');

        $this->assertDatabaseMissing('server_databases', ['id' => $db->id]);
    }

    public function test_page_render_shows_flash_error_when_capability_probe_ssh_fails(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')
                ->andThrow(new \RuntimeException('SSH connection failed for server: bright-meadow'));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->assertSee('Dply could not connect to the server to check database engines.')
            ->assertSee('The server is not accepting Dply\'s SSH login right now for root@');
    }

    public function test_databases_page_uses_basics_first_layout(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'app_db',
            'engine' => 'mysql',
            'username' => 'app_user',
            'password' => 'secret',
            'host' => '127.0.0.1',
        ]);

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => false, 'redis' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->assertSet('workspace_tab', 'databases')
            ->assertSee('Create and connect to databases')
            ->assertSee('Basics')
            ->assertSee('Advanced')
            ->assertSee('See credentials')
            ->assertSee('Connection URL')
            ->assertSee('Advanced options')
            ->assertDontSee('Redis (redis-cli)')
            ->assertDontSee('Import SQL')
            ->assertDontSee('Export SQL (queued)')
            ->assertDontSee('PostgreSQL superuser')
            ->call('setWorkspaceTab', 'advanced')
            ->assertSet('workspace_tab', 'advanced')
            ->assertSee('Synchronize databases')
            ->assertSee('Recheck engines')
            ->assertSee('SSH database admin credentials')
            ->assertSee('Dply vs server drift')
            ->assertSee('Audit log')
            ->assertSee('Host tools')
            ->assertSee('Per-database advanced actions')
            ->assertSee('Database activity notifications')
            ->assertSee('Remove from Dply')
            ->assertSee('Drop on server')
            ->assertDontSee('Redis (redis-cli)')
            ->assertDontSee('Import SQL')
            ->assertDontSee('Export SQL (queued)')
            ->assertDontSee('PostgreSQL superuser')
            ->assertDontSee('PostgreSQL password')
            ->assertDontSee('Use sudo -u postgres');
    }

    public function test_user_can_save_database_notification_routing(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        NotificationChannel::factory()->forUser($user)->create([
            'type' => NotificationChannel::TYPE_EMAIL,
            'label' => 'Ops email',
            'config' => ['email' => 'ops@example.com'],
        ]);

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => false, 'redis' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $channel = NotificationChannel::query()->firstOrFail();

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->set('workspace_tab', 'advanced')
            ->set("databaseAlertMatrix.{$channel->id}.created", true)
            ->set("databaseAlertMatrix.{$channel->id}.removed", true)
            ->call('saveDatabaseAlertPreferences')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('notification_subscriptions', [
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Server::class,
            'subscribable_id' => $server->id,
            'event_key' => ServerDatabaseNotificationKeys::eventKey('created'),
        ]);

        $this->assertDatabaseHas('notification_subscriptions', [
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Server::class,
            'subscribable_id' => $server->id,
            'event_key' => ServerDatabaseNotificationKeys::eventKey('removed'),
        ]);
    }

    public function test_create_credential_share_opens_copy_modal_with_share_url(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'app_db',
            'engine' => 'mysql',
            'username' => 'app_user',
            'password' => 'secret',
            'host' => '127.0.0.1',
        ]);

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => false, 'redis' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $db = $server->serverDatabases()->firstOrFail();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->set('workspace_tab', 'advanced')
            ->set('share_target_db_id', $db->id)
            ->set('share_expires_hours', 24)
            ->set('share_max_views', 5)
            ->call('createCredentialShare')
            ->assertHasNoErrors()
            ->assertSet('share_link_modal_db_name', 'app_db');

        $share = ServerDatabaseCredentialShare::query()->firstOrFail();
        $component->assertSet('share_link_modal_url', route('database-credential-shares.show', ['token' => $share->token]));
    }
}
