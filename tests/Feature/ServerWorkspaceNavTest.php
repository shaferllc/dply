<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerProvisionRun;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class ServerWorkspaceNavTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = [
        'workspace.cluster', 'workspace.console', 'workspace.files', 'workspace.services',
        'workspace.system_users', 'workspace.insights', 'workspace.caches', 'workspace.schedule',
        'workspace.activity', 'workspace.run',
    ];

    public function test_nav_shows_all_items_when_stack_summary_is_missing(): void
    {
        $server = $this->serverWithoutProvisionArtifact();

        $keys = array_column(server_workspace_nav_for_server($server), 'key');

        // Fail-open: every configured item is visible when we don't yet know the stack.
        foreach (['php', 'databases', 'daemons', 'firewall'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_nav_hides_php_and_databases_when_stack_excludes_them(): void
    {
        $server = $this->serverWithStack([
            'webserver' => 'haproxy',
            'php_version' => 'none',
            'database' => 'none',
            'cache_service' => 'none',
            'expected_services' => ['haproxy', 'ufw'],
        ]);

        $keys = array_column(server_workspace_nav_for_server($server), 'key');

        $this->assertNotContains('php', $keys);
        $this->assertNotContains('databases', $keys);
        // Daemons stays visible even without supervisor installed — the page itself
        // offers the Install Supervisor CTA, so the nav entry can't be gated on it.
        $this->assertContains('daemons', $keys);
        $this->assertContains('queue-workers', $keys);
        // Always-on / non-gated tabs stay.
        $this->assertContains('firewall', $keys);
        $this->assertContains('settings', $keys);
        $this->assertContains('services', $keys);
    }

    public function test_nav_shows_php_and_databases_when_stack_installs_them(): void
    {
        $server = $this->serverWithStack([
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'postgres17',
            'cache_service' => 'redis',
            'expected_services' => ['nginx', 'php-fpm', 'postgresql', 'redis'],
        ]);

        $keys = array_column(server_workspace_nav_for_server($server), 'key');

        $this->assertContains('php', $keys);
        $this->assertContains('databases', $keys);
        // Daemons and Queue workers ride on the SSH host, not on Supervisor being installed.
        $this->assertContains('daemons', $keys);
        $this->assertContains('queue-workers', $keys);
    }

    public function test_nav_flags_daemons_needs_setup_when_supervisor_missing(): void
    {
        $server = $this->serverWithStack([
            'webserver' => 'nginx',
            'php_version' => 'none',
            'database' => 'none',
            'cache_service' => 'none',
            'expected_services' => ['nginx'],
        ]);
        // No supervisor_package_status update — default leaves it null/missing.

        $items = collect(server_workspace_nav_for_server($server))->keyBy('key');

        $this->assertTrue((bool) ($items['daemons']['needs_setup'] ?? false));
        $this->assertTrue((bool) ($items['queue-workers']['needs_setup'] ?? false));
    }

    public function test_nav_drops_needs_setup_flag_when_supervisor_installed(): void
    {
        $server = $this->serverWithStack([
            'webserver' => 'nginx',
            'php_version' => 'none',
            'database' => 'none',
            'cache_service' => 'none',
            'expected_services' => ['nginx'],
        ]);
        $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);

        $items = collect(server_workspace_nav_for_server($server->fresh()))->keyBy('key');

        $this->assertArrayHasKey('daemons', $items);
        $this->assertFalse((bool) ($items['daemons']['needs_setup'] ?? false));
        $this->assertFalse((bool) ($items['queue-workers']['needs_setup'] ?? false));
    }

    public function test_route_gate_returns_404_for_php_when_php_is_not_installed(): void
    {
        $server = $this->serverWithStack([
            'webserver' => 'haproxy',
            'php_version' => 'none',
            'database' => 'none',
            'cache_service' => 'none',
            'expected_services' => ['haproxy'],
        ]);

        $this->actingAs($server->user)
            ->get(route('servers.php', $server))
            ->assertNotFound();
    }

    public function test_route_gate_returns_404_for_databases_when_no_db_is_installed(): void
    {
        $server = $this->serverWithStack([
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'none',
            'cache_service' => 'none',
            'expected_services' => ['nginx', 'php-fpm'],
        ]);

        $this->actingAs($server->user)
            ->get(route('servers.databases', $server))
            ->assertNotFound();
    }

    public function test_route_gate_allows_php_when_php_is_installed(): void
    {
        $server = $this->serverWithStack([
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'none',
            'cache_service' => 'none',
            'expected_services' => ['nginx', 'php-fpm'],
        ]);

        $this->actingAs($server->user)
            ->get(route('servers.php', $server))
            ->assertOk();
    }

    public function test_route_gate_fails_open_when_stack_summary_is_unknown(): void
    {
        $server = $this->serverWithoutProvisionArtifact();

        // No stack summary artifact yet → tags include 'unknown' → middleware passes through.
        $this->actingAs($server->user)
            ->get(route('servers.php', $server))
            ->assertOk();
    }

    private function serverWithoutProvisionArtifact(): Server
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_organization_id' => $org->id]);
        session(['current_organization_id' => $org->id]);

        return Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stack
     */
    private function serverWithStack(array $stack): Server
    {
        $server = $this->serverWithoutProvisionArtifact();

        $task = Task::query()->create([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'timeout' => 600,
            'user' => 'root',
            'status' => TaskStatus::Finished,
            'output' => '',
            'server_id' => $server->id,
            'created_by' => $server->user_id,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);

        $run = ServerProvisionRun::query()->create([
            'server_id' => $server->id,
            'task_id' => $task->id,
            'attempt' => 1,
            'status' => 'completed',
            'summary' => 'Provisioning completed.',
            'started_at' => now()->subMinutes(3),
            'completed_at' => now(),
        ]);

        $run->artifacts()->create([
            'type' => 'stack_summary',
            'key' => 'stack-summary',
            'label' => 'Installed stack',
            'metadata' => $stack,
            'content' => '{}',
        ]);

        return $server->fresh();
    }
}
