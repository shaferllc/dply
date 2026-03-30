<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Traits;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Jobs\ProcessMonitoringAlertJob;
use App\Modules\TaskRunner\Services\MonitoringService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HandlesMonitoring trait provides core monitoring and alerting functionality.
 * Focuses on health checks, alerting, and basic monitoring metrics.
 */
trait HandlesMonitoring
{
    /**
     * Monitoring configuration properties.
     */
    protected bool $monitoringEnabled = true;

    protected array $monitoringConfig = [];

    protected array $alertRules = [];

    protected array $performanceThresholds = [];

    protected array $resourceLimits = [];

    protected array $monitoringHistory = [];

    protected array $monitoringAlerts = [];

    protected array $healthChecks = [];

    /**
     * Check if monitoring is enabled for this task.
     */
    public function isMonitoringEnabled(): bool
    {
        return $this->monitoringEnabled;
    }

    /**
     * Get health status of this task.
     */
    public function getHealthStatus(): array
    {
        $status = 'healthy';
        $issues = [];
        $checks = [];

        // Perform all health checks
        foreach ($this->getHealthChecks() as $checkName => $checkConfig) {
            $checkResult = $this->performHealthCheck($checkName);
            $checks[$checkName] = [
                'status' => $checkResult ? 'pass' : 'fail',
                'timestamp' => now()->toISOString(),
                'details' => $checkConfig['description'] ?? '',
            ];

            if (! $checkResult) {
                $issues[] = $checkName;
                $status = 'unhealthy';
            }
        }

        // Check task-specific health
        if ($this->task) {
            $taskHealth = $this->checkTaskHealth();
            if (! $taskHealth['healthy']) {
                $status = 'unhealthy';
                $issues = array_merge($issues, $taskHealth['issues']);
            }
        }

        return [
            'status' => $status,
            'timestamp' => now()->toISOString(),
            'issues' => $issues,
            'checks' => $checks,
            'overall_health_score' => $this->calculateHealthScore($checks),
        ];
    }

    /**
     * Get monitoring metrics.
     */
    public function getMonitoringMetrics(): array
    {
        $metrics = [
            'task_id' => $this->task?->id,
            'task_name' => $this->task?->name,
            'status' => $this->task?->status?->value,
            'uptime' => $this->calculateUptime(),
            'availability' => $this->calculateAvailability(),
            'response_time' => $this->getResponseTime(),
            'error_rate' => $this->getErrorRate(),
            'throughput' => $this->getThroughput(),
            'resource_usage' => $this->getResourceUsage(),
            'performance_score' => $this->getPerformanceScore(),
            'timestamp' => now()->toISOString(),
        ];

        // Add custom metrics
        $metrics = array_merge($metrics, $this->getCustomMetrics());

        return $metrics;
    }

    /**
     * Get alert rules for this task.
     */
    public function getAlertRules(): array
    {
        return array_merge([
            'high_error_rate' => [
                'enabled' => true,
                'threshold' => 0.05, // 5% error rate
                'severity' => 'warning',
                'description' => 'Error rate exceeds threshold',
                'action' => 'notify_team',
            ],
            'high_response_time' => [
                'enabled' => true,
                'threshold' => 30.0, // 30 seconds
                'severity' => 'warning',
                'description' => 'Response time exceeds threshold',
                'action' => 'notify_team',
            ],
            'high_memory_usage' => [
                'enabled' => true,
                'threshold' => 0.8, // 80% memory usage
                'severity' => 'critical',
                'description' => 'Memory usage exceeds threshold',
                'action' => 'restart_task',
            ],
            'task_failure' => [
                'enabled' => true,
                'threshold' => 1, // Any failure
                'severity' => 'critical',
                'description' => 'Task has failed',
                'action' => 'immediate_notification',
            ],
            'resource_exhaustion' => [
                'enabled' => true,
                'threshold' => 0.9, // 90% resource usage
                'severity' => 'critical',
                'description' => 'Resource usage critically high',
                'action' => 'emergency_restart',
            ],
        ], $this->alertRules);
    }

