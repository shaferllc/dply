<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Servers\WorkspaceBackups;
use App\Livewire\Servers\WorkspaceDaemons;
use App\Livewire\Servers\WorkspaceQueueWorkers;
use App\Livewire\Servers\WorkspaceSchedule;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Models\SupervisorProgram;
use App\Support\Servers\ServerInstalledServices;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BackgroundWorkspacePagesTest extends TestCase
{
    use RefreshDatabase;

    private function actingOrgUser(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    private function readyServer(User $user): Server
    {
        return Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()?->id,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        ]);
    }

    public function test_queue_workers_page_lists_only_queue_program_types(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

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
            ->test(WorkspaceQueueWorkers::class, ['server' => $server]);

        $component->assertOk();
        $programs = $component->viewData('programs');
        $this->assertSame([$queue->id], $programs->pluck('id')->all(), 'Only the queue-type program should appear, not the custom one.');
    }

    public function test_schedule_page_renders_detected_unmonitored_card_for_scheduler_shaped_cron(): void
    {
        // Rewritten for the milestone-2A scheduler control plane: the page no
        // longer surfaces a raw cron-entries collection — it pivots into
        // per-site cards with state. A scheduler-shaped cron on a site
        // without a wrapper-managed heartbeat shows up as
        // `detected_unmonitored`; an unrelated cron contributes nothing.
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
        $this->assertCount(1, $cards, 'Only the scheduler-shaped cron produces a card; unrelated cron is ignored.');
        $this->assertSame('detected_unmonitored', $cards[0]['state']);
        $this->assertSame('laravel', $cards[0]['kind']);
        $this->assertSame($site->id, $cards[0]['site']->id);

        $stats = $component->viewData('stats');
        $this->assertSame(1, $stats['unmonitored']);
        $this->assertSame(0, $stats['tracked_total']);
    }

    public function test_backups_page_run_now_dispatches_database_export_job(): void
    {
        Bus::fake();

        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

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
    }

    public function test_backups_page_add_schedule_creates_backup_schedule_and_managed_cron(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
        $this->assertNotNull($schedule);
        $this->assertSame('database', $schedule->target_type);
        $this->assertSame($database->id, $schedule->target_id);
        $this->assertSame('15 4 * * *', $schedule->cron_expression);
        $this->assertNotNull($schedule->server_cron_job_id);

        $cronJob = ServerCronJob::find($schedule->server_cron_job_id);
        $this->assertNotNull($cronJob);
        $this->assertStringContainsString('dply:run-backup-schedule '.$schedule->id, $cronJob->command);
        $this->assertTrue($cronJob->system_managed);
    }

    public function test_backups_delete_schedule_removes_cron_entry(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
        $this->assertNotNull($cronId);

        Livewire::actingAs($user)
            ->test(WorkspaceBackups::class, ['server' => $server])
            ->call('deleteSchedule', $schedule->id);

        $this->assertNull(ServerBackupSchedule::find($schedule->id));
        $this->assertNull(ServerCronJob::find($cronId));
    }

    public function test_queue_workers_route_renders_via_http(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
        // Supervisor install status is irrelevant for the route gate — the page now
        // self-handles the not-installed case with an Install CTA. We mark it
        // installed here so the worker management surface renders (Add a worker,
        // Active workers panel) rather than the install prompt.
        $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);

        $this->actingAs($user)
            ->get(route('servers.queue-workers', $server))
            ->assertOk()
            ->assertSee('Queue workers', false)
            ->assertSee('Active workers', false)
            ->assertSee('Add a worker', false);
    }

    public function test_schedule_route_renders_via_http(): void
    {
        // Stable markers post milestone-2A rewrite: page heading + the
        // description copy that ships in every render regardless of whether
        // any sites exist or any schedulers are configured. Per-site cards
        // and the Enable form only render when there are sites to act on.
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

        $this->actingAs($user)
            ->get(route('servers.schedule', $server))
            ->assertOk()
            ->assertSee('Schedule', false)
            ->assertSee('Framework schedulers running on this server', false);
    }

    public function test_backups_route_renders_via_http(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

        $this->actingAs($user)
            ->get(route('servers.backups', $server))
            ->assertOk()
            ->assertSee('Backups', false)
            ->assertSee('Recent database backups', false);
    }

    public function test_site_queue_workers_route_renders_via_http(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
        ]);

        $this->actingAs($user)
            ->get(route('sites.queue-workers', ['server' => $server, 'site' => $site]))
            ->assertOk()
            ->assertSee('Queue workers', false)
            ->assertSee($site->name, false);
    }

    /** Helper: install a stack_summary artifact so ServerInstalledServices stops failing-open. */
    private function setExpectedServices(Server $server, array $services): void
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

    public function test_backups_route_404s_when_no_database_tag_present(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
        // Stack summary explicitly says no database service is installed — gating should fire.
        $this->setExpectedServices($server, ['nginx', 'php-fpm']);

        $this->actingAs($user)
            ->get(route('servers.backups', $server))
            ->assertNotFound();
    }

    public function test_queue_workers_route_renders_when_supervisor_missing(): void
    {
        // The supervisor gate was dropped so the page is reachable before install —
        // the page itself surfaces the Install Supervisor CTA. Verify the route
        // serves the page (not a 404) and the install banner is present.
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
        $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_MISSING]);
        $this->setExpectedServices($server, ['nginx', 'php-fpm']);

        $this->actingAs($user)
            ->get(route('servers.queue-workers', $server))
            ->assertOk()
            ->assertSee('Supervisor is not installed', false)
            ->assertSee('Go to Daemons to install', false);
    }

    public function test_site_queue_workers_route_shows_install_banner_when_supervisor_missing(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
        $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_MISSING]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
        ]);

        $this->actingAs($user)
            ->get(route('sites.queue-workers', ['server' => $server, 'site' => $site]))
            ->assertOk()
            ->assertSee('Supervisor is not installed', false)
            ->assertSee('Go to Daemons to install', false);
    }

    public function test_backups_route_denied_for_user_outside_organization(): void
    {
        $owner = $this->actingOrgUser();
        $server = $this->readyServer($owner);

        // Outsider has their own org and isn't a member of $owner's org.
        $outsider = User::factory()->create();
        $outsiderOrg = Organization::factory()->create();
        $outsiderOrg->users()->attach($outsider->id, ['role' => 'owner']);
        session(['current_organization_id' => $outsiderOrg->id]);

        // mount() authorizes 'view' on the server — outsider can't even render.
        $this->actingAs($outsider)
            ->get(route('servers.backups', $server))
            ->assertForbidden();
    }

    public function test_download_database_backup_returns_file_response_when_completed(): void
    {
        Storage::fake('local');

        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
            'disk_path' => 'backup.sql',
        ]);

        // Drive the action directly so we can assert on the returned Symfony response.
        $this->actingAs($user);
        $component = new WorkspaceBackups;
        $component->mount($server);
        $response = $component->downloadDatabaseBackup($backup->id);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $disposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringContainsString('app-'.$backup->id.'.sql', $disposition);
    }

    public function test_download_database_backup_short_circuits_when_pending(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $this->assertNull(ServerDatabaseBackup::find($backup->id)->disk_path);
    }

    public function test_empty_state_explainer_offers_inline_add_destination(): void
    {
        // With org-scoped destinations and the inline "Add destination" modal,
        // the empty-state copy invites the operator to add one without leaving
        // the page rather than linking back to the profile settings screen.
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

        $this->actingAs($user)
            ->get(route('servers.backups', $server))
            ->assertOk()
            ->assertSee('add one now', false)
            ->assertSee('openDestinationModal', false);
    }

    public function test_toggle_schedule_pauses_and_resumes_managed_cron(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $this->assertFalse(ServerBackupSchedule::find($schedule->id)->is_active);
        $this->assertFalse(ServerCronJob::find($cronJob->id)->enabled);

        Livewire::actingAs($user)
            ->test(WorkspaceBackups::class, ['server' => $server])
            ->call('toggleSchedule', $schedule->id);

        $this->assertTrue(ServerBackupSchedule::find($schedule->id)->is_active);
        $this->assertTrue(ServerCronJob::find($cronJob->id)->enabled);
    }

    public function test_delete_database_backup_removes_row_and_disk_file(): void
    {
        Storage::fake('local');

        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
            'disk_path' => 'to-delete.sql',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceBackups::class, ['server' => $server])
            ->call('deleteDatabaseBackup', $backup->id);

        $this->assertNull(ServerDatabaseBackup::find($backup->id));
        $this->assertFalse(Storage::disk('local')->exists('to-delete.sql'));
    }

    public function test_delete_file_backup_removes_row_and_disk_file(): void
    {
        Storage::fake('local');

        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $this->assertNull(SiteFileBackup::find($backup->id));
        $this->assertFalse(Storage::disk('local')->exists('site-files.tar.gz'));
    }

    public function test_backups_stats_count_completed_and_failed_in_last_7_days(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
        $this->assertSame(2, $stats['db_completed_7d']);
        $this->assertSame(1, $stats['db_failed_7d']);
        // total_bytes counts ALL completed backups (not just 7d window).
        $this->assertSame(1024 + 2048 + 999, $stats['total_bytes']);
    }

    public function test_schedule_meta_includes_next_run_and_latest_status(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
        $this->assertArrayHasKey($schedule->id, $meta);
        $this->assertSame('failed', $meta[$schedule->id]['latest_status']);
        $this->assertNotNull($meta[$schedule->id]['next_run_at'], 'Active schedules with valid cron must compute a next-run time.');
        $this->assertGreaterThan(now()->timestamp, $meta[$schedule->id]['next_run_at']->getTimestamp());
    }

    /** Stub the preflight so Enable doesn't try to SSH to a fake test server. */
    private function stubAllPreflightChecksPass(): void
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
        $stub = \Mockery::mock(\App\Services\Servers\PreflightSchedulerOnSite::class);
        $stub->shouldReceive('run')->andReturn($allPass);
        $stub->shouldReceive('structuralFailures')->andReturn([]);
        $stub->shouldReceive('advisoryWarnings')->andReturn([]);
        $this->app->instance(\App\Services\Servers\PreflightSchedulerOnSite::class, $stub);
    }

    public function test_enable_scheduler_for_site_creates_laravel_wrapper_cron_entry(): void
    {
        $this->stubAllPreflightChecksPass();
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->set('enable_site_id', $site->id)
            ->set('enable_framework', 'laravel')
            ->set('enable_cron_expression', '* * * * *')
            ->call('enableSchedulerForSite');

        $entry = ServerCronJob::query()
            ->where('server_id', $server->id)
            ->where('site_id', $site->id)
            ->first();
        $this->assertNotNull($entry);
        // Enable now wraps the bare command in dply-scheduler-tick (Q3 + Q9
        // wrapper contract). The underlying `php artisan schedule:run` is
        // preserved as the wrapper's `-- <cmd>` tail.
        $this->assertStringContainsString('/usr/local/bin/dply-scheduler-tick', $entry->command);
        $this->assertStringContainsString('schedule:run', $entry->command);
        $this->assertSame('* * * * *', $entry->cron_expression);
    }

    public function test_enable_scheduler_for_site_creates_rails_wrapper_cron_entry(): void
    {
        $this->stubAllPreflightChecksPass();
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->set('enable_site_id', $site->id)
            ->set('enable_framework', 'rails')
            ->set('enable_cron_expression', '0 * * * *')
            ->call('enableSchedulerForSite');

        $entry = ServerCronJob::query()
            ->where('server_id', $server->id)
            ->where('site_id', $site->id)
            ->first();
        $this->assertNotNull($entry);
        $this->assertStringContainsString('/usr/local/bin/dply-scheduler-tick', $entry->command);
        $this->assertStringContainsString('whenever', $entry->command);
    }

    public function test_enable_scheduler_rejects_missing_site(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->set('enable_site_id', '')
            ->call('enableSchedulerForSite');

        $this->assertSame(0, ServerCronJob::query()->where('server_id', $server->id)->count());
    }

    public function test_run_schedule_now_dispatches_database_export_job(): void
    {
        Bus::fake();

        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
    }

    public function test_run_schedule_now_dispatches_site_files_export_job(): void
    {
        Bus::fake();

        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
    }

    public function test_schedule_mutations_emit_audit_log_rows(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
        $this->assertNotNull($schedule);

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

        $actions = \App\Models\AuditLog::query()
            ->where('organization_id', $server->organization_id)
            ->orderBy('created_at')
            ->pluck('action')
            ->all();

        $this->assertContains('backup.schedule.created', $actions);
        $this->assertContains('backup.schedule.paused', $actions);
        $this->assertContains('backup.schedule.resumed', $actions);
        $this->assertContains('backup.schedule.cadence_updated', $actions);
        $this->assertContains('backup.schedule.run_now', $actions);
    }

    public function test_schedule_meta_includes_recent_runs_for_target(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
        $this->assertCount(5, $recent, 'History should be capped at 5 entries.');
    }

    public function test_queue_workers_stop_action_calls_stop_program_group(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $provisioner = \Mockery::mock(\App\Services\Servers\SupervisorProvisioner::class);
        $provisioner->shouldReceive('stopProgramGroup')->once()->andReturn('ok');
        app()->instance(\App\Services\Servers\SupervisorProvisioner::class, $provisioner);

        Livewire::actingAs($user)
            ->test(WorkspaceQueueWorkers::class, ['server' => $server])
            ->call('stopWorker', $program->id);

        // SupervisorProgram::create above triggers the observer, which writes
        // a `server.daemons.program_created` row at the same second as the
        // worker action below — so filter by action rather than ordering by
        // created_at (which ties at second-precision timestamps).
        $audit = \App\Models\AuditLog::query()
            ->where('organization_id', $server->organization_id)
            ->where('action', 'queue_worker.stop')
            ->first();
        $this->assertNotNull($audit, 'Expected a queue_worker.stop audit row.');
    }

    public function test_queue_workers_start_action_calls_start_program_group(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $provisioner = \Mockery::mock(\App\Services\Servers\SupervisorProvisioner::class);
        $provisioner->shouldReceive('startProgramGroup')->once()->andReturn('ok');
        app()->instance(\App\Services\Servers\SupervisorProvisioner::class, $provisioner);

        Livewire::actingAs($user)
            ->test(WorkspaceQueueWorkers::class, ['server' => $server])
            ->call('startWorker', $program->id);

        $audit = \App\Models\AuditLog::query()
            ->where('organization_id', $server->organization_id)
            ->where('action', 'queue_worker.start')
            ->first();
        $this->assertNotNull($audit, 'Expected a queue_worker.start audit row.');
    }

    public function test_queue_workers_stats_count_active_inactive_and_total_processes(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
            ->test(WorkspaceQueueWorkers::class, ['server' => $server]);
        $stats = $component->viewData('stats');

        $this->assertSame(2, $stats['active']);
        $this->assertSame(1, $stats['inactive']);
        // Inactive workers don't count toward total_processes.
        $this->assertSame(4, $stats['total_processes']);
    }

    public function test_send_test_alert_sends_notification_with_test_marker_and_audits(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\BackupFailureNotification::class, function ($notification) {
            return $notification->isTest === true;
        });

        $audit = \App\Models\AuditLog::query()
            ->where('organization_id', $server->organization_id)
            ->latest('created_at')
            ->first();
        $this->assertSame('backup.schedule.test_alert', $audit?->action);
    }

    public function test_failed_backup_sends_notification_when_schedule_opted_in(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\BackupFailureNotification::class);
    }

    public function test_failed_backup_does_not_notify_when_schedule_opted_out(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }

    public function test_toggle_notify_on_failure_flips_flag_and_audits(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $this->assertFalse(ServerBackupSchedule::find($schedule->id)->notify_on_failure);

        $latestAudit = \App\Models\AuditLog::query()
            ->where('organization_id', $server->organization_id)
            ->orderByDesc('created_at')
            ->first();
        $this->assertSame('backup.schedule.notify_disabled', $latestAudit?->action);
    }

    public function test_completed_backup_auto_resumes_paused_schedule(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $this->assertTrue(ServerBackupSchedule::find($schedule->id)->is_active);
        $this->assertTrue(ServerCronJob::find($cronJob->id)->enabled);
    }

    public function test_failed_backup_does_not_auto_resume_paused_schedule(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $this->assertFalse(ServerBackupSchedule::find($schedule->id)->is_active);
    }

    public function test_paused_schedule_has_null_next_run(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
        $this->assertNull($meta[$schedule->id]['next_run_at']);
    }

    public function test_save_schedule_cadence_updates_both_schedule_and_cron(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $this->assertSame('30 4 * * 0', ServerBackupSchedule::find($schedule->id)->cron_expression);
        $this->assertSame('30 4 * * 0', ServerCronJob::find($cronJob->id)->cron_expression);
    }

    public function test_save_schedule_cadence_rejects_empty_cron(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
        $this->assertSame('0 3 * * *', ServerBackupSchedule::find($schedule->id)->cron_expression);
    }

    public function test_solid_queue_preset_fills_daemons_form(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
        $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);

        Livewire::actingAs($user)
            ->test(WorkspaceDaemons::class, ['server' => $server])
            ->call('applySupervisorPreset', 'solid-queue')
            ->assertSet('new_sv_slug', 'solid-queue')
            ->assertSet('new_sv_command', 'bin/jobs');
    }

    public function test_action_cable_preset_fills_daemons_form(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
        $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);

        Livewire::actingAs($user)
            ->test(WorkspaceDaemons::class, ['server' => $server])
            ->call('applySupervisorPreset', 'action-cable')
            ->assertSet('new_sv_slug', 'action-cable')
            ->assertSet('new_sv_command', 'bundle exec puma -p 28080 cable/config.ru');
    }

    public function test_backups_site_query_param_filters_schedules_and_hides_db_runs(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $this->assertSame($site->id, $component->get('context_site_id'));
        $schedules = $component->viewData('schedules');
        $this->assertSame([$siteSchedule->id], $schedules->pluck('id')->all());
        $this->assertCount(0, $component->viewData('databaseBackups'), 'DB runs hidden under site filter.');
        $this->assertCount(1, $component->viewData('fileBackups'));
    }

    public function test_backups_invalid_site_query_param_does_not_set_context(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['site' => '01nonexistent00000000000000'])
            ->test(WorkspaceBackups::class, ['server' => $server]);

        $this->assertNull($component->get('context_site_id'));
    }

    public function test_schedule_site_query_param_filters_cron_and_daemon_lists(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $this->assertSame($site->id, $component->get('context_site_id'));
        // Cards are filtered to the context site at render time. Both sites
        // had scheduler-shaped cron entries; only the filtered one shows.
        $cards = $component->viewData('cards');
        $this->assertCount(1, $cards, 'Context filter should narrow to the requested site only.');
        $this->assertSame($site->id, $cards[0]['site']->id);
        $this->assertSame('detected_unmonitored', $cards[0]['state']);
        $this->assertSame($site->id, $component->get('enable_site_id'), 'Enable scheduler form pre-fills site id.');
    }

    public function test_activity_mount_honors_category_query_param(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['category' => 'background'])
            ->test(\App\Livewire\Servers\WorkspaceActivity::class, ['server' => $server]);

        $this->assertSame('background', $component->get('category'));
    }

    public function test_activity_mount_ignores_unknown_category_query_param(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['category' => 'made-up-bucket'])
            ->test(\App\Livewire\Servers\WorkspaceActivity::class, ['server' => $server]);

        $this->assertSame('', $component->get('category'));
    }

    public function test_activity_categorize_routes_backup_and_worker_actions_to_background(): void
    {
        $this->assertSame('background', \App\Livewire\Servers\WorkspaceActivity::categorize('backup.schedule.created'));
        $this->assertSame('background', \App\Livewire\Servers\WorkspaceActivity::categorize('backup.schedule.paused'));
        $this->assertSame('background', \App\Livewire\Servers\WorkspaceActivity::categorize('queue_worker.restart'));
        $this->assertSame('background', \App\Livewire\Servers\WorkspaceActivity::categorize('queue_worker.stop'));
        // Sibling categories still route correctly.
        $this->assertSame('insights', \App\Livewire\Servers\WorkspaceActivity::categorize('insight.opened'));
        $this->assertSame('site', \App\Livewire\Servers\WorkspaceActivity::categorize('site.settings.updated'));
        // 'other' bucket excludes my new prefixes.
        $this->assertSame('other', \App\Livewire\Servers\WorkspaceActivity::categorize('unknown.thing.happened'));
    }

    public function test_overview_background_tile_summarizes_workers_and_schedules(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
            ->test(\App\Livewire\Servers\WorkspaceOverview::class, ['server' => $server]);

        $summary = $component->viewData('backgroundSummary');
        $this->assertSame(2, $summary['active_workers']);
        $this->assertSame(1, $summary['active_schedules']);
        $this->assertSame(1, $summary['paused_schedules']);
        $this->assertSame(1, $summary['failed_backups_7d'], '30-day-old failure must be excluded from 7d window.');
    }

    public function test_site_queue_workers_stop_dispatches_and_audits_with_site_id(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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

        $provisioner = \Mockery::mock(\App\Services\Servers\SupervisorProvisioner::class);
        $provisioner->shouldReceive('stopProgramGroup')->once()->andReturn('ok');
        app()->instance(\App\Services\Servers\SupervisorProvisioner::class, $provisioner);

        Livewire::actingAs($user)
            ->test(WorkspaceQueueWorkers::class, ['server' => $server, 'site' => $site])
            ->call('stopWorker', $program->id);

        // Filter by action to avoid tying with the observer's `program_created` row.
        $audit = \App\Models\AuditLog::query()
            ->where('organization_id', $server->organization_id)
            ->where('action', 'queue_worker.stop')
            ->first();
        $this->assertNotNull($audit, 'Expected a queue_worker.stop audit row.');
        $this->assertSame($site->id, $audit?->new_values['site_id'] ?? null);
    }

    public function test_site_queue_workers_stats_count_only_this_site_programs(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
            ->test(WorkspaceQueueWorkers::class, ['server' => $server, 'site' => $siteA]);
        $stats = $component->viewData('stats');

        $this->assertSame(1, $stats['active']);
        $this->assertSame(1, $stats['inactive']);
        $this->assertSame(3, $stats['total_processes']);
    }

    public function test_site_queue_workers_page_scopes_to_site(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
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
            ->test(WorkspaceQueueWorkers::class, ['server' => $server, 'site' => $siteA]);
        $component->assertOk();

        $programs = $component->viewData('programs');
        $this->assertSame([$aProgram->id], $programs->pluck('id')->all(), 'Site A page must only show site A programs.');
    }
}
