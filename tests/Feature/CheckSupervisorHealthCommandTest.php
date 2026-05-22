<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class CheckSupervisorHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_op_when_disabled(): void
    {
        Config::set('dply.supervisor_health_check_enabled', false);

        $exit = Artisan::call('dply:supervisor-check-health');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('disabled', Artisan::output());
    }

    public function test_skips_servers_without_active_programs(): void
    {
        Config::set('dply.supervisor_health_check_enabled', true);

        $user = User::factory()->create();
        Server::factory()->ready()->create([
            'user_id' => $user->id,
            'ssh_private_key' => 'k',
        ]);

        // Should never call SSH because no supervisor programs exist.
        $mock = Mockery::mock(SupervisorProvisioner::class);
        $mock->shouldNotReceive('fetchSupervisorctlStatus');
        $this->app->instance(SupervisorProvisioner::class, $mock);

        $exit = Artisan::call('dply:supervisor-check-health');

        $this->assertSame(0, $exit);
    }

    public function test_skips_server_when_supervisor_package_missing(): void
    {
        Config::set('dply.supervisor_health_check_enabled', true);

        $user = User::factory()->create();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'ssh_private_key' => 'k',
            'supervisor_package_status' => Server::SUPERVISOR_PACKAGE_MISSING,
        ]);
        SupervisorProgram::query()->create([
            'server_id' => $server->id,
            'slug' => 'queue-default',
            'program_type' => 'site',
            'command' => 'php /var/www/app/artisan queue:work',
            'directory' => '/var/www/app',
            'user' => 'forge',
            'numprocs' => 1,
            'is_active' => true,
        ]);

        $mock = Mockery::mock(SupervisorProvisioner::class);
        $mock->shouldNotReceive('fetchSupervisorctlStatus');
        $this->app->instance(SupervisorProvisioner::class, $mock);

        $exit = Artisan::call('dply:supervisor-check-health');

        $this->assertSame(0, $exit);
    }

    public function test_unhealthy_supervisor_status_records_meta_and_notifies(): void
    {
        Config::set('dply.supervisor_health_check_enabled', true);
        Notification::fake();
        Cache::flush();

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ssh_private_key' => 'k',
            'supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED ?? 'installed',
        ]);
        SupervisorProgram::query()->create([
            'server_id' => $server->id,
            'slug' => 'queue-default',
            'program_type' => 'site',
            'command' => 'php /var/www/app/artisan queue:work',
            'directory' => '/var/www/app',
            'user' => 'forge',
            'numprocs' => 1,
            'is_active' => true,
        ]);

        $mock = Mockery::mock(SupervisorProvisioner::class);
        $mock->shouldReceive('fetchSupervisorctlStatus')
            ->andReturn("queue-default FATAL    Exited too quickly\n");
        $mock->shouldReceive('analyzeStatusForManagedPrograms')
            ->andReturn(['ok' => false, 'summary' => '1 program in FATAL state']);
        $mock->shouldReceive('hasConfigDrift')->andReturn(false);
        $this->app->instance(SupervisorProvisioner::class, $mock);

        Artisan::call('dply:supervisor-check-health');

        $server->refresh();
        $health = $server->meta['supervisor_health'] ?? null;
        $this->assertIsArray($health);
        $this->assertFalse($health['ok']);
        $this->assertStringContainsString('FATAL', $health['summary']);
    }

    public function test_ssh_failure_is_logged_and_does_not_crash_command(): void
    {
        Config::set('dply.supervisor_health_check_enabled', true);

        $user = User::factory()->create();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'ssh_private_key' => 'k',
            'supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED ?? 'installed',
            'name' => 'edge-1',
        ]);
        SupervisorProgram::query()->create([
            'server_id' => $server->id,
            'slug' => 'queue-default',
            'program_type' => 'site',
            'command' => 'php /var/www/app/artisan queue:work',
            'directory' => '/var/www/app',
            'user' => 'forge',
            'numprocs' => 1,
            'is_active' => true,
        ]);

        $mock = Mockery::mock(SupervisorProvisioner::class);
        $mock->shouldReceive('fetchSupervisorctlStatus')
            ->andThrow(new \RuntimeException('connection refused'));
        $this->app->instance(SupervisorProvisioner::class, $mock);

        $exit = Artisan::call('dply:supervisor-check-health');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('edge-1: connection refused', Artisan::output());
    }
}
