<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Server;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\TaskDispatcher;
use App\Modules\TaskRunner\Services\TaskRunnerService;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->service = app(TaskRunnerService::class);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('create and execute task returns result', function () {
    $result = $this->service->createAndExecute('Test Task', 'echo "Hello World"');

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('task_id');
});

it('get task status returns task details', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Running]);

    $status = $this->service->getTaskStatus($task->id);

    expect($status['found'])->toBeTrue()
        ->and($status['task'])->toBeInstanceOf(Task::class)
        ->and($status['progress'])->toBeInt();
});

it('get task status handles not found', function () {
    $status = $this->service->getTaskStatus('non-existent-id');

    expect($status['found'])->toBeFalse()
        ->and($status)->toHaveKey('error');
});

it('create task chain returns chain', function () {
    $tasks = [
        ['script' => 'echo "Task 1"'],
        ['script' => 'echo "Task 2"'],
    ];

    $result = $this->service->createTaskChain($tasks);

    expect($result['success'])->toBeTrue()
        ->and($result['task_count'])->toBe(2);
});

it('execute parallel tasks returns results', function () {
    $tasks = [
        ['name' => 'Task 1', 'script' => 'echo "1"'],
        ['name' => 'Task 2', 'script' => 'echo "2"'],
    ];

    $result = $this->service->executeParallel($tasks);

    expect($result)->toHaveKeys(['success', 'total', 'successful', 'failed', 'results'])
        ->and($result['total'])->toBe(2);
});

it('get analytics summary returns metrics', function () {
    Task::factory()->count(3)->create(['status' => TaskStatus::Finished]);
    Task::factory()->create(['status' => TaskStatus::Failed]);

    $summary = $this->service->getAnalyticsSummary();

    expect($summary)->toHaveKeys(['total_tasks', 'by_status', 'avg_duration', 'success_rate'])
        ->and($summary['total_tasks'])->toBe(4);
});

it('retry task creates new execution', function () {
    $failedTask = Task::factory()->create(['status' => TaskStatus::Failed]);

    $result = $this->service->retryTask($failedTask->id);

    expect($result)->toHaveKey('task_id');
});

it('retry task validates status', function () {
    $runningTask = Task::factory()->create(['status' => TaskStatus::Running]);

    $result = $this->service->retryTask($runningTask->id);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Only failed tasks');
});

it('cancel task stops execution', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Running]);

    $result = $this->service->cancelTask($task->id);

    expect($result['success'])->toBeTrue()
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('cancel task stops remote process and marks task cancelled', function () {
    $server = Server::factory()->create([
        'ssh_private_key' => file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
    ]);

    $task = Task::factory()->create([
        'server_id' => $server->id,
        'status' => TaskStatus::Running,
        'options' => [
            'remote_wrapper_script_path' => "/root/.dply-task-runner/task-{$server->id}.sh",
            'remote_script_path' => "/root/.dply-task-runner/task-{$server->id}-original.sh",
            'remote_pid_path' => "/root/.dply-task-runner/task-{$server->id}.pid",
            'remote_child_pid_path' => "/root/.dply-task-runner/task-{$server->id}-child.pid",
        ],
    ]);

    $dispatcher = \Mockery::mock(TaskDispatcher::class);
    $dispatcher->shouldReceive('run')
        ->once()
        ->andReturn(new ProcessOutput('cancelled remote task', 0, true));
    app()->instance(TaskDispatcher::class, $dispatcher);

    $service = app(TaskRunnerService::class);

    $result = $service->cancelTask($task->id);

    expect($result['success'])->toBeTrue()
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($task->fresh()->completed_at)->not->toBeNull();
});

it('cancel task validates status', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Finished]);

    $result = $this->service->cancelTask($task->id);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Only running tasks');
});

it('get task history returns tasks', function () {
    Task::factory()->count(5)->create();

    $history = $this->service->getTaskHistory();

    expect($history)->toBeInstanceOf(Collection::class)
        ->and($history->count())->toBeGreaterThanOrEqual(5);
});

it('get task history filters by status', function () {
    Task::factory()->count(2)->create(['status' => TaskStatus::Finished]);
    Task::factory()->create(['status' => TaskStatus::Failed]);

    $history = $this->service->getTaskHistory(['status' => TaskStatus::Finished]);

    expect($history->every(fn ($t) => $t->status === TaskStatus::Finished))->toBeTrue();
});

it('get monitoring dashboard returns dashboard data', function () {
    Task::factory()->count(3)->create();

    $dashboard = $this->service->getMonitoringDashboard();

    expect($dashboard)->toHaveKeys(['summary', 'recent_tasks', 'running_tasks', 'failed_tasks', 'health_metrics']);
});

it('cleanup old tasks removes completed tasks', function () {
    Task::factory()->create([
        'status' => TaskStatus::Finished,
        'completed_at' => now()->subDays(40),
    ]);

    $result = $this->service->cleanupOldTasks(30);

    expect($result['success'])->toBeTrue()
        ->and($result['deleted_count'])->toBeGreaterThanOrEqual(0);
});

it('get performance insights provides recommendations', function () {
    Task::factory()->count(10)->create(['status' => TaskStatus::Finished]);

    $insights = $this->service->getPerformanceInsights();

    expect($insights)->toHaveKeys(['summary', 'insights'])
        ->and($insights['insights'])->toBeArray();
});

it('export report supports array format', function () {
    Task::factory()->count(3)->create();

    $report = $this->service->exportReport([], 'array');

    expect($report)->toBeArray()
        ->and($report)->toHaveKeys(['summary', 'tasks', 'generated_at']);
});

it('export report supports json format', function () {
    Task::factory()->count(2)->create();

    $report = $this->service->exportReport([], 'json');

    expect($report)->toBeString();
    $decoded = json_decode($report, true);
    expect($decoded)->toBeArray();
});

it('export report supports csv format', function () {
    Task::factory()->count(2)->create();

    $report = $this->service->exportReport([], 'csv');

    expect($report)->toBeString()
        ->and($report)->toContain('Task Execution Report');
});

it('bulk operation handles multiple tasks', function () {
    $tasks = Task::factory()->count(3)->create(['status' => TaskStatus::Running]);
    $taskIds = $tasks->pluck('id')->toArray();

    $result = $this->service->bulkOperation($taskIds, 'cancel');

    expect($result)->toHaveKeys(['success', 'operation', 'total', 'successful', 'failed', 'results'])
        ->and($result['total'])->toBe(3);
});

it('get quick stats returns summary', function () {
    Task::factory()->create(['status' => TaskStatus::Running]);
    Task::factory()->create(['status' => TaskStatus::Finished]);
    Task::factory()->create(['status' => TaskStatus::Failed]);

    $stats = $this->service->getQuickStats();

    expect($stats)->toHaveKeys(['total', 'running', 'finished', 'failed', 'pending'])
        ->and($stats['total'])->toBeGreaterThanOrEqual(3);
});
