# TaskRunner Streaming Features Implementation

This document summarizes all the streaming logging features that have been implemented in the TaskRunner module.

## 🚀 **1. WebSocket Broadcasting Integration**

### **Features:**
- Real-time WebSocket broadcasting for live task monitoring
- Multiple broadcast channels (general, task-specific, user-specific)
- Automatic event routing based on log type
- Error handling and fallback mechanisms

### **Files:**
- `app/Modules/TaskRunner/Broadcasting/TaskRunnerBroadcaster.php`
- Updated `TaskServiceProvider.php` for WebSocket registration

### **Usage:**
```php
// Enable WebSocket broadcasting
config(['task-runner.logging.streaming.handlers.websocket' => true]);

// Frontend JavaScript
Echo.channel('task-runner')
    .listen('log', (e) => console.log('Log:', e))
    .listen('task-event', (e) => console.log('Task Event:', e))
    .listen('progress', (e) => console.log('Progress:', e))
    .listen('metrics', (e) => console.log('Metrics:', e));
```

---

## 📊 **2. Task Progress Tracking with Visual Indicators**

### **Features:**
- Step-by-step progress tracking with descriptions
- Automatic progress percentage calculation
- Visual progress indicators
- Custom step management
- Progress reset and completion handling

### **Files:**
- `app/Modules/TaskRunner/Traits/HasProgressTracking.php`

### **Usage:**
```php
class MyTask extends Task
{
    use HasProgressTracking;

    public function render(): string
    {
        $this->initializeProgress(5, [
            'Step 1: Starting process',
            'Step 2: Processing data',
            'Step 3: Validating results',
            'Step 4: Saving output',
            'Step 5: Cleanup',
        ]);

        // Progress updates automatically streamed
        return '#!/bin/bash...';
    }
}
```

---

## 🔗 **3. Task Dependency Management**

### **Features:**
- Sequential task execution with streaming
- Configurable failure handling (stop on failure or continue)
- Parallel task execution support
- Comprehensive result tracking
- Chain event streaming

### **Files:**
- `app/Modules/TaskRunner/TaskChain.php`
- `app/Modules/TaskRunner/Exceptions/TaskChainException.php`

### **Usage:**
```php
$chain = TaskChain::make()
    ->withStreaming(true)
    ->stopOnFailure(false);

$chain->addMany([
    BackupDatabase::make(),
    CompressBackup::make(),
    UploadToCloud::make(),
]);

$results = $chain->run();
```

---

## 📈 **4. Real-Time Task Metrics Dashboard**

### **Features:**
- Live metrics display (active tasks, success rate, execution time)
- Real-time task history and statistics
- Auto-refresh capabilities
- Time range filtering
- Export functionality
- Responsive design with Tailwind CSS

### **Files:**
- `app/Modules/TaskRunner/Livewire/TaskMetricsDashboard.php`
- `app/Modules/TaskRunner/resources/views/livewire/task-metrics-dashboard.blade.php`

### **Usage:**
```php
// In your Livewire component
class TaskDashboard extends Component
{
    public function render()
    {
        return view('livewire.task-dashboard', [
            'metrics' => new TaskMetricsDashboard(),
        ]);
    }
}
```

```blade
{{-- In your Blade view --}}
<livewire:task-metrics-dashboard />
```

---

## 🎯 **5. Conditional Streaming Based on Task Type**

### **Features:**
- Priority-based streaming (low, normal, high, critical)
- Category-based streaming (backup, deployment, maintenance, etc.)
- Conditional notification rules
- Business hours filtering
- Custom streaming conditions

### **Files:**
- `app/Modules/TaskRunner/Services/ConditionalStreamingService.php`

### **Usage:**
```php
$conditionalService = app(ConditionalStreamingService::class);

$conditionalService->configureTaskStreaming($task, [
    'priority' => ConditionalStreamingService::PRIORITY_CRITICAL,
    'category' => ConditionalStreamingService::CATEGORY_BACKUP,
    'notify_on_completion' => true,
    'notify_on_error' => true,
]);

if ($conditionalService->shouldStreamTask($task, [
    'min_priority' => ConditionalStreamingService::PRIORITY_NORMAL,
    'categories' => [ConditionalStreamingService::CATEGORY_BACKUP],
    'business_hours_only' => false,
])) {
    TaskRunner::run($task);
}
```

---

## 🔧 **Core Infrastructure**

