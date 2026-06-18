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

test('can test callbacks with HTTP fakes', function () {
    // Enable fake mode to disable background tracking
    Task::fake();

    // Fake HTTP requests
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
        'https://example.com/failed' => Http::response(['status' => 'error'], 200),
        'https://example.com/timeout' => Http::response(['status' => 'timeout'], 200),
    ]);

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test that callbacks would be sent to the correct URLs
    $callbackData = $task->getCallbackData();
    $headers = $task->getCallbackHeaders();

    // Test success callback
    $successResult = $task->sendCallback(CallbackType::Finished, [
        'event' => 'task_completed',
        'success' => true,
    ]);

    expect($successResult)->toBeTrue();

    // Verify HTTP request was made
    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/finished' &&
               $request->method() === 'POST' &&
               $request->header('Content-Type')[0] === 'application/json' &&
               $request->header('X-Callback-Type')[0] === 'background_task_update';
    });

    // Test failure callback
    $failureResult = $task->sendCallback(CallbackType::Failed, [
        'event' => 'task_failed',
        'success' => false,
        'error' => 'Test error',
    ]);

    expect($failureResult)->toBeTrue();

    // Verify failure HTTP request was made
    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/finished' && // Uses finished URL as default
               $request->method() === 'POST';
    });

    // Disable fake mode
    Task::unfake();
});

test('can test callback payload structure with HTTP fakes', function () {
    // Enable fake mode
    Task::fake();

    // Fake HTTP requests
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Send a callback
    $task->sendCallback(CallbackType::Finished, [
        'event' => 'task_completed',
        'success' => true,
        'custom_field' => 'custom_value',
    ]);

    // Verify the exact payload structure
    Http::assertSent(function ($request) {
        $payload = $request->data();

        // Check that all required fields are present
        expect($payload)->toHaveKey('task_id');
        expect($payload)->toHaveKey('task_name');
        expect($payload)->toHaveKey('status');
        expect($payload)->toHaveKey('actual_task_class');
        expect($payload)->toHaveKey('event');
        expect($payload)->toHaveKey('success');
        expect($payload)->toHaveKey('custom_field');

        // Check specific values
        expect($payload['task_id'])->toBeNull(); // No task model when background tracking is disabled
        expect($payload['task_name'])->toBe('Test Task');
        expect($payload['actual_task_class'])->toBe(TestTask::class);
        expect($payload['event'])->toBe('task_completed');
        expect($payload['success'])->toBeTrue();
        expect($payload['custom_field'])->toBe('custom_value');

        return true;
    });

    // Disable fake mode
    Task::unfake();
});

test('can test callback failure scenarios', function () {
    // Enable fake mode
    Task::fake();

    // Fake HTTP requests to simulate failures
    Http::fake([
        'https://example.com/finished' => Http::response(['error' => 'Server error'], 500),
    ]);

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test that callback fails when server returns error
    $result = $task->sendCallback(CallbackType::Finished, [
        'event' => 'task_completed',
        'success' => true,
    ]);

    // The callback should fail due to 500 response
    expect($result)->toBeFalse();

    // Verify the request was still made
    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/finished' &&
               $request->method() === 'POST';
    });

    // Disable fake mode
    Task::unfake();
});

test('can test callback timeout scenarios', function () {
    // Enable fake mode
    Task::fake();

    // Fake HTTP requests to simulate timeout
    Http::fake([
        'https://example.com/finished' => Http::response(['error' => 'Timeout'], 408),
    ]);

    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    // Test that callback fails when request times out
    $result = $task->sendCallback(CallbackType::Finished, [
        'event' => 'task_completed',
        'success' => true,
    ]);

    // The callback should fail due to timeout response
    expect($result)->toBeFalse();

    // Verify the request was still attempted
    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/finished' &&
               $request->method() === 'POST';
    });

    // Disable fake mode
    Task::unfake();
});

test('sending callback with missing callback URLs does not send request', function () {
    Task::fake();
    Http::fake();
    $task = new TrackTaskInBackground(new TestTask, '', '', '');
    $result = $task->sendCallback(CallbackType::Finished, ['event' => 'no_url']);
    expect($result)->toBeFalse();
    Http::assertNothingSent();
    Task::unfake();
});

test('can send callback with large payload', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);
    $largePayload = ['data' => str_repeat('A', 10000)];
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, $largePayload);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => strlen($request->body()) > 10000);
    Task::unfake();
});

test('can send callback with special characters in payload', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);
    $payload = ['data' => '特殊字符!@#$%^&*()'];
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, $payload);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => $request->data()['data'] === '特殊字符!@#$%^&*()');
    Task::unfake();
});

test('can send callback with null values in payload', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);
    $payload = ['data' => null];
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, $payload);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => json_decode($request->body(), true)['data'] === null);
    Task::unfake();
});

test('can send callback with array values in payload', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);
    $payload = ['items' => [1, 2, 3]];
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, $payload);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => json_decode($request->body(), true)['items'] === [1, 2, 3]);
    Task::unfake();
});

test('can send callback with deeply nested payload', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);
    $payload = ['meta' => ['level1' => ['level2' => ['level3' => 'deep']]]];
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, $payload);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => json_decode($request->body(), true)['meta']['level1']['level2']['level3'] === 'deep');
    Task::unfake();
});

