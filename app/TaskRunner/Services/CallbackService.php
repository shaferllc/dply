<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Services;

use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Jobs\RetryCallbackJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * CallbackService handles sending HTTP callbacks to home servers.
 * Provides retry logic, logging, and error handling for callback requests.
 */
class CallbackService
{
    /**
     * Send a callback to the home server.
     */
    public function send(HasCallbacks $task, CallbackType $type, array $additionalData = []): bool
    {
        $url = $task->getCallbackUrl($type);

        if (! $url) {
            Log::warning('No callback URL provided for task', [
                'task_class' => get_class($task),
                'callback_type' => $type->value,
            ]);

            return false;
        }

        $data = array_merge($task->getCallbackData(), $additionalData, [
            'callback_type' => $type->value,
        ]);

        if (! $task->validateCallbackData($data)) {
            Log::error('Invalid callback data', [
                'task_class' => get_class($task),
                'data' => $data,
            ]);

            return false;
        }

        $headers = $task->getCallbackHeaders();
        $timeout = $task->getCallbackTimeout();
        $retryConfig = $task->getCallbackRetryConfig();

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->post($url, $data);

            if ($response->successful()) {
                $this->logSuccessfulCallback($task, $type, $data, $response);

                return true;
            }

            $this->logFailedCallback($task, $type, $data, $response);
            $this->scheduleRetry($task, $type, $additionalData, $retryConfig);

            return false;

        } catch (\Exception $e) {
            $this->logCallbackException($task, $type, $data, $e);
            $this->scheduleRetry($task, $type, $additionalData, $retryConfig);

            return false;
        }
    }

    /**
     * Send a callback with custom configuration.
     */
    public function sendWithConfig(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 30,
        array $retryConfig = []
    ): bool {
        $defaultRetryConfig = [
            'max_attempts' => 3,
            'delay' => 5,
            'backoff_multiplier' => 2,
        ];

        $retryConfig = array_merge($defaultRetryConfig, $retryConfig);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->post($url, $data);

            if ($response->successful()) {
                Log::info('Custom callback sent successfully', [
                    'url' => $url,
                    'status_code' => $response->status(),
                ]);

                return true;
            }

            Log::warning('Custom callback failed', [
                'url' => $url,
                'status_code' => $response->status(),
                'response' => $response->body(),
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('Custom callback exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a batch of callbacks.
     */
    public function sendBatch(array $callbacks): array
    {
        $results = [];

        foreach ($callbacks as $index => $callback) {
            $results[$index] = $this->send(
                $callback['task'],
                $callback['type'],
                $callback['data'] ?? []
            );
        }

        return $results;
    }

    /**
     * Test a callback URL to ensure it's reachable.
     */
    public function testCallbackUrl(string $url, int $timeout = 10): bool
    {
        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'TaskRunner/1.0'])
                ->get($url);

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Callback URL test failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Validate callback data structure.
     */
    public function validateCallbackData(array $data): bool
    {
        $requiredFields = ['task_id', 'callback_type', 'timestamp'];

        foreach ($requiredFields as $field) {
            if (! isset($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Log successful callback.
     */
    protected function logSuccessfulCallback(
        HasCallbacks $task,
        CallbackType $type,
        array $data,
        $response
    ): void {
        Log::info('Callback sent successfully', [
            'task_class' => get_class($task),
            'callback_type' => $type->value,
            'url' => $task->getCallbackUrl(),
            'status_code' => $response->status(),
            'task_id' => $data['task_id'] ?? null,
        ]);
    }

    /**
     * Log failed callback.
     */
    protected function logFailedCallback(
        HasCallbacks $task,
        CallbackType $type,
        array $data,
        $response
    ): void {
        Log::warning('Callback failed', [
            'task_class' => get_class($task),
            'callback_type' => $type->value,
            'url' => $task->getCallbackUrl(),
            'status_code' => $response->status(),
            'response_body' => $response->body(),
            'task_id' => $data['task_id'] ?? null,
        ]);
    }

    /**
     * Log callback exception.
     */
    protected function logCallbackException(
        HasCallbacks $task,
        CallbackType $type,
        array $data,
        \Exception $e
    ): void {
        Log::error('Callback exception', [
            'task_class' => get_class($task),
            'callback_type' => $type->value,
            'url' => $task->getCallbackUrl(),
            'error' => $e->getMessage(),
            'task_id' => $data['task_id'] ?? null,
        ]);
    }

    /**
     * Schedule a retry for failed callbacks.
     */
    protected function scheduleRetry(
        HasCallbacks $task,
        CallbackType $type,
        array $additionalData,
        array $retryConfig
    ): void {
        $maxAttempts = $retryConfig['max_attempts'] ?? 3;
        $delay = $retryConfig['delay'] ?? 5;
        $backoffMultiplier = $retryConfig['backoff_multiplier'] ?? 2;

        // This would be implemented with a job that tracks retry attempts
        // For now, we'll just log the retry attempt
        Log::info('Scheduling callback retry', [
            'task_class' => get_class($task),
            'callback_type' => $type->value,
            'url' => $task->getCallbackUrl(),
            'delay' => $delay,
        ]);

        // Queue::later(now()->addSeconds($delay), new RetryCallbackJob($task, $type, $additionalData));
    }
}