### **Streaming Logger Interface:**
- `app/Modules/TaskRunner/Contracts/StreamingLoggerInterface.php`
- `app/Modules/TaskRunner/StreamingLogger.php`

### **Enhanced Process Runner:**
- Updated `app/Modules/TaskRunner/ProcessRunner.php` with streaming integration

### **Configuration:**
- Enhanced `app/Modules/TaskRunner/config/task-runner.php` with streaming options

### **Service Provider:**
- Updated `app/Modules/TaskRunner/TaskServiceProvider.php` with all service bindings

---

## 📋 **Configuration Options**

### **Environment Variables:**
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

---

## 🎨 **Livewire Components**

### **Task Monitor:**
- Real-time log display with auto-scroll
- Log filtering by level and type
- Export functionality
- Task status monitoring

### **Metrics Dashboard:**
- Live metrics display
- Task history charts
- Auto-refresh capabilities
- Time range filtering

---

## 🔌 **Integration Points**

### **WebSocket Events:**
- `log` - General log messages
- `task-event` - Task lifecycle events
- `progress` - Progress updates
- `metrics` - System metrics
- `task-completed` - Task completion notifications

### **Streaming Channels:**
- `task-runner` - General channel
- `task-runner.{task_id}` - Task-specific channel
- `private-task-runner.{user_id}` - User-specific channel

### **Log Types:**
- `process_output` - Process stdout/stderr
- `task_event` - Task lifecycle events
- `error` - Error messages
- `progress` - Progress updates
- `general` - General log messages

---

## 📚 **Example Usage**

See `app/Modules/TaskRunner/Examples/AdvancedTaskExample.php` for comprehensive examples of all features working together.

### **Complete Example:**
```php
// 1. Configure conditional streaming
$task = new AdvancedTaskExample();
$conditionalService = app(ConditionalStreamingService::class);
$conditionalService->configureTaskStreaming($task, [
    'priority' => ConditionalStreamingService::PRIORITY_CRITICAL,
    'category' => ConditionalStreamingService::CATEGORY_BACKUP,
    'notify_on_completion' => true,
    'notify_on_error' => true,
]);

// 2. Create a task chain with streaming
$chain = TaskChain::make()
    ->withStreaming(true)
    ->stopOnFailure(true);

$chain->add($task);

// 3. Run the chain
$results = $chain->run();
```

---

## 🚀 **Benefits**

1. **Real-time Monitoring**: Live visibility into task execution
2. **Better Debugging**: Immediate feedback on task progress and errors
3. **Enhanced UX**: Rich web interfaces for task management
4. **Scalable Architecture**: Modular design with multiple streaming options
5. **Production Ready**: Comprehensive error handling and fallback mechanisms
6. **Flexible Configuration**: Easy to enable/disable features as needed
7. **Integration Friendly**: WebSocket support for custom dashboards
8. **Performance Optimized**: Efficient streaming with minimal overhead

---

## 🎨 **Complex View Support**

### **Enhanced View System:**
- **TaskViewRenderer**: Advanced view rendering with caching and validation
- **View Composers**: Custom data injection for views
- **Helper Functions**: Built-in shell-safe helper functions
- **View Validation**: Security checks for view templates
- **View Caching**: Performance optimization with configurable TTL

### **Complex View Examples:**
- **Database Backup View**: Multi-stage backup with cloud upload and notifications
- **Deployment View**: Full deployment pipeline with rollback capabilities
- **System Maintenance View**: Comprehensive system maintenance operations

### **View Features:**
```php
// View caching
config(['task-runner.view.cache.enabled' => true]);

// View composers
config(['task-runner.view.composers.tasks.*' => function($view, $task) {
    $view->with('custom_data', 'value');
}]);

// Helper functions in views
{{ $variable|escape_shell_arg }}
{{ $array|join_args }}
{{ $path|format_path }}
```

### **View Validation:**
- Automatic security checks for unescaped variables
- Detection of dangerous patterns
- View compilation validation
- Precompilation of all views

---

## 🚀 **Anonymous Task Support**

### **Quick Task Creation:**
- **AnonymousTask**: Create tasks without dedicated classes
- **Facade Methods**: Convenient facade methods for quick tasks
- **Multiple Creation Methods**: Script, command, view, callback, and more
- **Full Feature Support**: All streaming and progress features available

