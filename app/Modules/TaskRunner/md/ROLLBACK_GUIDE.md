# TaskRunner Rollback & Recovery System Guide

The TaskRunner module now includes a comprehensive rollback and recovery system to ensure production safety. This guide covers all aspects of the rollback functionality.

## Overview

The rollback and recovery system provides automatic rollback mechanisms, safety checks, and recovery procedures to protect production environments from failed tasks. It includes checkpoint creation, dependency management, and multiple recovery options.

## Core Components

### 1. HasRollback Contract
The `HasRollback` contract defines the interface for tasks that support rollback functionality:

```php
interface HasRollback
{
    public function supportsRollback(): bool;
    public function isRollbackRequired(): bool;
    public function getRollbackScript(): string;
    public function getRollbackTimeout(): int;
    public function getRollbackDependencies(): array;
    public function getRollbackSafetyChecks(): array;
    public function getRollbackData(): array;
    public function validateRollback(): bool;
    public function createRollbackCheckpoint(): bool;
    public function executeRollback(string $reason = null): bool;
    public function getRollbackHistory(): array;
    public function isRecoveryPossible(): bool;
    public function getRecoveryOptions(): array;
    public function executeRecovery(string $recoveryType): bool;
}
```

### 2. HandlesRollback Trait
The `HandlesRollback` trait provides comprehensive rollback functionality:

```php
trait HandlesRollback
{
    // Rollback support
    public function supportsRollback(): bool;
    public function isRollbackRequired(): bool;
    public function getRollbackScript(): string;
    public function getRollbackTimeout(): int;
    public function getRollbackDependencies(): array;
    public function getRollbackSafetyChecks(): array;
    public function getRollbackData(): array;
    
    // Rollback execution
    public function validateRollback(): bool;
    public function createRollbackCheckpoint(): bool;
    public function executeRollback(string $reason = null): bool;
    
    // Recovery
    public function isRecoveryPossible(): bool;
    public function getRecoveryOptions(): array;
    public function executeRecovery(string $recoveryType): bool;
    
    // Configuration
    public function setRollbackConfig(array $config): self;
    public function enableRollback(): self;
    public function disableRollback(): self;
    public function setRollbackScript(string $script): self;
    public function addRollbackDependency(string $dependency): self;
    public function addSafetyCheck(string $check): self;
    
    // Background execution
    public function scheduleRollback(string $reason = null): void;
}
```

### 3. RollbackService
The `RollbackService` handles rollback and recovery operations:

```php
class RollbackService
{
    public function execute(HasRollback $task, string $reason = null): bool;
    public function recover(HasRollback $task, string $recoveryType): bool;
    protected function executeRollbackScript(HasRollback $task): bool;
    protected function restoreFromCheckpoint(HasRollback $task): bool;
    protected function partialRollback(HasRollback $task): bool;
    protected function manualRecovery(HasRollback $task): bool;
    protected function systemRestore(HasRollback $task): bool;
}
```

### 4. RollbackException
Custom exception for rollback-related errors:

```php
class RollbackException extends Exception
{
    public static function validationFailed(string $taskId, array $validationErrors): self;
    public static function executionFailed(string $taskId, string $reason, string $error): self;
    public static function dependencyFailed(string $taskId, array $dependencies): self;
    public static function safetyCheckFailed(string $taskId, string $check, string $reason): self;
}
```

### 5. ExecuteRollbackJob
Background job for rollback execution:

```php
class ExecuteRollbackJob implements ShouldQueue
{
    public function handle(RollbackService $rollbackService): void;
    public function failed(\Throwable $exception): void;
}
```

## Usage Examples

