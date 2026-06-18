# TaskRunner Callback System Guide

The TaskRunner module now includes a comprehensive callback system for sending status updates and data back to home servers. This guide covers all aspects of the callback functionality.

## Overview

Callbacks allow tasks to send real-time updates to external systems (home servers) about their execution status, progress, and results. The system includes automatic retries, logging, and error handling.

## Core Components

### 1. HasCallbacks Contract
The `HasCallbacks` contract defines the interface for tasks that support callbacks:

```php
interface HasCallbacks
{
    public function handleCallback(Task $task, Request $request, CallbackType $type): void;
    public function getCallbackUrl(): ?string;
    public function getCallbackData(): array;
    public function getCallbackHeaders(): array;
    public function getCallbackTimeout(): int;
    public function isCallbacksEnabled(): bool;
    public function getCallbackRetryConfig(): array;
    public function validateCallbackData(array $data): bool;
}
```

### 2. HandlesCallbacks Trait
The `HandlesCallbacks` trait provides comprehensive callback functionality:

```php
trait HandlesCallbacks
{
    // Automatic callback handling
    public function handleCallback(Request $request, CallbackType $callbackType): void;
    
    // Callback configuration
    public function getCallbackUrl(): ?string;
    public function getCallbackData(): array;
    public function getCallbackHeaders(): array;
    public function getCallbackTimeout(): int;
    public function isCallbacksEnabled(): bool;
    public function getCallbackRetryConfig(): array;
    public function validateCallbackData(array $data): bool;
    
    // Sending callbacks
    public function sendCallback(CallbackType $type, array $additionalData = []): bool;
    public function sendStartedCallback(): bool;
    public function sendFinishedCallback(): bool;
    public function sendFailedCallback(string $error = null): bool;
    public function sendTimeoutCallback(): bool;
    public function sendProgressCallback(array $progressData): bool;
}
```

### 3. CallbackService
The `CallbackService` handles HTTP requests with retry logic:

```php
class CallbackService
{
    public function send(HasCallbacks $task, CallbackType $type, array $additionalData = []): bool;
    public function sendWithConfig(string $url, array $data, array $headers = [], int $timeout = 30): bool;
    public function sendBatch(array $callbacks): array;
    public function testCallbackUrl(string $url, int $timeout = 10): bool;
    public function validateCallbackData(array $data): bool;
}
```

### 4. CallbackType Enum
Defines all available callback types:

```php
enum CallbackType: string
{
    case Custom = 'custom';
    case Timeout = 'timeout';
    case Failed = 'failed';
    case Finished = 'finished';
    case Started = 'started';
    case Progress = 'progress';
    case Cancelled = 'cancelled';
    case Paused = 'paused';
    case Resumed = 'resumed';
}
```

## Usage Examples

### Basic Task with Callbacks

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;
use App\Modules\TaskRunner\Enums\CallbackType;

class MyTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        // Configure callbacks
        $this->setCallbackUrl('https://api.example.com/callbacks');
        $this->setCallbackConfig([
            'timeout' => 30,
            'max_attempts' => 3,
            'delay' => 5,
            'backoff_multiplier' => 2,
            'enabled' => true,
        ]);
    }

    public function render(): string
    {
        // Send started callback
        $this->sendStartedCallback();
        
        // Your task logic here
        $script = "echo 'Starting task...'";
        
        // Send progress callback
        $this->sendProgressUpdate(25, 'Task is running...');
        
        $script .= "\necho 'Task completed'";
        
        // Send finished callback
        $this->sendFinishedCallback();
        
        return $script;
    }
}
```

### Advanced Task with Custom Callbacks

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;
use App\Modules\TaskRunner\Enums\CallbackType;

class DeploymentTask extends BaseTask
{
    public function render(): string
    {
        $this->sendStartedCallback();
        
        $script = '';
        $steps = ['prepare', 'deploy', 'test', 'cleanup'];
        
        foreach ($steps as $index => $step) {
            $percentage = (($index + 1) / count($steps)) * 100;
            
            // Send progress update
            $this->sendProgressUpdate($percentage, "Executing {$step} step");
            
            $script .= "\n# {$step} step";
            $script .= "\necho 'Executing {$step}...'";
            
            // Send custom callback with step data
            $this->sendCustomCallback([
                'step' => $step,
                'step_number' => $index + 1,
                'total_steps' => count($steps),
                'step_data' => $this->getStepData($step),
            ]);
        }
        
        $this->sendFinishedCallback();
        
        return $script;
    }
    
    private function getStepData(string $step): array
    {
        // Return step-specific data
        return [
            'prepare' => ['files_processed' => 10],
            'deploy' => ['files_deployed' => 5],
            'test' => ['tests_run' => 15],
            'cleanup' => ['files_removed' => 3],
        ][$step] ?? [];
    }
}
```