### **Anonymous Task Types:**
```php
// Simple command
TaskRunner::runAnonymous(AnonymousTask::command('List Files', 'ls -la'));

// Multiple commands
TaskRunner::runAnonymous(AnonymousTask::commands('System Info', [
    'uname -a', 'whoami', 'pwd', 'date'
]));

// With environment variables
TaskRunner::runAnonymous(AnonymousTask::withEnv('Backup', [
    'DB_HOST' => 'localhost'
], 'mysqldump -h $DB_HOST mydb'));

// With conditional logic
TaskRunner::runAnonymous(AnonymousTask::conditional('Conditional', [
    '[ -f /tmp/file.txt ]' => 'echo "File exists"',
    '[ -d /var/log ]' => 'echo "Log dir exists"'
]));

// With retry logic
TaskRunner::runAnonymous(AnonymousTask::withRetry('Retry Task', 'curl -f http://localhost', 3, 5));

// With progress tracking
TaskRunner::runAnonymous(AnonymousTask::withProgress('Progress', [
    'Step 1' => 'echo "Step 1"',
    'Step 2' => 'echo "Step 2"'
]));

// With cleanup
TaskRunner::runAnonymous(AnonymousTask::withCleanup('Cleanup', 'echo "work"', 'rm -f /tmp/temp'));

// With logging
TaskRunner::runAnonymous(AnonymousTask::withLogging('Logging', 'echo "test"', '/tmp/task.log'));

// Using views
TaskRunner::runAnonymous(AnonymousTask::view('Backup', 'tasks.database-backup', [
    'database_name' => 'mydb'
]));

// Using callbacks
TaskRunner::runAnonymous(AnonymousTask::callback('Callback', function($task) {
    return "#!/bin/bash\necho 'Hello from callback!'\n";
}));
```

### **Facade Methods:**
```php
// Direct facade usage
TaskRunner::runAnonymous(TaskRunner::command('Quick Command', 'echo "Hello"'));
TaskRunner::runAnonymous(TaskRunner::commands('Quick Commands', ['cmd1', 'cmd2']));
TaskRunner::runAnonymous(TaskRunner::view('Quick View', 'tasks.backup', $data));
TaskRunner::runAnonymous(TaskRunner::callback('Quick Callback', $callback));
```

### **Anonymous Task Features:**
- **Environment Variables**: Set custom environment variables
- **Conditional Logic**: Execute commands based on conditions
- **Error Handling**: Custom error handling and fallback commands
- **Retry Logic**: Automatic retry with configurable attempts and delays
- **Progress Tracking**: Built-in progress reporting
- **Cleanup**: Automatic cleanup on exit
- **Logging**: Automatic logging to files
- **View Integration**: Use complex views with anonymous tasks
- **Callback Support**: Dynamic script generation via callbacks
- **Streaming Support**: Full integration with streaming logging
- **Task Chains**: Use anonymous tasks in task chains

---

## 🔗 **Task Chaining with Streaming**

### **Sequential Workflows:**
- **Task Chains**: Execute multiple tasks in sequence
- **Progress Tracking**: Real-time progress updates for each task
- **Failure Handling**: Configurable stop-on-failure behavior
- **Timeout Management**: Set timeouts for entire chains
- **Result Aggregation**: Collect and analyze results from all tasks
- **Streaming Output**: Real-time output streaming to frontend

### **Task Chain Features:**
```php
// Basic task chain
$chain = TaskRunner::chain()
    ->addCommand('System Info', 'uname -a')
    ->addCommand('Disk Usage', 'df -h')
    ->addCommand('Memory Usage', 'free -h')
    ->addCommand('Process Count', 'ps aux | wc -l');

$results = $chain->run();

// Chain with different task types
$chain = TaskRunner::chain()
    ->addCommand('Database Backup', 'mysqldump -u root -p myapp > backup.sql')
    ->addCommands('System Maintenance', [
        'apt update',
        'apt upgrade -y',
        'systemctl restart nginx'
    ])
    ->addView('Generate Report', 'reports.system-status', [
        'timestamp' => now(),
        'server' => gethostname()
    ])
    ->addCallback('Data Processing', function () {
        echo "Processing data...\n";
        sleep(2);
        return true;
    });

// Chain with options
$chain = TaskRunner::chain()
    ->addCommand('Step 1', 'echo "Step 1"')
    ->addCommand('Step 2', 'echo "Step 2"')
    ->addCommand('Step 3', 'echo "Step 3"')
    ->withOptions([
        'stop_on_failure' => true,
        'timeout' => 300,
        'progress_tracking' => true,
        'streaming' => true
    ]);
```

