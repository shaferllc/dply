<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests;

use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(LazilyRefreshDatabase::class, TestCase::class);

test('example of testing task execution with Task::fake()', function () {
    // Enable fake mode - this works like Laravel's Event::fake(), Notification::fake(), etc.
    Task::fake();

    // Create a task - it will automatically be set up with a fake task model
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Disable callbacks to avoid HTTP requests during testing
    $task->disableCallbacks();

    // Run the actual task code - this will execute without background monitoring
    $task->handle();

    // Assert that the task was executed successfully, but no task model exists
    expect($task->getTaskModel())->toBeNull();

    // Disable fake mode
    Task::unfake();
});

test('example of testing callbacks with Task::fake()', function () {
    // Enable fake mode
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test that callbacks would be sent with correct data
    $callbackData = $task->getCallbackData();

    expect($callbackData)->toHaveKey('task_id');
    expect($callbackData)->toHaveKey('task_name');
    expect($callbackData)->toHaveKey('status');
    expect($callbackData)->toHaveKey('actual_task_class');
    expect($callbackData['task_name'])->toBe('Test Task');
    expect($callbackData['actual_task_class'])->toBe(TestTask::class);

    // Test callback configuration
    expect($task->getCallbackUrl())->toBe('https://example.com/finished');
    expect($task->isCallbacksEnabled())->toBeTrue();
    expect($task->getCallbackTimeout())->toBe(30);

    // Test callback headers
    $headers = $task->getCallbackHeaders();
    expect($headers['Content-Type'])->toBe('application/json');
    expect($headers['X-Callback-Type'])->toBe('background_task_update');

    // Disable fake mode
    Task::unfake();
});

test('example of testing task lifecycle with Task::fake()', function () {
    // Enable fake mode
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test initial state - no task model when background tracking is disabled
    expect($task->getTaskModel())->toBeNull();

    // Execute the task
    $task->handle();

    // Test final state
    // Assert that no task model exists when background tracking is disabled
    expect($task->getTaskModel())->toBeNull();

    // Test task info
    $taskInfo = $task->getTaskInfo();
    expect($taskInfo['tracking_class'])->toBe(TrackTaskInBackground::class);
    expect($taskInfo['actual_task_class'])->toBe(TestTask::class);
    expect($taskInfo['task_name'])->toBe('Test Task');
    expect($taskInfo['status'])->toBeNull(); // No status when no task model

    // Disable fake mode
    Task::unfake();
});