### Basic Task with Rollback

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class SafeTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        // Configure rollback
        $this->setRollbackScript($this->getRollbackScript());
        $this->setRollbackConfiguration([
            'enabled' => true,
            'timeout' => 300,
            'dependencies' => [],
            'safety_checks' => ['check_database_health'],
        ]);
    }

    public function render(): string
    {
        // Create checkpoint before execution
        $this->createCheckpoint();
        
        $script = "echo 'Starting safe task...'";
        $script .= "\necho 'Performing critical operation...'";
        
        // Add error handling
        $script .= "\nif [ \$? -ne 0 ]; then";
        $script .= "\n  echo 'Task failed - rollback required'";
        $script .= "\n  exit 1";
        $script .= "\nfi";
        
        $script .= "\necho 'Task completed successfully'";
        
        return $script;
    }

    private function getRollbackScript(): string
    {
        return "echo 'Rolling back changes...'";
    }
}
```

### Advanced Task with Dependencies

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class DatabaseMigrationTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        $this->setRollbackScript($this->getRollbackScript());
        $this->setRollbackConfiguration([
            'enabled' => true,
            'timeout' => 600,
            'dependencies' => ['backup_database', 'stop_application'],
            'safety_checks' => [
                'check_database_health',
                'verify_backup_integrity',
                'confirm_maintenance_window',
            ],
        ]);
    }

    public function render(): string
    {
        $this->createCheckpoint();
        
        $script = "echo 'Starting database migration...'";
        $script .= "\n# Backup database";
        $script .= "\nmysqldump -u root -p database > backup.sql";
        $script .= "\n# Stop application";
        $script .= "\nsystemctl stop myapp";
        $script .= "\n# Run migration";
        $script .= "\nphp artisan migrate";
        $script .= "\n# Start application";
        $script .= "\nsystemctl start myapp";
        
        return $script;
    }

    private function getRollbackScript(): string
    {
        return "echo 'Rolling back database migration...'";
    }

    protected function checkDatabaseHealth(): bool
    {
        // Check database connectivity and health
        return true;
    }

    protected function verifyBackupIntegrity(): bool
    {
        // Verify backup file integrity
        return true;
    }

    protected function confirmMaintenanceWindow(): bool
    {
        // Confirm we're in maintenance window
        return true;
    }
}
```