    /**
     * Check if any alerts should be triggered.
     */
    public function checkAlerts(): array
    {
        $triggeredAlerts = [];
        $metrics = $this->getMonitoringMetrics();
        $rules = $this->getAlertRules();

        foreach ($rules as $ruleName => $rule) {
            if (! $rule['enabled']) {
                continue;
            }

            $shouldTrigger = $this->evaluateAlertRule($ruleName, $rule, $metrics);

            if ($shouldTrigger) {
                $alert = $this->createAlert($ruleName, $rule, $metrics);
                $triggeredAlerts[] = $alert;

                // Process the alert
                $this->processAlert($alert);
            }
        }

        return $triggeredAlerts;
    }

    /**
     * Get monitoring configuration.
     */
    public function getMonitoringConfig(): array
    {
        return array_merge([
            'enabled' => $this->monitoringEnabled,
            'check_interval' => 60, // seconds
            'alert_cooldown' => 300, // seconds
            'retention_days' => 30,
            'enable_notifications' => true,
            'enable_auto_recovery' => true,
            'enable_health_checks' => true,
            'enable_performance_monitoring' => true,
            'enable_resource_monitoring' => true,
        ], $this->monitoringConfig);
    }

    /**
     * Get performance thresholds.
     */
    public function getPerformanceThresholds(): array
    {
        return array_merge([
            'max_execution_time' => 300, // 5 minutes
            'max_memory_usage' => 0.8, // 80%
            'max_cpu_usage' => 0.9, // 90%
            'max_error_rate' => 0.05, // 5%
            'min_availability' => 0.99, // 99%
            'max_response_time' => 30.0, // 30 seconds
        ], $this->performanceThresholds);
    }

    /**
     * Get resource limits.
     */
    public function getResourceLimits(): array
    {
        return array_merge([
            'memory_limit' => 512 * 1024 * 1024, // 512MB
            'cpu_limit' => 1.0, // 1 CPU core
            'disk_limit' => 1024 * 1024 * 1024, // 1GB
            'network_limit' => 100 * 1024 * 1024, // 100MB
        ], $this->resourceLimits);
    }

    /**
     * Get monitoring history.
     */
    public function getMonitoringHistory(): array
    {
        return $this->monitoringHistory;
    }

    /**
     * Record monitoring event.
     */
    public function recordMonitoringEvent(string $event, array $data = []): void
    {
        $eventData = [
            'event' => $event,
            'task_id' => $this->task?->id,
            'timestamp' => now()->toISOString(),
            'data' => $data,
        ];

        $this->monitoringHistory[] = $eventData;

        // Store in monitoring service
        $monitoringService = app(MonitoringService::class);
        $monitoringService->recordEvent($this->task?->id, $event, $data);

        Log::info('Monitoring event recorded', $eventData);
    }

    /**
     * Set monitoring configuration.
     */
    public function setMonitoringConfig(array $config): void
    {
        $this->monitoringConfig = array_merge($this->monitoringConfig, $config);
    }

    /**
     * Enable monitoring for this task.
     */
    public function enableMonitoring(): void
    {
        $this->monitoringEnabled = true;
        $this->recordMonitoringEvent('monitoring_enabled');
    }

    /**
     * Disable monitoring for this task.
     */
    public function disableMonitoring(): void
    {
        $this->monitoringEnabled = false;
        $this->recordMonitoringEvent('monitoring_disabled');
    }

    /**
     * Get monitoring dashboard data.
     */
    public function getMonitoringDashboard(): array
    {
        return [
            'task_info' => [
                'id' => $this->task?->id,
                'name' => $this->task?->name,
                'status' => $this->task?->status?->value,
            ],
            'health_status' => $this->getHealthStatus(),
            'monitoring_metrics' => $this->getMonitoringMetrics(),
            'alert_rules' => $this->getAlertRules(),
            'performance_thresholds' => $this->getPerformanceThresholds(),
            'resource_limits' => $this->getResourceLimits(),
            'recent_alerts' => $this->getRecentAlerts(),
            'monitoring_history' => array_slice($this->monitoringHistory, -10),
            'uptime_stats' => $this->getUptimeStats(),
        ];
    }

