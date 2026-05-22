<?php


namespace Tests\Feature\WorkspaceDatabasesTest;
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
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function actingOwnerWithServer(): array
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

test('create database with optional credentials calls provisioner', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('create database can reuse existing mysql user', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('new db name auto formats on type', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('create sqlite database uses canonical path', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('create database blocks engine when not installed', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('delete database removes record', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('delete database can be confirmed through modal state', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('databases page uses basics first layout', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('create credential share opens copy modal with share url', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('save database edit updates metadata and records audit', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('save database edit moves sqlite file and persists new path', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('run sqlite sql executes and records output', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('run sqlite sql refuses non sqlite database', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('install database engine dispatches job and creates row', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

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
});

test('install database engine rejects unsupported engine', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
        $mock->shouldReceive('forServer')->andReturn(['mysql' => false, 'postgres' => false, 'sqlite' => false]);
        $mock->shouldReceive('forget')->zeroOrMoreTimes();
    });

    Livewire::actingAs($user)
        ->test(WorkspaceDatabases::class, ['server' => $server])
        ->call('installDatabaseEngine', 'oracle');

    Queue::assertNotPushed(InstallDatabaseEngineJob::class);
});

test('uninstall database engine dispatches job', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

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
});

test('stop and revert marks row failed and dispatches uninstall', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    $row = ServerDatabaseEngine::query()->create([
        'server_id' => $server->id,
        'engine' => 'mysql',
        'is_default' => true,
        'status' => ServerDatabaseEngine::STATUS_INSTALLING,
        'port' => 3306,
    ]);

    $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
        $mock->shouldReceive('forServer')->andReturn(['mysql' => false, 'postgres' => false, 'sqlite' => false]);
        $mock->shouldReceive('forget')->zeroOrMoreTimes();
    });

    Livewire::actingAs($user)
        ->test(WorkspaceDatabases::class, ['server' => $server])
        ->call('stopAndRevertDatabaseEngineInstall', 'mysql')
        ->assertHasNoErrors();

    $row->refresh();
    expect($row->status)->toBe(ServerDatabaseEngine::STATUS_FAILED);
    $this->assertStringContainsString('Stopped by operator', (string) $row->error_message);

    Queue::assertPushed(UninstallDatabaseEngineJob::class);
});

test('stop and revert refuses running engine', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    $row = ServerDatabaseEngine::query()->create([
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
        ->call('stopAndRevertDatabaseEngineInstall', 'postgres');

    expect($row->fresh()->status)->toBe(ServerDatabaseEngine::STATUS_RUNNING);
    Queue::assertNotPushed(UninstallDatabaseEngineJob::class);
});