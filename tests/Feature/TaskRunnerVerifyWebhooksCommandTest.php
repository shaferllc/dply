<?php

declare(strict_types=1);

namespace Tests\Feature\TaskRunnerVerifyWebhooksCommandTest;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('missing task id returns failure', function () {
    $exit = Artisan::call('dply:task-runner-verify-webhooks');

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Pass a task ULID', Artisan::output());
});
test('unknown task returns failure', function () {
    $exit = Artisan::call('dply:task-runner-verify-webhooks', [
        'task_id' => '01abcdefghijklmnopqrstuvwx',
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('No task_runner_tasks row found', Artisan::output());
});
test('prints signed webhook urls', function () {
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

    expect($exit)->toBe(0);
    $output = Artisan::output();
    $this->assertStringContainsString('update-output', $output);
    $this->assertStringContainsString('mark-as-finished', $output);
    $this->assertStringContainsString('mark-as-failed', $output);
    $this->assertStringContainsString('mark-as-timed-out', $output);
    $this->assertStringContainsString($task->id, $output);

    // Each URL should carry a signature query param.
    $this->assertStringContainsString('signature=', $output);
});
test('default run hints at ping local flag', function () {
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
});
