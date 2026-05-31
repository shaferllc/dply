<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\BackgroundWorkspacePagesTest;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Servers\WorkspaceActivity;
use App\Livewire\Servers\WorkspaceBackups;
use App\Livewire\Servers\WorkspaceDaemons;
use App\Livewire\Servers\WorkspaceOverview;
use App\Livewire\Servers\WorkspaceSchedule;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabaseBackup;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Models\SupervisorProgram;
use App\Models\SupervisorProgramAuditLog;
use App\Models\User;
use App\Notifications\BackupFailureNotification;
use App\Services\Servers\PreflightSchedulerOnSite;
use App\Services\Servers\SupervisorProvisioner;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

usesFeatures('workspace.schedule', 'workspace.activity', 'workspace.backups');

function actingOrgUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
function readyServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);
}
test('daemons page lists queue and custom program types', function () {
    $user = actingOrgUser();
    $server = readyServer($user);

    $queue = SupervisorProgram::create([
        'server_id' => $server->id,
        'slug' => 'app-queue',
        'program_type' => 'queue',
        'command' => 'php artisan queue:work',
        'directory' => '/home/dply/app/current',
        'user' => 'dply',
        'numprocs' => 2,
        'is_active' => true,
    ]);
    $custom = SupervisorProgram::create([
        'server_id' => $server->id,
        'slug' => 'custom-thing',
        'program_type' => 'custom',
        'command' => '/usr/local/bin/thing',
        'directory' => '/srv',
        'user' => 'root',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server]);

    $component->assertOk();
    $programs = $component->viewData('filteredSupervisorPrograms');
    expect($programs->pluck('id')->all())->toEqualCanonicalizing([$queue->id, $custom->id]);
});
test('schedule page renders detected unmonitored card for scheduler shaped cron', function () {
    // Rewritten for the milestone-2A scheduler control plane: the page no
    // longer surfaces a raw cron-entries collection — it pivots into
    // per-site cards with state. A scheduler-shaped cron on a site
    // without a wrapper-managed heartbeat shows up as
    // `detected_unmonitored`; an unrelated cron contributes nothing.
    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    ServerCronJob::create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'cron_expression' => '* * * * *',
        'command' => 'cd /home/dply/app && php artisan schedule:run',
        'user' => 'dply',
        'enabled' => true,
    ]);
    ServerCronJob::create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'cron_expression' => '0 3 * * *',
        'command' => 'rsync /var/log /backup',
        'user' => 'dply',
        'enabled' => true,
    ]);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceSchedule::class, ['server' => $server]);

    $component->assertOk();

    $cards = $component->viewData('cards');
    expect($cards)->toHaveCount(1, 'Only the scheduler-shaped cron produces a card; unrelated cron is ignored.');
    expect($cards[0]['state'])->toBe('detected_unmonitored');
    expect($cards[0]['kind'])->toBe('laravel');
    expect($cards[0]['site']->id)->toBe($site->id);

    $stats = $component->viewData('stats');
    expect($stats['unmonitored'])->toBe(1);
    expect($stats['tracked_total'])->toBe(0);
});
test('backups page run now dispatches database export job', function () {
    Bus::fake();

    $user = actingOrgUser();
    $server = readyServer($user);

    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->set('run_database_id', $database->id)
        ->call('runDatabaseBackup')
        ->assertHasNoErrors();

    Bus::assertDispatched(ExportServerDatabaseBackupJob::class);
});
test('backups page add schedule creates backup schedule and managed cron', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'postgres',
        'username' => '',
        'password' => '',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->set('new_target_type', 'database')
        ->set('new_target_id', $database->id)
        ->set('new_cron_expression', '15 4 * * *')
        ->call('addSchedule')
        ->assertHasNoErrors();

    $schedule = ServerBackupSchedule::query()->where('server_id', $server->id)->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->target_type)->toBe('database');
    expect($schedule->target_id)->toBe($database->id);
    expect($schedule->cron_expression)->toBe('15 4 * * *');
    expect($schedule->server_cron_job_id)->not->toBeNull();

    $cronJob = ServerCronJob::find($schedule->server_cron_job_id);
    expect($cronJob)->not->toBeNull();
    $this->assertStringContainsString('dply:run-backup-schedule '.$schedule->id, $cronJob->command);
    expect($cronJob->system_managed)->toBeTrue();
});
test('backups delete schedule removes cron entry', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->set('new_target_type', 'site_files')
        ->set('new_target_id', $site->id)
        ->set('new_cron_expression', '0 5 * * *')
        ->call('addSchedule');

    $schedule = ServerBackupSchedule::query()->where('server_id', $server->id)->first();
    $cronId = $schedule->server_cron_job_id;
    expect($cronId)->not->toBeNull();

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('deleteSchedule', $schedule->id);

    expect(ServerBackupSchedule::find($schedule->id))->toBeNull();
    expect(ServerCronJob::find($cronId))->toBeNull();
});
test('schedule route renders via http', function () {
    // Stable markers post milestone-2A rewrite: page heading + the
    // description copy that ships in every render regardless of whether
    // any sites exist or any schedulers are configured. Per-site cards
    // and the Enable form only render when there are sites to act on.
    $user = actingOrgUser();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.schedule', $server))
        ->assertOk()
        ->assertSee('Schedule', false)
        ->assertSee('Framework schedulers running on this server', false);
});
test('backups route renders via http', function () {
    $user = actingOrgUser();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->set('backups_workspace_tab', 'history')
        ->assertSee('Recent database backups', false);
});
test('legacy site queue workers route redirects to site daemons', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    $this->actingAs($user)
        ->get(route('sites.queue-workers', ['server' => $server, 'site' => $site]))
        ->assertRedirect(route('sites.daemons', ['server' => $server, 'site' => $site]));
});
/** Helper: install a stack_summary artifact so ServerInstalledServices stops failing-open. */
function setExpectedServices(Server $server, array $services): void
{
    $run = ServerProvisionRun::create([
        'server_id' => $server->id,
        'attempt' => 1,
        'status' => 'completed',
    ]);
    ServerProvisionArtifact::create([
        'server_provision_run_id' => $run->id,
        'type' => 'stack_summary',
        'key' => 'stack_summary',
        'label' => 'stack summary',
        'metadata' => ['expected_services' => $services],
    ]);
    ServerInstalledServices::flushCaches();
}
test('backups route 404s when no database tag present', function () {
    $user = actingOrgUser();
    $server = readyServer($user);

    // Stack summary explicitly says no database service is installed — gating should fire.
    setExpectedServices($server, ['nginx', 'php-fpm']);

    $this->actingAs($user)
        ->get(route('servers.backups', $server))
        ->assertNotFound();
});
test('site daemons route shows install banner when supervisor missing', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_MISSING]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    $this->actingAs($user)
        ->get(route('sites.daemons', ['server' => $server, 'site' => $site]))
        ->assertOk()
        ->assertSee('Supervisor is not installed', false)
        ->assertSee('Install Supervisor', false);
});
test('backups route denied for user outside organization', function () {
    $owner = actingOrgUser();
    $server = readyServer($owner);

    // Outsider has their own org and isn't a member of $owner's org.
    $outsider = User::factory()->create();
    $outsiderOrg = Organization::factory()->create();
    $outsiderOrg->users()->attach($outsider->id, ['role' => 'owner']);
    session(['current_organization_id' => $outsiderOrg->id]);

    // mount() authorizes 'view' on the server — outsider can't even render.
    $this->actingAs($outsider)
        ->get(route('servers.backups', $server))
        ->assertForbidden();
});
test('download database backup returns file response when completed', function () {
    Storage::fake('local');
    config(['server_database.allow_control_plane_storage' => true]);

    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    Storage::disk('local')->put('backup.sql', "-- dump --\n");
    $backup = ServerDatabaseBackup::create([
        'server_database_id' => $database->id,
        'user_id' => $user->id,
        'status' => ServerDatabaseBackup::STATUS_COMPLETED,
        'storage_kind' => 'control_plane',
        'disk_path' => 'backup.sql',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('downloadDatabaseBackup', $backup->id)
        ->assertSuccessful();
});
test('download database backup short circuits when pending', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $backup = ServerDatabaseBackup::create([
        'server_database_id' => $database->id,
        'user_id' => $user->id,
        'status' => ServerDatabaseBackup::STATUS_PENDING,
    ]);

    // Pending → toast error, no download response.
    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('downloadDatabaseBackup', $backup->id);

    expect(ServerDatabaseBackup::find($backup->id)->disk_path)->toBeNull();
});
test('empty state explainer offers inline add destination', function () {
    // With org-scoped destinations and the inline "Add destination" modal,
    // the empty-state copy invites the operator to add one without leaving
    // the page rather than linking back to the profile settings screen.
    $user = actingOrgUser();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.backups', $server))
        ->assertOk()
        ->assertSee('add one now', false)
        ->assertSee('openDestinationModal', false);
});
test('toggle schedule pauses and resumes managed cron', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
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
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
        'server_cron_job_id' => $cronJob->id,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('toggleSchedule', $schedule->id);

    expect(ServerBackupSchedule::find($schedule->id)->is_active)->toBeFalse();
    expect(ServerCronJob::find($cronJob->id)->enabled)->toBeFalse();

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('toggleSchedule', $schedule->id);

    expect(ServerBackupSchedule::find($schedule->id)->is_active)->toBeTrue();
    expect(ServerCronJob::find($cronJob->id)->enabled)->toBeTrue();
});
test('delete database backup removes row and disk file', function () {
    Storage::fake('local');

    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    Storage::disk('local')->put('to-delete.sql', 'data');
    $backup = ServerDatabaseBackup::create([
        'server_database_id' => $database->id,
        'user_id' => $user->id,
        'status' => ServerDatabaseBackup::STATUS_COMPLETED,
        'storage_kind' => 'control_plane',
        'disk_path' => 'to-delete.sql',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('deleteDatabaseBackup', $backup->id);

    expect(ServerDatabaseBackup::find($backup->id))->toBeNull();
    expect(Storage::disk('local')->exists('to-delete.sql'))->toBeFalse();
});
test('delete file backup removes row and disk file', function () {
    Storage::fake('local');

    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);
    Storage::disk('local')->put('site-files.tar.gz', 'data');
    $backup = SiteFileBackup::create([
        'site_id' => $site->id,
        'user_id' => $user->id,
        'status' => SiteFileBackup::STATUS_COMPLETED,
        'disk_path' => 'site-files.tar.gz',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('deleteFileBackup', $backup->id);

    expect(SiteFileBackup::find($backup->id))->toBeNull();
    expect(Storage::disk('local')->exists('site-files.tar.gz'))->toBeFalse();
});
test('backups stats count completed and failed in last 7 days', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);

    // 2 completed in window, 1 failed in window, 1 completed older (excluded).
    ServerDatabaseBackup::create(['server_database_id' => $database->id, 'status' => 'completed', 'bytes' => 1024]);
    ServerDatabaseBackup::create(['server_database_id' => $database->id, 'status' => 'completed', 'bytes' => 2048]);
    ServerDatabaseBackup::create(['server_database_id' => $database->id, 'status' => 'failed']);
    $old = ServerDatabaseBackup::create(['server_database_id' => $database->id, 'status' => 'completed', 'bytes' => 999]);
    $old->created_at = now()->subDays(30);
    $old->save();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server]);

    $stats = $component->viewData('stats');
    expect($stats['db_completed_7d'])->toBe(2);
    expect($stats['db_failed_7d'])->toBe(1);

    // total_bytes counts ALL completed backups (not just 7d window).
    expect($stats['total_bytes'])->toBe(1024 + 2048 + 999);
});
test('schedule meta includes next run and latest status', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
    ]);

    // Latest backup is failed — meta should surface this.
    ServerDatabaseBackup::create([
        'server_database_id' => $database->id,
        'status' => 'failed',
    ]);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server]);

    $meta = $component->viewData('scheduleMeta');
    expect($meta)->toHaveKey($schedule->id);
    expect($meta[$schedule->id]['latest_status'])->toBe('failed');
    expect($meta[$schedule->id]['next_run_at'])->not->toBeNull('Active schedules with valid cron must compute a next-run time.');
    expect($meta[$schedule->id]['next_run_at']->getTimestamp())->toBeGreaterThan(now()->timestamp);
});
/** Stub the preflight so Enable doesn't try to SSH to a fake test server. */
function stubAllPreflightChecksPass(): void
{
    $allPass = [
        ['key' => 'site_release_present', 'status' => 'pass', 'message' => 'ok'],
        ['key' => 'php_binary', 'status' => 'pass', 'message' => 'ok'],
        ['key' => 'artisan_file', 'status' => 'pass', 'message' => 'ok'],
        ['key' => 'laravel_boots', 'status' => 'pass', 'message' => 'ok'],
        ['key' => 'scheduler_has_tasks', 'status' => 'pass', 'message' => 'ok'],
        ['key' => 'cron_user_access', 'status' => 'pass', 'message' => 'ok'],
        ['key' => 'no_duplicate_scheduler', 'status' => 'pass', 'message' => 'ok'],
    ];
    $stub = \Mockery::mock(PreflightSchedulerOnSite::class);
    $stub->shouldReceive('run')->andReturn($allPass);
    $stub->shouldReceive('structuralFailures')->andReturn([]);
    $stub->shouldReceive('advisoryWarnings')->andReturn([]);
    app()->instance(PreflightSchedulerOnSite::class, $stub);
}
test('enable scheduler for site creates laravel wrapper cron entry', function () {
    stubAllPreflightChecksPass();
    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceSchedule::class, ['server' => $server])
        ->set('enable_site_id', $site->id)
        ->set('enable_cron_expression', '* * * * *')
        ->call('enableSchedulerForSite');

    $entry = ServerCronJob::query()
        ->where('server_id', $server->id)
        ->where('site_id', $site->id)
        ->first();
    expect($entry)->not->toBeNull();

    // Enable now wraps the bare command in dply-scheduler-tick (Q3 + Q9
    // wrapper contract). The underlying `php artisan schedule:run` is
    // preserved as the wrapper's `-- <cmd>` tail.
    $this->assertStringContainsString('/usr/local/bin/dply-scheduler-tick', $entry->command);
    $this->assertStringContainsString('schedule:run', $entry->command);
    expect($entry->cron_expression)->toBe('* * * * *');
});
test('enable scheduler for site creates rails wrapper cron entry', function () {
    stubAllPreflightChecksPass();
    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'rails', 'language' => 'ruby'],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceSchedule::class, ['server' => $server])
        ->set('enable_site_id', $site->id)
        ->set('enable_cron_expression', '0 * * * *')
        ->call('enableSchedulerForSite');

    $entry = ServerCronJob::query()
        ->where('server_id', $server->id)
        ->where('site_id', $site->id)
        ->first();
    expect($entry)->not->toBeNull();
    $this->assertStringContainsString('/usr/local/bin/dply-scheduler-tick', $entry->command);
    $this->assertStringContainsString('whenever', $entry->command);
});
test('enable scheduler rejects missing site', function () {
    $user = actingOrgUser();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceSchedule::class, ['server' => $server])
        ->set('enable_site_id', '')
        ->call('enableSchedulerForSite');

    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(0);
});
test('run schedule now dispatches database export job', function () {
    Bus::fake();

    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('runScheduleNow', $schedule->id);

    Bus::assertDispatched(ExportServerDatabaseBackupJob::class);
});
test('run schedule now dispatches site files export job', function () {
    Bus::fake();

    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'site_files',
        'target_id' => $site->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('runScheduleNow', $schedule->id);

    Bus::assertDispatched(ExportSiteFileBackupJob::class);
});
test('schedule mutations emit audit log rows', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);

    // Create
    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->set('new_target_type', 'database')
        ->set('new_target_id', $database->id)
        ->set('new_cron_expression', '0 3 * * *')
        ->call('addSchedule');

    $schedule = ServerBackupSchedule::query()->where('server_id', $server->id)->first();
    expect($schedule)->not->toBeNull();

    // Pause
    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('toggleSchedule', $schedule->id);

    // Resume
    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('toggleSchedule', $schedule->id);

    // Edit cadence
    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('startEditSchedule', $schedule->id)
        ->set('editing_schedules.'.$schedule->id, '15 * * * *')
        ->call('saveScheduleCadence', $schedule->id);

    // Run now
    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('runScheduleNow', $schedule->id);

    $actions = AuditLog::query()
        ->where('organization_id', $server->organization_id)
        ->orderBy('created_at')
        ->pluck('action')
        ->all();

    expect($actions)->toContain('backup.schedule.created');
    expect($actions)->toContain('backup.schedule.paused');
    expect($actions)->toContain('backup.schedule.resumed');
    expect($actions)->toContain('backup.schedule.cadence_updated');
    expect($actions)->toContain('backup.schedule.run_now');
});
test('schedule meta includes recent runs for target', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
    ]);

    for ($i = 0; $i < 7; $i++) {
        ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'status' => $i % 2 === 0 ? 'completed' : 'failed',
            'bytes' => 100 * ($i + 1),
        ]);
    }

    $component = Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server]);

    $meta = $component->viewData('scheduleMeta');
    $recent = $meta[$schedule->id]['recent_runs'];
    expect($recent)->toHaveCount(5, 'History should be capped at 5 entries.');
});
test('daemons stop action calls stop program group', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);

    $program = SupervisorProgram::create([
        'server_id' => $server->id,
        'slug' => 'app-queue',
        'program_type' => 'queue',
        'command' => 'php artisan queue:work',
        'directory' => '/srv/app/current',
        'user' => 'dply',
        'numprocs' => 2,
        'is_active' => true,
    ]);

    $provisioner = \Mockery::mock(SupervisorProvisioner::class);
    $provisioner->shouldReceive('stopProgramGroup')->once()->andReturn('ok');
    app()->instance(SupervisorProvisioner::class, $provisioner);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server])
        ->call('stopOneProgram', $program->id);

    expect(SupervisorProgramAuditLog::query()
        ->where('server_id', $server->id)
        ->where('action', 'stop_one')
        ->exists())->toBeTrue();
});
test('daemons start action calls start program group', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);

    $program = SupervisorProgram::create([
        'server_id' => $server->id,
        'slug' => 'idle-queue',
        'program_type' => 'queue',
        'command' => 'php artisan queue:work',
        'directory' => '/srv/app/current',
        'user' => 'dply',
        'numprocs' => 1,
        'is_active' => false,
    ]);

    $provisioner = \Mockery::mock(SupervisorProvisioner::class);
    $provisioner->shouldReceive('startProgramGroup')->once()->andReturn('ok');
    app()->instance(SupervisorProvisioner::class, $provisioner);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server])
        ->call('startOneProgram', $program->id);

    expect(SupervisorProgramAuditLog::query()
        ->where('server_id', $server->id)
        ->where('action', 'start_one')
        ->exists())->toBeTrue();
});
test('daemons stats count active inactive and total processes', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);

    SupervisorProgram::create([
        'server_id' => $server->id, 'slug' => 'a', 'program_type' => 'queue',
        'command' => 'x', 'directory' => '/', 'user' => 'dply', 'numprocs' => 3, 'is_active' => true,
    ]);
    SupervisorProgram::create([
        'server_id' => $server->id, 'slug' => 'b', 'program_type' => 'horizon',
        'command' => 'x', 'directory' => '/', 'user' => 'dply', 'numprocs' => 1, 'is_active' => true,
    ]);
    SupervisorProgram::create([
        'server_id' => $server->id, 'slug' => 'c', 'program_type' => 'sidekiq',
        'command' => 'x', 'directory' => '/', 'user' => 'dply', 'numprocs' => 5, 'is_active' => false,
    ]);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server]);
    $stats = $component->viewData('daemonsStats');

    expect($stats['active'])->toBe(2);
    expect($stats['inactive'])->toBe(1);

    // Inactive workers don't count toward total_processes.
    expect($stats['total_processes'])->toBe(4);
});
test('send test alert sends notification with test marker and audits', function () {
    Notification::fake();

    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
        'notify_on_failure' => true,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('sendTestAlert', $schedule->id);

    Notification::assertSentTo($user, BackupFailureNotification::class, function ($notification) {
        return $notification->isTest === true;
    });

    $audit = AuditLog::query()
        ->where('organization_id', $server->organization_id)
        ->latest('created_at')
        ->first();
    expect($audit?->action)->toBe('backup.schedule.test_alert');
});
test('failed backup sends notification when schedule opted in', function () {
    Notification::fake();

    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
        'notify_on_failure' => true,
    ]);

    $backup = ServerDatabaseBackup::create([
        'server_database_id' => $database->id,
        'status' => 'pending',
    ]);
    $backup->update(['status' => 'failed', 'error_message' => 'connection refused']);

    Notification::assertSentTo($user, BackupFailureNotification::class);
});
test('failed backup does not notify when schedule opted out', function () {
    Notification::fake();

    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
        'notify_on_failure' => false,
    ]);

    $backup = ServerDatabaseBackup::create([
        'server_database_id' => $database->id,
        'status' => 'pending',
    ]);
    $backup->update(['status' => 'failed']);

    Notification::assertNothingSent();
});
test('toggle notify on failure flips flag and audits', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
        'notify_on_failure' => true,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('toggleNotifyOnFailure', $schedule->id);

    expect(ServerBackupSchedule::find($schedule->id)->notify_on_failure)->toBeFalse();

    $latestAudit = AuditLog::query()
        ->where('organization_id', $server->organization_id)
        ->orderByDesc('created_at')
        ->first();
    expect($latestAudit?->action)->toBe('backup.schedule.notify_disabled');
});
test('completed backup auto resumes paused schedule', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $cronJob = ServerCronJob::create([
        'server_id' => $server->id,
        'cron_expression' => '0 3 * * *',
        'command' => 'php artisan dply:run-backup-schedule X',
        'user' => 'root',
        'enabled' => false,
        'system_managed' => true,
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => false,
        'server_cron_job_id' => $cronJob->id,
    ]);

    $backup = ServerDatabaseBackup::create([
        'server_database_id' => $database->id,
        'status' => ServerDatabaseBackup::STATUS_PENDING,
    ]);

    // Status flip from pending → completed should re-enable both schedule and cron.
    $backup->update(['status' => ServerDatabaseBackup::STATUS_COMPLETED, 'disk_path' => 'x.sql']);

    expect(ServerBackupSchedule::find($schedule->id)->is_active)->toBeTrue();
    expect(ServerCronJob::find($cronJob->id)->enabled)->toBeTrue();
});
test('failed backup does not auto resume paused schedule', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => false,
    ]);

    $backup = ServerDatabaseBackup::create([
        'server_database_id' => $database->id,
        'status' => ServerDatabaseBackup::STATUS_PENDING,
    ]);
    $backup->update(['status' => 'failed']);

    expect(ServerBackupSchedule::find($schedule->id)->is_active)->toBeFalse();
});
test('paused schedule has null next run', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => false,
    ]);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server]);

    $meta = $component->viewData('scheduleMeta');
    expect($meta[$schedule->id]['next_run_at'])->toBeNull();
});
test('save schedule cadence updates both schedule and cron', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
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
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
        'server_cron_job_id' => $cronJob->id,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('startEditSchedule', $schedule->id)
        ->set('editing_schedules.'.$schedule->id, '30 4 * * 0')
        ->call('saveScheduleCadence', $schedule->id);

    expect(ServerBackupSchedule::find($schedule->id)->cron_expression)->toBe('30 4 * * 0');
    expect(ServerCronJob::find($cronJob->id)->cron_expression)->toBe('30 4 * * 0');
});
test('save schedule cadence rejects empty cron', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);
    $schedule = ServerBackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => 'database',
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBackups::class, ['server' => $server])
        ->call('startEditSchedule', $schedule->id)
        ->set('editing_schedules.'.$schedule->id, '   ')
        ->call('saveScheduleCadence', $schedule->id);

    // Original cadence preserved.
    expect(ServerBackupSchedule::find($schedule->id)->cron_expression)->toBe('0 3 * * *');
});
test('solid queue preset fills daemons form', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'rails', 'language' => 'ruby'],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server, 'site' => $site])
        ->call('applySupervisorPreset', 'solid-queue')
        ->assertSet('new_sv_slug', 'solid-queue')
        ->assertSet('new_sv_command', 'bin/jobs');
});
test('action cable preset fills daemons form', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'rails', 'language' => 'ruby'],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server, 'site' => $site])
        ->call('applySupervisorPreset', 'action-cable')
        ->assertSet('new_sv_slug', 'action-cable')
        ->assertSet('new_sv_command', 'bundle exec puma -p 28080 cable/config.ru');
});
test('backups site query param filters schedules and hides db runs', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);
    $otherSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);

    // Schedule for site (should appear); schedule for other site (should NOT); db schedule (should NOT).
    $siteSchedule = ServerBackupSchedule::create([
        'server_id' => $server->id, 'target_type' => 'site_files', 'target_id' => $site->id,
        'cron_expression' => '0 3 * * *', 'is_active' => true,
    ]);
    ServerBackupSchedule::create([
        'server_id' => $server->id, 'target_type' => 'site_files', 'target_id' => $otherSite->id,
        'cron_expression' => '0 4 * * *', 'is_active' => true,
    ]);
    ServerBackupSchedule::create([
        'server_id' => $server->id, 'target_type' => 'database', 'target_id' => $database->id,
        'cron_expression' => '0 5 * * *', 'is_active' => true,
    ]);

    // Database backup row (must NOT show under site filter).
    ServerDatabaseBackup::create(['server_database_id' => $database->id, 'status' => 'completed']);

    // Site file backup row for THIS site (should show).
    SiteFileBackup::create(['site_id' => $site->id, 'status' => 'completed']);

    $this->actingAs($user);
    request()->query->set('site', $site->id);

    $component = Livewire::actingAs($user)
        ->withQueryParams(['site' => $site->id])
        ->test(WorkspaceBackups::class, ['server' => $server]);

    expect($component->get('context_site_id'))->toBe($site->id);
    $schedules = $component->viewData('schedules');
    expect($schedules->pluck('id')->all())->toBe([$siteSchedule->id]);
    expect($component->viewData('databaseBackups'))->toHaveCount(0, 'DB runs hidden under site filter.');
    expect($component->viewData('fileBackups'))->toHaveCount(1);
});
test('backups invalid site query param does not set context', function () {
    $user = actingOrgUser();
    $server = readyServer($user);

    $component = Livewire::actingAs($user)
        ->withQueryParams(['site' => '01nonexistent00000000000000'])
        ->test(WorkspaceBackups::class, ['server' => $server]);

    expect($component->get('context_site_id'))->toBeNull();
});
test('schedule site query param filters cron and daemon lists', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);
    $other = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    $siteCron = ServerCronJob::create([
        'server_id' => $server->id, 'site_id' => $site->id,
        'cron_expression' => '* * * * *', 'command' => 'php artisan schedule:run', 'user' => 'dply', 'enabled' => true,
    ]);
    ServerCronJob::create([
        'server_id' => $server->id, 'site_id' => $other->id,
        'cron_expression' => '* * * * *', 'command' => 'php artisan schedule:run', 'user' => 'dply', 'enabled' => true,
    ]);

    $component = Livewire::actingAs($user)
        ->withQueryParams(['site' => $site->id])
        ->test(WorkspaceSchedule::class, ['server' => $server]);

    expect($component->get('context_site_id'))->toBe($site->id);

    // Cards are filtered to the context site at render time. Both sites
    // had scheduler-shaped cron entries; only the filtered one shows.
    $cards = $component->viewData('cards');
    expect($cards)->toHaveCount(1, 'Context filter should narrow to the requested site only.');
    expect($cards[0]['site']->id)->toBe($site->id);
    expect($cards[0]['state'])->toBe('detected_unmonitored');
    expect($component->get('enable_site_id'))->toBe($site->id, 'Enable scheduler form pre-fills site id.');
});
test('activity mount honors category query param', function () {
    $user = actingOrgUser();
    $server = readyServer($user);

    $component = Livewire::actingAs($user)
        ->withQueryParams(['category' => 'background'])
        ->test(WorkspaceActivity::class, ['server' => $server]);

    expect($component->get('category'))->toBe('background');
});
test('activity mount ignores unknown category query param', function () {
    $user = actingOrgUser();
    $server = readyServer($user);

    $component = Livewire::actingAs($user)
        ->withQueryParams(['category' => 'made-up-bucket'])
        ->test(WorkspaceActivity::class, ['server' => $server]);

    expect($component->get('category'))->toBe('');
});
test('activity categorize routes backup and worker actions to background', function () {
    expect(WorkspaceActivity::categorize('backup.schedule.created'))->toBe('background');
    expect(WorkspaceActivity::categorize('backup.schedule.paused'))->toBe('background');
    expect(WorkspaceActivity::categorize('queue_worker.restart'))->toBe('background');
    expect(WorkspaceActivity::categorize('queue_worker.stop'))->toBe('background');

    // Sibling categories still route correctly.
    expect(WorkspaceActivity::categorize('insight.opened'))->toBe('insights');
    expect(WorkspaceActivity::categorize('site.settings.updated'))->toBe('site');

    // 'other' bucket excludes my new prefixes.
    expect(WorkspaceActivity::categorize('unknown.thing.happened'))->toBe('other');
});
test('overview background tile summarizes workers and schedules', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $database = $server->serverDatabases()->create([
        'name' => 'app',
        'engine' => 'mysql',
        'username' => '',
        'password' => '',
    ]);

    // 2 active workers, 1 inactive.
    SupervisorProgram::create([
        'server_id' => $server->id, 'slug' => 'a', 'program_type' => 'queue',
        'command' => 'x', 'directory' => '/', 'user' => 'dply', 'numprocs' => 1, 'is_active' => true,
    ]);
    SupervisorProgram::create([
        'server_id' => $server->id, 'slug' => 'b', 'program_type' => 'horizon',
        'command' => 'x', 'directory' => '/', 'user' => 'dply', 'numprocs' => 1, 'is_active' => true,
    ]);
    SupervisorProgram::create([
        'server_id' => $server->id, 'slug' => 'c', 'program_type' => 'queue',
        'command' => 'x', 'directory' => '/', 'user' => 'dply', 'numprocs' => 1, 'is_active' => false,
    ]);

    // 1 active schedule, 1 paused.
    ServerBackupSchedule::create([
        'server_id' => $server->id, 'target_type' => 'database', 'target_id' => $database->id,
        'cron_expression' => '0 3 * * *', 'is_active' => true,
    ]);
    ServerBackupSchedule::create([
        'server_id' => $server->id, 'target_type' => 'database', 'target_id' => $database->id,
        'cron_expression' => '0 4 * * *', 'is_active' => false,
    ]);

    // 1 recent failure, 1 ancient failure (excluded from the 7d window).
    ServerDatabaseBackup::create([
        'server_database_id' => $database->id, 'status' => 'failed',
    ]);
    $old = ServerDatabaseBackup::create([
        'server_database_id' => $database->id, 'status' => 'failed',
    ]);
    $old->created_at = now()->subDays(30);
    $old->save();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server]);

    $summary = $component->viewData('backgroundSummary');
    expect($summary['active_workers'])->toBe(2);
    expect($summary['active_schedules'])->toBe(1);
    expect($summary['paused_schedules'])->toBe(1);
    expect($summary['failed_backups_7d'])->toBe(1, '30-day-old failure must be excluded from 7d window.');
});
test('site daemons stop dispatches for site scoped program', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);
    $program = SupervisorProgram::create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'slug' => 'site-queue',
        'program_type' => 'queue',
        'command' => 'php artisan queue:work',
        'directory' => '/srv/site/current',
        'user' => 'dply',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $provisioner = \Mockery::mock(SupervisorProvisioner::class);
    $provisioner->shouldReceive('stopProgramGroup')->once()->andReturn('ok');
    app()->instance(SupervisorProvisioner::class, $provisioner);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server, 'site' => $site])
        ->call('stopOneProgram', $program->id);

    expect(SupervisorProgramAuditLog::query()
        ->where('supervisor_program_id', $program->id)
        ->where('action', 'stop_one')
        ->exists())->toBeTrue();
});
test('site daemons stats count only this site programs', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $siteA = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);
    $siteB = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    // Site A: 1 active (3 procs), 1 inactive (2 procs).
    SupervisorProgram::create([
        'server_id' => $server->id, 'site_id' => $siteA->id,
        'slug' => 'a1', 'program_type' => 'queue', 'command' => 'x',
        'directory' => '/', 'user' => 'dply', 'numprocs' => 3, 'is_active' => true,
    ]);
    SupervisorProgram::create([
        'server_id' => $server->id, 'site_id' => $siteA->id,
        'slug' => 'a2', 'program_type' => 'horizon', 'command' => 'x',
        'directory' => '/', 'user' => 'dply', 'numprocs' => 2, 'is_active' => false,
    ]);

    // Site B noise — must NOT contribute to A's stats.
    SupervisorProgram::create([
        'server_id' => $server->id, 'site_id' => $siteB->id,
        'slug' => 'b1', 'program_type' => 'queue', 'command' => 'x',
        'directory' => '/', 'user' => 'dply', 'numprocs' => 99, 'is_active' => true,
    ]);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server, 'site' => $siteA]);
    $stats = $component->viewData('daemonsStats');

    expect($stats['active'])->toBe(1);
    expect($stats['inactive'])->toBe(1);
    expect($stats['total_processes'])->toBe(3);
});
test('site daemons page scopes programs to site by default', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $siteA = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);
    $siteB = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    $aProgram = SupervisorProgram::create([
        'server_id' => $server->id,
        'site_id' => $siteA->id,
        'slug' => 'a-queue',
        'program_type' => 'queue',
        'command' => 'php artisan queue:work',
        'directory' => '/srv/a',
        'user' => 'dply',
        'numprocs' => 1,
        'is_active' => true,
    ]);
    SupervisorProgram::create([
        'server_id' => $server->id,
        'site_id' => $siteB->id,
        'slug' => 'b-queue',
        'program_type' => 'queue',
        'command' => 'php artisan queue:work',
        'directory' => '/srv/b',
        'user' => 'dply',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server, 'site' => $siteA]);
    $component->assertOk();

    $programs = $component->viewData('filteredSupervisorPrograms');
    expect($programs->pluck('id')->all())->toBe([$aProgram->id], 'Site A page must only show site A programs.');
});
test('site daemons can import programs from another site on the same server', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $sourceSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'repository_path' => '/var/www/source-app',
    ]);
    $targetSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'repository_path' => '/var/www/target-app',
    ]);

    SupervisorProgram::create([
        'server_id' => $server->id,
        'site_id' => $sourceSite->id,
        'slug' => 'laravel-queue',
        'program_type' => 'queue',
        'command' => 'php /var/www/source-app/current/artisan queue:work',
        'directory' => '/var/www/source-app/current',
        'user' => 'dply',
        'numprocs' => 2,
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server, 'site' => $targetSite])
        ->set('import_from_site_id', $sourceSite->id)
        ->call('importProgramFromSite', SupervisorProgram::query()->where('site_id', $sourceSite->id)->value('id'))
        ->assertHasNoErrors();

    $imported = SupervisorProgram::query()
        ->where('server_id', $server->id)
        ->where('site_id', $targetSite->id)
        ->first();

    expect($imported)->not->toBeNull();
    expect($imported->slug)->toBe('laravel-queue-'.Str::slug($targetSite->slug));
    expect($imported->directory)->toBe('/var/www/target-app/current');
    expect($imported->command)->toBe('php /var/www/target-app/current/artisan queue:work');
    expect($imported->numprocs)->toBe(2);
});
test('site daemons preset list is filtered by detected framework', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $laravelSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ]);
    $railsSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'rails', 'language' => 'ruby'],
            ],
        ],
    ]);

    $laravelPresets = Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server, 'site' => $laravelSite])
        ->instance()
        ->supervisorPresetOptionsForForm();
    $laravelValues = array_column($laravelPresets, 'value');
    expect($laravelValues)->toContain('laravel-queue');
    expect($laravelValues)->not->toContain('sidekiq');

    $railsPresets = Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server, 'site' => $railsSite])
        ->instance()
        ->supervisorPresetOptionsForForm();
    $railsValues = array_column($railsPresets, 'value');
    expect($railsValues)->toContain('sidekiq');
    expect($railsValues)->not->toContain('laravel-queue');
});
test('site daemons saves solid queue preset with site id', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'rails', 'language' => 'ruby'],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server, 'site' => $site])
        ->call('applySupervisorPreset', 'solid-queue')
        ->call('saveSupervisorProgram');

    $program = SupervisorProgram::query()
        ->where('server_id', $server->id)
        ->where('slug', 'solid-queue')
        ->first();

    expect($program)->not->toBeNull();
    expect($program->program_type)->toBe('solid-queue');
    expect($program->site_id)->toBe($site->id);
});
test('daemons page edit and delete program', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);

    $provisioner = \Mockery::mock(SupervisorProvisioner::class);
    $provisioner->shouldReceive('deleteConfigFile')->once();
    app()->instance(SupervisorProvisioner::class, $provisioner);

    $program = SupervisorProgram::create([
        'server_id' => $server->id,
        'slug' => 'edit-me',
        'program_type' => 'horizon',
        'command' => 'php artisan horizon',
        'directory' => '/srv/app/current',
        'user' => 'dply',
        'numprocs' => 2,
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server])
        ->call('beginEditProgram', $program->id)
        ->set('new_sv_numprocs', 4)
        ->call('saveSupervisorProgram');

    expect($program->fresh()->numprocs)->toBe(4);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server])
        ->call('openConfirmActionModal', 'deleteSupervisorProgram', [$program->id], 'Delete', 'Delete?', 'Delete', true)
        ->call('confirmActionModal');

    expect(SupervisorProgram::find($program->id))->toBeNull();
});
test('daemons page tails stdout log for individual program', function () {
    $user = actingOrgUser();
    $server = readyServer($user);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);

    $program = SupervisorProgram::create([
        'server_id' => $server->id,
        'slug' => 'laravel-queue',
        'program_type' => 'queue',
        'command' => 'php artisan queue:work',
        'directory' => '/srv/app/current',
        'user' => 'dply',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $provisioner = \Mockery::mock(SupervisorProvisioner::class);
    $provisioner->shouldReceive('tailProgramStdoutLog')->once()->andReturn("processed job\n");
    app()->instance(SupervisorProvisioner::class, $provisioner);

    Livewire::actingAs($user)
        ->test(WorkspaceDaemons::class, ['server' => $server])
        ->call('openProgramLogs', $program->id)
        ->assertSet('log_tail_program_id', $program->id)
        ->assertSet('log_tail_slug', 'laravel-queue')
        ->assertSet('log_tail_body', "processed job\n");
});
test('legacy server queue workers route redirects to daemons', function () {
    $user = actingOrgUser();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.queue-workers', $server))
        ->assertRedirect(route('servers.daemons', $server));
});
