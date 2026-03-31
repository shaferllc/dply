<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceMonitorTest extends TestCase
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

    protected function deployerWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'deployer']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_monitor_page_shows_simplified_monitor_status_card(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_private_key' => 'test-private-key',
            'meta' => [
                'monitoring_ssh_reachable' => true,
                'monitoring_python_installed' => true,
                'monitoring_guest_push_token_hash' => hash('sha256', 'secret'),
                'monitoring_callback_env_deployed' => true,
                'monitoring_callback_env_deployed_at' => '2026-03-31T12:00:00Z',
                'monitoring_guest_cron_installed_at' => '2026-03-31T12:01:00Z',
                'monitoring_guest_push_cron_expression' => '* * * * *',
                'monitoring_callback_env_present_remote' => true,
                'monitoring_guest_cron_present_remote' => true,
                'monitoring_guest_script_sha' => app(\App\Services\Servers\ServerMetricsGuestScript::class)->bundledSha256(),
            ],
        ]);

        $response = $this->actingAs($user)->get(route('servers.monitor', $server));

        $response->assertOk();
        $response->assertSee('Monitor status');
        $response->assertSee('Installed and running');
        $response->assertSee('Every minute');
        $response->assertSee('Recheck monitor');
        $response->assertSee('Repair monitor now');
        $response->assertSee('Run callback diagnostics');
        $response->assertSee('Inspect callback env');
        $response->assertSee('The server pushes fresh metrics back to Dply every minute.');
    }

    public function test_monitor_page_hides_admin_actions_for_deployer_role(): void
    {
        $user = $this->deployerWithOrganization();
        $org = $user->currentOrganization();

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_private_key' => 'test-private-key',
            'meta' => [
                'monitoring_ssh_reachable' => true,
                'monitoring_python_installed' => true,
                'monitoring_guest_push_token_hash' => hash('sha256', 'secret'),
                'monitoring_callback_env_deployed' => true,
                'monitoring_callback_env_deployed_at' => '2026-03-31T12:00:00Z',
                'monitoring_guest_cron_installed_at' => '2026-03-31T12:01:00Z',
                'monitoring_guest_push_cron_expression' => '* * * * *',
                'monitoring_callback_env_present_remote' => true,
                'monitoring_guest_cron_present_remote' => true,
                'monitoring_guest_script_sha' => app(\App\Services\Servers\ServerMetricsGuestScript::class)->bundledSha256(),
            ],
        ]);

        $response = $this->actingAs($user)->get(route('servers.monitor', $server));

        $response->assertOk();
        $response->assertSee('Monitor status');
        $response->assertSee('Installed and running');
        $response->assertDontSee('Recheck monitor');
        $response->assertDontSee('Repair monitor now');
        $response->assertDontSee('Run callback diagnostics');
        $response->assertDontSee('Inspect callback env');
    }

    public function test_monitor_page_shows_extended_signals_and_deployment_context(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_private_key' => 'test-private-key',
            'meta' => [
                'monitoring_ssh_reachable' => true,
                'monitoring_python_installed' => true,
                'monitoring_guest_push_token_hash' => hash('sha256', 'secret'),
                'monitoring_callback_env_deployed' => true,
                'monitoring_guest_cron_installed_at' => '2026-03-31T12:01:00Z',
                'monitoring_guest_push_cron_expression' => '* * * * *',
                'monitoring_callback_env_present_remote' => true,
                'monitoring_guest_cron_present_remote' => true,
                'monitoring_guest_script_sha' => app(\App\Services\Servers\ServerMetricsGuestScript::class)->bundledSha256(),
            ],
        ]);

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => '2026-03-31T12:00:00Z',
            'payload' => [
                'cpu_pct' => 10.5,
                'mem_pct' => 42.0,
                'disk_pct' => 55.0,
                'load_1m' => 0.75,
                'load_5m' => 0.60,
                'load_15m' => 0.45,
                'mem_total_kb' => 1000000,
                'mem_available_kb' => 640000,
                'swap_total_kb' => 512000,
                'swap_used_kb' => 64000,
                'disk_total_bytes' => 100000000,
                'disk_used_bytes' => 55000000,
                'disk_free_bytes' => 45000000,
                'inode_pct_root' => 33.2,
                'cpu_count' => 4,
                'load_per_cpu_1m' => 0.19,
                'uptime_seconds' => 7200,
                'rx_bytes_per_sec' => 4096.5,
                'tx_bytes_per_sec' => 2048.25,
            ],
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Marketing App',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'deploy-test-1',
            'trigger' => SiteDeployment::TRIGGER_MANUAL,
            'status' => SiteDeployment::STATUS_SUCCESS,
            'git_sha' => 'abc1234',
            'started_at' => now()->subMinutes(4),
            'finished_at' => now()->subMinutes(2),
        ]);

        $response = $this->actingAs($user)->get(route('servers.monitor', $server));

        $response->assertOk();
        $response->assertSee('Headroom and pressure');
        $response->assertSee('Memory headroom');
        $response->assertSee('Swap pressure');
        $response->assertSee('Disk headroom');
        $response->assertSee('Site and deployment context');
        $response->assertSee('Latest deployment');
        $response->assertSee('Marketing App');
        $response->assertSee('Hover the graph for fuller sample details.');
    }
}
