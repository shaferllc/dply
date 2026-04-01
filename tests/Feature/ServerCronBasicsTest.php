<?php

namespace Tests\Feature;

use App\Livewire\Servers\WorkspaceCron;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\User;
use App\Services\Servers\ServerCrontabReader;
use App\Services\Servers\ServerCronSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ServerCronBasicsTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    protected function readyServer(User $user): Server
    {
        return Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()?->id,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        ]);
    }

    public function test_cron_page_uses_basics_first_layout(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        $this->actingAs($user)
            ->get(route('servers.cron', $server))
            ->assertOk()
            ->assertSee('Schedule jobs for this server')
            ->assertSee('Basics')
            ->assertSee('Troubleshooting')
            ->assertSee('Scheduled jobs')
            ->assertDontSee('Troubleshooting & advanced tools')
            ->assertDontSee('Organization templates')
            ->assertDontSee('Organization maintenance window');
    }

    public function test_user_can_add_basic_cron_job(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceCron::class, ['server' => $server])
            ->set('new_cron_command', 'php /var/www/current/artisan schedule:run')
            ->set('new_cron_user', 'deploy')
            ->set('frequency_preset', 'hourly')
            ->set('new_cron_expression', '0 * * * *')
            ->set('new_description', 'Laravel scheduler')
            ->call('saveCronJob')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('server_cron_jobs', [
            'server_id' => $server->id,
            'command' => 'php /var/www/current/artisan schedule:run',
            'user' => 'deploy',
            'cron_expression' => '0 * * * *',
            'description' => 'Laravel scheduler',
        ]);
    }

    public function test_user_can_pause_and_delete_cron_job(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);
        $job = ServerCronJob::query()->create([
            'server_id' => $server->id,
            'cron_expression' => '* * * * *',
            'command' => 'php artisan schedule:run',
            'user' => 'deploy',
            'enabled' => true,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceCron::class, ['server' => $server])
            ->call('toggleCronJob', (string) $job->id);

        $this->assertFalse($job->fresh()->enabled);

        Livewire::actingAs($user)
            ->test(WorkspaceCron::class, ['server' => $server])
            ->call('deleteCronJob', (string) $job->id);

        $this->assertDatabaseMissing('server_cron_jobs', [
            'id' => $job->id,
        ]);
    }

    public function test_user_can_sync_crontab_from_basics_page(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        $synchronizer = Mockery::mock(ServerCronSynchronizer::class);
        $synchronizer->shouldReceive('sync')
            ->once()
            ->andReturn('installed');
        $this->app->instance(ServerCronSynchronizer::class, $synchronizer);

        Livewire::actingAs($user)
            ->test(WorkspaceCron::class, ['server' => $server])
            ->call('syncCronJobs')
            ->assertSet('flash_success', __('Crontab sync finished. Output: :out', ['out' => 'installed']));
    }

    public function test_loading_crontab_keeps_user_on_troubleshooting_tab(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->readyServer($user);

        $reader = Mockery::mock(ServerCrontabReader::class);
        $reader->shouldReceive('readForUser')
            ->once()
            ->andReturn([
                'body' => '* * * * * php artisan schedule:run',
                'exit_code' => 0,
            ]);
        $this->app->instance(ServerCrontabReader::class, $reader);

        Livewire::actingAs($user)
            ->test(WorkspaceCron::class, ['server' => $server])
            ->set('cron_workspace_tab', 'troubleshooting')
            ->set('inspect_crontab_user', 'deploy')
            ->call('loadInspectCrontab')
            ->assertSet('cron_workspace_tab', 'troubleshooting')
            ->assertSet('inspect_crontab_body', '* * * * * php artisan schedule:run')
            ->assertSet('inspect_crontab_exit_code', 0);
    }
}
