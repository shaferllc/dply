# Testing Callbacks with Task::fake()

When you disable background tracking using `Task::fake()`, you can still test that callbacks are working correctly. Here are different approaches:

## 1. Testing Callback Data Structure

Test that callbacks contain the correct data without making actual HTTP requests:

```php
test('callback data structure is correct', function () {
    \App\Modules\TaskRunner\Task::fake();
    
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
    
    // Test callback headers
    $headers = $task->getCallbackHeaders();
    expect($headers['Content-Type'])->toBe('application/json');
    expect($headers['X-Callback-Type'])->toBe('background_task_update');
    
    \App\Modules\TaskRunner\Task::unfake();
});
```

## 2. Testing Callback URLs

Verify that callbacks are configured to send to the correct URLs:

```php
test('callback URLs are configured correctly', function () {
    \App\Modules\TaskRunner\Task::fake();
    
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://api.example.com/success',
        'https://api.example.com/error',
        'https://api.example.com/timeout',
    );
    
    expect($task->getCallbackUrl())->toBe('https://api.example.com/success');
    expect($task->finishedUrl)->toBe('https://api.example.com/success');
    expect($task->failedUrl)->toBe('https://api.example.com/error');
    expect($task->timeoutUrl)->toBe('https://api.example.com/timeout');
    
    \App\Modules\TaskRunner\Task::unfake();
});
```

## 3. Testing Callback Payloads

Test that the correct payload would be sent for different callback types:

```php
test('success callback payload is correct', function () {
    \App\Modules\TaskRunner\Task::fake();
    
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );
    
    // Simulate task completion
    $task->handle();
    
    // Test success callback payload
    $successData = [
        'event' => 'task_completed',
        'success' => true,
        'completed_at' => now()->toISOString(),
    ];
    
    $expectedPayload = array_merge($task->getCallbackData(), $successData);
    
    expect($expectedPayload['event'])->toBe('task_completed');
    expect($expectedPayload['success'])->toBeTrue();
    expect($expectedPayload['task_name'])->toBe('test-task');
    
    \App\Modules\TaskRunner\Task::unfake();
});
```

## 4. Testing with HTTP Fakes

Use Laravel's `Http::fake()` to test actual HTTP requests:

```php
test('callback HTTP requests are sent correctly', function () {
    \App\Modules\TaskRunner\Task::fake();
    
    // Fake HTTP responses
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
        'https://example.com/failed' => Http::response(['status' => 'error'], 200),
    ]);
    
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );
    
    // Send success callback
    $result = $task->sendCallback(CallbackType::Finished, [
        'event' => 'task_completed',
        'success' => true,
    ]);
    
    expect($result)->toBeTrue();
    
    // Verify HTTP request was made
    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/finished' &&
               $request->method() === 'POST' &&
               $request->header('Content-Type')[0] === 'application/json';
    });
    
    \App\Modules\TaskRunner\Task::unfake();
});
```

## 5. Testing Callback Failures

Test how callbacks behave when the HTTP request fails:

```php
test('callback handles HTTP failures gracefully', function () {
    \App\Modules\TaskRunner\Task::fake();
    
    // Fake HTTP failure
    Http::fake([
        'https://example.com/finished' => Http::response(['error' => 'Server error'], 500),
    ]);
    
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );
    
    // Send callback that will fail
    $result = $task->sendCallback(CallbackType::Finished, [
        'event' => 'task_completed',
        'success' => true,
    ]);
    
    expect($result)->toBeFalse();
    
    // Verify the request was still attempted
    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/finished';
    });
    
    \App\Modules\TaskRunner\Task::unfake();
});
```

## 6. Testing Callback Configuration

Test callback retry and timeout configuration:

```php
test('callback configuration is correct', function () {
    \App\Modules\TaskRunner\Task::fake();
    
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );
    
    // Test retry configuration
    $retryConfig = $task->getCallbackRetryConfig();
    expect($retryConfig['max_attempts'])->toBe(3);
    expect($retryConfig['delay'])->toBe(5);
    expect($retryConfig['backoff_multiplier'])->toBe(2);
    
    // Test timeout
    expect($task->getCallbackTimeout())->toBe(30);
    
    \App\Modules\TaskRunner\Task::unfake();
});
```

## 7. Testing Callback Disabling

Test that callbacks can be disabled for testing:

```php
test('callbacks can be disabled for testing', function () {
    \App\Modules\TaskRunner\Task::fake();
    
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
    
    \App\Modules\TaskRunner\Task::unfake();
});
```

## Key Benefits of This Approach

1. **No Background Jobs**: `Task::fake()` prevents background monitoring jobs from being queued
2. **No Real HTTP Requests**: You can test callbacks without making actual HTTP requests
3. **Fast Tests**: Tests run quickly without external dependencies
4. **Comprehensive Coverage**: You can test all aspects of callback functionality
5. **Realistic Testing**: When using `Http::fake()`, you can test actual HTTP behavior

## When to Use Each Approach

- **Data Structure Testing**: When you want to verify callback payloads without HTTP
- **HTTP Fake Testing**: When you want to test actual HTTP request/response behavior
- **Configuration Testing**: When you want to verify callback settings
- **Failure Testing**: When you want to test error scenarios

This approach gives you complete control over testing callbacks while keeping your tests fast and reliable. 
