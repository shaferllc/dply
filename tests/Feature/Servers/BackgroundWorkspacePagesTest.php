<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Servers\WorkspaceBackups;
use App\Livewire\Servers\WorkspaceQueueWorkers;
use App\Livewire\Servers\WorkspaceSchedule;
use App\Livewire\Sites\SiteQueueWorkers;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
