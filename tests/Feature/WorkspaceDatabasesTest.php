<?php

namespace Tests\Feature;

use App\Jobs\InstallDatabaseEngineJob;
use App\Jobs\UninstallDatabaseEngineJob;
use App\Livewire\Servers\WorkspaceDatabases;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseCredentialShare;
use App\Models\ServerDatabaseEngine;
use App\Models\User;
use App\Services\Servers\ServerDatabaseHostCapabilities;
use App\Services\Servers\ServerDatabaseProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => true, 'sqlite' => true]);
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
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => true, 'sqlite' => true]);
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

    public function test_new_db_name_auto_formats_on_type(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => true, 'sqlite' => true]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->set('new_db_name', 'My  Test - DB.name!')
            ->assertSet('new_db_name', 'my_test_db_name')
            ->set('new_db_name', '___leading')
            ->assertSet('new_db_name', 'leading')
            ->set('new_db_name', 'trailing___')
            ->assertSet('new_db_name', 'trailing')
            ->set('new_db_name', 'testsddasdasdas ')
            ->assertSet('new_db_name', 'testsddasdasdas')
            ->set('new_db_name', str_repeat('a', 80))
            ->assertSet('new_db_name', str_repeat('a', 64));
    }

    public function test_create_sqlite_database_uses_canonical_path(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => false, 'postgres' => false, 'sqlite' => true]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(ServerDatabaseProvisioner::class, function ($mock): void {
            $mock->shouldReceive('createOnServer')->once()->andReturn('[dply] sqlite database ready');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->set('new_db_name', 'inventory')
            ->set('new_db_engine', 'sqlite')
            ->call('createDatabase')
            ->assertHasNoErrors();

        $expectedRoot = rtrim((string) config('server_database.sqlite_root', '/var/lib/dply/sqlite'), '/');

        $this->assertDatabaseHas('server_databases', [
            'server_id' => $server->id,
            'name' => 'inventory',
            'engine' => 'sqlite',
            'host' => $expectedRoot.'/'.$server->id.'/inventory.db',
        ]);
    }

    public function test_create_database_blocks_engine_when_not_installed(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        // No engines available — render()'s auto-correct can't promote
        // new_db_engine to a capable value, so the createDatabase guard
        // is the path under test.
        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => false, 'postgres' => false, 'sqlite' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(ServerDatabaseProvisioner::class, function ($mock): void {
            $mock->shouldNotReceive('createOnServer');
            $mock->shouldNotReceive('createMysqlDatabaseForExistingUser');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->set('new_db_name', 'should_fail')
            ->set('new_db_engine', 'mysql')
            ->call('createDatabase')
            ->assertHasErrors('new_db_engine');

        $this->assertDatabaseMissing('server_databases', [
            'server_id' => $server->id,
            'name' => 'should_fail',
        ]);
    }

    public function test_delete_database_removes_record(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

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
            $mock->shouldReceive('forServer')->andReturn(['mysql' => true, 'postgres' => true, 'sqlite' => true]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->assertSet('workspace_tab', 'databases')
            ->assertSee('Basics')
            ->assertSee('Advanced')
            ->assertSee('Notifications')
            ->assertSee('MySQL')
            ->assertSee('See credentials')
            ->assertSee('Connection URL')
            ->assertSee('Advanced MySQL options')
            ->assertDontSee('Redis (redis-cli)')
            ->assertDontSee('Import SQL')
            ->assertDontSee('Export SQL (queued)')
            ->call('setWorkspaceTab', 'mysql')
            ->assertSet('workspace_tab', 'mysql')
            ->assertSee('MySQL admin credentials')
            ->assertSee('MySQL databases')
            ->assertSee('app_db')
            ->assertSee('Connection snippet')
            ->assertSee('Share credentials')
            ->assertSee('Destructive actions')
            ->assertSee('MySQL drift')
            ->assertSee('Remove from Dply')
            ->assertSee('Drop on server')
            ->call('setWorkspaceTab', 'advanced')
            ->assertSet('workspace_tab', 'advanced')
            ->assertSee('Synchronize databases')
            ->assertSee('Recheck engines')
            ->assertSee('Audit log')
            ->assertDontSee('Database activity notifications')
            ->assertDontSee('Redis (redis-cli)')
            ->assertDontSee('Import SQL')
            ->assertDontSee('Export SQL (queued)');
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

        $db = $server->serverDatabases()->firstOrFail();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->set('workspace_tab', 'mysql')
            ->set('share_target_db_id', $db->id)
            ->set('share_expires_hours', 24)
            ->set('share_max_views', 5)
            ->call('createCredentialShare')
            ->assertHasNoErrors()
            ->assertSet('share_link_modal_db_name', 'app_db');

        $share = ServerDatabaseCredentialShare::query()->firstOrFail();
        $component->assertSet('share_link_modal_url', route('database-credential-shares.show', ['token' => $share->token]));
    }

    public function test_save_database_edit_updates_metadata_and_records_audit(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $db = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'app_db',
            'engine' => 'mysql',
            'username' => 'app_user',
            'password' => 'secret',
            'host' => '127.0.0.1',
            'description' => 'Old description',
            'mysql_charset' => 'utf8mb4',
            'mysql_collation' => 'utf8mb4_general_ci',
        ]);

        // MySQL edit must NOT call any host-side method — charset edits
        // are local-only metadata that apply to the next create.
        $this->mock(ServerDatabaseProvisioner::class, function ($mock): void {
            $mock->shouldNotReceive('relocateSqliteFile');
            $mock->shouldNotReceive('createOnServer');
            $mock->shouldNotReceive('dropFromServer');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->call('openEditDatabaseModal', $db->id)
            ->assertSet('editing_db_id', $db->id)
            ->assertSet('edit_description', 'Old description')
            ->set('edit_description', 'New description')
            ->set('edit_mysql_collation', 'utf8mb4_unicode_ci')
            ->call('saveDatabaseEdit')
            ->assertHasNoErrors()
            ->assertSet('editing_db_id', null);

        $this->assertDatabaseHas('server_databases', [
            'id' => $db->id,
            'description' => 'New description',
            'mysql_collation' => 'utf8mb4_unicode_ci',
        ]);

        $this->assertDatabaseHas('server_database_audit_events', [
            'server_id' => $server->id,
            'event' => 'database_updated',
        ]);
    }

    public function test_save_database_edit_moves_sqlite_file_and_persists_new_path(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $db = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'reports',
            'engine' => 'sqlite',
            'username' => '',
            'password' => '',
            'host' => '/var/lib/dply/sqlite/reports.db',
            'description' => '',
        ]);

        $this->mock(ServerDatabaseProvisioner::class, function ($mock): void {
            $mock->shouldReceive('relocateSqliteFile')
                ->once()
                ->withArgs(function ($db, string $path): bool {
                    return $path === '/var/lib/dply/sqlite/archive/reports.db';
                })
                ->andReturn('[dply] moved');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->call('openEditDatabaseModal', $db->id)
            ->set('edit_sqlite_path', '/var/lib/dply/sqlite/archive/reports.db')
            ->call('saveDatabaseEdit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('server_databases', [
            'id' => $db->id,
            'host' => '/var/lib/dply/sqlite/archive/reports.db',
        ]);
    }

    public function test_run_sqlite_sql_executes_and_records_output(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $db = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'store',
            'engine' => 'sqlite',
            'username' => '',
            'password' => '',
            'host' => '/var/lib/dply/sqlite/store.db',
        ]);

        $this->mock(ServerDatabaseProvisioner::class, function ($mock): void {
            $mock->shouldReceive('executeSqliteSql')
                ->once()
                ->andReturn("id  name\n--  ----\n1   foo");
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->call('openSqliteConsoleModal', $db->id)
            ->assertSet('sqlite_console_db_id', $db->id)
            ->set('sqlite_console_sql', 'SELECT * FROM rows;')
            ->call('runSqliteSql')
            ->assertHasNoErrors()
            ->assertSet('sqlite_console_exit_code', 0)
            ->assertSet('sqlite_console_output', "id  name\n--  ----\n1   foo");

        $this->assertDatabaseHas('server_database_audit_events', [
            'server_id' => $server->id,
            'event' => 'import_ran',
        ]);
    }

    public function test_run_sqlite_sql_refuses_non_sqlite_database(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $mysqlDb = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'app_db',
            'engine' => 'mysql',
            'username' => 'app_user',
            'password' => 'secret',
            'host' => '127.0.0.1',
        ]);

        $this->mock(ServerDatabaseProvisioner::class, function ($mock): void {
            $mock->shouldNotReceive('executeSqliteSql');
        });

        // openSqliteConsoleModal silently no-ops on non-sqlite engines
        // — sqlite_console_db_id stays null, so the modal never opens.
        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->call('openSqliteConsoleModal', $mysqlDb->id)
            ->assertSet('sqlite_console_db_id', null);
    }

    public function test_install_database_engine_dispatches_job_and_creates_row(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => false, 'postgres' => false, 'sqlite' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->call('installDatabaseEngine', 'mysql')
            ->assertHasNoErrors()
            ->assertSet('workspace_tab', 'mysql');

        Queue::assertPushed(InstallDatabaseEngineJob::class);
        $this->assertDatabaseHas('server_database_engines', [
            'server_id' => $server->id,
            'engine' => 'mysql',
            'status' => ServerDatabaseEngine::STATUS_PENDING,
        ]);
    }

    public function test_install_database_engine_rejects_unsupported_engine(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => false, 'postgres' => false, 'sqlite' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->call('installDatabaseEngine', 'oracle');

        Queue::assertNotPushed(InstallDatabaseEngineJob::class);
    }

    public function test_uninstall_database_engine_dispatches_job(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        ServerDatabaseEngine::query()->create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'is_default' => true,
            'status' => ServerDatabaseEngine::STATUS_RUNNING,
            'port' => 5432,
        ]);

        $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['mysql' => false, 'postgres' => true, 'sqlite' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceDatabases::class, ['server' => $server])
            ->call('uninstallDatabaseEngine', 'postgres')
            ->assertHasNoErrors();

        Queue::assertPushed(UninstallDatabaseEngineJob::class);
    }
}
