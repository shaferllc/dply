<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneBackupsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(): Server
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        return Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
    }

    public function test_prunes_database_backups_older_than_retention_days(): void
    {
        Storage::fake('local');
        config(['server_database.run_retention_days' => 30]);
        config(['server_database.backup_disk' => 'local']);

        $server = $this->makeServer();
        $database = $server->serverDatabases()->create([
            'name' => 'app',
            'engine' => 'mysql',
            'username' => '',
            'password' => '',
        ]);

        Storage::disk('local')->put('old.sql', 'data');
        Storage::disk('local')->put('new.sql', 'data');

        $old = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'status' => 'completed',
            'disk_path' => 'old.sql',
        ]);
        $old->created_at = now()->subDays(60);
        $old->save();

        $fresh = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'status' => 'completed',
            'disk_path' => 'new.sql',
        ]);

        $this->artisan('dply:prune-backups')->assertSuccessful();

        $this->assertNull(ServerDatabaseBackup::find($old->id));
        $this->assertFalse(Storage::disk('local')->exists('old.sql'));
        $this->assertNotNull(ServerDatabaseBackup::find($fresh->id));
        $this->assertTrue(Storage::disk('local')->exists('new.sql'));
    }

    public function test_prunes_site_file_backups_older_than_retention_days(): void
    {
        Storage::fake('local');
        config(['site_file_backup.run_retention_days' => 30]);

        $server = $this->makeServer();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $server->user_id,
            'organization_id' => $server->organization_id,
        ]);

        Storage::disk('local')->put('old.tar.gz', 'data');
        $old = SiteFileBackup::create([
            'site_id' => $site->id,
            'status' => 'completed',
            'disk_path' => 'old.tar.gz',
        ]);
        $old->created_at = now()->subDays(60);
        $old->save();

        $this->artisan('dply:prune-backups')->assertSuccessful();

        $this->assertNull(SiteFileBackup::find($old->id));
        $this->assertFalse(Storage::disk('local')->exists('old.tar.gz'));
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        Storage::fake('local');
        config(['server_database.run_retention_days' => 30]);

        $server = $this->makeServer();
        $database = $server->serverDatabases()->create([
            'name' => 'app',
            'engine' => 'mysql',
            'username' => '',
            'password' => '',
        ]);
        Storage::disk('local')->put('old.sql', 'data');
        $old = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'status' => 'completed',
            'disk_path' => 'old.sql',
        ]);
        $old->created_at = now()->subDays(60);
        $old->save();

        $this->artisan('dply:prune-backups --dry-run')->assertSuccessful();

        $this->assertNotNull(ServerDatabaseBackup::find($old->id));
        $this->assertTrue(Storage::disk('local')->exists('old.sql'));
    }

    public function test_retention_floor_of_seven_days_is_enforced(): void
    {
        Storage::fake('local');
        config(['server_database.run_retention_days' => 1]); // user tries to set 1 day
        config(['site_file_backup.run_retention_days' => 1]);

        $server = $this->makeServer();
        $database = $server->serverDatabases()->create([
            'name' => 'app',
            'engine' => 'mysql',
            'username' => '',
            'password' => '',
        ]);
        $threeDays = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'status' => 'completed',
        ]);
        $threeDays->created_at = now()->subDays(3);
        $threeDays->save();

        $this->artisan('dply:prune-backups')->assertSuccessful();

        // Floor of 7 days protects this 3-day-old row from being pruned.
        $this->assertNotNull(ServerDatabaseBackup::find($threeDays->id));
    }
}
