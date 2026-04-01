<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\CheckSupervisorHealthCommand;
use App\Models\Organization;
use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupervisorHealthNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_health_command_publishes_universal_notification_for_org_admins(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_private_key' => 'test-private-key',
            'supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED,
            'name' => 'queue-1',
        ]);

        SupervisorProgram::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'slug' => 'worker',
            'program_type' => 'queue',
            'command' => 'php artisan queue:work',
            'directory' => '/var/www/app',
            'user' => 'forge',
            'numprocs' => 1,
            'is_active' => true,
        ]);

        $provisioner = $this->mock(SupervisorProvisioner::class);
        $provisioner->shouldReceive('fetchSupervisorctlStatus')->once()->andReturn('worker STOPPED');
        $provisioner->shouldReceive('analyzeStatusForManagedPrograms')->once()->andReturn([
            'ok' => false,
            'summary' => 'worker is STOPPED',
        ]);
        $provisioner->shouldReceive('hasConfigDrift')->once()->andReturn(false);

        $this->artisan(CheckSupervisorHealthCommand::class)->assertSuccessful();

        $this->assertSame(false, (bool) ($server->fresh()->meta['supervisor_health']['ok'] ?? true));

        $this->assertDatabaseHas('notification_events', [
            'event_key' => 'server.supervisor.unhealthy',
            'subject_type' => Server::class,
            'subject_id' => $server->id,
            'organization_id' => $org->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $owner->id,
            'type' => \App\Notifications\UniversalEventNotification::class,
        ]);
    }
}
