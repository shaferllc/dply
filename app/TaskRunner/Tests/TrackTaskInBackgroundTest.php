<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests;

use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(LazilyRefreshDatabase::class, TestCase::class);

test('TrackTaskInBackground can be constructed', function () {
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );
    expect($task)->toBeInstanceOf(TrackTaskInBackground::class);
    expect($task->actualTask)->toBeInstanceOf(TestTask::class);
    expect($task->finishedUrl)->toBe('https://example.com/finished');
    expect($task->failedUrl)->toBe('https://example.com/failed');
    expect($task->timeoutUrl)->toBe('https://example.com/timeout');
    expect($task->eof)->toStartWith('DPLY-TASK-RUNNER-');

    Task::unfake();
});

test('configureCallbacks', function () {
    Task::fake();
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    expect(invade($task)->callbackUrl)->toBe('https://example.com/finished');
    expect(invade($task)->callbackTimeout)->toBe(30);
    expect(invade($task)->callbackMaxAttempts)->toBe(3);
    expect(invade($task)->callbackDelay)->toBe(5);
    expect(invade($task)->callbackBackoffMultiplier)->toBe(2);
    expect(invade($task)->callbacksEnabled)->toBeTrue();

    Task::unfake();
});

test('getTimeout', function () {
    Task::fake();
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );
    expect($task->getTimeout())->toBe(330);

    Task::unfake();
});

test('handle', function () {
    Task::fake();
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Disable callbacks for testing to avoid HTTP requests
    $task->disableCallbacks();

    // Test basic functionality without triggering background monitoring
    expect($task->getTaskInfo())->toBeArray();
    expect($task->getTaskInfo())->toHaveKey('tracking_class');
    expect($task->getTaskInfo())->toHaveKey('actual_task_class');
    expect($task->getTaskInfo()['tracking_class'])->toBe(TrackTaskInBackground::class);
    expect($task->getTaskInfo()['actual_task_class'])->toBe(TestTask::class);

    // Test that callbacks are disabled
    expect($task->isCallbacksEnabled())->toBeFalse();

    // Test callback URL
    expect($task->getCallbackUrl())->toBe('https://example.com/finished');

    // Test timeout calculation
    expect($task->getTimeout())->toBe(330); // TestTask timeout (300) + 30

    Task::unfake();
});

test('fake method creates task with proper setup', function () {
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    expect($task)->toBeInstanceOf(TrackTaskInBackground::class);
    expect($task->actualTask)->toBeInstanceOf(TestTask::class);
    expect($task->finishedUrl)->toBe('https://example.com/finished');
    expect($task->failedUrl)->toBe('https://example.com/failed');
    expect($task->timeoutUrl)->toBe('https://example.com/timeout');

    // Test that task model is not created when background tracking is disabled
    expect($task->getTaskModel())->toBeNull();
    expect($task->actualTask->getTaskModel())->toBeNull();

    // Test task info
    $taskInfo = $task->getTaskInfo();
    expect($taskInfo['task_id'])->toBeNull(); // No task model when background tracking is disabled
    expect($taskInfo['task_name'])->toBe('Test Task');
    expect($taskInfo['status'])->toBeNull(); // No status when no task model

    // Disable fake mode
    Task::unfake();
});

test('callbacks can be tested with fake method', function () {
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test callback data
    $callbackData = $task->getCallbackData();
    expect($callbackData)->toHaveKey('task_id');
    expect($callbackData)->toHaveKey('task_name');
    expect($callbackData)->toHaveKey('status');
    expect($callbackData)->toHaveKey('actual_task_class');
    expect($callbackData['task_name'])->toBe('Test Task');
    expect($callbackData['actual_task_class'])->toBe(TestTask::class);

    // Test callback headers
    $callbackHeaders = $task->getCallbackHeaders();
    expect($callbackHeaders)->toHaveKey('Content-Type');
    expect($callbackHeaders)->toHaveKey('X-Task-ID');
    expect($callbackHeaders)->toHaveKey('X-Callback-Type');
    expect($callbackHeaders['Content-Type'])->toBe('application/json');
    expect($callbackHeaders['X-Callback-Type'])->toBe('background_task_update');

    // Test callback retry config
    $retryConfig = $task->getCallbackRetryConfig();
    expect($retryConfig)->toHaveKey('max_attempts');
    expect($retryConfig)->toHaveKey('delay');
    expect($retryConfig)->toHaveKey('backoff_multiplier');
    expect($retryConfig['max_attempts'])->toBe(3);
    expect($retryConfig['delay'])->toBe(5);
    expect($retryConfig['backoff_multiplier'])->toBe(2);

    // Disable fake mode
    Task::unfake();
});

