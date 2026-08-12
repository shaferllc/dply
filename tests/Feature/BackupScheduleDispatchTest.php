<?php

namespace Tests\Feature\BackupScheduleDispatchTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\BackupSchedule;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\User;
use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * The engine, not the UI: until dply:dispatch-due-backups existed, a schedule
 * could read "Active · Every 15 minutes · Last run: Never" forever, because the
 * system_managed cron row it minted was excluded from every crontab and nothing
 * on the control plane iterated the schedules. These tests are what stop that
 * from silently returning. See docs/adr/backups-as-a-product.md, decision 14.
 */
function scheduleForDatabase(string $cron, ?string $lastRunAt = null): BackupSchedule
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $database = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'app_db',
        'engine' => 'mysql',
        'username' => 'app',
        'password' => 'secret',
        'host' => '127.0.0.1',
    ]);

    return BackupSchedule::query()->create([
        'server_id' => $server->id,
        'target_type' => BackupSchedule::TARGET_DATABASE,
        'target_id' => $database->id,
        'cron_expression' => $cron,
        'is_active' => true,
        'last_run_at' => $lastRunAt,
    ]);
}

test('a due schedule that has never run fires and records last_run_at', function () {
    Queue::fake();
    $this->travelTo(now()->startOfHour());

    $schedule = scheduleForDatabase('0 * * * *');

    $this->artisan('dply:dispatch-due-backups')->assertSuccessful();

    Queue::assertPushed(ExportServerDatabaseBackupJob::class);
    expect($schedule->fresh()->last_run_at)->not->toBeNull();
    expect(ServerDatabaseBackup::query()->count())->toBe(1);
});

test('a schedule already run for its current occurrence does not fire again', function () {
    Queue::fake();
    $this->travelTo(now()->startOfHour());

    scheduleForDatabase('0 * * * *', now()->toDateTimeString());

    $this->artisan('dply:dispatch-due-backups')->assertSuccessful();

    Queue::assertNotPushed(ExportServerDatabaseBackupJob::class);
    expect(ServerDatabaseBackup::query()->count())->toBe(0);
});

test('ticking every minute for an hour fires an hourly schedule exactly once', function () {
    Queue::fake();
    $this->travelTo(now()->startOfHour());

    scheduleForDatabase('0 * * * *');

    for ($minute = 0; $minute < 60; $minute++) {
        $this->artisan('dply:dispatch-due-backups')->assertSuccessful();
        $this->travel(1)->minutes();
    }

    expect(ServerDatabaseBackup::query()->count())->toBe(1);
});

test('occurrences missed beyond the lookback window are abandoned, not replayed', function () {
    Queue::fake();
    // A schedule that has been "active" for a year without the engine running:
    // turning it on must not replay a year of nightly occurrences.
    $this->travelTo(now()->startOfDay()->addHours(11));

    scheduleForDatabase('0 3 * * *');

    $this->artisan('dply:dispatch-due-backups')->assertSuccessful();

    Queue::assertNotPushed(ExportServerDatabaseBackupJob::class);
    expect(ServerDatabaseBackup::query()->count())->toBe(0);
});

test('an inactive schedule never fires', function () {
    Queue::fake();
    $this->travelTo(now()->startOfHour());

    $schedule = scheduleForDatabase('0 * * * *');
    $schedule->update(['is_active' => false]);

    $this->artisan('dply:dispatch-due-backups')->assertSuccessful();

    Queue::assertNotPushed(ExportServerDatabaseBackupJob::class);
});

test('dry run reports the due schedule without dispatching it', function () {
    Queue::fake();
    $this->travelTo(now()->startOfHour());

    $schedule = scheduleForDatabase('0 * * * *');

    $this->artisan('dply:dispatch-due-backups --dry-run')->assertSuccessful();

    Queue::assertNotPushed(ExportServerDatabaseBackupJob::class);
    expect($schedule->fresh()->last_run_at)->toBeNull();
});

test('an unparseable cron expression is skipped rather than throwing', function () {
    Queue::fake();

    scheduleForDatabase('@reboot');

    $this->artisan('dply:dispatch-due-backups')->assertSuccessful();

    Queue::assertNotPushed(ExportServerDatabaseBackupJob::class);
});

test('the dispatcher and the retention prune are both on the control-plane schedule', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('dply:dispatch-due-backups')
        ->expectsOutputToContain('dply:prune-backups')
        ->assertSuccessful();
});
