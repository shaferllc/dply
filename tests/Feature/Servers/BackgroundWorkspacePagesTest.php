<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Servers\WorkspaceBackups;
use App\Livewire\Servers\WorkspaceDaemons;
use App\Livewire\Servers\WorkspaceQueueWorkers;
use App\Livewire\Servers\WorkspaceSchedule;
use App\Livewire\Sites\SiteQueueWorkers;
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

    public function test_schedule_page_filters_cron_entries_by_command_pattern(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

        $schedRunCron = ServerCronJob::create([
            'server_id' => $server->id,
            'cron_expression' => '* * * * *',
            'command' => 'cd /home/dply/app && php artisan schedule:run',
            'user' => 'dply',
            'enabled' => true,
        ]);
        $unrelatedCron = ServerCronJob::create([
            'server_id' => $server->id,
            'cron_expression' => '0 3 * * *',
            'command' => 'rsync /var/log /backup',
            'user' => 'dply',
            'enabled' => true,
        ]);
        $schedDaemon = SupervisorProgram::create([
            'server_id' => $server->id,
            'slug' => 'laravel-schedule',
            'program_type' => 'custom',
            'command' => 'php artisan schedule:work',
            'directory' => '/home/dply/app',
            'user' => 'dply',
            'numprocs' => 1,
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server]);

        $component->assertOk();
        $cron = $component->viewData('cronEntries');
        $daemons = $component->viewData('schedulerDaemons');
        $this->assertSame([$schedRunCron->id], $cron->pluck('id')->all());
        $this->assertSame([$schedDaemon->id], $daemons->pluck('id')->all());
        $this->assertNotContains($unrelatedCron->id, $cron->pluck('id')->all());
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
        // Mark supervisor as installed so the `requires_any_tags: ['supervisor']` middleware lets us through.
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
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

        $this->actingAs($user)
            ->get(route('servers.schedule', $server))
            ->assertOk()
            ->assertSee('Schedule', false)
            ->assertSee('Cron-driven schedulers', false);
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

    public function test_queue_workers_route_404s_when_supervisor_missing(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);
        $this->setExpectedServices($server, ['nginx', 'php-fpm']);

        $this->actingAs($user)
            ->get(route('servers.queue-workers', $server))
            ->assertNotFound();
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

    public function test_empty_state_explainer_links_to_backup_configurations(): void
    {
        $user = $this->actingOrgUser();
        $server = $this->readyServer($user);

        $this->actingAs($user)
            ->get(route('servers.backups', $server))
            ->assertOk()
            ->assertSee('add a backup destination', false)
            ->assertSee(route('profile.backup-configurations'), false);
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
            ->test(SiteQueueWorkers::class, ['server' => $server, 'site' => $siteA]);
        $component->assertOk();

        $programs = $component->viewData('programs');
        $this->assertSame([$aProgram->id], $programs->pluck('id')->all(), 'Site A page must only show site A programs.');
    }
}
