<?php

declare(strict_types=1);

namespace Tests\Feature\Console\RunBackupScheduleCommandTest;
use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
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
test('database schedule dispatches export database job', function () {
    Bus::fake();
    $server = makeServer();
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => ServerBackupSchedule::TARGET_DATABASE,
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
    ]);

    $this->artisan('dply:run-backup-schedule', ['schedule' => $schedule->id])
        ->assertSuccessful();

    Bus::assertDispatched(ExportServerDatabaseBackupJob::class);
    expect($schedule->fresh()->last_run_at)->not->toBeNull();
});
test('site files schedule dispatches export site files job', function () {
    Bus::fake();
    $server = makeServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $server->user_id,
        'organization_id' => $server->organization_id,
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => ServerBackupSchedule::TARGET_SITE_FILES,
        'target_id' => $site->id,
        'cron_expression' => '30 4 * * *',
        'is_active' => true,
    ]);

    $this->artisan('dply:run-backup-schedule', ['schedule' => $schedule->id])
        ->assertSuccessful();

    Bus::assertDispatched(ExportSiteFileBackupJob::class);
});
test('inactive schedule skips dispatch', function () {
    Bus::fake();
    $server = makeServer();
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => ServerBackupSchedule::TARGET_DATABASE,
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => false,
    ]);

    $this->artisan('dply:run-backup-schedule', ['schedule' => $schedule->id])
        ->assertSuccessful();

    Bus::assertNotDispatched(ExportServerDatabaseBackupJob::class);
    Bus::assertNotDispatched(ExportSiteFileBackupJob::class);
});
test('missing schedule fails cleanly', function () {
    $this->artisan('dply:run-backup-schedule', ['schedule' => '01nonexistent00000000000000'])
        ->assertFailed();
});
test('schedule auto pauses after three consecutive failures', function () {
    Bus::fake();
    $server = makeServer();
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);

    // Materialize a cron entry like the real schedule path does.
    $cronJob = ServerCronJob::create([
        'server_id' => $server->id,
        'cron_expression' => '0 3 * * *',
        'command' => 'php artisan dply:run-backup-schedule X',
        'user' => 'root',
        'enabled' => true,
        'system_managed' => true,
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => ServerBackupSchedule::TARGET_DATABASE,
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
        'server_cron_job_id' => $cronJob->id,
    ]);

    // Three failed backups for the target.
    for ($i = 0; $i < 3; $i++) {
        ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'user_id' => null,
            'status' => 'failed',
        ]);
    }

    $this->artisan('dply:run-backup-schedule', ['schedule' => $schedule->id])
        ->assertSuccessful();

    // Schedule + cron should be auto-paused, no new backup dispatched.
    expect($schedule->fresh()->is_active)->toBeFalse();
    expect($cronJob->fresh()->enabled)->toBeFalse();
    Bus::assertNotDispatched(ExportServerDatabaseBackupJob::class);
});