### **Chain Execution Options:**
- **stop_on_failure**: Stop execution on first failure (default: true)
- **timeout**: Set timeout for entire chain (default: null)
- **progress_tracking**: Enable progress tracking (default: true)
- **streaming**: Enable real-time streaming (default: true)

### **Progress Tracking:**
```php
// Enable progress tracking
$chain = TaskRunner::chain()
    ->addCommand('Step 1', 'sleep 2 && echo "Step 1 completed"')
    ->addCommand('Step 2', 'sleep 2 && echo "Step 2 completed"')
    ->addCommand('Step 3', 'sleep 2 && echo "Step 3 completed"')
    ->withProgressTracking(true);

$results = $chain->run();

// Progress information
echo "Total tasks: {$results['total_tasks']}\n";
echo "Completed: {$results['completed_tasks']}\n";
echo "Successful: {$results['successful_tasks']}\n";
echo "Failed: {$results['failed_tasks']}\n";
echo "Success rate: {$results['success_rate']}%\n";
echo "Duration: {$results['duration']}s\n";
```

### **Chain Events:**
```php
// Chain started
Event::listen(TaskChainStarted::class, function (TaskChainStarted $event) {
    echo "🚀 Task chain started: {$event->chainId}\n";
    echo "   Tasks: {$event->getTaskCount()}\n";
    echo "   Task names: " . implode(', ', $event->getTaskNames()) . "\n";
});

// Chain progress
Event::listen(TaskChainProgress::class, function (TaskChainProgress $event) {
    $progressBar = $event->getProgressBar(30);
    echo "📊 Progress: {$progressBar} {$event->getPercentageInt()}%\n";
    echo "   Current: {$event->getCurrentTaskName()} ({$event->currentTask}/{$event->totalTasks})\n";
    echo "   Message: {$event->message}\n";
});

// Chain completed
Event::listen(TaskChainCompleted::class, function (TaskChainCompleted $event) {
    echo "✅ Task chain completed: {$event->chainId}\n";
    echo "   Success rate: {$event->getSuccessRate()}%\n";
    echo "   Duration: {$event->getDurationForHumans()}\n";
    echo "   Overall success: " . ($event->wasSuccessful() ? 'Yes' : 'No') . "\n";
});

// Chain failed
Event::listen(TaskChainFailed::class, function (TaskChainFailed $event) {
    echo "❌ Task chain failed: {$event->chainId}\n";
    echo "   Reason: {$event->getFailureReason()}\n";
    echo "   Failed task: {$event->getFailedTaskIndex()}\n";
    echo "   Success rate: {$event->getSuccessRate()}%\n";
});
```

### **Result Analysis:**
```php
$results = $chain->run();

// Individual task results
foreach ($results['results'] as $index => $result) {
    $status = $result['success'] ? '✅' : '❌';
    echo "{$status} {$result['task_name']}\n";
    
    if ($result['success']) {
        echo "   Exit code: {$result['exit_code']}\n";
        echo "   Output: {$result['output']}\n";
    } else {
        echo "   Error: {$result['error']}\n";
    }
}

// Aggregated output
$aggregatedOutput = $chain->getAggregatedOutput();
$aggregatedErrors = $chain->getAggregatedErrors();
```

### **Deployment Workflow Example:**
```php
$chain = TaskRunner::chain()
    ->addCommand('Pre-deployment Check', '
        echo "Checking system status..."
        systemctl is-active --quiet nginx && echo "Nginx is running"
        systemctl is-active --quiet mysql && echo "MySQL is running"
    ')
    ->addCommand('Backup Database', '
        echo "Creating database backup..."
        mysqldump -u root -p myapp > backup_$(date +%Y%m%d_%H%M%S).sql
    ')
    ->addCommand('Update Code', '
        echo "Updating application code..."
        cd /var/www/myapp
        git pull origin main
        composer install --no-dev --optimize-autoloader
    ')
    ->addCommand('Clear Cache', '
        echo "Clearing application cache..."
        cd /var/www/myapp
        php artisan cache:clear
        php artisan config:clear
        php artisan route:clear
    ')
    ->addCommand('Run Migrations', '
        echo "Running database migrations..."
        cd /var/www/myapp
        php artisan migrate --force
    ')
    ->addCommand('Restart Services', '
        echo "Restarting services..."
        systemctl reload nginx
        systemctl reload php-fpm
    ')
    ->addCommand('Post-deployment Check', '
        echo "Performing post-deployment checks..."
        curl -f http://localhost/health || echo "Health check failed"
    ')
    ->withOptions([
        'stop_on_failure' => true,
        'timeout' => 600, // 10 minutes
        'progress_tracking' => true
    ]);

$results = $chain->run();
echo "Deployment " . ($results['overall_success'] ? 'SUCCESSFUL' : 'FAILED') . "\n";
```

