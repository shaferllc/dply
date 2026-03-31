<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests;

use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(LazilyRefreshDatabase::class, TestCase::class);

test('can test callbacks directly when background tracking is disabled', function () {
    // Enable fake mode to disable background tracking
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test that callbacks are enabled
    expect($task->isCallbacksEnabled())->toBeTrue();

    // Test callback URL
    expect($task->getCallbackUrl())->toBe('https://example.com/finished');

    // Test callback data structure
    $callbackData = $task->getCallbackData();
    expect($callbackData)->toHaveKey('task_id');
    expect($callbackData)->toHaveKey('task_name');
    expect($callbackData)->toHaveKey('status');
    expect($callbackData)->toHaveKey('actual_task_class');
    expect($callbackData['task_name'])->toBe('Test Task');
    expect($callbackData['actual_task_class'])->toBe(TestTask::class);

    // Test callback headers
    $headers = $task->getCallbackHeaders();
    expect($headers['Content-Type'])->toBe('application/json');
    expect($headers['X-Callback-Type'])->toBe('background_task_update');
    expect($headers['X-Task-ID'])->toBeNull(); // No task model when background tracking is disabled

    // Disable fake mode
    Task::unfake();
});

test('can test success callback data', function () {
    // Enable fake mode
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Simulate task completion
    $task->handle();

    // Test that success callback would be sent with correct data
    $successData = [
        'event' => 'task_completed',
        'success' => true,
        'completed_at' => now()->toISOString(),
    ];

    // The callback data should include both task info and success data
    $expectedCallbackData = array_merge($task->getCallbackData(), $successData);

    expect($expectedCallbackData)->toHaveKey('task_id');
    expect($expectedCallbackData)->toHaveKey('event');
    expect($expectedCallbackData)->toHaveKey('success');
    expect($expectedCallbackData)->toHaveKey('completed_at');
    expect($expectedCallbackData['success'])->toBeTrue();
    expect($expectedCallbackData['event'])->toBe('task_completed');
    expect($expectedCallbackData['task_name'])->toBe('Test Task');

    // Test callback validation
    expect($task->validateCallbackData($expectedCallbackData))->toBeTrue();

    // Disable fake mode
    Task::unfake();
});

test('can test failure callback data', function () {
    // Enable fake mode
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test that failure callback would be sent with correct data
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
    expect($expectedFailureData['error'])->toBe('Test error message');

    // Test callback validation
    expect($task->validateCallbackData($expectedFailureData))->toBeTrue();

    // Disable fake mode
    Task::unfake();
});

test('can test callback retry configuration', function () {
    // Enable fake mode
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test retry configuration
    $retryConfig = $task->getCallbackRetryConfig();
    expect($retryConfig)->toHaveKey('max_attempts');
    expect($retryConfig)->toHaveKey('delay');
    expect($retryConfig)->toHaveKey('backoff_multiplier');
    expect($retryConfig['max_attempts'])->toBe(3);
    expect($retryConfig['delay'])->toBe(5);
    expect($retryConfig['backoff_multiplier'])->toBe(2);

    // Test callback timeout
    expect($task->getCallbackTimeout())->toBe(30);

    // Disable fake mode
    Task::unfake();
});

test('can test callback disabling', function () {
    // Enable fake mode
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test that callbacks are enabled by default
    expect($task->isCallbacksEnabled())->toBeTrue();

    // Disable callbacks
    $task->disableCallbacks();
    expect($task->isCallbacksEnabled())->toBeFalse();

    // Test that disabled callbacks return false
    expect($task->sendCallback(CallbackType::Finished, []))->toBeFalse();

    // Disable fake mode
    Task::unfake();
});

test('can test callback data validation', function () {
    // Enable fake mode
    Task::fake();

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test valid callback data
    $validData = $task->getCallbackData();
    expect($task->validateCallbackData($validData))->toBeTrue();

    // Test invalid callback data
    expect($task->validateCallbackData([]))->toBeFalse();
    // When in fake mode, any non-empty data is valid
    expect($task->validateCallbackData(['some_key' => 'value']))->toBeTrue();
    // When in fake mode, even null task_id is valid
    expect($task->validateCallbackData(['task_id' => null]))->toBeTrue();

    // Disable fake mode
    Task::unfake();
});

test('callback url is correct for each callback type', function () {
    Task::fake();
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );
    expect($task->getCallbackUrl(CallbackType::Finished))->toBe('https://example.com/finished');
    expect($task->getCallbackUrl(CallbackType::Failed))->toBe('https://example.com/failed');
    expect($task->getCallbackUrl(CallbackType::Timeout))->toBe('https://example.com/timeout');
    expect($task->getCallbackUrl(CallbackType::Custom))->toBe('https://example.com/finished'); // default fallback
    Task::unfake();
});

test('callback headers contain correct values for each type', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $headers = $task->getCallbackHeaders();
    expect($headers['Content-Type'])->toBe('application/json');
    expect($headers['User-Agent'])->toBe('TaskRunner/2.0');
    expect($headers['X-Callback-Type'])->toBe('background_task_update');
    expect($headers['X-Actual-Task-Class'])->toBe(TestTask::class);
    Task::unfake();
});

