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
    use HandlesResourceMonitoring;

    /**
     * Monitoring configuration properties.
     */
    protected bool $monitoringEnabled = true;

    /** @var array<string, mixed> */
    protected array $monitoringConfig = [];

    /** @var array<string, mixed> */
    protected array $alertRules = [];

    /** @var array<string, mixed> */
    protected array $performanceThresholds = [];

    /** @var array<string, mixed> */
    protected array $monitoringHistory = [];

    /** @var array<string, mixed> */
    protected array $monitoringAlerts = [];

    /** @var array<string, mixed> */
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
     *
     * @return array<string, mixed>
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
        if ($this->taskModel) {
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
     *
     * @return array<string, mixed>
     */
    public function getMonitoringMetrics(): array
    {
        $metrics = [
            'task_id' => $this->taskModel?->id,
            'task_name' => $this->taskModel?->name,
            'status' => $this->taskModel?->status?->value,
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
     *
     * @return array<string, mixed>
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
     *
     * @return list<array<string, mixed>>
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
     *
     * @return array<string, mixed>
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
     *
     * @return array<string, mixed>
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
     * Get monitoring history.
     *
     * @return array<string, mixed>
     */
    public function getMonitoringHistory(): array
    {
        return $this->monitoringHistory;
    }

    /**
     * Record monitoring event.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordMonitoringEvent(string $event, array $data = []): void
    {
        $eventData = [
            'event' => $event,
            'task_id' => $this->taskModel?->id,
            'timestamp' => now()->toISOString(),
            'data' => $data,
        ];

        $this->monitoringHistory[] = $eventData;

        // Store in monitoring service
        $monitoringService = app(MonitoringService::class);
        $monitoringService->recordEvent($this->taskModel?->id, $event, $data);

        Log::info('Monitoring event recorded', $eventData);
    }

    /**
     * Set monitoring configuration.
     *
     * @param  array<string, mixed>  $config
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
     *
     * @return array<string, mixed>
     */
    public function getMonitoringDashboard(): array
    {
        return [
            'task_info' => [
                'id' => $this->taskModel?->id,
                'name' => $this->taskModel?->name,
                'status' => $this->taskModel?->status?->value,
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
                'id' => $this->taskModel?->id,
                'name' => $this->taskModel?->name,
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
     *
     * @return array<string, mixed>
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
     *
     * @param  array<string, mixed>  $rule
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
     * Add health check.
     *
     * @param  array<string, mixed>  $config
     */
    public function addHealthCheck(string $name, array $config): self
    {
        $this->healthChecks[$name] = $config;

        return $this;
    }

    /**
     * Get health checks.
     *
     * @return array<string, mixed>
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
    public function performHealthCheck(?string $checkName = null): bool
    {
        if ($checkName !== null) {
            return match ($checkName) {
                'task_status' => $this->checkTaskStatus(),
                'resource_usage' => $this->checkResourceUsage(),
                'performance_metrics' => $this->checkPerformanceMetrics(),
                'connectivity' => $this->checkConnectivity(),
                default => $this->performCustomHealthCheck($checkName),
            };
        }

        foreach ($this->getHealthChecks() as $name => $checkConfig) {
            if (($checkConfig['enabled'] ?? true) && ! $this->performHealthCheck($name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check task status health.
     */
    protected function checkTaskStatus(): bool
    {
        if (! $this->taskModel) {
            return false;
        }

        $validStatuses = [TaskStatus::Pending, TaskStatus::Running, TaskStatus::Finished];

        return in_array($this->taskModel->status, $validStatuses);
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
     *
     * @return array<string, mixed>
     */
    protected function checkTaskHealth(): array
    {
        $issues = [];
        $healthy = true;

        if (! $this->taskModel) {
            $issues[] = 'Task not found';
            $healthy = false;
        } elseif ($this->taskModel->status === TaskStatus::Failed) {
            $issues[] = 'Task has failed';
            $healthy = false;
        } elseif ($this->taskModel->status === TaskStatus::Timeout) {
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
     *
     * @param  array<string, mixed>  $checks
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
        if (! $this->taskModel) {
            return 0.0;
        }

        $startedAt = $this->taskModel->created_at;
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
     *
     * @return array<string, mixed>
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
     *
     * @return array<string, mixed>
     */
    protected function getCustomMetrics(): array
    {
        return [];
    }

    /**
     * Evaluate alert rule.
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $metrics
     */
    protected function evaluateAlertRule(string $ruleName, array $rule, array $metrics): bool
    {
        $threshold = $rule['threshold'];
        $metricValue = $metrics[$ruleName] ?? 0;

        return $metricValue > $threshold;
    }

    /**
     * Create alert.
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
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
     *
     * @param  array<string, mixed>  $alert
     */
    protected function processAlert(array $alert): void
    {
        // Dispatch alert processing job
        ProcessMonitoringAlertJob::dispatch($alert, $this->taskModel?->id);

        $this->recordMonitoringEvent('alert_triggered', [
            'alert_id' => $alert['id'],
            'rule_name' => $alert['rule_name'],
            'severity' => $alert['severity'],
        ]);
    }

    /**
     * Get recent alerts.
     *
     * @return list<array<string, mixed>>
     */
    protected function getRecentAlerts(): array
    {
        return array_values(array_slice($this->monitoringAlerts, -5));
    }

    /**
     * Get uptime stats.
     *
     * @return array<string, mixed>
     */
    protected function getUptimeStats(): array
    {
        return [
            'current_uptime' => $this->calculateUptime(),
            'availability' => $this->calculateAvailability(),
            'last_restart' => $this->taskModel?->created_at?->toISOString(),
        ];
    }

    // Basic metrics methods that can be overridden by other traits
}
