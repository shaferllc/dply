<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Models;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tests\TestCase;

uses(TestCase::class);

it('task factory creates valid task', function () {
    $task = Task::factory()->create();

    expect($task)->toBeInstanceOf(Task::class);
    expect($task->name)->not->toBeNull();
    expect($task->status)->not->toBeNull();
    expect($task->status)->toBeInstanceOf(TaskStatus::class);
});

it('task has fillable attributes', function () {
    $task = new Task([
        'name' => 'Test Task',
        'status' => TaskStatus::Pending,
        'exit_code' => 0,
        'output' => 'Test output',
        'timeout' => 300,
        'user' => 'testuser',
        'instance' => 'test-instance',
    ]);

    expect($task->name)->toBe('Test Task');
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->exit_code)->toBe(0);
    expect($task->output)->toBe('Test output');
    expect($task->timeout)->toBe(300);
    expect($task->user)->toBe('testuser');
    expect($task->instance)->toBe('test-instance');
});

it('task casts attributes', function () {
    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'exit_code' => 1,
        'timeout' => 600,
    ]);

    expect($task->status)->toBeInstanceOf(TaskStatus::class);
    expect($task->exit_code)->toBeInt();
    expect($task->timeout)->toBeInt();
});

it('task has timestamps', function () {
    $task = Task::factory()->create();

    expect($task->created_at)->not->toBeNull();
    expect($task->updated_at)->not->toBeNull();
});

it('task can have started at', function () {
    $task = Task::factory()->create([
        'started_at' => now()->subMinutes(5),
    ]);

    expect($task->started_at)->not->toBeNull();
    expect($task->started_at)->toBeInstanceOf(Carbon::class);
});

it('task can have completed at', function () {
    $task = Task::factory()->create([
        'completed_at' => now()->subMinutes(2),
    ]);

    expect($task->completed_at)->not->toBeNull();
    expect($task->completed_at)->toBeInstanceOf(Carbon::class);
});

it('task can be pending', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Pending]);

    expect($task->isPending())->toBeTrue();
    expect($task->isRunning())->toBeFalse();
    expect($task->isFinished())->toBeFalse();
    expect($task->isFailed())->toBeFalse();
});

it('task can be running', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Running]);

    expect($task->isRunning())->toBeTrue();
    expect($task->isPending())->toBeFalse();
    expect($task->isFinished())->toBeFalse();
    expect($task->isFailed())->toBeFalse();
});

it('task can be finished', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Finished]);

    expect($task->isFinished())->toBeTrue();
    expect($task->isPending())->toBeFalse();
    expect($task->isRunning())->toBeFalse();
    expect($task->isFailed())->toBeFalse();
});

it('task can be failed', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Failed]);

    expect($task->isFailed())->toBeTrue();
    expect($task->isPending())->toBeFalse();
    expect($task->isRunning())->toBeFalse();
    expect($task->isFinished())->toBeFalse();
});

it('task can be timed out', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Timeout]);

    expect($task->isTimedOut())->toBeTrue();
    expect($task->isPending())->toBeFalse();
    expect($task->isRunning())->toBeFalse();
    expect($task->isFinished())->toBeFalse();
});

it('task can be cancelled', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Cancelled]);

    expect($task->status)->toBe(TaskStatus::Cancelled);
    expect($task->isPending())->toBeFalse();
    expect($task->isRunning())->toBeFalse();
    expect($task->isFinished())->toBeFalse();
});

it('task can have upload failed', function () {
    $task = Task::factory()->create(['status' => TaskStatus::UploadFailed]);

    expect($task->status)->toBe(TaskStatus::UploadFailed);
    expect($task->isPending())->toBeFalse();
    expect($task->isRunning())->toBeFalse();
    expect($task->isFinished())->toBeFalse();
});

it('task can have connection failed', function () {
    $task = Task::factory()->create(['status' => TaskStatus::ConnectionFailed]);

    expect($task->status)->toBe(TaskStatus::ConnectionFailed);
    expect($task->isPending())->toBeFalse();
    expect($task->isRunning())->toBeFalse();
    expect($task->isFinished())->toBeFalse();
});