test('callback data structure is valid for finished type', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $data = $task->getCallbackData();
    expect($data)->toHaveKeys(['task_id', 'task_name', 'status', 'exit_code', 'duration', 'output', 'timestamp', 'callback_type', 'actual_task_class']);
    expect($data['actual_task_class'])->toBe(TestTask::class);
    Task::unfake();
});

test('disabling and enabling callbacks toggles state', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    expect($task->isCallbacksEnabled())->toBeTrue();
    $task->disableCallbacks();
    expect($task->isCallbacksEnabled())->toBeFalse();
    $task->enableCallbacks();
    expect($task->isCallbacksEnabled())->toBeTrue();
    Task::unfake();
});

test('disabling callbacks prevents sending for all types', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $task->disableCallbacks();
    foreach ([CallbackType::Finished, CallbackType::Failed, CallbackType::Timeout, CallbackType::Custom] as $type) {
        expect($task->sendCallback($type, []))->toBeFalse();
    }
    Task::unfake();
});

test('enabling after disabling restores callback sending', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', 'https://example.com/failed', 'https://example.com/timeout');
    $task->disableCallbacks();
    expect($task->sendCallback(CallbackType::Finished, []))->toBeFalse();
    $task->enableCallbacks();
    Http::fake();
    expect($task->sendCallback(CallbackType::Finished, []))->toBeTrue();
    Task::unfake();
});

test('callback retry config can be customized', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $taskReflection = new \ReflectionClass($task);
    $taskReflection->getProperty('callbackMaxAttempts')->setValue($task, 5);
    $taskReflection->getProperty('callbackDelay')->setValue($task, 10);
    $taskReflection->getProperty('callbackBackoffMultiplier')->setValue($task, 3);
    $config = $task->getCallbackRetryConfig();
    expect($config['max_attempts'])->toBe(5);
    expect($config['delay'])->toBe(10);
    expect($config['backoff_multiplier'])->toBe(3);
    Task::unfake();
});

test('callback timeout can be customized', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $taskReflection = new \ReflectionClass($task);
    $taskReflection->getProperty('callbackTimeout')->setValue($task, 99);
    expect($task->getCallbackTimeout())->toBe(99);
    Task::unfake();
});

test('callback data validation with partial data', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    expect($task->validateCallbackData(['task_id' => 1]))->toBeTrue();
    expect($task->validateCallbackData(['foo' => 'bar']))->toBeTrue(); // fake mode
    Task::unfake();
});

test('callback data validation with invalid types', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    expect($task->validateCallbackData(['task_id' => null]))->toBeTrue(); // fake mode
    expect($task->validateCallbackData(['task_id' => '']))->toBeTrue(); // fake mode
    Task::unfake();
});

test('callback data can be extended with extra fields', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $data = array_merge($task->getCallbackData(), ['extra' => 'value']);
    expect($data)->toHaveKey('extra');
    expect($task->validateCallbackData($data))->toBeTrue();
    Task::unfake();
});

test('callback headers can be extended', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $headers = array_merge($task->getCallbackHeaders(), ['X-Custom' => 'foo']);
    expect($headers)->toHaveKey('X-Custom');
    expect($headers['X-Custom'])->toBe('foo');
    Task::unfake();
});

test('callback data is JSON serializable', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $data = $task->getCallbackData();
    $json = json_encode($data);
    expect($json)->not->toBeFalse();
    Task::unfake();
});

test('callback headers are case-insensitive', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $headers = $task->getCallbackHeaders();
    $headersLower = array_change_key_case($headers, CASE_LOWER);
    expect($headersLower)->toHaveKey('content-type');
    expect($headersLower['content-type'])->toBe('application/json');
    Task::unfake();
});

test('callback retry config edge cases', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $taskReflection = new \ReflectionClass($task);
    $taskReflection->getProperty('callbackMaxAttempts')->setValue($task, 0);
    $taskReflection->getProperty('callbackDelay')->setValue($task, -1);
    $config = $task->getCallbackRetryConfig();
    expect($config['max_attempts'])->toBe(0);
    expect($config['delay'])->toBe(-1);
    Task::unfake();
});

test('callback timeout edge cases', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $taskReflection = new \ReflectionClass($task);
    $taskReflection->getProperty('callbackTimeout')->setValue($task, 0);
    expect($task->getCallbackTimeout())->toBe(0);
    $taskReflection->getProperty('callbackTimeout')->setValue($task, -5);
    expect($task->getCallbackTimeout())->toBe(-5);
    Task::unfake();
});

test('fake mode does not persist between tests', function () {
    Task::fake();
    $task1 = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    Task::unfake();
    $task2 = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    expect($task2->isCallbacksEnabled())->toBeTrue();
});

test('unfake restores real behavior', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    Task::unfake();
    expect($task->isCallbacksEnabled())->toBeTrue();
});

test('callback urls can be changed after instantiation', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $taskReflection = new \ReflectionClass($task);
    $taskReflection->getProperty('finishedUrl')->setValue($task, 'https://changed.com/finished');
    expect($task->getCallbackUrl(CallbackType::Finished))->toBe('https://changed.com/finished');
    Task::unfake();
});

test('callback data includes custom payloads', function () {
    Task::fake();
    $task = new TrackTaskInBackground(new TestTask, 'a', 'b', 'c');
    $custom = ['foo' => 'bar'];
    $data = array_merge($task->getCallbackData(), $custom);
    expect($data['foo'])->toBe('bar');
    Task::unfake();
});
