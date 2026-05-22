<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\InstalledStackReconciliationTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Support\Servers\InstalledStack;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('observer reconciles installed stack from tagged output line', function () {
    [$user, $server] = makeServer(['database' => 'mysql84', 'php_version' => '8.4']);

    $task = makeProvisionTask($user, $server, '[dply-step] Provisioning starting');

    // No installed_stack yet — the tagged line hasn't appeared in output.
    $this->assertArrayNotHasKey(
        InstalledStack::META_KEY,
        $server->fresh()->meta ?? []
    );

    // Simulate bash emitting the snapshot line at end of script.
    $task->update(['output' => $task->output."\n".stackLine([
        'database' => 'sqlite3',
        'database_version' => '3.45.1',
        'php_version' => '8.4',
        'webserver' => 'nginx',
        'cache_service' => 'redis',
        'low_mem_mode' => true,
        'total_memory_mb' => 458,
        'swap_mb' => 2048,
    ])]);

    $stack = InstalledStack::fromMeta($server->fresh());

    // Reconciled snapshot is now in meta.
    expect($stack->database)->toBe('sqlite3');
    expect($stack->databaseVersion)->toBe('3.45.1');
    expect($stack->lowMemoryMode)->toBeTrue();
    expect($stack->totalMemoryMb)->toBe(458);
    expect($stack->swapMb)->toBe(2048);

    // Wizard request preserved separately for divergence display.
    expect($server->fresh()->meta['database'])->toBe('mysql84');
    expect($stack->divergesFromRequest($server->fresh()))->toBeTrue();
});
test('observer skips write when snapshot unchanged', function () {
    [$user, $server] = makeServer();

    $line = stackLine([
        'database' => 'mysql84',
        'database_version' => '8.0.45',
        'php_version' => '8.4',
        'webserver' => 'nginx',
        'cache_service' => 'redis',
        'low_mem_mode' => false,
        'total_memory_mb' => 2048,
        'swap_mb' => 2048,
    ]);

    // Observer hooks `updated` (not `created`), so we make the task
    // without the line, then update() to fire reconciliation.
    $task = makeProvisionTask($user, $server, '[dply-step] starting');
    $task->update(['output' => $line]);

    $reconciled = $server->fresh()->meta[InstalledStack::META_KEY] ?? null;
    expect($reconciled['database'] ?? null)->toBe('mysql84');

    // Capture the updated_at, then re-update the task with the same
    // tagged line — observer should diff and skip the DB write.
    $updatedAtBefore = $server->fresh()->updated_at;

    // Tiny pause so we'd notice if updated_at moved.
    usleep(20_000);

    $task->update(['output' => $task->output."\nfollow-up bash output\n".$line]);

    $updatedAtAfter = $server->fresh()->updated_at;

    expect($updatedAtAfter)->toEqual($updatedAtBefore, 'server row should not be re-saved when installed_stack is unchanged');
});
test('observer ignores malformed tagged line', function () {
    [$user, $server] = makeServer();

    $task = makeProvisionTask($user, $server, '[dply-installed-stack] {not valid json');

    $this->assertArrayNotHasKey(
        InstalledStack::META_KEY,
        $server->fresh()->meta ?? []
    );
});
test('from meta falls back to wizard keys when snapshot absent', function () {
    [$user, $server] = makeServer([
        'database' => 'postgres17',
        'php_version' => '8.3',
        'webserver' => 'caddy',
        'cache_service' => 'valkey',
    ]);

    $stack = InstalledStack::fromMeta($server);

    // Legacy server with no observer-written snapshot — fromMeta
    // synthesizes from wizard keys so consumers still get a usable
    // value object.
    expect($stack->database)->toBe('postgres17');
    expect($stack->phpVersion)->toBe('8.3');
    expect($stack->webserver)->toBe('caddy');
    expect($stack->cacheService)->toBe('valkey');
    expect($stack->databaseVersion)->toBeNull();
    // never recorded
});
/**
 * @param  array<string,mixed>  $extraMeta
 * @return array{0:User,1:Server}
 */
function makeServer(array $extraMeta = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_RUNNING,
        'ip_address' => '203.0.113.10',
        'meta' => array_merge([
            'server_role' => 'application',
            'database' => 'mysql84',
            'php_version' => '8.4',
            'webserver' => 'nginx',
            'cache_service' => 'redis',
        ], $extraMeta),
    ]);

    return [$user, $server];
}
function makeProvisionTask(User $user, Server $server, string $output): Task
{
    $task = Task::query()->create([
        'name' => 'Server stack provision',
        'action' => 'provision_stack',
        'script' => 'dply-provision-stack.sh',
        'timeout' => 600,
        'user' => 'root',
        'status' => TaskStatus::Running,
        'output' => $output,
        'server_id' => $server->id,
        'created_by' => $user->id,
        'started_at' => now()->subSeconds(10),
    ]);

    // Wire provision_task_id so the observer's gate
    // (`$meta['provision_task_id'] !== $task->id` skip) doesn't
    // short-circuit the reconciliation path. Without this, the
    // observer treats the task as "stale" and bails before reaching
    // the snapshot parser.
    $meta = $server->meta ?? [];
    $meta['provision_task_id'] = (string) $task->id;
    $server->update(['meta' => $meta]);

    return $task;
}
/** @param  array<string,mixed>  $fields */
function stackLine(array $fields): string
{
    return '[dply-installed-stack] '.json_encode($fields, JSON_UNESCAPED_SLASHES);
}
