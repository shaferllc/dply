# TaskRunner Advanced Monitoring & Alerting Guide

The TaskRunner module now includes comprehensive advanced monitoring and alerting functionality to ensure production readiness. This guide covers all aspects of the monitoring system for real-time monitoring, intelligent alerting, and health checks.

## Overview

The advanced monitoring and alerting system provides production-ready monitoring capabilities with real-time health checks, intelligent alerting, performance monitoring, and comprehensive observability. It ensures system reliability and provides early warning capabilities for production environments.

## Core Components

### 1. HasMonitoring Contract
The `HasMonitoring` contract defines the interface for tasks that support advanced monitoring:

```php
interface HasMonitoring
{
    public function isMonitoringEnabled(): bool;
    public function getHealthStatus(): array;
    public function performHealthCheck(): bool;
    public function getMonitoringMetrics(): array;
    public function getAlertRules(): array;
    public function checkAlerts(): array;
    public function getMonitoringConfig(): array;
    public function getPerformanceThresholds(): array;
    public function getResourceLimits(): array;
    public function getMonitoringHistory(): array;
    public function recordMonitoringEvent(string $event, array $data = []): void;
    public function setMonitoringConfig(array $config): void;
    public function enableMonitoring(): void;
    public function disableMonitoring(): void;
    public function getMonitoringDashboard(): array;
    public function exportMonitoringData(string $format = 'json'): string;
    public function getMonitoringAlerts(): array;
    public function acknowledgeAlert(string $alertId): bool;
    public function getMonitoringStatus(): string;
}
```

### 2. HandlesMonitoring Trait
The `HandlesMonitoring` trait provides comprehensive monitoring functionality:

```php
trait HandlesMonitoring
{
    // Monitoring support
    public function isMonitoringEnabled(): bool;
    public function getHealthStatus(): array;
    public function performHealthCheck(): bool;
    public function getMonitoringMetrics(): array;
    
    // Alerting
    public function getAlertRules(): array;
    public function checkAlerts(): array;
    public function getMonitoringAlerts(): array;
    public function acknowledgeAlert(string $alertId): bool;
    
    // Configuration
    public function getMonitoringConfig(): array;
    public function setMonitoringConfig(array $config): void;
    public function enableMonitoring(): void;
    public function disableMonitoring(): void;
    
    // Health checks
    public function addHealthCheck(string $name, array $config): self;
    public function setPerformanceThreshold(string $metric, float $threshold): self;
    public function setResourceLimit(string $resource, float $limit): self;
    
    // Events and history
    public function recordMonitoringEvent(string $event, array $data = []): void;
    public function getMonitoringHistory(): array;
}
```

### 3. MonitoringService
The `MonitoringService` handles monitoring operations and alerting:

```php
class MonitoringService
{
    public function recordEvent(string $taskId, string $event, array $data = []): void;
    public function processAlert(array $alert, string $taskId = null): void;
    public function generateDashboardData(): array;
    public function performSystemHealthCheck(): array;
    public function getMonitoringStats(): array;
    public function sendNotification(array $alert, string $channel = 'email'): bool;
}
```

### 4. ProcessMonitoringAlertJob
Background job for alert processing:

```php
class ProcessMonitoringAlertJob implements ShouldQueue
{
    public function handle(MonitoringService $monitoringService): void;
    public function failed(\Throwable $exception): void;
}
```

## Usage Examples