it('task can be successful', function () {
    $task = Task::factory()->create([
        'status' => TaskStatus::Finished,
        'exit_code' => 0,
    ]);

    expect($task->isSuccessful())->toBeTrue();
});

it('task is not successful when failed', function () {
    $task = Task::factory()->create([
        'status' => TaskStatus::Failed,
        'exit_code' => 1,
    ]);

    expect($task->isSuccessful())->toBeFalse();
});

it('task is not successful when not finished', function () {
    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'exit_code' => 0,
    ]);

    expect($task->isSuccessful())->toBeFalse();
});

it('task can have duration', function () {
    $task = Task::factory()->create([
        'started_at' => now()->subMinutes(5),
        'completed_at' => now()->subMinutes(2),
    ]);

    expect($task->getDuration())->toBe(180);
});

it('task has zero duration when not started', function () {
    $task = Task::factory()->create([
        'started_at' => null,
        'completed_at' => now()->subMinutes(2),
    ]);

    expect($task->getDuration())->toBe(0);
});

it('task returns elapsed duration when not completed', function () {
    $task = Task::factory()->create([
        'started_at' => now()->subMinutes(5),
        'completed_at' => null,
    ]);

    expect($task->getDuration())->toBeGreaterThanOrEqual(299);
});

it('task can have output lines', function () {
    $task = Task::factory()->create([
        'output' => "Line 1\nLine 2\nLine 3",
    ]);

    expect($task->outputLines()->count())->toBe(3);
});

it('task has zero output lines when no output', function () {
    $task = Task::factory()->create(['output' => null]);

    expect($task->outputLines()->count())->toBe(0);
});

it('task can have output size', function () {
    $output = 'This is a test output';
    $task = Task::factory()->create(['output' => $output]);

    expect(strlen($task->output ?? ''))->toBe(strlen($output));
});

it('task has zero output size when no output', function () {
    $task = Task::factory()->create(['output' => null]);

    expect(strlen($task->output ?? ''))->toBe(0);
});

it('task can generate callback url', function () {
    $task = Task::factory()->create();

    $url = $task->callbackUrl();
    expect($url)->toContain((string) $task->id);
});

it('task can generate timeout url', function () {
    $task = Task::factory()->create();

    $url = $task->timeoutUrl();
    expect($url)->toContain((string) $task->id);
});

it('task can generate failed url', function () {
    $task = Task::factory()->create();

    $url = $task->failedUrl();
    expect($url)->toContain((string) $task->id);
});

it('task can generate finished url', function () {
    $task = Task::factory()->create();

    $url = $task->finishedUrl();
    expect($url)->toContain((string) $task->id);
});

it('task can be older than timeout', function () {
    $task = Task::factory()->create([
        'created_at' => now()->subMinutes(10),
        'timeout' => 300,
    ]);

    expect($task->isOlderThanTimeout())->toBeTrue();
});

it('task is not older than timeout when within timeout', function () {
    $task = Task::factory()->create([
        'created_at' => now()->subMinutes(2),
        'timeout' => 300,
    ]);

    expect($task->isOlderThanTimeout())->toBeFalse();
});

it('task is not older than timeout with very large timeout', function () {
    $task = Task::factory()->create([
        'created_at' => now()->subMinutes(10),
        'timeout' => 86400,
    ]);

    expect($task->isOlderThanTimeout())->toBeFalse();
});

it('task is not older than timeout when created recently', function () {
    $task = Task::factory()->create([
        'created_at' => now()->subSeconds(30),
        'timeout' => 300,
    ]);

    expect($task->isOlderThanTimeout())->toBeFalse();
});

it('task has server relationship', function () {
    $task = Task::factory()->create();

    expect($task->server())->toBeInstanceOf(BelongsTo::class);
});

it('task can have options', function () {
    $task = Task::factory()->create([
        'options' => ['key1' => 'value1', 'key2' => 'value2'],
    ]);

    expect($task->options)->toBe(['key1' => 'value1', 'key2' => 'value2']);
});

it('task can have script', function () {
    $script = 'echo "Hello World"';
    $task = Task::factory()->create(['script' => $script]);

    expect($task->script)->toBe($script);
});

it('task can have action', function () {
    $task = Task::factory()->create(['action' => 'deploy']);

    expect($task->action)->toBe('deploy');
});
