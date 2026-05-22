<?php

namespace Tests\Feature\Jobs\ExportServerDatabaseBackupJobSqliteTest;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\User;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseRemoteExec;
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

test('sqlite backup writes db file and completes', function () {
    Storage::fake('local');

    [$user, $server, $db] = makeSqliteSetup();
    $payload = "SQLite format 3\0fake-binary-payload";

    $this->mock(ServerDatabaseRemoteExec::class, function ($mock) use ($payload): void {
        $mock->shouldReceive('sqliteBackup')->once()->andReturn($payload);
        $mock->shouldNotReceive('mysqldump');
        $mock->shouldNotReceive('pgDump');
    });

    $backup = ServerDatabaseBackup::query()->create([
        'server_database_id' => $db->id,
        'user_id' => $user->id,
        'status' => ServerDatabaseBackup::STATUS_PENDING,
    ]);

    (new ExportServerDatabaseBackupJob($backup->id))
        ->handle(app(ServerDatabaseRemoteExec::class), app(ServerDatabaseAuditLogger::class));

    $backup->refresh();
    expect($backup->status)->toBe(ServerDatabaseBackup::STATUS_COMPLETED);
    expect($backup->bytes)->toBe(strlen($payload));
    expect($backup->disk_path)->toBe('database-backups/'.$server->id.'/'.$backup->id.'.db');
    Storage::disk('local')->assertExists($backup->disk_path);
    expect(Storage::disk('local')->get($backup->disk_path))->toBe($payload);
});

test('backup lands on configured disk', function () {
    Storage::fake('public');
    config(['server_database.backup_disk' => 'public']);

    [$user, $server, $db] = makeSqliteSetup();

    $this->mock(ServerDatabaseRemoteExec::class, function ($mock): void {
        $mock->shouldReceive('sqliteBackup')->once()->andReturn('payload');
    });

    $backup = ServerDatabaseBackup::query()->create([
        'server_database_id' => $db->id,
        'user_id' => $user->id,
        'status' => ServerDatabaseBackup::STATUS_PENDING,
    ]);

    (new ExportServerDatabaseBackupJob($backup->id))
        ->handle(app(ServerDatabaseRemoteExec::class), app(ServerDatabaseAuditLogger::class));

    Storage::disk('public')->assertExists('database-backups/'.$server->id.'/'.$backup->id.'.db');
});

test('retention prunes older backups', function () {
    Storage::fake('local');
    config(['server_database.backup_retention_per_database' => 2]);

    [$user, $server, $db] = makeSqliteSetup();

    $this->mock(ServerDatabaseRemoteExec::class, function ($mock): void {
        $mock->shouldReceive('sqliteBackup')->andReturn('payload');
    });

    // Three sequential backups; retention=2 → after the third one, the
    // oldest completed row + file should be gone.
    $backups = [];
    foreach (range(1, 3) as $i) {
        $b = ServerDatabaseBackup::query()->create([
            'server_database_id' => $db->id,
            'user_id' => $user->id,
            'status' => ServerDatabaseBackup::STATUS_PENDING,
            'created_at' => now()->addSeconds($i),
        ]);
        (new ExportServerDatabaseBackupJob($b->id))
            ->handle(app(ServerDatabaseRemoteExec::class), app(ServerDatabaseAuditLogger::class));
        $backups[] = $b->fresh();
    }

    // The first one should be pruned.
    expect($backups[0]->fresh())->toBeNull();
    Storage::disk('local')->assertMissing('database-backups/'.$server->id.'/'.$backups[0]->id.'.db');

    // The two newest survive.
    expect($backups[1]->fresh())->not->toBeNull();
    expect($backups[2]->fresh())->not->toBeNull();
    Storage::disk('local')->assertExists('database-backups/'.$server->id.'/'.$backups[1]->id.'.db');
    Storage::disk('local')->assertExists('database-backups/'.$server->id.'/'.$backups[2]->id.'.db');
});