### Basic Task with Monitoring

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class MonitoredTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        // Configure monitoring
        $this->setMonitoringConfiguration([
            'enabled' => true,
            'check_interval' => 60,
            'alert_cooldown' => 300,
            'enable_notifications' => true,
            'enable_auto_recovery' => true,
        ]);

        // Add custom alert rules
        $this->addAlertRule('custom_error', [
            'enabled' => true,
            'threshold' => 0.1,
            'severity' => 'warning',
            'description' => 'Custom error rate exceeded',
            'action' => 'notify_team',
        ]);

        // Set performance thresholds
        $this->setPerformanceThreshold('max_execution_time', 120);
        $this->setPerformanceThreshold('max_memory_usage', 0.8);

        // Set resource limits
        $this->setResourceLimit('memory_limit', 512 * 1024 * 1024); // 512MB
        $this->setResourceLimit('cpu_limit', 1.0);
    }

    public function render(): string
    {
        // Record monitoring events
        $this->recordMonitoringEvent('task_started', [
            'timestamp' => now()->toISOString(),
            'user_id' => auth()->id(),
        ]);

        $script = "echo 'Starting monitored task...'";
        
        // Add health checks
        $this->addHealthCheck('database_connectivity', [
            'description' => 'Check database connectivity',
            'enabled' => true,
        ]);

        $this->addHealthCheck('file_permissions', [
            'description' => 'Check file permissions',
            'enabled' => true,
        ]);

        // Record task completion
        $this->recordMonitoringEvent('task_completed', [
            'execution_time' => $this->getExecutionTime(),
            'memory_usage' => memory_get_usage(true),
        ]);

        return $script;
    }
}
```

### Advanced Task with Comprehensive Monitoring

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class ProductionTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        $this->setMonitoringConfiguration([
            'enabled' => true,
            'check_interval' => 30, // Check every 30 seconds
            'alert_cooldown' => 600, // 10 minutes cooldown
            'retention_days' => 90,
            'enable_notifications' => true,
            'enable_auto_recovery' => true,
            'enable_health_checks' => true,
            'enable_performance_monitoring' => true,
            'enable_resource_monitoring' => true,
        ]);

        // Critical alert rules
        $this->addAlertRule('critical_failure', [
            'enabled' => true,
            'threshold' => 1,
            'severity' => 'critical',
            'description' => 'Critical task failure detected',
            'action' => 'immediate_notification',
        ]);

        $this->addAlertRule('high_memory_usage', [
            'enabled' => true,
            'threshold' => 0.85,
            'severity' => 'critical',
            'description' => 'Memory usage critically high',
            'action' => 'emergency_restart',
        ]);

        // Warning alert rules
        $this->addAlertRule('slow_execution', [
            'enabled' => true,
            'threshold' => 60.0,
            'severity' => 'warning',
            'description' => 'Task execution time exceeded threshold',
            'action' => 'notify_team',
        ]);

        $this->addAlertRule('high_error_rate', [
            'enabled' => true,
            'threshold' => 0.05,
            'severity' => 'warning',
            'description' => 'Error rate exceeds acceptable threshold',
            'action' => 'notify_team',
        ]);

        // Performance thresholds
        $this->setPerformanceThreshold('max_execution_time', 300);
        $this->setPerformanceThreshold('max_memory_usage', 0.8);
        $this->setPerformanceThreshold('max_cpu_usage', 0.9);
        $this->setPerformanceThreshold('max_error_rate', 0.05);
        $this->setPerformanceThreshold('min_availability', 0.99);

        // Resource limits
        $this->setResourceLimit('memory_limit', 1024 * 1024 * 1024); // 1GB
        $this->setResourceLimit('cpu_limit', 2.0);
        $this->setResourceLimit('disk_limit', 5 * 1024 * 1024 * 1024); // 5GB
        $this->setResourceLimit('network_limit', 100 * 1024 * 1024); // 100MB

        // Health checks
        $this->addHealthCheck('database_connectivity', [
            'description' => 'Verify database connectivity',
            'enabled' => true,
        ]);

        $this->addHealthCheck('cache_connectivity', [
            'description' => 'Verify cache connectivity',
            'enabled' => true,
        ]);

        $this->addHealthCheck('queue_connectivity', [
            'description' => 'Verify queue connectivity',
            'enabled' => true,
        ]);

        $this->addHealthCheck('disk_space', [
            'description' => 'Check available disk space',
            'enabled' => true,
        ]);
    }

    public function render(): string
    {
        // Record task start
        $this->recordMonitoringEvent('production_task_started', [
            'environment' => config('app.env'),
            'user_id' => auth()->id(),
            'session_id' => session()->getId(),
        ]);

        $script = "echo 'Starting production task...'";
        
        // Perform health checks before execution
        if (!$this->performHealthCheck()) {
            $this->recordMonitoringEvent('health_check_failed', [
                'health_status' => $this->getHealthStatus(),
            ]);
            
            $script .= "\necho 'Health check failed - aborting task'";
            $script .= "\nexit 1";
        }

        // Monitor execution phases
        $this->recordMonitoringEvent('execution_phase_started', [
            'phase' => 'initialization',
        ]);

        $script .= "\necho 'Initializing task...'";
        $script .= "\nsleep 2";

        $this->recordMonitoringEvent('execution_phase_completed', [
            'phase' => 'initialization',
            'duration' => 2,
        ]);

        $this->recordMonitoringEvent('execution_phase_started', [
            'phase' => 'processing',
        ]);

        $script .= "\necho 'Processing data...'";
        $script .= "\nsleep 5";

        $this->recordMonitoringEvent('execution_phase_completed', [
            'phase' => 'processing',
            'duration' => 5,
        ]);

        // Check for alerts during execution
        $alerts = $this->checkAlerts();
        if (!empty($alerts)) {
            $this->recordMonitoringEvent('alerts_triggered', [
                'alerts' => $alerts,
            ]);
        }

        // Record task completion
        $this->recordMonitoringEvent('production_task_completed', [
            'execution_time' => $this->getExecutionTime(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'alerts_triggered' => count($alerts),
        ]);

        return $script;
    }

    protected function checkDatabaseConnectivity(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function checkCacheConnectivity(): bool
    {
        try {
            Cache::put('health_check', 'ok', 60);
            return Cache::get('health_check') === 'ok';
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function checkQueueConnectivity(): bool
    {
        // Implement queue connectivity check
        return true;
    }

    protected function checkDiskSpace(): bool
    {
        $freeSpace = disk_free_space(storage_path());
        $totalSpace = disk_total_space(storage_path());
        $usagePercentage = (($totalSpace - $freeSpace) / $totalSpace) * 100;
        
        return $usagePercentage < 90;
    }
}
```