### Error Handling with Callbacks

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;
use Exception;

class ErrorHandlingTask extends BaseTask
{
    public function render(): string
    {
        try {
            $this->sendStartedCallback();
            
            $script = "echo 'Starting task...'";
            
            // Simulate potential failure
            $script .= "\nif [ \$? -ne 0 ]; then";
            $script .= "\n  echo 'Task failed'";
            $script .= "\n  exit 1";
            $script .= "\nfi";
            
            $script .= "\necho 'Task completed successfully'";
            
            $this->sendFinishedCallback();
            
            return $script;
            
        } catch (Exception $e) {
            $this->sendFailedCallback($e->getMessage());
            throw $e;
        }
    }
}
```

## Callback Data Structure

All callbacks include standard data:

```json
{
    "task_id": "task-uuid",
    "task_name": "My Task",
    "status": "running",
    "exit_code": 0,
    "duration": 45,
    "output": "Task output...",
    "timestamp": "2024-01-01T12:00:00Z",
    "callback_type": "task_update",
    "event": "task_started",
    "started_at": "2024-01-01T12:00:00Z"
}
```

### Custom Callback Data

You can add custom data to any callback:

```php
$this->sendCustomCallback([
    'custom_field' => 'custom_value',
    'metadata' => [
        'user_id' => 123,
        'project_id' => 456,
    ],
    'metrics' => [
        'files_processed' => 100,
        'processing_time' => 2.5,
    ],
]);
```

## Configuration

### Task-Level Configuration

```php
$task->setCallbackConfig([
    'timeout' => 30,              // HTTP timeout in seconds
    'max_attempts' => 3,          // Max retry attempts
    'delay' => 5,                 // Initial delay between retries
    'backoff_multiplier' => 2,    // Exponential backoff multiplier
    'enabled' => true,            // Enable/disable callbacks
]);
```

### Global Configuration

Add to `config/task-runner.php`:

```php
return [
    'callbacks' => [
        'enabled' => true,
        'default_timeout' => 30,
        'default_max_attempts' => 3,
        'default_delay' => 5,
        'default_backoff_multiplier' => 2,
        'queue' => 'callbacks',
        'log_level' => 'info',
    ],
];
```

## HTTP Headers

Callbacks include standard headers:

```
Content-Type: application/json
User-Agent: TaskRunner/1.0
X-Task-ID: task-uuid
X-Callback-Type: task_update
```

## Retry Logic

The callback system includes automatic retry logic:

1. **Exponential Backoff**: Delays increase exponentially between retries
2. **Configurable Attempts**: Set maximum retry attempts per task
3. **Queue-Based**: Failed callbacks are queued for retry
4. **Logging**: All retry attempts are logged

### Retry Configuration

```php
$retryConfig = [
    'max_attempts' => 3,
    'delay' => 5,                 // Initial delay in seconds
    'backoff_multiplier' => 2,    // Multiply delay by this factor
];