### **Use Cases:**
- **Deployment Workflows**: Multi-step deployment processes
- **System Maintenance**: Sequential maintenance tasks
- **Data Processing**: Multi-stage data transformation
- **Backup Procedures**: Complex backup workflows
- **Testing Pipelines**: Automated testing sequences
- **Monitoring Checks**: Sequential health checks
- **Build Processes**: Multi-step build workflows
- **Database Migrations**: Complex migration sequences

---

## 🎯 **Task Event System**

### **Event-Driven Architecture:**
- **TaskStarted**: Dispatched when a task begins execution
- **TaskCompleted**: Dispatched when a task finishes successfully
- **TaskFailed**: Dispatched when a task encounters an error
- **TaskProgress**: Dispatched when task progress is updated
- **Full Integration**: Events work with all task types and features

### **Event Types:**
```php
// Task Started Event
TaskStarted::class
- task: Task instance
- pendingTask: PendingTask instance
- startedAt: ISO timestamp
- context: Additional data

// Task Completed Event
TaskCompleted::class
- task: Task instance
- pendingTask: PendingTask instance
- output: ProcessOutput instance
- startedAt: Start timestamp
- completedAt: Completion timestamp
- duration: Execution time in seconds
- context: Additional data

// Task Failed Event
TaskFailed::class
- task: Task instance
- pendingTask: PendingTask instance
- output: ProcessOutput instance (nullable)
- exception: Exception instance (nullable)
- startedAt: Start timestamp
- failedAt: Failure timestamp
- duration: Execution time in seconds
- reason: Failure reason
- context: Additional data

// Task Progress Event
TaskProgress::class
- task: Task instance
- pendingTask: PendingTask instance
- currentStep: Current step number
- totalSteps: Total number of steps
- stepName: Current step name
- percentage: Progress percentage
- timestamp: Progress timestamp
- context: Additional data
```

### **Event Listeners:**
```php
// Register event listeners
Event::listen(TaskStarted::class, function (TaskStarted $event) {
    Log::info('Task started', ['name' => $event->getTaskName()]);
});

Event::listen(TaskCompleted::class, function (TaskCompleted $event) {
    if ($event->wasSuccessful()) {
        Log::info('Task completed successfully', [
            'name' => $event->getTaskName(),
            'duration' => $event->getDurationForHumans()
        ]);
    }
});

Event::listen(TaskFailed::class, function (TaskFailed $event) {
    Log::error('Task failed', [
        'name' => $event->getTaskName(),
        'reason' => $event->getReason()
    ]);
});

Event::listen(TaskProgress::class, function (TaskProgress $event) {
    Log::debug('Task progress', [
        'name' => $event->getTaskName(),
        'percentage' => $event->getPercentageInt(),
        'step' => $event->getStepName()
    ]);
});
```

### **Event Features:**
- **Performance Metrics**: Duration, output size, success rate
- **Progress Tracking**: Step-by-step progress with visual indicators
- **Failure Analysis**: Detailed failure reasons and exception information
- **Context Data**: Additional metadata and custom data
- **Real-time Updates**: Live progress updates during task execution
- **Monitoring Integration**: Easy integration with monitoring systems
- **Analytics Support**: Built-in support for task analytics and metrics
- **Notification System**: Automatic notifications for important events
- **Retry Logic**: Automatic retry scheduling for failed tasks
- **Incident Management**: Integration with incident management systems

### **Use Cases:**
- **Monitoring & Alerting**: Track task performance and failures
- **Analytics & Metrics**: Collect task execution statistics
- **Real-time Dashboards**: Display live task progress
- **Notification Systems**: Alert users about task status
- **Audit Logging**: Maintain detailed task execution logs
- **Performance Optimization**: Identify slow or problematic tasks
- **Incident Response**: Automatic incident creation for failures
- **Retry Management**: Intelligent retry logic for transient failures

---

## 🚀 **Multi-Server Dispatch**

### **Parallel Execution:**
- **Multiple Servers**: Dispatch tasks to multiple servers simultaneously
- **Parallel vs Sequential**: Choose between parallel or sequential execution
- **Connection Management**: Support for multiple remote connections
- **Result Aggregation**: Collect and analyze results from all servers
- **Failure Handling**: Configurable failure handling strategies