    /**
     * Export monitoring data.
     */
    public function exportMonitoringData(string $format = 'json'): string
    {
        $data = [
            'task_info' => [
                'id' => $this->task?->id,
                'name' => $this->task?->name,
            ],
            'monitoring_config' => $this->getMonitoringConfig(),
            'health_status' => $this->getHealthStatus(),
            'monitoring_metrics' => $this->getMonitoringMetrics(),
            'alert_rules' => $this->getAlertRules(),
            'monitoring_history' => $this->monitoringHistory,
            'alerts' => $this->monitoringAlerts,
        ];

        return match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            'csv' => $this->convertToCsv($data),
            'xml' => $this->convertToXml($data),
            default => json_encode($data, JSON_PRETTY_PRINT),
        };
    }

    /**
     * Get monitoring alerts.
     */
    public function getMonitoringAlerts(): array
    {
        return $this->monitoringAlerts;
    }

    /**
     * Acknowledge alert.
     */
    public function acknowledgeAlert(string $alertId): bool
    {
        foreach ($this->monitoringAlerts as &$alert) {
            if ($alert['id'] === $alertId) {
                $alert['acknowledged'] = true;
                $alert['acknowledged_at'] = now()->toISOString();
                $alert['acknowledged_by'] = 'system'; // Simplified for trait usage

                $this->recordMonitoringEvent('alert_acknowledged', [
                    'alert_id' => $alertId,
                    'acknowledged_by' => $alert['acknowledged_by'],
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Get monitoring status.
     */
    public function getMonitoringStatus(): string
    {
        if (! $this->isMonitoringEnabled()) {
            return 'disabled';
        }

        $healthStatus = $this->getHealthStatus();

        return $healthStatus['status'];
    }

    /**
     * Add alert rule.
     */
    public function addAlertRule(string $name, array $rule): self
    {
        $this->alertRules[$name] = $rule;

        return $this;
    }

    /**
     * Set performance threshold.
     */
    public function setPerformanceThreshold(string $metric, float $threshold): self
    {
        $this->performanceThresholds[$metric] = $threshold;

        return $this;
    }

    /**
     * Set resource limit.
     */
    public function setResourceLimit(string $resource, float $limit): self
    {
        $this->resourceLimits[$resource] = $limit;

        return $this;
    }

    /**
     * Add health check.
     */
    public function addHealthCheck(string $name, array $config): self
    {
        $this->healthChecks[$name] = $config;

        return $this;
    }

    /**
     * Get health checks.
     */
    protected function getHealthChecks(): array
    {
        return array_merge([
            'task_status' => [
                'description' => 'Check if task is in a valid state',
                'enabled' => true,
            ],
            'resource_usage' => [
                'description' => 'Check if resource usage is within limits',
                'enabled' => true,
            ],
            'performance_metrics' => [
                'description' => 'Check if performance metrics are acceptable',
                'enabled' => true,
            ],
            'connectivity' => [
                'description' => 'Check if task can connect to required services',
                'enabled' => true,
            ],
        ], $this->healthChecks);
    }

    /**
     * Perform health check for this task.
     */
    public function performHealthCheck(): bool
    {
        return $this->checkTaskStatus() &&
               $this->checkResourceUsage() &&
               $this->checkPerformanceMetrics() &&
               $this->checkConnectivity();
    }

    /**
     * Check task status health.
     */
    protected function checkTaskStatus(): bool
    {
        if (! $this->task) {
            return false;
        }

        $validStatuses = [TaskStatus::Pending, TaskStatus::Running, TaskStatus::Finished];

        return in_array($this->task->status, $validStatuses);
    }

    /**
     * Check resource usage health.
     */
    protected function checkResourceUsage(): bool
    {
        $limits = $this->getResourceLimits();
        $usage = $this->getResourceUsage();

        foreach ($limits as $resource => $limit) {
            if (isset($usage[$resource]) && $usage[$resource] > $limit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check performance metrics health.
     */
    protected function checkPerformanceMetrics(): bool
    {
        $thresholds = $this->getPerformanceThresholds();
        $metrics = $this->getMonitoringMetrics();

        foreach ($thresholds as $metric => $threshold) {
            if (isset($metrics[$metric]) && $metrics[$metric] > $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check connectivity health.
     */
    protected function checkConnectivity(): bool
    {
        // Check if task can connect to required services
        // This would be implemented based on task requirements
        return true;
    }

    /**
     * Perform custom health check.
     */
    protected function performCustomHealthCheck(string $checkName): bool
    {
        // Override in subclasses for custom health checks
        return true;
    }

    /**
     * Check task health.
     */
    protected function checkTaskHealth(): array
    {
        $issues = [];
        $healthy = true;

        if (! $this->task) {
            $issues[] = 'Task not found';
            $healthy = false;
        } elseif ($this->task->status === TaskStatus::Failed) {
            $issues[] = 'Task has failed';
            $healthy = false;
        } elseif ($this->task->status === TaskStatus::Timeout) {
            $issues[] = 'Task has timed out';
            $healthy = false;
        }

        return [
            'healthy' => $healthy,
            'issues' => $issues,
        ];
    }

    /**
     * Calculate health score.
     */
    protected function calculateHealthScore(array $checks): float
    {
        $totalChecks = count($checks);
        $passedChecks = 0;

        foreach ($checks as $check) {
            if ($check['status'] === 'pass') {
                $passedChecks++;
            }
        }

        return $totalChecks > 0 ? $passedChecks / $totalChecks : 0;
    }

    /**
     * Calculate uptime.
     */
    protected function calculateUptime(): float
    {
        if (! $this->task) {
            return 0.0;
        }

        $startedAt = $this->task->created_at;
        $now = now();

        if (! $startedAt) {
            return 0.0;
        }

        return $startedAt->diffInSeconds($now);
    }

    /**
     * Calculate availability.
     */
    protected function calculateAvailability(): float
    {
        // Calculate availability based on successful vs failed executions
        return 0.99; // Placeholder
    }

    /**
     * Get response time.
     */
    protected function getResponseTime(): float
    {
        return $this->getExecutionTime();
    }

    /**
     * Get resource usage.
     */
    protected function getResourceUsage(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => 0.0, // Would be calculated from system metrics
            'disk' => 0, // Would be calculated from disk usage
            'network' => 0, // Would be calculated from network metrics
        ];
    }

    /**
     * Get performance score.
     */
    protected function getPerformanceScore(): float
    {
        // Calculate overall performance score
        return 0.85; // Placeholder
    }

    /**
     * Get custom metrics.
     */
    protected function getCustomMetrics(): array
    {
        return [];
    }

    /**
     * Evaluate alert rule.
     */
    protected function evaluateAlertRule(string $ruleName, array $rule, array $metrics): bool
    {
        $threshold = $rule['threshold'];
        $metricValue = $metrics[$ruleName] ?? 0;

        return $metricValue > $threshold;
    }

    /**
     * Create alert.
     */
    protected function createAlert(string $ruleName, array $rule, array $metrics): array
    {
        $alert = [
            'id' => Str::uuid()->toString(),
            'rule_name' => $ruleName,
            'severity' => $rule['severity'],
            'description' => $rule['description'],
            'threshold' => $rule['threshold'],
            'current_value' => $metrics[$ruleName] ?? 0,
            'timestamp' => now()->toISOString(),
            'acknowledged' => false,
            'action' => $rule['action'],
        ];

        $this->monitoringAlerts[] = $alert;

        return $alert;
    }

    /**
     * Process alert.
     */
    protected function processAlert(array $alert): void
    {
        // Dispatch alert processing job
        ProcessMonitoringAlertJob::dispatch($alert, $this->task?->id);

        $this->recordMonitoringEvent('alert_triggered', [
            'alert_id' => $alert['id'],
            'rule_name' => $alert['rule_name'],
            'severity' => $alert['severity'],
        ]);
    }

    /**
     * Get recent alerts.
     */
    protected function getRecentAlerts(): array
    {
        return array_slice($this->monitoringAlerts, -5);
    }

    /**
     * Get uptime stats.
     */
    protected function getUptimeStats(): array
    {
        return [
            'current_uptime' => $this->calculateUptime(),
            'availability' => $this->calculateAvailability(),
            'last_restart' => $this->task?->created_at?->toISOString(),
        ];
    }

    // Basic metrics methods that can be overridden by other traits
}