test('can send callback with all callback URLs set to same value', function () {
    Task::fake();
    Http::fake([
        'https://example.com/callback' => Http::response(['status' => 'success'], 200),
    ]);
    $task = new TrackTaskInBackground(
        new TestTask,
        'https://example.com/callback',
        'https://example.com/callback',
        'https://example.com/callback',
    );
    $result = $task->sendCallback(CallbackType::Failed, ['event' => 'same_url']);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/callback');
    Task::unfake();
});

test('can send callback with only finished URL set', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, ['event' => 'finished_only']);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/finished');
    Task::unfake();
});

test('can send callback with only failed URL set', function () {
    Task::fake();
    Http::fake([
        'https://example.com/failed' => Http::response(['status' => 'fail'], 200),
    ]);
    $task = new TrackTaskInBackground(new TestTask, '', 'https://example.com/failed', '');
    $result = $task->sendCallback(CallbackType::Failed, ['event' => 'failed_only']);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/failed');
    Task::unfake();
});

test('can send callback with only timeout URL set', function () {
    Task::fake();
    Http::fake([
        'https://example.com/timeout' => Http::response(['status' => 'timeout'], 200),
    ]);
    $task = new TrackTaskInBackground(new TestTask, '', '', 'https://example.com/timeout');
    $result = $task->sendCallback(CallbackType::Timeout, ['event' => 'timeout_only']);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/timeout');
    Task::unfake();
});

test('callback uses POST method by default even if unsupported method is specified', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, ['event' => 'force_post'], ['X-HTTP-Method-Override' => 'PUT']);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => $request->method() === 'POST');
    Task::unfake();
});

test('can send callback with custom X-Callback-Type header', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $headers = $task->getCallbackHeaders();
    $headers['X-Callback-Type'] = 'custom_callback'; // This will be ignored by the implementation
    $task->sendCallback(CallbackType::Finished, ['event' => 'custom_callback_type'], $headers);
    // Assert the default value is used
    Http::assertSent(fn ($request) => $request->header('X-Callback-Type')[0] === 'background_task_update');
    Task::unfake();
});

test('callback handles 302 redirect response', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response('', 302),
    ]);
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, ['event' => 'redirect']);
    expect($result)->toBeFalse();
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/finished');
    Task::unfake();
});

test('callback handles 204 No Content response as success', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response('', 204),
    ]);
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, ['event' => 'no_content']);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/finished');
    Task::unfake();
});

test('callback handles malformed JSON response gracefully', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response('not-json', 200),
    ]);
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, ['event' => 'malformed_json']);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/finished');
    Task::unfake();
});

test('callback handles slow response (simulated delay)', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => function () {
            usleep(100000); // 100ms delay

            return Http::response(['status' => 'success'], 200);
        },
    ]);
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, ['event' => 'slow_response']);
    expect($result)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/finished');
    Task::unfake();
});

test('can send multiple callbacks in quick succession', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    for ($i = 0; $i < 5; $i++) {
        $result = $task->sendCallback(CallbackType::Finished, ['event' => 'multi_'.$i]);
        expect($result)->toBeTrue();
    }
    Http::assertSentCount(5);
    Task::unfake();
});

test('can send callback with authentication headers', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);

    // Create a custom task that overrides getCallbackHeaders to include auth headers
    $customTask = new class(new TestTask, 'https://example.com/finished', '', '') extends TrackTaskInBackground
    {
        public function getCallbackHeaders(): array
        {
            return array_merge(parent::getCallbackHeaders(), [
                'Authorization' => 'Bearer test-token-123',
                'X-API-Key' => 'api-key-456',
            ]);
        }
    };

    $result = $customTask->sendCallback(CallbackType::Finished, ['event' => 'auth_test']);
    expect($result)->toBeTrue();
    Http::assertSent(function ($request) {
        $authHeader = $request->header('Authorization');
        $apiKeyHeader = $request->header('X-API-Key');

        return ! empty($authHeader) && $authHeader[0] === 'Bearer test-token-123' &&
               ! empty($apiKeyHeader) && $apiKeyHeader[0] === 'api-key-456';
    });
    Task::unfake();
});

test('callback handles network timeout gracefully', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['error' => 'Connection timeout'], 408),
    ]);
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, ['event' => 'network_timeout']);
    expect($result)->toBeFalse();
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/finished');
    Task::unfake();
});

test('can send callback with different content types', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['status' => 'success'], 200),
    ]);

    // Create a custom task that overrides getCallbackHeaders to include custom content type
    $customTask = new class(new TestTask, 'https://example.com/finished', '', '') extends TrackTaskInBackground
    {
        public function getCallbackHeaders(): array
        {
            return array_merge(parent::getCallbackHeaders(), [
                'Accept' => 'application/xml',
            ]);
        }
    };

    $result = $customTask->sendCallback(CallbackType::Finished, ['event' => 'xml_content']);
    expect($result)->toBeTrue();
    // Should still use JSON content type as per implementation, but Accept header can be custom
    Http::assertSent(function ($request) {
        return $request->header('Content-Type')[0] === 'application/json' &&
               $request->header('Accept')[0] === 'application/xml';
    });
    Task::unfake();
});

test('callback handles server returning 429 Too Many Requests', function () {
    Task::fake();
    Http::fake([
        'https://example.com/finished' => Http::response(['error' => 'Rate limited'], 429, [
            'Retry-After' => '60',
        ]),
    ]);
    $task = new TrackTaskInBackground(new TestTask, 'https://example.com/finished', '', '');
    $result = $task->sendCallback(CallbackType::Finished, ['event' => 'rate_limited']);
    expect($result)->toBeFalse();
    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/finished' &&
               $request->method() === 'POST';
    });
    Task::unfake();
});