### **Multi-Server Features:**
```php
// Basic multi-server dispatch
$connections = ['server1', 'server2', 'server3'];
$task = AnonymousTask::command('System Check', 'uname -a');

$results = TaskRunner::dispatchToMultipleServers($task, $connections);

// Parallel execution (default)
$results = TaskRunner::dispatchToMultipleServers($task, $connections, [
    'parallel' => true
]);

// Sequential execution
$results = TaskRunner::dispatchToMultipleServers($task, $connections, [
    'parallel' => false
]);

// With timeout
$results = TaskRunner::dispatchToMultipleServers($task, $connections, [
    'timeout' => 60
]);

// Stop on first failure
$results = TaskRunner::dispatchToMultipleServers($task, $connections, [
    'stop_on_failure' => true
]);

// Minimum success requirement
$results = TaskRunner::dispatchToMultipleServers($task, $connections, [
    'min_success' => 2 // At least 2 servers must succeed
]);

// Maximum failures allowed
$results = TaskRunner::dispatchToMultipleServers($task, $connections, [
    'max_failures' => 1 // Allow only 1 server to fail
]);
```

### **Execution Options:**
- **parallel**: Execute tasks in parallel (default: true)
- **timeout**: Set timeout for each server (default: null)
- **stop_on_failure**: Stop execution on first failure (default: false)
- **wait_for_all**: Wait for all servers to complete (default: true)
- **min_success**: Minimum number of successful servers required
- **max_failures**: Maximum number of failed servers allowed

### **Result Analysis:**
```php
$results = TaskRunner::dispatchToMultipleServers($task, $connections);

// Summary information
echo "Task ID: {$results['multi_server_task_id']}\n";
echo "Total servers: {$results['total_servers']}\n";
echo "Successful: {$results['successful_servers']}\n";
echo "Failed: {$results['failed_servers']}\n";
echo "Success rate: {$results['success_rate']}%\n";
echo "Duration: {$results['duration']}s\n";
echo "Overall success: " . ($results['overall_success'] ? 'Yes' : 'No') . "\n";

// Individual server results
foreach ($results['results'] as $connection => $result) {
    if ($result['success']) {
        echo "✅ {$connection}: Exit code {$result['exit_code']}\n";
        echo "   Output: {$result['output']}\n";
    } else {
        echo "❌ {$connection}: {$result['error']}\n";
    }
}

// Aggregated output
$aggregatedOutput = $multiServerDispatcher->getAggregatedOutput();
$aggregatedErrors = $multiServerDispatcher->getAggregatedErrors();
```

### **Multi-Server Events:**
```php
// Multi-server task started
Event::listen(MultiServerTaskStarted::class, function (MultiServerTaskStarted $event) {
    echo "🚀 Multi-server task started: {$event->getTaskName()}\n";
    echo "   Servers: {$event->getServerCount()}\n";
    echo "   Parallel: " . ($event->isParallel() ? 'Yes' : 'No') . "\n";
});

// Multi-server task completed
Event::listen(MultiServerTaskCompleted::class, function (MultiServerTaskCompleted $event) {
    echo "✅ Multi-server task completed: {$event->getTaskName()}\n";
    echo "   Success rate: {$event->getSuccessRate()}%\n";
    echo "   Duration: {$event->getDurationForHumans()}\n";
});

// Multi-server task failed
Event::listen(MultiServerTaskFailed::class, function (MultiServerTaskFailed $event) {
    echo "❌ Multi-server task failed: {$event->getTaskName()}\n";
    echo "   Success rate: {$event->getSuccessRate()}%\n";
    echo "   Error: {$event->getErrorMessage()}\n";
});
```

### **Use Cases:**
- **Load Balancing**: Distribute tasks across multiple servers
- **High Availability**: Ensure tasks run even if some servers fail
- **Performance Scaling**: Execute tasks in parallel for faster completion
- **Disaster Recovery**: Run critical tasks on multiple servers
- **Monitoring**: Check system health across multiple servers
- **Deployment**: Deploy applications to multiple servers simultaneously
- **Backup**: Create backups on multiple servers for redundancy
- **Maintenance**: Perform maintenance tasks across server clusters

---

## 🔮 **Future Enhancements**

- Database persistence for task history
- Advanced analytics and reporting
- Mobile app support
- Integration with external monitoring tools
- Advanced scheduling and automation
- Multi-tenant support
- API endpoints for external integrations 