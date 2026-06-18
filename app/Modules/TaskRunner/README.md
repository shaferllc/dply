# TaskRunner Module

**Status:** ✅ **Production Ready** | **Compliance:** 100% | **Version:** 3.0  
**Last Updated:** October 23, 2025

---

## Overview

The **TaskRunner Module** is an enterprise-grade task execution engine providing comprehensive background job processing, remote command execution, task orchestration, monitoring, analytics, and lifecycle management.

**Mission:** Enable reliable, scalable, and monitored execution of tasks across local and remote infrastructure.

---

## Quick Stats

- 📁 **224 PHP Files** (largest module in application)
- ✅ **300+ Tests** across 88+ test files
- 🎯 **9 Services** (4,500+ lines)
- 🔄 **15+ Events** (complete event system)
- ⚙️ **7 Background Jobs** (queue integration)
- 📊 **2 Livewire Dashboards** (monitoring + analytics)
- 📚 **25 Documentation Files** (14 MD + 11 HTML)

---

## Features

### 1. Task Execution

**Execution Modes:**
- **Synchronous** - Immediate execution with real-time output
- **Background (Queued)** - Laravel queue-based execution
- **Remote (SSH)** - Execute on remote servers via SSH
- **Parallel** - Concurrent task execution
- **Chained** - Sequential task dependencies

**Capabilities:**
- Timeout management
- User context preservation
- Output capture
- Exit code tracking
- Progress monitoring

### 2. Task Chains

Sequential task execution with dependencies:
- Add tasks to chain
- Stop on failure (configurable)
- Chain progress tracking
- Rollback on failure
- Event broadcasting

```php
use App\Modules\TaskRunner\TaskChain;

$chain = new TaskChain();
$chain->add($task1)
      ->add($task2)
      ->dontStopOnFailure()
      ->dispatch();
```

### 3. Parallel Execution

Execute multiple tasks concurrently:
- Independent task execution
- Progress aggregation
- Individual failure handling
- Resource optimization

### 4. Multi-Server Execution

Orchestrate tasks across multiple servers:
- Load balancing
- Fallback strategies
- Server health checks
- Distributed execution

### 5. Monitoring & Analytics

**Monitoring:**
- Real-time task status
- Health checks
- Alert processing
- Performance tracking

**Analytics:**
- Execution metrics
- Success rates
- Duration analysis
- Trend identification

### 6. Templates

Reusable task templates:
- Variable substitution
- Template library
- Quick task creation
- Pre-configured tasks

```php
$service->executeWithTemplate('deploy', [
    'branch' => 'main',
    'environment' => 'production',
]);
```

### 7. Rollback Support

Transaction-style rollback for failed tasks:
- Register rollback scripts
- Automatic rollback on failure
- State recovery
- Cleanup operations

```php
$service->executeWithRollback(
    'Deploy App',
    'deploy.sh',
    'rollback.sh'
);
```

### 8. Callbacks

HTTP callback delivery:
- OnStart, OnComplete, OnFail callbacks
- Retry logic with exponential backoff
- Webhook integration
- Custom headers and authentication

### 9. Scheduling

Task scheduling and planning:
- Calendar visualization
- Recurring task setup (cron)
- Conflict detection
- Upcoming task preview

---

## Usage Examples

### Basic Task Execution

```php
use App\Modules\TaskRunner\Services\TaskRunnerService;

$service = app(TaskRunnerService::class);

// Execute task synchronously
$result = $service->createAndExecute(
    'Backup Database',
    'mysqldump -u root database > backup.sql'
);

// Execute in background
$result = $service->createAndExecute(
    'Long Running Task',
    'php artisan process:data',
    ['background' => true]
);
```

### Task with Monitoring

```php
// Get task status
$status = $service->getTaskStatus($taskId);

// Monitor execution
if ($status['found']) {
    $progress = $status['progress']; // 0-100
    $analytics = $status['analytics'];
}
```

### Task Chains

```php
$tasks = [
    ['script' => 'git pull'],
    ['script' => 'composer install'],
    ['script' => 'php artisan migrate'],
];

$result = $service->createTaskChain($tasks);
```

### Parallel Execution

```php
$tasks = [
    ['name' => 'Task 1', 'script' => 'process-batch-1.sh'],
    ['name' => 'Task 2', 'script' => 'process-batch-2.sh'],
    ['name' => 'Task 3', 'script' => 'process-batch-3.sh'],
];

$result = $service->executeParallel($tasks);
```

### Template-Based Execution

```php
// Execute with template
$result = $service->executeWithTemplate('deploy-app', [
    'branch' => 'main',
    'environment' => 'production',
    'migrate' => true,
]);

// Create task from template
$task = $service->createFromTemplate('backup', [
    'database' => 'production',
    'path' => '/backups/daily',
]);
```

### Rollback Support

