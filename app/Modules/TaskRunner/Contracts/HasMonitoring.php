<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Contracts;

use App\Modules\TaskRunner\Models\Task;

/**
 * HasMonitoring contract for tasks that support advanced monitoring and alerting.
 * Provides production-ready monitoring capabilities with intelligent alerting.
 */
interface HasMonitoring
{
    /**
     * Check if monitoring is enabled for this task.
     */
    public function isMonitoringEnabled(): bool;

    /**
     * Get health status of this task.
     * @return array<string, mixed>
     */
    public function getHealthStatus(): array;

    /**
     * Perform health check for this task.
     */
    public function performHealthCheck(): bool;

    /**
     * Get monitoring metrics.
     * @return array<string, mixed>
     */
    public function getMonitoringMetrics(): array;

    /**
     * Get alert rules for this task.
     * @return array<string, mixed>
     */
    public function getAlertRules(): array;

    /**
     * Check if any alerts should be triggered.
     * @return array<string, mixed>
     */
    public function checkAlerts(): array;

    /**
     * Get monitoring configuration.
     * @return array<string, mixed>
     */
    public function getMonitoringConfig(): array;

    /**
     * Get performance thresholds.
     * @return array<string, mixed>
     */
    public function getPerformanceThresholds(): array;

    /**
     * Get resource limits.
     * @return array<string, mixed>
     */
    public function getResourceLimits(): array;

    /**
     * Get monitoring history.
     * @return array<string, mixed>
     */
    public function getMonitoringHistory(): array;

    /**
     * Record monitoring event.
     * @param  array<string, mixed> $data
     */
    public function recordMonitoringEvent(string $event, array $data = []): void;

    /**
     * Set monitoring configuration.
     * @param  array<string, mixed> $config
     */
    public function setMonitoringConfig(array $config): void;

    /**
     * Enable monitoring for this task.
     */
    public function enableMonitoring(): void;

    /**
     * Disable monitoring for this task.
     */
    public function disableMonitoring(): void;

    /**
     * Get monitoring dashboard data.
     * @return array<string, mixed>
     */
    public function getMonitoringDashboard(): array;

    /**
     * Export monitoring data.
     */
    public function exportMonitoringData(string $format = 'json'): string;

    /**
     * Get monitoring alerts.
     * @return array<string, mixed>
     */
    public function getMonitoringAlerts(): array;

    /**
     * Acknowledge alert.
     */
    public function acknowledgeAlert(string $alertId): bool;

    /**
     * Get monitoring status.
     */
    public function getMonitoringStatus(): string;
}