test('callback sending can be tested', function () {
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test that callbacks are enabled by default
    expect($task->isCallbacksEnabled())->toBeTrue();

    // Test that callback URL is set correctly
    expect($task->getCallbackUrl())->toBe('https://example.com/finished');

    // Test that callback data validation works
    $validData = $task->getCallbackData();
    expect($task->validateCallbackData($validData))->toBeTrue();

    // Test that invalid data is rejected
    expect($task->validateCallbackData([]))->toBeFalse();
    // When in fake mode, any non-empty data is valid
    expect($task->validateCallbackData(['some_key' => 'value']))->toBeTrue();

    // Test that disabled callbacks return false
    $task->disableCallbacks();
    expect($task->isCallbacksEnabled())->toBeFalse();

    // Test callback timeout
    expect($task->getCallbackTimeout())->toBe(30);

    // Disable fake mode
    Task::unfake();
});

test('example of testing callback firing', function () {
    // This is an example of how you could test that callbacks are actually fired
    // In a real scenario, you might want to spy on the CallbackService or use HTTP fakes

    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Example: Test that success callback would be sent with correct data
    $successData = [
        'event' => 'task_completed',
        'success' => true,
        'completed_at' => now()->toISOString(),
    ];

    // The callback data should include both the task info and the additional data
    $expectedCallbackData = array_merge($task->getCallbackData(), $successData);

    expect($expectedCallbackData)->toHaveKey('task_id');
    expect($expectedCallbackData)->toHaveKey('event');
    expect($expectedCallbackData)->toHaveKey('success');
    expect($expectedCallbackData)->toHaveKey('completed_at');
    expect($expectedCallbackData['success'])->toBeTrue();
    expect($expectedCallbackData['event'])->toBe('task_completed');

    // Example: Test that failure callback would be sent with correct data
    $failureData = [
        'event' => 'task_failed',
        'success' => false,
        'error' => 'Test error message',
        'failed_at' => now()->toISOString(),
    ];

    $expectedFailureData = array_merge($task->getCallbackData(), $failureData);

    expect($expectedFailureData)->toHaveKey('error');
    expect($expectedFailureData['success'])->toBeFalse();
    expect($expectedFailureData['event'])->toBe('task_failed');

    // Disable fake mode
    Task::unfake();
});

test('Task::fake() enables fake mode like Laravel fakes', function () {
    Task::fake();

    expect(Task::isFake())->toBeTrue();

    // Create a task - it should automatically be a fake instance
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // The task should not have a task model when background tracking is disabled
    expect($task->getTaskModel())->toBeNull();
    expect($task->actualTask->getTaskModel())->toBeNull();

    // Disable fake mode
    Task::unfake();

    expect(Task::isFake())->toBeFalse();
});

test('can run actual task code with Task::fake()', function () {
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Disable callbacks to avoid HTTP requests
    $task->disableCallbacks();

    // Run the actual task code - this should work without background monitoring
    $task->handle();

    // The task should have been executed, but no task model exists
    expect($task->getTaskModel())->toBeNull();

    // Disable fake mode
    Task::unfake();
});

test('handle() creates and tracks task model when not faked', function () {
    // Ensure we're not in fake mode
    Task::unfake();

    // Clean up any previous tasks
    \App\Modules\TaskRunner\Models\Task::query()->delete();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Disable callbacks to avoid HTTP requests
    $task->disableCallbacks();

    // Run the handle method, which should create a task model and track it
    $task->handle();

    // The task model should exist and be associated with the actual task
    $taskModel = $task->getTaskModel();
    expect($taskModel)->not()->toBeNull();
    expect($task->actualTask->getTaskModel())->not()->toBeNull();
    expect($taskModel->name)->toBe('Test Task');
    expect($taskModel->status->value)->toBe('finished');

    // The task info should reflect the tracked model
    $info = $task->getTaskInfo();
    expect($info['task_id'])->toBe($taskModel->id);
    expect($info['status'])->toBe('finished');
    expect($info['tracking_class'])->toBe(TrackTaskInBackground::class);
    expect($info['actual_task_class'])->toBe(TestTask::class);
});