### Task with Custom Health Checks

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class CustomHealthTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        $this->setMonitoringConfiguration([
            'enabled' => true,
            'check_interval' => 45,
        ]);

        // Add custom health checks
        $this->addHealthCheck('api_connectivity', [
            'description' => 'Check external API connectivity',
            'enabled' => true,
        ]);

        $this->addHealthCheck('file_system', [
            'description' => 'Check file system permissions',
            'enabled' => true,
        ]);

        $this->addHealthCheck('custom_business_logic', [
            'description' => 'Check business logic integrity',
            'enabled' => true,
        ]);
    }

    public function render(): string
    {
        $this->recordMonitoringEvent('custom_health_task_started');
        
        $script = "echo 'Starting custom health task...'";
        
        // Perform custom health checks
        if (!$this->performHealthCheck()) {
            $healthStatus = $this->getHealthStatus();
            $this->recordMonitoringEvent('custom_health_check_failed', [
                'health_status' => $healthStatus,
                'failed_checks' => $healthStatus['issues'],
            ]);
        }

        return $script;
    }

    protected function performCustomHealthCheck(string $checkName): bool
    {
        return match ($checkName) {
            'api_connectivity' => $this->checkApiConnectivity(),
            'file_system' => $this->checkFileSystem(),
            'custom_business_logic' => $this->checkBusinessLogic(),
            default => parent::performCustomHealthCheck($checkName),
        };
    }

    protected function checkApiConnectivity(): bool
    {
        try {
            $response = Http::timeout(5)->get('https://api.example.com/health');
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function checkFileSystem(): bool
    {
        $requiredPaths = [
            storage_path('logs'),
            storage_path('app'),
            storage_path('cache'),
        ];

        foreach ($requiredPaths as $path) {
            if (!is_dir($path) || !is_writable($path)) {
                return false;
            }
        }

        return true;
    }

    protected function checkBusinessLogic(): bool
    {
        // Implement custom business logic checks
        return true;
    }
}
```

## Monitoring Metrics

### Health Status

```php
$healthStatus = $task->getHealthStatus();

// Returns:
[
    'status' => 'healthy', // or 'unhealthy'
    'timestamp' => '2024-01-01T12:00:00Z',
    'issues' => [], // Array of failed health checks
    'checks' => [
        'task_status' => [
            'status' => 'pass',
            'timestamp' => '2024-01-01T12:00:00Z',
            'details' => 'Check if task is in a valid state',
        ],
        'resource_usage' => [
            'status' => 'pass',
            'timestamp' => '2024-01-01T12:00:00Z',
            'details' => 'Check if resource usage is within limits',
        ],
        // ... more checks
    ],
    'overall_health_score' => 0.95,
]
```

### Monitoring Metrics

```php
$metrics = $task->getMonitoringMetrics();

// Returns:
[
    'task_id' => 'task-uuid',
    'task_name' => 'My Task',
    'status' => 'running',
    'uptime' => 3600, // seconds
    'availability' => 0.99,
    'response_time' => 45.2,
    'error_rate' => 0.02,
    'throughput' => 0.022,
    'resource_usage' => [
        'memory' => 52428800,
        'cpu' => 0.75,
        'disk' => 1048576,
        'network' => 2048,
    ],
    'performance_score' => 0.85,
    'timestamp' => '2024-01-01T12:00:00Z',
]
```

### Alert Rules

```php
$alertRules = $task->getAlertRules();

// Returns:
[
    'high_error_rate' => [
        'enabled' => true,
        'threshold' => 0.05,
        'severity' => 'warning',
        'description' => 'Error rate exceeds threshold',
        'action' => 'notify_team',
    ],
    'high_response_time' => [
        'enabled' => true,
        'threshold' => 30.0,
        'severity' => 'warning',
        'description' => 'Response time exceeds threshold',
        'action' => 'notify_team',
    ],
    'high_memory_usage' => [
        'enabled' => true,
        'threshold' => 0.8,
        'severity' => 'critical',
        'description' => 'Memory usage exceeds threshold',
        'action' => 'restart_task',
    ],
    // ... more rules
]
```

## Alert Processing

### Alert Actions

```php
// Available alert actions:
[
    'notify_team' => 'Send notification to team',
    'immediate_notification' => 'Send immediate notification via multiple channels',
    'restart_task' => 'Restart the task automatically',
    'emergency_restart' => 'Perform emergency restart',
    'webhook_notification' => 'Send webhook notification',
]
```

### Alert Severity Levels

```php
// Severity levels:
[
    'info' => 'Informational alerts',
    'warning' => 'Warning alerts requiring attention',
    'critical' => 'Critical alerts requiring immediate action',
    'emergency' => 'Emergency alerts requiring immediate intervention',
]
```

## Configuration

### Task-Level Configuration

```php
$task->setMonitoringConfiguration([
    'enabled' => true,
    'check_interval' => 60, // seconds
    'alert_cooldown' => 300, // seconds
    'retention_days' => 30,
    'enable_notifications' => true,
    'enable_auto_recovery' => true,
    'enable_health_checks' => true,
    'enable_performance_monitoring' => true,
    'enable_resource_monitoring' => true,
]);
```

### Global Configuration

Add to `config/task-runner.php`:

```php
return [
    'monitoring' => [
        'enabled' => true,
        'default_check_interval' => 60,
        'default_alert_cooldown' => 300,
        'retention_days' => 30,
        'notifications' => [
            'email' => [
                'enabled' => true,
                'recipients' => ['admin@example.com'],
            ],
            'slack' => [
                'enabled' => false,
                'webhook_url' => env('SLACK_WEBHOOK_URL'),
                'channel' => '#alerts',
            ],
            'webhook' => [
                'enabled' => false,
                'url' => env('WEBHOOK_URL'),
            ],
        ],
        'health_checks' => [
            'database_connectivity' => true,
            'cache_connectivity' => true,
            'queue_connectivity' => true,
            'disk_space' => true,
            'memory_usage' => true,
            'cpu_usage' => true,
        ],
        'auto_recovery' => [
            'enabled' => true,
            'max_restart_attempts' => 3,
            'restart_delay' => 60,
        ],
    ],
];
```

## Dashboard Integration

### Generate Dashboard Data

```php
$monitoringService = app(MonitoringService::class);
$dashboardData = $monitoringService->generateDashboardData();

// Returns:
[
    'overview' => [
        'total_tasks' => 150,
        'healthy_tasks' => 142,
        'unhealthy_tasks' => 8,
        'active_alerts' => 3,
        'system_health' => 'healthy',
    ],
    'health_summary' => [
        'healthy_count' => 142,
        'unhealthy_count' => 8,
        'health_percentage' => 94.67,
    ],
    'alert_summary' => [
        'total_alerts' => 25,
        'active_alerts' => 3,
        'critical_alerts' => 1,
        'resolved_alerts' => 22,
    ],
    'performance_metrics' => [...],
    'resource_usage' => [...],
    'recent_events' => [...],
    'system_status' => [...],
]
```

### System Health Check

```php
$systemHealth = $monitoringService->performSystemHealthCheck();

// Returns:
[
    'status' => 'healthy',
    'checks' => [
        'database_connectivity' => [
            'healthy' => true,
            'response_time' => 0.1,
            'details' => 'Database connection successful',
        ],
        'cache_connectivity' => [
            'healthy' => true,
            'response_time' => 0.05,
            'details' => 'Cache connection successful',
        ],
        'disk_space' => [
            'healthy' => true,
            'usage_percentage' => 65.2,
            'free_space' => 107374182400,
            'total_space' => 1073741824000,
            'details' => 'Disk usage: 65.2%',
        ],
        // ... more checks
    ],
    'issues' => [],
    'timestamp' => '2024-01-01T12:00:00Z',
]
```

## Best Practices

### 1. Enable Monitoring for Production Tasks

```php
class ProductionTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        $this->enableMonitoring();
        $this->setMonitoringConfiguration([
            'enabled' => true,
            'check_interval' => 30,
            'enable_notifications' => true,
        ]);
    }
}
```

### 2. Set Appropriate Thresholds

```php
// Set realistic thresholds based on your system
$this->setPerformanceThreshold('max_execution_time', 300); // 5 minutes
$this->setPerformanceThreshold('max_memory_usage', 0.8); // 80%
$this->setPerformanceThreshold('max_error_rate', 0.05); // 5%
```

### 3. Implement Custom Health Checks

```php
protected function performCustomHealthCheck(string $checkName): bool
{
    return match ($checkName) {
        'database_connectivity' => $this->checkDatabaseConnectivity(),
        'api_connectivity' => $this->checkApiConnectivity(),
        'file_permissions' => $this->checkFilePermissions(),
        default => parent::performCustomHealthCheck($checkName),
    };
}
```

### 4. Record Meaningful Events

```php
// Record important events
$this->recordMonitoringEvent('task_started', [
    'user_id' => auth()->id(),
    'environment' => config('app.env'),
]);