// Retry delays: 5s, 10s, 20s
```

## Monitoring and Logging

### Log Levels

- **Info**: Successful callbacks
- **Warning**: Failed callbacks (will be retried)
- **Error**: Callback exceptions and exhausted retries

### Log Structure

```php
Log::info('Callback sent successfully', [
    'task_class' => 'App\Modules\TaskRunner\MyTask',
    'callback_type' => 'finished',
    'url' => 'https://api.example.com/callbacks',
    'status_code' => 200,
    'task_id' => 'task-uuid',
]);
```

## Testing Callbacks

### Test Callback URL

```php
$callbackService = app(CallbackService::class);
$isReachable = $callbackService->testCallbackUrl('https://api.example.com/callbacks');
```

### Mock Callbacks for Testing

```php
// In your test
$task = new MyTask();
$task->setCallbackUrl('https://api.example.com/callbacks');

// Mock the callback service
$this->mock(CallbackService::class, function ($mock) {
    $mock->shouldReceive('send')
        ->once()
        ->andReturn(true);
});

$task->sendStartedCallback();
```

## Best Practices

### 1. Always Handle Errors

```php
try {
    $this->sendStartedCallback();
    // Task logic
    $this->sendFinishedCallback();
} catch (Exception $e) {
    $this->sendFailedCallback($e->getMessage());
    throw $e;
}
```

### 2. Use Progress Updates for Long Tasks

```php
$totalSteps = 10;
for ($i = 0; $i < $totalSteps; $i++) {
    $percentage = (($i + 1) / $totalSteps) * 100;
    $this->sendProgressUpdate($percentage, "Step {$i + 1} of {$totalSteps}");
    // Execute step
}
```

### 3. Include Relevant Data

```php
$this->sendCustomCallback([
    'step' => 'deployment',
    'files_deployed' => $fileCount,
    'deployment_time' => $duration,
    'environment' => $environment,
]);
```

### 4. Configure Appropriate Timeouts

```php
$task->setCallbackConfig([
    'timeout' => 60,  // Longer timeout for slow networks
    'max_attempts' => 5,  // More attempts for unreliable endpoints
]);
```

### 5. Monitor Callback Health

```php
// Check callback endpoint health
$isHealthy = $callbackService->testCallbackUrl($callbackUrl);

if (!$isHealthy) {
    Log::warning('Callback endpoint is not responding', [
        'url' => $callbackUrl,
    ]);
}
```

## Troubleshooting

### Common Issues

1. **Callback Not Sent**: Check if callbacks are enabled and URL is set
2. **Timeout Errors**: Increase timeout or check network connectivity
3. **Retry Loops**: Check callback endpoint availability
4. **Invalid Data**: Ensure callback data validation passes

### Debug Commands

```bash
# Test callback endpoint
php artisan task:test-callback https://api.example.com/callbacks

# View callback logs
tail -f storage/logs/laravel.log | grep "Callback"

# Check callback queue
php artisan queue:work callbacks
```

## Integration Examples

### Webhook Integration

```php
class WebhookTask extends BaseTask
{
    public function __construct(string $webhookUrl)
    {
        parent::__construct();
        $this->setCallbackUrl($webhookUrl);
    }
    
    public function render(): string
    {
        $this->sendStartedCallback();
        
        // Task logic
        $result = $this->executeTask();
        
        $this->sendCustomCallback([
            'result' => $result,
            'webhook_data' => $this->prepareWebhookData(),
        ]);
        
        return "echo 'Task completed'";
    }
}
```

### API Integration

```php
class ApiTask extends BaseTask
{
    public function render(): string
    {
        $this->sendStartedCallback();
        
        // Send progress updates
        $this->sendProgressUpdate(25, 'Connecting to API...');
        $this->sendProgressUpdate(50, 'Processing data...');
        $this->sendProgressUpdate(75, 'Saving results...');
        
        $this->sendFinishedCallback();
        
        return "curl -X POST https://api.example.com/process";
    }
}
```

This comprehensive callback system provides robust, reliable communication between tasks and external systems with automatic retry logic, detailed logging, and flexible configuration options. 