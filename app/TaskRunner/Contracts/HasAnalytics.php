<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Contracts;

use App\Modules\TaskRunner\Models\Task;

/**
 * HasAnalytics contract for tasks that support performance analytics.
 * Provides optimization insights through comprehensive performance monitoring.
 */
interface HasAnalytics
{
    /**
     * Check if analytics are enabled for this task.
     */
    public function isAnalyticsEnabled(): bool;

    /**
     * Get performance metrics for this task.
     */
    public function getPerformanceMetrics(): array;

    /**
     * Get resource usage metrics.
     */
    public function getResourceMetrics(): array;

    /**
     * Get execution time breakdown.
     */
    public function getExecutionTimeBreakdown(): array;

    /**
     * Get optimization recommendations.
     */
    public function getOptimizationRecommendations(): array;

    /**
     * Get performance trends.
     */
    public function getPerformanceTrends(): array;

    /**
     * Get bottleneck analysis.
     */
    public function getBottleneckAnalysis(): array;

    /**
     * Get cost analysis.
     */
    public function getCostAnalysis(): array;

    /**
     * Get efficiency score.
     */
    public function getEfficiencyScore(): float;

    /**
     * Get performance alerts.
     */
    public function getPerformanceAlerts(): array;

    /**
     * Record performance metric.
     */
    public function recordMetric(string $metric, mixed $value, array $context = []): void;

    /**
     * Start performance measurement.
     */
    public function startMeasurement(string $measurement): void;

    /**
     * End performance measurement.
     */
    public function endMeasurement(string $measurement): float;

    /**
     * Get performance summary.
     */
    public function getPerformanceSummary(): array;

    /**
     * Export performance data.
     */
    public function exportPerformanceData(string $format = 'json'): string;

    /**
     * Compare performance with baseline.
     */
    public function compareWithBaseline(): array;
}
