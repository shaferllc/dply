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
     * @return array<string, mixed>
     */
    public function getPerformanceMetrics(): array;

    /**
     * Get resource usage metrics.
     * @return array<string, mixed>
     */
    public function getResourceMetrics(): array;

    /**
     * Get execution time breakdown.
     * @return array<string, mixed>
     */
    public function getExecutionTimeBreakdown(): array;

    /**
     * Get optimization recommendations.
     * @return array<string, mixed>
     */
    public function getOptimizationRecommendations(): array;

    /**
     * Get performance trends.
     * @return array<string, mixed>
     */
    public function getPerformanceTrends(): array;

    /**
     * Get bottleneck analysis.
     * @return array<string, mixed>
     */
    public function getBottleneckAnalysis(): array;

    /**
     * Get cost analysis.
     * @return array<string, mixed>
     */
    public function getCostAnalysis(): array;

    /**
     * Get efficiency score.
     */
    public function getEfficiencyScore(): float;

    /**
     * Get performance alerts.
     * @return array<string, mixed>
     */
    public function getPerformanceAlerts(): array;

    /**
     * Record performance metric.
     * @param  array<string, mixed> $context
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
     * @return array<string, mixed>
     */
    public function getPerformanceSummary(): array;

    /**
     * Export performance data.
     */
    public function exportPerformanceData(string $format = 'json'): string;

    /**
     * Compare performance with baseline.
     * @return array<string, mixed>
     */
    public function compareWithBaseline(): array;
}
