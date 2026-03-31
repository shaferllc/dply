# TaskRunner Module

A robust, secure, and feature-rich task execution system for Laravel applications. This module provides a comprehensive solution for running shell scripts both locally and remotely with advanced error handling, security features, monitoring capabilities, and **real-time streaming logs**.

## Features

### 🔒 Security
- **Script Validation**: Automatic validation of script content for dangerous patterns
- **Path Security**: Protection against path traversal attacks
- **Command Filtering**: Configurable forbidden command detection
- **Secure File Operations**: Proper file permissions and cleanup
- **Input Sanitization**: Comprehensive input validation and sanitization

### 🛡️ Error Handling
- **Retry Logic**: Automatic retry with exponential backoff for failed tasks
- **Exception Hierarchy**: Structured exception classes for different error types
- **Comprehensive Logging**: Detailed logging with configurable levels
- **Graceful Degradation**: Proper cleanup on failures

### 🔧 Configuration
- **Flexible Configuration**: Extensive configuration options via environment variables
- **Validation**: Automatic configuration validation on startup
- **Security Settings**: Configurable security parameters
- **Logging Options**: Customizable logging behavior

### 🚀 Performance
- **Background Execution**: Support for background task execution
- **Resource Management**: Automatic cleanup of temporary resources
- **Connection Pooling**: Efficient connection management
- **Timeout Handling**: Configurable timeouts with proper cleanup

### 📡 **Real-Time Streaming Logs**
- **Live Output Monitoring**: Real-time streaming of task execution output
- **Multiple Handlers**: Console, file, and WebSocket streaming support
- **Channel-Based Filtering**: Stream specific channels or all channels
- **Livewire Integration**: Built-in Livewire component for web-based monitoring
- **Progress Tracking**: Real-time progress updates and task lifecycle events

## Installation

1. **Register the Service Provider** in `config/app.php`:

```php
'providers' => [
    // ...
    App\Modules\TaskRunner\TaskServiceProvider::class,
],
```

2. **Publish the configuration**:

```bash
php artisan vendor:publish --tag=task-runner
```

3. **Configure environment variables** in your `.env` file:

```env
TASK_RUNNER_TEMPORARY_DIRECTORY=/tmp/task-runner
TASK_RUNNER_DEFAULT_TIMEOUT=60
TASK_RUNNER_LOGGING_ENABLED=true
TASK_RUNNER_RETRY_ENABLED=true
TASK_RUNNER_MAX_ATTEMPTS=3

# Streaming Logging
TASK_RUNNER_STREAMING_ENABLED=true
TASK_RUNNER_STREAMING_DEFAULT_LEVEL=info
TASK_RUNNER_STREAMING_CONSOLE_HANDLER=true
TASK_RUNNER_STREAMING_FILE_HANDLER=false
TASK_RUNNER_STREAMING_WEBSOCKET_HANDLER=false
```

## Configuration

### Basic Configuration

```php
// config/task-runner.php
return [
    'temporary_directory' => env('TASK_RUNNER_TEMPORARY_DIRECTORY', ''),
    'default_timeout' => env('TASK_RUNNER_DEFAULT_TIMEOUT', 60),
    'task_views' => env('TASK_RUNNER_VIEWS', 'tasks'),
    
    'security' => [
        'max_script_size' => env('TASK_RUNNER_MAX_SCRIPT_SIZE', 1024 * 1024),
        'forbidden_commands' => [
            'rm -rf /',
            'dd if=/dev/zero',
            'mkfs',
            'fdisk',
            'parted',
        ],
    ],
    
    'retry' => [
        'enabled' => env('TASK_RUNNER_RETRY_ENABLED', true),
        'max_attempts' => env('TASK_RUNNER_MAX_ATTEMPTS', 3),
        'backoff_multiplier' => env('TASK_RUNNER_BACKOFF_MULTIPLIER', 2),
        'initial_delay' => env('TASK_RUNNER_INITIAL_DELAY', 1),
    ],
    
    'logging' => [
        'enabled' => env('TASK_RUNNER_LOGGING_ENABLED', true),
        'level' => env('TASK_RUNNER_LOG_LEVEL', 'info'),
        'include_output' => env('TASK_RUNNER_LOG_INCLUDE_OUTPUT', false),
        
        'streaming' => [
            'enabled' => env('TASK_RUNNER_STREAMING_ENABLED', true),
            'default_level' => env('TASK_RUNNER_STREAMING_DEFAULT_LEVEL', 'info'),
            'levels' => ['info', 'warning', 'error'],
            'channels' => [
                'process_output' => true,
                'task_events' => true,
                'errors' => true,
                'progress' => true,
            ],
            'handlers' => [
                'console' => true,
                'websocket' => false,
                'file' => false,
            ],
        ],
    ],
];
```

