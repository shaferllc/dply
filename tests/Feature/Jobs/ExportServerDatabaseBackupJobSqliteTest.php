<?php

namespace Tests\Feature\Jobs;

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
use Tests\TestCase;

class ExportServerDatabaseBackupJobSqliteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Server, 2: ServerDatabase}
     */
    protected function makeSqliteSetup(): array
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

    public function test_sqlite_backup_writes_db_file_and_completes(): void
    {
        Storage::fake('local');

        [$user, $server, $db] = $this->makeSqliteSetup();
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
        $this->assertSame(ServerDatabaseBackup::STATUS_COMPLETED, $backup->status);
        $this->assertSame(strlen($payload), $backup->bytes);
        $this->assertSame('database-backups/'.$server->id.'/'.$backup->id.'.db', $backup->disk_path);
        Storage::disk('local')->assertExists($backup->disk_path);
        $this->assertSame($payload, Storage::disk('local')->get($backup->disk_path));
    }

    public function test_backup_lands_on_configured_disk(): void
    {
        Storage::fake('public');
        config(['server_database.backup_disk' => 'public']);

        [$user, $server, $db] = $this->makeSqliteSetup();

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
    }

    public function test_retention_prunes_older_backups(): void
    {
        Storage::fake('local');
        config(['server_database.backup_retention_per_database' => 2]);

        [$user, $server, $db] = $this->makeSqliteSetup();

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
        $this->assertNull($backups[0]->fresh());
        Storage::disk('local')->assertMissing('database-backups/'.$server->id.'/'.$backups[0]->id.'.db');

        // The two newest survive.
        $this->assertNotNull($backups[1]->fresh());
        $this->assertNotNull($backups[2]->fresh());
        Storage::disk('local')->assertExists('database-backups/'.$server->id.'/'.$backups[1]->id.'.db');
        Storage::disk('local')->assertExists('database-backups/'.$server->id.'/'.$backups[2]->id.'.db');
    }
}
