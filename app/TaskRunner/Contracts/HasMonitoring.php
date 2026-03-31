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
     */
    public function getHealthStatus(): array;

    /**
     * Perform health check for this task.
     */
    public function performHealthCheck(): bool;

    /**
     * Get monitoring metrics.
     */
    public function getMonitoringMetrics(): array;

    /**
     * Get alert rules for this task.
     */
    public function getAlertRules(): array;

    /**
     * Check if any alerts should be triggered.
     */
    public function checkAlerts(): array;

    /**
     * Get monitoring configuration.
     */
    public function getMonitoringConfig(): array;

    /**
     * Get performance thresholds.
     */
    public function getPerformanceThresholds(): array;

    /**
     * Get resource limits.
     */
    public function getResourceLimits(): array;

    /**
     * Get monitoring history.
     */
    public function getMonitoringHistory(): array;

    /**
     * Record monitoring event.
     */
    public function recordMonitoringEvent(string $event, array $data = []): void;

    /**
     * Set monitoring configuration.
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
     */
    public function getMonitoringDashboard(): array;

    /**
     * Export monitoring data.
     */
    public function exportMonitoringData(string $format = 'json'): string;

    /**
     * Get monitoring alerts.
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