## Usage

### Creating Tasks

1. **Using Artisan Command**:

```bash
php artisan make:task BackupDatabase
```

2. **Manual Task Creation**:

```php
<?php

namespace App\Tasks;

use App\Modules\TaskRunner\Task;

class BackupDatabase extends Task
{
    public string $database = 'myapp';
    public string $backupPath = '/backups';
    
    public function render(): string
    {
        return <<<BASH
        #!/bin/bash
        set -euo pipefail
        
        echo "Starting database backup..."
        mysqldump -u root -p {$this->database} > {$this->backupPath}/backup_$(date +%Y%m%d_%H%M%S).sql
        echo "Backup completed successfully!"
        BASH;
    }
}
```

### Executing Tasks

```php
use App\Tasks\BackupDatabase;
use App\Modules\TaskRunner\Facades\TaskRunner;

// Basic execution
$result = TaskRunner::run(BackupDatabase::make());

// With connection
$result = TaskRunner::run(
    BackupDatabase::make()
        ->onConnection('production')
        ->inBackground()
        ->id('backup-' . time())
);

// With output callback
$result = TaskRunner::run(
    BackupDatabase::make()
        ->onOutput(function ($type, $output) {
            echo "[$type] $output";
        })
);
```

### **Real-Time Streaming Logs**

#### **Console Streaming**

```php
use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;

$streamingLogger = app(StreamingLoggerInterface::class);

// Add a custom stream handler
$streamingLogger->addStreamHandler(function ($logData) {
    echo "[{$logData['timestamp']}] {$logData['level']}: {$logData['message']}\n";
});

// Execute task with streaming
$result = TaskRunner::run(BackupDatabase::make());
```

#### **File Streaming**

```php
// Enable file streaming in config
// TASK_RUNNER_STREAMING_FILE_HANDLER=true

// Logs will be written to: storage/logs/task-runner-streaming.log
```

#### **WebSocket Streaming**

```php
// Enable WebSocket streaming in config
// TASK_RUNNER_STREAMING_WEBSOCKET_HANDLER=true

// Add custom WebSocket handler
$streamingLogger->addStreamHandler(function ($logData) {
    // Send to WebSocket clients
    broadcast()->to('task-runner')->emit('log', $logData);
}, 'websocket');
```

#### **Livewire Component for Web Monitoring**

```php
// In your Livewire component
use App\Modules\TaskRunner\Livewire\TaskMonitor;

class TaskDashboard extends Component
{
    public function render()
    {
        return view('livewire.task-dashboard', [
            'taskMonitor' => new TaskMonitor('task-123'),
        ]);
    }
}
```

```blade
{{-- In your Blade view --}}
<livewire:task-monitor task-id="{{ $taskId }}" />
```

#### **Custom Streaming Handlers**