```php
$result = $service->executeWithRollback(
    'Database Migration',
    'php artisan migrate',
    'php artisan migrate:rollback'
);

// If migration fails, rollback automatically
if (!$result['success']) {
    $service->performRollback($result['task_id']);
}
```

### Analytics & Reporting

```php
// Get analytics summary
$summary = $service->getAnalyticsSummary([
    'created_after' => now()->subDays(30),
]);

// Get performance insights
$insights = $service->getPerformanceInsights();

// Export report
$csv = $service->exportReport([], 'csv');
$json = $service->exportReport([], 'json');
```

### Bulk Operations

```php
// Cancel multiple tasks
$result = $service->bulkOperation($taskIds, 'cancel');

// Retry failed tasks
$result = $service->bulkOperation($failedTaskIds, 'retry');

// Delete old tasks
$result = $service->bulkOperation($oldTaskIds, 'delete');
```

### Scheduling

```php
// Get calendar view
$calendar = $service->getSchedulingCalendar('2025-10');

// Schedule recurring task
$result = $service->scheduleRecurring(
    'Daily Backup',
    'backup.sh',
    '0 2 * * *' // 2 AM daily
);

// Check for conflicts
$conflicts = $service->getScheduleConflicts('2025-10');
```

---

## API Reference

### TaskRunnerService (Master Orchestrator)

#### Execution Methods

**`createAndExecute(string $name, string $script, array $options): array`**
- Create and execute task with monitoring
- Options: background, timeout, user, server_id, callback_url

**`createTaskChain(array $tasks, array $options): array`**
- Create sequential task chain
- Options: stop_on_failure

**`executeParallel(array $tasks, array $options): array`**
- Execute tasks concurrently
- Returns: success, total, successful, failed, results

**`executeWithTemplate(string $templateName, array $variables, array $options): array`**
- Execute task from template

**`executeWithRollback(string $name, string $script, string $rollbackScript, array $options): array`**
- Execute with rollback support

#### Management Methods

**`getTaskStatus(string $taskId): array`**
- Get task status with analytics and monitoring

**`retryTask(string $taskId, array $options): array`**
- Retry failed task

**`cancelTask(string $taskId): array`**
- Cancel running task

**`performRollback(string $taskId): array`**
- Execute rollback for task

**`bulkOperation(array $taskIds, string $operation): array`**
- Bulk operations: cancel, retry, delete

**`cleanupOldTasks(int $olderThanDays): array`**
- Delete old completed tasks

#### Analytics Methods

**`getAnalyticsSummary(array $filters): array`**
- Analytics summary with metrics

**`getPerformanceInsights(array $filters): array`**
- Performance insights and recommendations

**`getMonitoringDashboard(array $filters): array`**
- Complete monitoring dashboard

**`getQuickStats(): array`**
- Quick task statistics

#### Export Methods

**`exportReport(array $filters, string $format): array|string`**
- Export report (array, json, csv)

**`getSchedulingCalendar(string $month): array`**
- Calendar view for month

---

## Configuration

No dedicated config file - behavior controlled by:
1. Task model options (timeout, user, etc.)
2. Service-specific configuration
3. Queue configuration
4. SSH key management

---

## Integration Points

### Servers Module ⭐⭐⭐

**Remote execution:**
- SSH connection management
- Remote script execution
- File upload/download
- Server status checking

### Teams Module ⭐⭐

**Team scoping:**
- Team-owned tasks
- Permission checks
- Team-based quotas

### Usage Module ⭐

**Quota tracking:**
- Count task executions
- Enforce plan limits

### Queue System ⭐⭐⭐

**Background processing:**
- Laravel queue integration
- Redis-backed jobs
- Job retry logic

---

## Testing

**Test Files:**
- 88+ test files across Unit, Feature, Integration
- 30 new tests for TaskRunnerService and TaskSchedulingService

**Run Tests:**
```bash
php artisan test app/Modules/TaskRunner/Tests/
```

---

## Where Used

### UI
- Task monitoring dashboard
- Task metrics dashboard
- Calendar view

### API
- `/api/v1/tasks/*` - Task CRUD and execution

### CLI
- `artisan task:run {name}` - Execute task
- `artisan task:list` - List tasks
- `artisan task:show {id}` - Task details

### Other Modules
- Servers: Remote command execution
- Deploy: Deployment automation
- Backup: Scheduled backups

---

## Troubleshooting

### Issue: Task Timeouts

**Solution:** Increase timeout in options
```php
['timeout' => 600] // 10 minutes
```

### Issue: Background Tasks Not Running

**Solution:** Ensure queue worker is running
```bash
php artisan queue:work
```

### Issue: Remote Execution Fails

**Solution:** Verify SSH key and server connection
- Check server credentials
- Test SSH connection manually
- Review server logs

---

## Compliance: 100%

See [MODULE_COMPLIANCE.md](./MODULE_COMPLIANCE.md) for detailed compliance breakdown.

---

**Maintained by:** Core Team  
**Contact:** [Support](mailto:support@example.com)