$this->recordMonitoringEvent('critical_operation_completed', [
    'operation' => 'database_migration',
    'duration' => $duration,
    'records_affected' => $count,
]);
```

### 5. Monitor Resource Usage

```php
// Set resource limits
$this->setResourceLimit('memory_limit', 1024 * 1024 * 1024); // 1GB
$this->setResourceLimit('cpu_limit', 2.0);
$this->setResourceLimit('disk_limit', 5 * 1024 * 1024 * 1024); // 5GB
```

### 6. Configure Alert Actions

```php
// Configure appropriate alert actions
$this->addAlertRule('critical_failure', [
    'enabled' => true,
    'threshold' => 1,
    'severity' => 'critical',
    'description' => 'Critical task failure',
    'action' => 'immediate_notification',
]);

$this->addAlertRule('high_memory_usage', [
    'enabled' => true,
    'threshold' => 0.85,
    'severity' => 'critical',
    'description' => 'Memory usage critically high',
    'action' => 'emergency_restart',
]);
```

## Troubleshooting

### Common Issues

1. **High Alert Volume**: Adjust thresholds and cooldown periods
2. **False Positives**: Fine-tune alert rules and thresholds
3. **Health Check Failures**: Review health check implementations
4. **Notification Failures**: Check notification configuration

### Debug Commands

```bash
# Check monitoring status
php artisan task:monitoring-status task-id

# Perform health check
php artisan task:health-check task-id

# View monitoring dashboard
php artisan task:monitoring-dashboard

# Export monitoring data
php artisan task:export-monitoring task-id --format=json

# Check system health
php artisan task:system-health

# View active alerts
php artisan task:active-alerts
```

This comprehensive monitoring and alerting system provides production-ready monitoring capabilities with real-time health checks, intelligent alerting, and comprehensive observability to ensure system reliability and provide early warning capabilities. 