<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Support\Servers\InstalledStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end: bash emits `[dply-installed-stack] {json}` line in task
 * output → TaskRunnerTaskObserver picks it up → server.meta.installed_stack
 * is reconciled → InstalledStack::fromMeta returns the snapshot.
 */
class InstalledStackReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_observer_reconciles_installed_stack_from_tagged_output_line(): void
    {
        [$user, $server] = $this->makeServer(['database' => 'mysql84', 'php_version' => '8.4']);

        $task = $this->makeProvisionTask($user, $server, '[dply-step] Provisioning starting');

        // No installed_stack yet — the tagged line hasn't appeared in output.
        $this->assertArrayNotHasKey(
            InstalledStack::META_KEY,
            $server->fresh()->meta ?? []
        );

        // Simulate bash emitting the snapshot line at end of script.
        $task->update(['output' => $task->output."\n".$this->stackLine([
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
        $this->assertSame('sqlite3', $stack->database);
        $this->assertSame('3.45.1', $stack->databaseVersion);
        $this->assertTrue($stack->lowMemoryMode);
        $this->assertSame(458, $stack->totalMemoryMb);
        $this->assertSame(2048, $stack->swapMb);

        // Wizard request preserved separately for divergence display.
        $this->assertSame('mysql84', $server->fresh()->meta['database']);
        $this->assertTrue($stack->divergesFromRequest($server->fresh()));
    }

    public function test_observer_skips_write_when_snapshot_unchanged(): void
    {
        [$user, $server] = $this->makeServer();

        $line = $this->stackLine([
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
        $task = $this->makeProvisionTask($user, $server, '[dply-step] starting');
        $task->update(['output' => $line]);

        $reconciled = $server->fresh()->meta[InstalledStack::META_KEY] ?? null;
        $this->assertSame('mysql84', $reconciled['database'] ?? null);

        // Capture the updated_at, then re-update the task with the same
        // tagged line — observer should diff and skip the DB write.
        $updatedAtBefore = $server->fresh()->updated_at;

        // Tiny pause so we'd notice if updated_at moved.
        usleep(20_000);

        $task->update(['output' => $task->output."\nfollow-up bash output\n".$line]);

        $updatedAtAfter = $server->fresh()->updated_at;

        $this->assertEquals($updatedAtBefore, $updatedAtAfter,
            'server row should not be re-saved when installed_stack is unchanged');
    }

    public function test_observer_ignores_malformed_tagged_line(): void
    {
        [$user, $server] = $this->makeServer();

        $task = $this->makeProvisionTask(
            $user,
            $server,
            '[dply-installed-stack] {not valid json'
        );

        $this->assertArrayNotHasKey(
            InstalledStack::META_KEY,
            $server->fresh()->meta ?? []
        );
    }

    public function test_from_meta_falls_back_to_wizard_keys_when_snapshot_absent(): void
    {
        [$user, $server] = $this->makeServer([
            'database' => 'postgres17',
            'php_version' => '8.3',
            'webserver' => 'caddy',
            'cache_service' => 'valkey',
        ]);

        $stack = InstalledStack::fromMeta($server);

        // Legacy server with no observer-written snapshot — fromMeta
        // synthesizes from wizard keys so consumers still get a usable
        // value object.
        $this->assertSame('postgres17', $stack->database);
        $this->assertSame('8.3', $stack->phpVersion);
        $this->assertSame('caddy', $stack->webserver);
        $this->assertSame('valkey', $stack->cacheService);
        $this->assertNull($stack->databaseVersion); // never recorded
    }

    /**
     * @param  array<string,mixed>  $extraMeta
     * @return array{0:User,1:Server}
     */
    private function makeServer(array $extraMeta = []): array
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

    private function makeProvisionTask(User $user, Server $server, string $output): Task
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
    private function stackLine(array $fields): string
    {
        return '[dply-installed-stack] '.json_encode($fields, JSON_UNESCAPED_SLASHES);
    }
}