### Task with Automatic Rollback

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class AutoRollbackTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        $this->setRollbackScript($this->getRollbackScript());
        $this->enableRollback();
    }

    public function render(): string
    {
        $this->createCheckpoint();
        
        $script = "echo 'Starting auto-rollback task...'";
        $script .= "\n# Perform operation";
        $script .= "\necho 'Performing operation...'";
        $script .= "\n# Check for errors";
        $script .= "\nif [ \$? -ne 0 ]; then";
        $script .= "\n  echo 'Operation failed - triggering rollback'";
        $script .= "\n  exit 1";
        $script .= "\nfi";
        
        return $script;
    }

    private function getRollbackScript(): string
    {
        return "echo 'Auto-rollback: Restoring previous state...'";
    }

    protected function hasCriticalErrors(): bool
    {
        // Custom critical error detection
        if (!$this->task) {
            return false;
        }

        $output = strtolower($this->task->output ?? '');
        $criticalPatterns = [
            'database connection failed',
            'disk space full',
            'permission denied',
        ];

        foreach ($criticalPatterns as $pattern) {
            if (str_contains($output, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
```

## Rollback Configuration

### Task-Level Configuration

```php
$task->setRollbackConfiguration([
    'enabled' => true,                    // Enable rollback
    'timeout' => 300,                     // Rollback timeout in seconds
    'dependencies' => [                   // Tasks that must be rolled back first
        'backup_database',
        'stop_services',
    ],
    'safety_checks' => [                  // Safety checks to run before rollback
        'check_system_health',
        'verify_backup_integrity',
        'confirm_rollback_safety',
    ],
    'data' => [                           // Additional rollback data
        'backup_location' => '/backups',
        'restore_point' => 'before_task',
    ],
    'script' => 'echo "Rollback script"', // Rollback script
]);
```

### Global Configuration

Add to `config/task-runner.php`:

```php
return [
    'rollback' => [
        'enabled' => true,
        'default_timeout' => 300,
        'default_max_attempts' => 3,
        'checkpoint_storage' => 'local',
        'checkpoint_path' => 'task-runner/checkpoints',
        'recovery_path' => 'task-runner/recovery',
        'queue' => 'rollbacks',
        'log_level' => 'info',
        'auto_rollback_on_failure' => true,
        'safety_checks' => [
            'check_system_health',
            'verify_backup_integrity',
            'confirm_rollback_safety',
        ],
    ],
];
```

## Safety Checks

### Built-in Safety Checks

1. **check_system_health** - Basic system health verification
2. **verify_backup_integrity** - Backup data integrity check
3. **confirm_rollback_safety** - Confirm rollback is safe to proceed

### Custom Safety Checks

```php
class CustomTask extends BaseTask
{
    protected function runCustomSafetyCheck(string $check): bool
    {
        return match ($check) {
            'check_database_health' => $this->checkDatabaseHealth(),
            'verify_backup_integrity' => $this->verifyBackupIntegrity(),
            'confirm_maintenance_window' => $this->confirmMaintenanceWindow(),
            default => parent::runCustomSafetyCheck($check),
        };
    }

    protected function checkDatabaseHealth(): bool
    {
        // Check database connectivity
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function verifyBackupIntegrity(): bool
    {
        // Verify backup file exists and is valid
        $backupFile = storage_path('backups/latest.sql');
        return file_exists($backupFile) && filesize($backupFile) > 0;
    }

    protected function confirmMaintenanceWindow(): bool
    {
        // Check if we're in maintenance window
        $now = now();
        $maintenanceStart = now()->setTime(2, 0); // 2 AM
        $maintenanceEnd = now()->setTime(4, 0);   // 4 AM
        
        return $now->between($maintenanceStart, $maintenanceEnd);
    }
}
```

## Recovery Options

### Available Recovery Types

1. **restore_from_checkpoint** - Restore from last checkpoint
2. **partial_rollback** - Partial rollback to safe state
3. **manual_recovery** - Manual recovery procedure
4. **system_restore** - System-level restore

### Recovery Execution

```php
// Check if recovery is possible
if ($task->isRecoveryPossible()) {
    $options = $task->getRecoveryOptions();
    
    // Execute recovery
    $success = $task->executeRecovery('restore_from_checkpoint');
    
    if ($success) {
        echo "Recovery completed successfully";
    } else {
        echo "Recovery failed";
    }
}
```

## Checkpoint System

### Creating Checkpoints

```php
// Create checkpoint before task execution
$task->createCheckpoint();

// Checkpoint includes:
// - Task state
// - Backup data
// - Timestamp
// - Task metadata
```

### Checkpoint Data Structure

```json
{
    "task_id": "task-uuid",
    "timestamp": "2024-01-01T12:00:00Z",
    "state": {
        "task_status": "running",
        "task_output": "Task output...",
        "task_exit_code": null,
        "timestamp": "2024-01-01T12:00:00Z"
    },
    "backup_data": {
        "files_backed_up": ["file1.txt", "file2.txt"],
        "database_backup": "backup.sql",
        "config_backup": "config.json"
    }
}
```

## Rollback Scripts

### Basic Rollback Script

```bash
#!/bin/bash
echo "Starting rollback procedure..."

# Restore files
if [ -f "/backups/files.tar.gz" ]; then
    tar -xzf /backups/files.tar.gz -C /
fi

# Restore database
if [ -f "/backups/database.sql" ]; then
    mysql -u root -p database < /backups/database.sql
fi

# Restart services
systemctl restart myapp

echo "Rollback completed"
```

### Advanced Rollback Script

```bash
#!/bin/bash
set -e

echo "Starting advanced rollback procedure..."

# Check if rollback is safe
if [ ! -f "/backups/rollback_safe" ]; then
    echo "ERROR: Rollback safety check failed"
    exit 1
fi

# Stop application
systemctl stop myapp

# Restore from backup
if [ -f "/backups/full_backup.tar.gz" ]; then
    echo "Restoring from full backup..."
    tar -xzf /backups/full_backup.tar.gz -C /
else
    echo "ERROR: Full backup not found"
    exit 1
fi

# Verify restoration
if [ ! -f "/var/www/myapp/index.php" ]; then
    echo "ERROR: Application files not restored"
    exit 1
fi

# Start application
systemctl start myapp

# Verify application health
if ! curl -f http://localhost/health; then
    echo "ERROR: Application health check failed"
    exit 1
fi

echo "Advanced rollback completed successfully"
```

## Error Handling

### Automatic Rollback on Failure

```php
class AutoRollbackTask extends BaseTask
{
    public function render(): string
    {
        $this->createCheckpoint();
        
        $script = "echo 'Starting task...'";
        $script .= "\n# Perform operation";
        $script .= "\necho 'Performing operation...'";
        
        // If operation fails, rollback will be triggered automatically
        $script .= "\nif [ \$? -ne 0 ]; then";
        $script .= "\n  echo 'Operation failed'";
        $script .= "\n  exit 1";
        $script .= "\nfi";
        
        return $script;
    }

    protected function hasCriticalErrors(): bool
    {
        // Custom error detection logic
        return parent::hasCriticalErrors();
    }
}
```

### Manual Rollback Trigger

```php
// Check if rollback is required
if ($task->isRollbackRequired()) {
    // Execute rollback immediately
    $success = $task->executeRollback('Manual trigger');
    
    // Or schedule for background execution
    $task->scheduleRollback('Scheduled rollback');
}
```

## Monitoring and Logging

### Log Levels

- **Info**: Successful rollbacks and checkpoints
- **Warning**: Rollback validation failures
- **Error**: Rollback execution failures
- **Critical**: Permanent rollback failures

### Log Structure

```php
Log::info('Rollback completed successfully', [
    'task_id' => 'task-uuid',
    'task_name' => 'My Task',
    'reason' => 'Task failed',
    'timestamp' => '2024-01-01T12:00:00Z',
    'rollback_duration' => 45,
    'checkpoint_used' => 'checkpoint-123',
]);
```

### Rollback History

```php
$history = $task->getRollbackHistory();

// Returns array of rollback events:
[
    [
        'timestamp' => '2024-01-01T12:00:00Z',
        'type' => 'success',
        'reason' => 'Task failed',
    ],
    [
        'timestamp' => '2024-01-01T11:00:00Z',
        'type' => 'failure',
        'reason' => 'Validation failed',
        'error' => 'Safety check failed',
    ],
]
```

## Testing Rollback

### Test Rollback Script

```php
// Test rollback script execution
$task = new SafeTask();
$task->setRollbackScript('echo "Test rollback"');

$success = $task->executeRollback('Test rollback');
$this->assertTrue($success);
```

### Mock Rollback Service

```php
// In your test
$this->mock(RollbackService::class, function ($mock) {
    $mock->shouldReceive('execute')
        ->once()
        ->andReturn(true);
});

$task = new SafeTask();
$success = $task->executeRollback('Test');
$this->assertTrue($success);
```

## Best Practices

### 1. Always Create Checkpoints

```php
public function render(): string
{
    // Create checkpoint before any critical operations
    $this->createCheckpoint();
    
    // Your task logic here
    return "echo 'Task execution'";
}
```

### 2. Implement Comprehensive Safety Checks

```php
protected function runCustomSafetyCheck(string $check): bool
{
    return match ($check) {
        'check_database_health' => $this->checkDatabaseHealth(),
        'verify_backup_integrity' => $this->verifyBackupIntegrity(),
        'confirm_maintenance_window' => $this->confirmMaintenanceWindow(),
        'check_disk_space' => $this->checkDiskSpace(),
        'verify_network_connectivity' => $this->verifyNetworkConnectivity(),
        default => parent::runCustomSafetyCheck($check),
    };
}
```

### 3. Use Descriptive Rollback Scripts

```bash
#!/bin/bash
# Rollback script for database migration
# This script restores the database to its previous state

echo "Starting database rollback..."

# Stop application
systemctl stop myapp

# Restore database from backup
mysql -u root -p database < /backups/before_migration.sql

# Start application
systemctl start myapp

echo "Database rollback completed"
```

### 4. Monitor Rollback Performance

```php
// Track rollback performance
$startTime = microtime(true);
$success = $task->executeRollback('Performance test');
$duration = microtime(true) - $startTime;

Log::info('Rollback performance', [
    'task_id' => $task->getTask()?->id,
    'duration' => $duration,
    'success' => $success,
]);
```

### 5. Implement Graceful Degradation

```php
protected function executeRollback(string $reason = null): bool
{
    try {
        return parent::executeRollback($reason);
    } catch (RollbackException $e) {
        // Log the failure
        Log::error('Rollback failed', [
            'task_id' => $this->task?->id,
            'reason' => $reason,
            'error' => $e->getMessage(),
        ]);
        
        // Try alternative recovery method
        return $this->executeRecovery('manual_recovery');
    }
}
```

## Troubleshooting

### Common Issues

1. **Rollback Not Triggered**: Check if rollback is enabled and script is set
2. **Safety Check Failures**: Review safety check implementations
3. **Dependency Issues**: Ensure all dependencies are satisfied
4. **Timeout Errors**: Increase rollback timeout for complex operations
5. **Checkpoint Not Found**: Verify checkpoint creation and storage

### Debug Commands

```bash
# Check rollback configuration
php artisan task:rollback-info task-id

# Test rollback script
php artisan task:test-rollback task-id

# View rollback logs
tail -f storage/logs/laravel.log | grep "Rollback"

# Check rollback queue
php artisan queue:work rollbacks
```

This comprehensive rollback and recovery system provides production safety through automatic rollback mechanisms, safety checks, and multiple recovery options, ensuring that failed tasks can be safely reversed and systems can be restored to stable states. 