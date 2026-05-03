<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TaskRunnerVerifyWebhooksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_task_id_returns_failure(): void
    {
        $exit = Artisan::call('dply:task-runner-verify-webhooks');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pass a task ULID', Artisan::output());
    }

    public function test_unknown_task_returns_failure(): void
    {
        $exit = Artisan::call('dply:task-runner-verify-webhooks', [
            'task_id' => '01abcdefghijklmnopqrstuvwx',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No task_runner_tasks row found', Artisan::output());
    }

    public function test_prints_signed_webhook_urls(): void
    {
        $task = Task::query()->create([
            'name' => 'Webhook probe',
            'action' => 'probe',
            'status' => TaskStatus::Running,
            'output' => null,
        ]);

        $exit = Artisan::call('dply:task-runner-verify-webhooks', [
            'task_id' => $task->id,
            '--urls-only' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('update-output', $output);
        $this->assertStringContainsString('mark-as-finished', $output);
        $this->assertStringContainsString('mark-as-failed', $output);
        $this->assertStringContainsString('mark-as-timed-out', $output);
        $this->assertStringContainsString($task->id, $output);
        // Each URL should carry a signature query param.
        $this->assertStringContainsString('signature=', $output);
    }

    public function test_default_run_hints_at_ping_local_flag(): void
    {
        $task = Task::query()->create([
            'name' => 'Webhook probe',
            'action' => 'probe',
            'status' => TaskStatus::Running,
            'output' => null,
        ]);

        Artisan::call('dply:task-runner-verify-webhooks', [
            'task_id' => $task->id,
        ]);

        $this->assertStringContainsString('Re-run with --ping-local', Artisan::output());
    }
}
