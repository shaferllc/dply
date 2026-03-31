# Tasks Module Merge Guide

This guide explains how the Tasks module has been merged into the TaskRunner module, providing enhanced functionality while maintaining backward compatibility.

## Overview

The Tasks module has been successfully merged into the TaskRunner module, creating a unified task management system with enhanced features including:

- **Task Chaining**: Execute multiple tasks sequentially with dependencies
- **Real-time Streaming**: Stream task output and progress to the frontend
- **Enhanced Task Management**: Improved task lifecycle and status tracking
- **Backward Compatibility**: Existing Tasks module code continues to work
- **Unified API**: Single interface for all task operations

## What Changed

### 1. BaseTask Class
- Now extends `App\Modules\TaskRunner\EnhancedTask` instead of `Dply\TaskRunner\Task`
- Maintains all existing functionality while gaining access to enhanced features
- Updated imports to use TaskRunner components

### 2. Task Model
- Now extends `App\Modules\TaskRunner\Models\Task` instead of `Illuminate\Database\Eloquent\Model`
- Inherits all TaskRunner functionality while preserving existing methods
- Updated to use TaskRunner enums and contracts

### 3. Updated Components
- **Enums**: Now use `App\Modules\TaskRunner\Enums\TaskStatus` and `App\Modules\TaskRunner\Enums\CallbackType`
- **Contracts**: Use `App\Modules\TaskRunner\Contracts\HasCallbacks`
- **Traits**: Use `App\Modules\TaskRunner\Traits\HandlesCallbacks`
- **Jobs**: Use `App\Modules\TaskRunner\Jobs\UpdateTaskOutput`

### 4. Individual Task Classes
- `GetFile`: Now extends `App\Modules\TaskRunner\EnhancedTask`
- `GenerateEd25519KeyPair`: Now extends `App\Modules\TaskRunner\Task`
- `TrackTaskInBackground`: Now extends `App\Modules\TaskRunner\EnhancedTask`
- `TestTask`: Now extends `App\Modules\TaskRunner\Task`

## Migration Process

### Automatic Migration
Run the migration command to move existing task data:

```bash
php artisan taskrunner:migrate-tasks
```

This command will:
- Check for existing task data in the old `tasks` table
- Migrate records to the new `task_runner_tasks` table
- Map old status values to new TaskRunner statuses
- Preserve all existing data and relationships

### Manual Migration Steps
If you prefer to migrate manually:

1. **Update Imports**: Change all imports from `Dply\Tasks\*` to `App\Modules\TaskRunner\*`
2. **Update Class Extensions**: Change base classes to use TaskRunner equivalents
3. **Update Method Calls**: Ensure method calls use the new TaskRunner API
4. **Test Thoroughly**: Verify all functionality works as expected

## Enhanced Features

### Task Chaining
```php
use App\Modules\TaskRunner\TaskChain;
use App\Modules\TaskRunner\Facades\TaskRunner;

// Create a task chain
$chain = TaskChain::make()
    ->addTask($task1)
    ->addTask($task2)
    ->addTask($task3)
    ->onProgress(function ($progress) {
        // Handle progress updates
    })
    ->onComplete(function ($results) {
        // Handle completion
    });

// Run the chain
$results = TaskRunner::runChain($chain);
```

### Real-time Streaming
```php
use App\Modules\TaskRunner\StreamingLogger;

// Stream task output in real-time
$logger = app(StreamingLogger::class);
$logger->stream('task-output', function ($data) {
    // Handle streaming data
    echo $data['message'];
});
```

### Enhanced Task Management
```php
use App\Modules\TaskRunner\EnhancedTask;

class MyTask extends EnhancedTask
{
    public function onOutputUpdated(string $output): void
    {
        // Handle output updates
        $this->setOutput($output);
    }

    public function onProgress(int $percentage): void
    {
        // Handle progress updates
        $this->setProgress($percentage);
    }
}
```

## Backward Compatibility

### Existing Code
All existing Tasks module code continues to work without changes:

```php
// This still works
$task = new MyTask();
$task->options(['key' => 'value']);
$result = $task->dispatch();
```

### Gradual Migration
You can migrate incrementally:

1. **Phase 1**: Update imports and base classes
2. **Phase 2**: Start using enhanced features
3. **Phase 3**: Migrate to new API patterns
4. **Phase 4**: Remove old Tasks module

## Configuration

The TaskRunner configuration now includes all Tasks module settings:

```php
// config/task-runner.php
return [
    'default_timeout' => 300,
    'temporary_directory' => storage_path('app/task-runner'),
    'logging' => [
        'streaming' => [
            'enabled' => true,
            'handlers' => [
                'console' => true,
                'file' => false,
                'websocket' => false,
            ],
        ],
    ],
    // ... other settings
];
```

## Testing

### Unit Tests
Update test imports to use TaskRunner components:

```php
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\EnhancedTask;
```

### Feature Tests
Test the enhanced functionality:

```php
public function test_task_chaining()
{
    $chain = TaskChain::make()
        ->addTask($task1)
        ->addTask($task2);

    $results = TaskRunner::runChain($chain);
    
    $this->assertTrue($results->isSuccessful());
}
```

## Troubleshooting

### Common Issues

1. **Import Errors**: Ensure all imports use `App\Modules\TaskRunner\*`
2. **Method Signature Conflicts**: Check for method signature compatibility
3. **Database Migration**: Run `php artisan taskrunner:migrate-tasks`
4. **Configuration**: Verify TaskRunner configuration is published

### Debugging

Enable debug logging:

```php
// config/task-runner.php
'logging' => [
    'level' => 'debug',
    'streaming' => [
        'enabled' => true,
    ],
],
```

## Next Steps

1. **Run Migration**: Execute the migration command
2. **Update Tests**: Update test suites to use new components
3. **Explore Features**: Start using task chaining and streaming
4. **Optimize**: Leverage enhanced performance features
5. **Document**: Update your application documentation

## Support

For issues or questions:
- Check the TaskRunner documentation
- Review the streaming features guide
- Examine the example implementations
- Test with the provided examples

The merge provides a solid foundation for future enhancements while maintaining all existing functionality. 