```php
use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;

$streamingLogger = app(StreamingLoggerInterface::class);

// Handler for all channels
$streamingLogger->addStreamHandler(function ($logData) {
    // Process all log data
    Log::info('Streaming log', $logData);
});

// Handler for specific channel
$streamingLogger->addStreamHandler(function ($logData) {
    // Process only process output
    if ($logData['context']['stream_type'] === 'process_output') {
        echo "Process: {$logData['message']}\n";
    }
}, 'process_output');

// Handler for specific task
$streamingLogger->addStreamHandler(function ($logData) {
    // Process only logs for specific task
    if ($logData['context']['task_id'] === 'my-task-123') {
        // Handle task-specific logging
    }
}, 'my-task-123');
```

#### **Streaming API Methods**

```php
$streamingLogger = app(StreamingLoggerInterface::class);

// Stream process output
$streamingLogger->streamProcessOutput('out', 'Task started', [
    'task_id' => 'task-123',
    'command' => 'backup-database',
]);

// Stream task events
$streamingLogger->streamTaskEvent('started', [
    'task_id' => 'task-123',
    'command' => 'backup-database',
]);

// Stream errors
$streamingLogger->streamError('Database connection failed', [
    'task_id' => 'task-123',
    'error_code' => 'DB_CONNECTION_ERROR',
]);

// Stream progress
$streamingLogger->streamProgress(50, 100, 'Backup in progress...', [
    'task_id' => 'task-123',
]);
```

### Remote Execution

Configure connections in your config:

```php
// config/dply.php
return [
    'connections' => [
        'production' => [
            'host' => 'prod.example.com',
            'port' => 22,
            'username' => 'deploy',
            'private_key' => env('PROD_SSH_KEY'),
            'script_path' => '/home/deploy/scripts',
        ],
    ],
];
```

### Error Handling

```php
use App\Modules\TaskRunner\Exceptions\TaskExecutionException;
use App\Modules\TaskRunner\Exceptions\TaskValidationException;

try {
    $result = TaskRunner::run(BackupDatabase::make());
    
    if ($result->isSuccessful()) {
        echo "Task completed successfully!";
    } else {
        echo "Task failed with exit code: " . $result->getExitCode();
    }
} catch (TaskValidationException $e) {
    echo "Validation failed: " . $e->getMessage();
    foreach ($e->getErrors() as $field => $error) {
        echo "$field: $error";
    }
} catch (TaskExecutionException $e) {
    echo "Execution failed: " . $e->getMessage();
    if ($e->getOutput()) {
        echo "Output: " . $e->getOutput();
    }
}
```

## **Streaming Logging Features**

### **Real-Time Output Types**

1. **Process Output**: Real-time stdout/stderr from running tasks
2. **Task Events**: Task lifecycle events (started, completed, failed, retrying)
3. **Errors**: Error messages with context
4. **Progress**: Progress updates with percentages
5. **General**: General log messages

### **Streaming Configuration**

```env
# Enable/disable streaming
TASK_RUNNER_STREAMING_ENABLED=true

# Default log level for streaming
TASK_RUNNER_STREAMING_DEFAULT_LEVEL=info

# Which log levels to stream
TASK_RUNNER_STREAMING_LEVELS=info,warning,error

# Enable specific channels
TASK_RUNNER_STREAMING_PROCESS_OUTPUT=true
TASK_RUNNER_STREAMING_TASK_EVENTS=true
TASK_RUNNER_STREAMING_ERRORS=true
TASK_RUNNER_STREAMING_PROGRESS=true

# Enable specific handlers
TASK_RUNNER_STREAMING_CONSOLE_HANDLER=true
TASK_RUNNER_STREAMING_FILE_HANDLER=false
TASK_RUNNER_STREAMING_WEBSOCKET_HANDLER=false
```

### **Livewire Component Features**

- **Real-time log display** with auto-scroll
- **Log filtering** by level and type
- **Log statistics** and counts
- **Export functionality** for log data
- **Task status monitoring**
- **Responsive design** with Tailwind CSS

### **WebSocket Integration**

