<?php

namespace Tests\Feature\ServerWorkspaceNavTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerProvisionRun;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

usesFeatures('workspace.services');

test('nav shows all items when stack summary is missing', function () {
    $server = serverWithoutProvisionArtifact();

    $keys = array_column(server_workspace_nav_for_server($server), 'key');

    // Fail-open: every configured item is visible when we don't yet know the stack.
    foreach (['php', 'databases', 'daemons', 'firewall'] as $key) {
        expect($keys)->toContain($key);
    }
});

test('nav hides php and databases when stack excludes them', function () {
    $server = serverWithStack([
        'webserver' => 'haproxy',
        'php_version' => 'none',
        'database' => 'none',
        'cache_service' => 'none',
        'expected_services' => ['haproxy', 'ufw'],
    ]);

    $keys = array_column(server_workspace_nav_for_server($server), 'key');

    expect($keys)->not->toContain('php');
    expect($keys)->not->toContain('databases');

    // Daemons stays visible even without supervisor installed — the page itself
    // offers the Install Supervisor CTA, so the nav entry can't be gated on it.
    expect($keys)->toContain('daemons');
    expect($keys)->toContain('queue-workers');

    // Always-on / non-gated tabs stay.
    expect($keys)->toContain('firewall');
    expect($keys)->toContain('settings');
    expect($keys)->toContain('services');
});

test('nav shows php and databases when stack installs them', function () {
    $server = serverWithStack([
        'webserver' => 'nginx',
        'php_version' => '8.3',
        'database' => 'postgres17',
        'cache_service' => 'redis',
        'expected_services' => ['nginx', 'php-fpm', 'postgresql', 'redis'],
    ]);

    $keys = array_column(server_workspace_nav_for_server($server), 'key');

    expect($keys)->toContain('php');
    expect($keys)->toContain('databases');

    // Daemons and Queue workers ride on the SSH host, not on Supervisor being installed.
    expect($keys)->toContain('daemons');
    expect($keys)->toContain('queue-workers');
});

test('nav flags daemons needs setup when supervisor missing', function () {
    $server = serverWithStack([
        'webserver' => 'nginx',
        'php_version' => 'none',
        'database' => 'none',
        'cache_service' => 'none',
        'expected_services' => ['nginx'],
    ]);

    // No supervisor_package_status update — default leaves it null/missing.
    $items = collect(server_workspace_nav_for_server($server))->keyBy('key');

    expect((bool) ($items['daemons']['needs_setup'] ?? false))->toBeTrue();
    expect((bool) ($items['queue-workers']['needs_setup'] ?? false))->toBeTrue();
});

test('nav drops needs setup flag when supervisor installed', function () {
    $server = serverWithStack([
        'webserver' => 'nginx',
        'php_version' => 'none',
        'database' => 'none',
        'cache_service' => 'none',
        'expected_services' => ['nginx'],
    ]);
    $server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);

    $items = collect(server_workspace_nav_for_server($server->fresh()))->keyBy('key');

    expect($items)->toHaveKey('daemons');
    expect((bool) ($items['daemons']['needs_setup'] ?? false))->toBeFalse();
    expect((bool) ($items['queue-workers']['needs_setup'] ?? false))->toBeFalse();
});

test('route gate returns 404 for php when php is not installed', function () {
    $server = serverWithStack([
        'webserver' => 'haproxy',
        'php_version' => 'none',
        'database' => 'none',
        'cache_service' => 'none',
        'expected_services' => ['haproxy'],
    ]);

    $this->actingAs($server->user)
        ->get(route('servers.php', $server))
        ->assertNotFound();
});

test('route gate returns 404 for databases when no db is installed', function () {
    $server = serverWithStack([
        'webserver' => 'nginx',
        'php_version' => '8.3',
        'database' => 'none',
        'cache_service' => 'none',
        'expected_services' => ['nginx', 'php-fpm'],
    ]);

    $this->actingAs($server->user)
        ->get(route('servers.databases', $server))
        ->assertNotFound();
});

test('route gate allows php when php is installed', function () {
    $server = serverWithStack([
        'webserver' => 'nginx',
        'php_version' => '8.3',
        'database' => 'none',
        'cache_service' => 'none',
        'expected_services' => ['nginx', 'php-fpm'],
    ]);

    $this->actingAs($server->user)
        ->get(route('servers.php', $server))
        ->assertOk();
});

test('route gate fails open when stack summary is unknown', function () {
    $server = serverWithoutProvisionArtifact();

    // No stack summary artifact yet → tags include 'unknown' → middleware passes through.
    $this->actingAs($server->user)
        ->get(route('servers.php', $server))
        ->assertOk();
});

function serverWithoutProvisionArtifact(): Server
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
function serverWithStack(array $stack): Server
{
    $server = serverWithoutProvisionArtifact();

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
