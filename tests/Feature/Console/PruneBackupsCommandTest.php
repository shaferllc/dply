<?php

declare(strict_types=1);

namespace Tests\Feature\Console\PruneBackupsCommandTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeServer(): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
}
test('prunes database backups older than retention days', function () {
    Storage::fake('local');
    config(['server_database.run_retention_days' => 30]);
    config(['server_database.backup_disk' => 'local']);

    $server = makeServer();
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

    expect(ServerDatabaseBackup::find($old->id))->toBeNull();
    expect(Storage::disk('local')->exists('old.sql'))->toBeFalse();
    expect(ServerDatabaseBackup::find($fresh->id))->not->toBeNull();
    expect(Storage::disk('local')->exists('new.sql'))->toBeTrue();
});
test('prunes site file backups older than retention days', function () {
    Storage::fake('local');
    config(['site_file_backup.run_retention_days' => 30]);

    $server = makeServer();
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

    expect(SiteFileBackup::find($old->id))->toBeNull();
    expect(Storage::disk('local')->exists('old.tar.gz'))->toBeFalse();
});
test('dry run reports without deleting', function () {
    Storage::fake('local');
    config(['server_database.run_retention_days' => 30]);

    $server = makeServer();
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

    expect(ServerDatabaseBackup::find($old->id))->not->toBeNull();
    expect(Storage::disk('local')->exists('old.sql'))->toBeTrue();
});
test('retention floor of seven days is enforced', function () {
    Storage::fake('local');
    config(['server_database.run_retention_days' => 1]);
    // user tries to set 1 day
    config(['site_file_backup.run_retention_days' => 1]);

    $server = makeServer();
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
    expect(ServerDatabaseBackup::find($threeDays->id))->not->toBeNull();
});