```php
// Example WebSocket handler for Laravel Echo
$streamingLogger->addStreamHandler(function ($logData) {
    broadcast()->to('task-runner')->emit('log', [
        'timestamp' => $logData['timestamp'],
        'level' => $logData['level'],
        'message' => $logData['message'],
        'context' => $logData['context'],
    ]);
}, 'websocket');
```

```javascript
// Frontend JavaScript
Echo.channel('task-runner')
    .listen('log', (e) => {
        console.log('Streaming log:', e);
        // Update UI with real-time log data
    });
```

## Security Features

### Script Validation

The TaskRunner automatically validates scripts for:

- **Size limits**: Configurable maximum script size
- **Dangerous commands**: Detection of forbidden commands
- **Path traversal**: Protection against `../` attacks
- **Command injection**: Validation of potentially dangerous patterns

### File Security

- **Secure permissions**: Temporary files are created with 0600 permissions
- **Automatic cleanup**: Temporary files are automatically removed
- **Path validation**: All file paths are validated for security
- **Input sanitization**: All inputs are properly sanitized

### Connection Security

- **SSH key validation**: Private keys are validated for proper format
- **Host validation**: Host names and IPs are validated
- **Port validation**: Port numbers are checked for valid ranges
- **Proxy validation**: Proxy jump configurations are validated

## Monitoring and Logging

### Logging Configuration

```php
'logging' => [
    'enabled' => true,
    'level' => 'info',
    'channel' => 'stack',
    'include_output' => false, // Set to true to include script output in logs
],
```

### Log Examples

```php
// Successful execution
Log::info('Process executed', [
    'command' => 'bash /tmp/task_abc123.sh',
    'exit_code' => 0,
    'successful' => true,
    'attempt' => 1,
]);

// Failed execution with retry
Log::warning('Process retry attempt', [
    'command' => 'bash /tmp/task_abc123.sh',
    'attempt' => 2,
    'max_attempts' => 3,
    'reason' => 'timeout',
    'message' => 'Process timed out after 60 seconds',
]);
```

### **Streaming Log Examples**

```php
// Process output streaming
[2024-01-15T10:30:15.123Z] info: Starting database backup...
[2024-01-15T10:30:16.456Z] info: Backup completed successfully!

// Task events streaming
[2024-01-15T10:30:15.000Z] info: Task started
[2024-01-15T10:30:16.500Z] info: Task completed

// Error streaming
[2024-01-15T10:30:15.789Z] error: Database connection failed
```

## Testing

### Fake Tasks for Testing

```php
use App\Modules\TaskRunner\Facades\TaskRunner;

// Fake all tasks
TaskRunner::fake();

// Fake specific tasks
TaskRunner::fake([
    BackupDatabase::class => 'Backup completed successfully',
]);

// Fake with custom output
TaskRunner::fake([
    BackupDatabase::class => ProcessOutput::make('Custom output')->setExitCode(0),
]);

// Assertions
TaskRunner::assertDispatched(BackupDatabase::class);
TaskRunner::assertDispatchedTimes(BackupDatabase::class, 2);
TaskRunner::assertNotDispatched(BackupDatabase::class);
```

### **Testing Streaming Logs**

```php
use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;

class StreamingLogTest extends TestCase
{
    public function test_streaming_logs_are_emitted()
    {
        $streamingLogger = app(StreamingLoggerInterface::class);
        $logs = [];
        
        $streamingLogger->addStreamHandler(function ($logData) use (&$logs) {
            $logs[] = $logData;
        });
        
        // Execute task
        TaskRunner::run(BackupDatabase::make());
        
        // Assert logs were streamed
        $this->assertNotEmpty($logs);
        $this->assertContains('Task started', array_column($logs, 'message'));
    }
}
```

## Exception Classes

### TaskValidationException

Thrown when task validation fails:

```php
try {
    $task = new InvalidTask();
    $task->validate();
} catch (TaskValidationException $e) {
    $errors = $e->getErrors();
    // Handle validation errors
}
```

### TaskExecutionException

Thrown when task execution fails:

```php
try {
    $result = TaskRunner::run($task);
} catch (TaskExecutionException $e) {
    $output = $e->getOutput();
    // Handle execution error
}
```

### ConnectionNotFoundException

Thrown when a connection is not found:

```php
try {
    $connection = Connection::fromConfig('nonexistent');
} catch (ConnectionNotFoundException $e) {
    // Handle missing connection
}
```

## Best Practices

### 1. Always Validate Input

```php
class SafeTask extends Task
{
    public function __construct(public string $userInput)
    {
        // Validate user input
        if (empty(trim($this->userInput))) {
            throw new InvalidArgumentException('User input cannot be empty');
        }
        
        // Sanitize user input
        $this->userInput = escapeshellarg($this->userInput);
    }
}
```

### 2. Use Proper Error Handling

```php
$result = TaskRunner::run($task);

if (!$result->isSuccessful()) {
    Log::error('Task failed', [
        'exit_code' => $result->getExitCode(),
        'output' => $result->getBuffer(),
    ]);
    
    // Handle failure appropriately
    throw new TaskFailedException('Task execution failed');
}
```

### 3. Implement Proper Cleanup

```php
class CleanupTask extends Task
{
    public function render(): string
    {
        return <<<BASH
        #!/bin/bash
        set -euo pipefail
        
        # Create temporary file
        temp_file=$(mktemp)
        
        # Ensure cleanup on exit
        trap 'rm -f "$temp_file"' EXIT
        
        # Your script logic here
        echo "Processing..." > "$temp_file"
        
        # Cleanup happens automatically
        BASH;
    }
}
```

### 4. Use Background Execution for Long-Running Tasks

```php
$result = TaskRunner::run(
    LongRunningTask::make()
        ->inBackground()
        ->writeOutputTo('/var/log/long-task.log')
);
```

### 5. **Leverage Streaming for Real-Time Monitoring**

```php
// Set up streaming for real-time monitoring
$streamingLogger = app(StreamingLoggerInterface::class);

$streamingLogger->addStreamHandler(function ($logData) {
    // Send to monitoring dashboard
    $this->updateDashboard($logData);
    
    // Send notifications for errors
    if ($logData['level'] === 'error') {
        $this->sendAlert($logData);
    }
});

// Execute task with streaming
$result = TaskRunner::run(BackupDatabase::make());
```

## Troubleshooting

### Common Issues

1. **Permission Denied**: Ensure the temporary directory is writable
2. **SSH Connection Failed**: Verify SSH key format and permissions
3. **Script Timeout**: Increase timeout or use background execution
4. **Validation Errors**: Check script content for forbidden patterns
5. **Streaming Not Working**: Verify streaming is enabled in configuration

### Debug Mode

Enable debug logging:

```env
TASK_RUNNER_LOGGING_ENABLED=true
TASK_RUNNER_LOG_LEVEL=debug
TASK_RUNNER_LOG_INCLUDE_OUTPUT=true
TASK_RUNNER_STREAMING_ENABLED=true
TASK_RUNNER_STREAMING_CONSOLE_HANDLER=true
```

### Health Checks

```php
// Check if TaskRunner is properly configured
if (!app()->bound(TaskDispatcher::class)) {
    throw new RuntimeException('TaskRunner not properly registered');
}

// Validate configuration
$config = config('task-runner');
if (!$config) {
    throw new RuntimeException('TaskRunner configuration not found');
}

// Check streaming logger
$streamingLogger = app(StreamingLoggerInterface::class);
if (!$streamingLogger->isStreamingEnabled()) {
    throw new RuntimeException('Streaming logger is disabled');
}
```

## Contributing

When contributing to the TaskRunner module:

1. Follow Laravel coding standards
2. Add comprehensive tests
3. Update documentation
4. Ensure security best practices
5. Add proper error handling
6. Include logging for debugging
7. Test streaming functionality

## License

This module is part of the Laravel application and follows the same license terms. 