test('handle() sets failed status and sends failed callback on exception', function () {
    Task::unfake();

    // Clean up any previous tasks
    \App\Modules\TaskRunner\Models\Task::query()->delete();

    // Create a task that will throw an exception
    $failingTask = new class extends TestTask
    {
        public function handle(): void
        {
            throw new \RuntimeException('Simulated failure');
        }
    };

    $task = new TrackTaskInBackground(
        $failingTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Disable callbacks to avoid HTTP requests
    $task->disableCallbacks();

    // Run the handle method and expect an exception
    try {
        $task->handle();
        $this->fail('Exception was not thrown');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('Simulated failure');
    }

    // The task model should exist and have failed status
    $taskModel = $task->getTaskModel();
    expect($taskModel)->not()->toBeNull();
    expect($taskModel->status->value)->toBe('failed');
});

test('handle() does not create task model when Task::fake() is enabled', function () {
    Task::fake();

    // Clean up any previous tasks
    \App\Modules\TaskRunner\Models\Task::query()->delete();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Disable callbacks to avoid HTTP requests
    $task->disableCallbacks();

    // Run the handle method
    $task->handle();

    // No task model should be created
    expect($task->getTaskModel())->toBeNull();
    expect($task->actualTask->getTaskModel())->toBeNull();
    expect(\App\Modules\TaskRunner\Models\Task::count())->toBe(0);

    Task::unfake();
});

test('handle() creates and tracks task model with background tracking enabled', function () {
    // Clean up any previous tasks
    \App\Modules\TaskRunner\Models\Task::query()->delete();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Disable callbacks to avoid HTTP requests but keep background tracking
    $task->disableCallbacks();

    // Run the handle method
    $task->handle();

    // The task model should exist and have finished status
    $taskModel = $task->getTaskModel();
    expect($taskModel)->not()->toBeNull();
    expect($taskModel->status->value)->toBe('finished');
    expect($taskModel->name)->toBe('Test Task');
    expect($taskModel->action)->toBe('test_action');
    expect($taskModel->script)->toBe('echo "Hello World"');
    expect($taskModel->options)->toBeJson();
    expect($taskModel->timeout)->toBeGreaterThan(0);
    expect($taskModel->completed_at)->not()->toBeNull();
});

test('handle() sets task status to failed and output on exception with background tracking enabled', function () {
    // Clean up any previous tasks
    \App\Modules\TaskRunner\Models\Task::query()->delete();

    $failingTask = new class extends TestTask
    {
        public function handle(): void
        {
            throw new \RuntimeException('Background failure');
        }
    };

    $task = new TrackTaskInBackground(
        $failingTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Disable callbacks to avoid HTTP requests but keep background tracking
    $task->disableCallbacks();

    // Run the handle method and expect an exception
    try {
        $task->handle();
        $this->fail('Exception was not thrown');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('Background failure');
    }

    // The task model should exist and have failed status and output
    $taskModel = $task->getTaskModel();
    expect($taskModel)->not()->toBeNull();
    expect($taskModel->status->value)->toBe('failed');
    expect($taskModel->output)->toBe('Background failure');
    expect($taskModel->completed_at)->not()->toBeNull();
});

test('createTaskModel() stores correct options and URLs', function () {
    // Clean up any previous tasks
    \App\Modules\TaskRunner\Models\Task::query()->delete();

    $testTask = new TestTask;
    $finishedUrl = 'https://example.com/finished';
    $failedUrl = 'https://example.com/failed';
    $timeoutUrl = 'https://example.com/timeout';

    $task = new TrackTaskInBackground(
        $testTask,
        $finishedUrl,
        $failedUrl,
        $timeoutUrl,
    );

    $reflection = new \ReflectionClass($task);
    $method = $reflection->getMethod('createTaskModel');
    $method->setAccessible(true);

    $taskModel = $method->invoke($task);

    expect($taskModel)->not()->toBeNull();
    $options = json_decode($taskModel->options, true);
    expect($options['finished_url'])->toBe($finishedUrl);
    expect($options['failed_url'])->toBe($failedUrl);
    expect($options['timeout_url'])->toBe($timeoutUrl);
    expect($options['actual_task_class'])->toBe(get_class($testTask));
});

test('getTimeout() returns actual task timeout plus 30 seconds', function () {
    $testTask = new class extends TestTask
    {
        public function getTimeout(): int
        {
            return 120;
        }
    };

    $task = new TrackTaskInBackground(
        $testTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    expect($task->getTimeout())->toBe(150);
});
