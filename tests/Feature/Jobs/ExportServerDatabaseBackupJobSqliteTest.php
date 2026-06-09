<?php

namespace Tests\Feature\Jobs\ExportServerDatabaseBackupJobSqliteTest;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\User;
use App\Services\Servers\DatabaseBackupExporter;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Servers\ServerDatabaseRemoteExec;
use App\Support\Servers\DatabaseBackupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: ServerDatabase}
 */
function makeSqliteSetup(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ssh_user' => 'root',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $db = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'inventory',
        'engine' => 'sqlite',
        'username' => '',
        'password' => '',
        'host' => rtrim((string) config('server_database.sqlite_root', '/var/lib/dply/sqlite'), '/').'/'.$server->id.'/inventory.db',
    ]);

    return [$user, $server, $db];
}

test('sqlite backup is stored on the remote server by default', function () {
    [$user, $server, $db] = makeSqliteSetup();
    $payloadBytes = 128;

    $this->mock(ServerDatabaseProvisioner::class, function ($mock) use ($db): void {
        $mock->shouldReceive('resolvedSqlitePath')->andReturn($db->host);
    });

    $this->mock(ServerDatabaseRemoteExec::class, function ($mock) use ($payloadBytes): void {
        $mock->shouldReceive('sqliteBackupToPath')->once()->andReturn($payloadBytes);
        $mock->shouldReceive('pruneRemoteBackupTree')->once();
        $mock->shouldNotReceive('sqliteBackup');
    });

    $backup = ServerDatabaseBackup::query()->create([
        'server_database_id' => $db->id,
        'user_id' => $user->id,
        'status' => ServerDatabaseBackup::STATUS_PENDING,
        'storage_kind' => DatabaseBackupSettings::KIND_REMOTE_SERVER,
    ]);

    (new ExportServerDatabaseBackupJob($backup->id))
        ->handle(app(DatabaseBackupExporter::class), app(ServerDatabaseAuditLogger::class));

    $backup->refresh();
    expect($backup->status)->toBe(ServerDatabaseBackup::STATUS_COMPLETED);
    expect($backup->bytes)->toBe($payloadBytes);
    expect($backup->storage_kind)->toBe(DatabaseBackupSettings::KIND_REMOTE_SERVER);
    expect($backup->remote_path)->toContain('/database-backups/'.$server->id.'/'.$db->id.'/'.$backup->id.'.db');
    expect($backup->disk_path)->toBeNull();
});

test('control plane storage requires explicit config flag', function () {
    config(['server_database.allow_control_plane_storage' => true]);
    Storage::fake('local');

    [$user, $server, $db] = makeSqliteSetup();

    $this->mock(ServerDatabaseProvisioner::class, function ($mock) use ($db): void {
        $mock->shouldReceive('resolvedSqlitePath')->andReturn($db->host);
    });

    $this->mock(ServerDatabaseRemoteExec::class, function ($mock): void {
        $mock->shouldReceive('sqliteBackup')->once()->andReturn('payload');
    });

    $backup = ServerDatabaseBackup::query()->create([
        'server_database_id' => $db->id,
        'user_id' => $user->id,
        'status' => ServerDatabaseBackup::STATUS_PENDING,
        'storage_kind' => DatabaseBackupSettings::KIND_CONTROL_PLANE,
    ]);

    (new ExportServerDatabaseBackupJob($backup->id))
        ->handle(app(DatabaseBackupExporter::class), app(ServerDatabaseAuditLogger::class));

    $backup->refresh();
    expect($backup->disk_path)->toBe('database-backups/'.$server->id.'/'.$backup->id.'.db');
    Storage::disk('local')->assertExists($backup->disk_path);
});

test('retention prunes older remote backups', function () {
    [$user, $server, $db] = makeSqliteSetup();
    config(['server_database.backup_retention_per_database' => 2]);

    $this->mock(ServerDatabaseProvisioner::class, function ($mock) use ($db): void {
        $mock->shouldReceive('resolvedSqlitePath')->andReturn($db->host);
    });

    $this->mock(ServerDatabaseRemoteExec::class, function ($mock): void {
        $mock->shouldReceive('sqliteBackupToPath')->andReturn(64);
        $mock->shouldReceive('pruneRemoteBackupTree');
        $mock->shouldReceive('shellRunWithExit')->andReturn(['', 0]);
    });

    $backups = [];
    foreach (range(1, 3) as $i) {
        $b = ServerDatabaseBackup::query()->create([
            'server_database_id' => $db->id,
            'user_id' => $user->id,
            'status' => ServerDatabaseBackup::STATUS_PENDING,
            'storage_kind' => DatabaseBackupSettings::KIND_REMOTE_SERVER,
            'created_at' => now()->addSeconds($i),
        ]);
        (new ExportServerDatabaseBackupJob($b->id))
            ->handle(app(DatabaseBackupExporter::class), app(ServerDatabaseAuditLogger::class));
        $backups[] = $b->fresh();
    }

    expect($backups[0]->fresh())->toBeNull();
    expect($backups[1]->fresh())->not->toBeNull();
    expect($backups[2]->fresh())->not->toBeNull();
});
