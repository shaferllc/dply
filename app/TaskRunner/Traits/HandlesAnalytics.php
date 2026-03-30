<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Traits;

use App\Modules\TaskRunner\Services\AnalyticsService;
use Illuminate\Support\Facades\Cache;

/**
 * HandlesAnalytics trait provides comprehensive performance analytics and optimization insights.
 * Enables detailed performance monitoring, metrics collection, and optimization recommendations.
 */
trait HandlesAnalytics
{
    /**
     * Analytics configuration properties.
     */
    protected bool $analyticsEnabled = true;

    protected array $performanceMetrics = [];

    protected array $resourceMetrics = [];

    protected array $executionTimes = [];

    protected array $measurements = [];

    protected array $baselineMetrics = [];

    protected array $performanceAlerts = [];

    /**
     * Check if analytics are enabled for this task.
     */
    public function isAnalyticsEnabled(): bool
    {
        return $this->analyticsEnabled;
    }

    /**
     * Get performance metrics for this task.
     */
    public function getPerformanceMetrics(): array
    {
        $metrics = [
            'task_id' => $this->task?->id,
            'task_name' => $this->task?->name,
            'execution_time' => $this->getExecutionTime(),
            'memory_usage' => $this->getMemoryUsage(),
            'cpu_usage' => $this->getCpuUsage(),
            'disk_io' => $this->getDiskIO(),
            'network_io' => $this->getNetworkIO(),
            'success_rate' => $this->getSuccessRate(),
            'error_rate' => $this->getErrorRate(),
            'throughput' => $this->getThroughput(),
            'latency' => $this->getLatency(),
            'efficiency_score' => $this->getEfficiencyScore(),
            'timestamp' => now()->toISOString(),
        ];

        return array_merge($metrics, $this->performanceMetrics);
    }

    /**
     * Get resource usage metrics.
     */
    public function getResourceMetrics(): array
    {
        return [
            'memory' => [
                'peak_usage' => $this->getPeakMemoryUsage(),
                'average_usage' => $this->getAverageMemoryUsage(),
                'memory_limit' => $this->getMemoryLimit(),
                'memory_efficiency' => $this->getMemoryEfficiency(),
            ],
            'cpu' => [
                'peak_usage' => $this->getPeakCpuUsage(),
                'average_usage' => $this->getAverageCpuUsage(),
                'cpu_time' => $this->getCpuTime(),
                'cpu_efficiency' => $this->getCpuEfficiency(),
            ],
            'disk' => [
                'read_bytes' => $this->getDiskReadBytes(),
                'write_bytes' => $this->getDiskWriteBytes(),
                'read_operations' => $this->getDiskReadOperations(),
                'write_operations' => $this->getDiskWriteOperations(),
                'disk_efficiency' => $this->getDiskEfficiency(),
            ],
            'network' => [
                'bytes_sent' => $this->getNetworkBytesSent(),
                'bytes_received' => $this->getNetworkBytesReceived(),
                'connections' => $this->getNetworkConnections(),
                'network_efficiency' => $this->getNetworkEfficiency(),
            ],
        ];
    }

    /**
     * Get optimization recommendations.
     */
    public function getOptimizationRecommendations(): array
    {
        $recommendations = [];
        $analyticsService = app(AnalyticsService::class);

        // Memory optimization
        if ($this->getMemoryEfficiency() < 0.7) {
            $recommendations[] = [
                'type' => 'memory_optimization',
                'priority' => 'high',
                'description' => 'Memory usage is inefficient. Consider optimizing data structures or implementing caching.',
                'impact' => 'medium',
                'effort' => 'medium',
            ];
        }

        // CPU optimization
        if ($this->getCpuEfficiency() < 0.6) {
            $recommendations[] = [
                'type' => 'cpu_optimization',
                'priority' => 'medium',
                'description' => 'CPU usage indicates potential for parallelization or algorithm optimization.',
                'impact' => 'high',
                'effort' => 'high',
            ];
        }

        // Execution time optimization
        if ($this->getExecutionTime() > $this->getBaselineExecutionTime() * 1.5) {
            $recommendations[] = [
                'type' => 'execution_time_optimization',
                'priority' => 'high',
                'description' => 'Execution time is significantly higher than baseline. Review algorithm efficiency.',
                'impact' => 'high',
                'effort' => 'medium',
            ];
        }

        // I/O optimization
        if ($this->getDiskEfficiency() < 0.5) {
            $recommendations[] = [
                'type' => 'io_optimization',
                'priority' => 'medium',
                'description' => 'Disk I/O is inefficient. Consider batch operations or caching.',
                'impact' => 'medium',
                'effort' => 'low',
            ];
        }

        return $recommendations;
    }

    /**
     * Get performance trends.
     */
    public function getPerformanceTrends(): array
    {
        $taskId = $this->task?->id;
        if (! $taskId) {
            return [];
        }

        $cacheKey = "task_trends_{$taskId}";
        $trends = Cache::get($cacheKey, []);

        if (empty($trends)) {
            $analyticsService = app(AnalyticsService::class);
            $trends = $analyticsService->calculateTrends($this);
            Cache::put($cacheKey, $trends, now()->addHours(1));
        }

        return $trends;
    }

    /**
     * Get bottleneck analysis.
     */
    public function getBottleneckAnalysis(): array
    {
        $bottlenecks = [];
        $metrics = $this->getPerformanceMetrics();

        // Identify bottlenecks based on metrics
        if ($metrics['execution_time'] > $this->getBaselineExecutionTime() * 2) {
            $bottlenecks[] = [
                'type' => 'execution_time',
                'severity' => 'high',
                'description' => 'Execution time is significantly higher than expected',
                'current_value' => $metrics['execution_time'],
                'expected_value' => $this->getBaselineExecutionTime(),
                'impact' => 'Major performance degradation',
            ];
        }

        if ($this->getMemoryEfficiency() < 0.5) {
            $bottlenecks[] = [
                'type' => 'memory_usage',
                'severity' => 'medium',
                'description' => 'Memory usage is inefficient',
                'current_value' => $this->getMemoryEfficiency(),
                'expected_value' => 0.8,
                'impact' => 'Potential memory leaks or inefficient data handling',
            ];
        }

        if ($this->getCpuEfficiency() < 0.4) {
            $bottlenecks[] = [
                'type' => 'cpu_usage',
                'severity' => 'medium',
                'description' => 'CPU usage indicates processing inefficiency',
                'current_value' => $this->getCpuEfficiency(),
                'expected_value' => 0.7,
                'impact' => 'Suboptimal algorithm or lack of parallelization',
            ];
        }

        return $bottlenecks;
    }

    /**
     * Get cost analysis.
     */
    public function getCostAnalysis(): array
    {
        $executionTime = $this->getExecutionTime();
        $memoryUsage = $this->getPeakMemoryUsage();
        $cpuUsage = $this->getAverageCpuUsage();

        // Calculate estimated costs (example calculations)
        $computeCost = ($executionTime / 3600) * 0.10; // $0.10 per hour
        $memoryCost = ($memoryUsage / 1024 / 1024) * 0.05; // $0.05 per MB
        $totalCost = $computeCost + $memoryCost;

        return [
            'compute_cost' => round($computeCost, 4),
            'memory_cost' => round($memoryCost, 4),
            'total_cost' => round($totalCost, 4),
            'cost_per_minute' => round($totalCost / ($executionTime / 60), 4),
            'cost_efficiency' => $this->calculateCostEfficiency($totalCost),
            'cost_trend' => $this->getCostTrend(),
            'optimization_potential' => $this->calculateOptimizationPotential($totalCost),
        ];
    }

    /**
     * Get efficiency score.
     */
    public function getEfficiencyScore(): float
    {
        $scores = [
            'execution_time' => $this->calculateExecutionTimeScore(),
            'memory_usage' => $this->calculateMemoryScore(),
            'cpu_usage' => $this->calculateCpuScore(),
            'disk_io' => $this->calculateDiskScore(),
            'network_io' => $this->calculateNetworkScore(),
        ];

        // Weighted average of all scores
        $weights = [
            'execution_time' => 0.3,
            'memory_usage' => 0.25,
            'cpu_usage' => 0.25,
            'disk_io' => 0.1,
            'network_io' => 0.1,
        ];

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($scores as $metric => $score) {
            $totalScore += $score * $weights[$metric];
            $totalWeight += $weights[$metric];
        }

        return $totalWeight > 0 ? $totalScore / $totalWeight : 0;
    }

    /**
     * Get performance alerts.
     */
    public function getPerformanceAlerts(): array
    {
        $alerts = [];
        $metrics = $this->getPerformanceMetrics();

        // Check for performance degradation
        if ($metrics['execution_time'] > $this->getBaselineExecutionTime() * 1.5) {
            $alerts[] = [
                'type' => 'performance_degradation',
                'severity' => 'warning',
                'message' => 'Task execution time is 50% higher than baseline',
                'metric' => 'execution_time',
                'current_value' => $metrics['execution_time'],
                'threshold' => $this->getBaselineExecutionTime() * 1.5,
            ];
        }

        // Check for memory issues
        if ($this->getMemoryEfficiency() < 0.3) {
            $alerts[] = [
                'type' => 'memory_inefficiency',
                'severity' => 'critical',
                'message' => 'Memory usage is highly inefficient',
                'metric' => 'memory_efficiency',
                'current_value' => $this->getMemoryEfficiency(),
                'threshold' => 0.3,
            ];
        }

        // Check for CPU bottlenecks
        if ($this->getCpuEfficiency() < 0.2) {
            $alerts[] = [
                'type' => 'cpu_bottleneck',
                'severity' => 'warning',
                'message' => 'CPU usage indicates severe bottleneck',
                'metric' => 'cpu_efficiency',
                'current_value' => $this->getCpuEfficiency(),
                'threshold' => 0.2,
            ];
        }

        return array_merge($alerts, $this->performanceAlerts);
    }

    /**
     * Record performance metric.
     */
    public function recordMetric(string $metric, mixed $value, array $context = []): void
    {
        $this->performanceMetrics[$metric] = [
            'value' => $value,
            'timestamp' => now()->toISOString(),
            'context' => $context,
        ];

        // Store in analytics service
        $analyticsService = app(AnalyticsService::class);
        $analyticsService->recordMetric($this->task?->id, $metric, $value, $context);
    }

    /**
     * Compare performance with baseline.
     */
    public function compareWithBaseline(): array
    {
        $currentMetrics = $this->getPerformanceMetrics();
        $baselineMetrics = $this->getBaselineMetrics();

        $comparison = [];
        foreach ($currentMetrics as $metric => $value) {
            if (isset($baselineMetrics[$metric])) {
                $baseline = $baselineMetrics[$metric];
                $difference = $value - $baseline;
                $percentage = $baseline > 0 ? ($difference / $baseline) * 100 : 0;

                $comparison[$metric] = [
                    'current' => $value,
                    'baseline' => $baseline,
                    'difference' => $difference,
                    'percentage_change' => $percentage,
                    'status' => $this->getComparisonStatus($percentage),
                ];
            }
        }

        return $comparison;
    }

    /**
     * Set analytics configuration.
     */
    public function setAnalyticsConfig(array $config): self
    {
        $this->analyticsEnabled = $config['enabled'] ?? true;
        $this->baselineMetrics = $config['baseline'] ?? [];

        return $this;
    }

    /**
     * Enable analytics for this task.
     */
    public function enableAnalytics(): self
    {
        $this->analyticsEnabled = true;

        return $this;
    }

    /**
     * Disable analytics for this task.
     */
    public function disableAnalytics(): self
    {
        $this->analyticsEnabled = false;

        return $this;
    }

    /**
     * Add performance alert.
     */
    public function addPerformanceAlert(array $alert): self
    {
        $this->performanceAlerts[] = $alert;

        return $this;
    }

    // Helper methods for metrics calculation

    protected function getExecutionTime(): float
    {
        if (! $this->task) {
            return 0.0;
        }

        $startedAt = $this->task->created_at;
        $completedAt = $this->task->updated_at;

        if (! $startedAt || ! $completedAt) {
            return 0.0;
        }

        return $startedAt->diffInSeconds($completedAt);
    }

    protected function getMemoryUsage(): int
    {
        return memory_get_usage(true);
    }

    protected function getCpuUsage(): float
    {
        // This would be implemented with system calls
        return 0.0;
    }

    protected function getDiskIO(): array
    {
        return [
            'read_bytes' => 0,
            'write_bytes' => 0,
        ];
    }

    protected function getNetworkIO(): array
    {
        return [
            'bytes_sent' => 0,
            'bytes_received' => 0,
        ];
    }

    protected function getSuccessRate(): float
    {
        // Calculate success rate based on task history
        return 1.0;
    }

    protected function getErrorRate(): float
    {
        return 1.0 - $this->getSuccessRate();
    }

    protected function getThroughput(): float
    {
        $executionTime = $this->getExecutionTime();

        return $executionTime > 0 ? 1 / $executionTime : 0;
    }

    protected function getLatency(): float
    {
        return $this->getExecutionTime();
    }

    protected function getPeakMemoryUsage(): int
    {
        return memory_get_peak_usage(true);
    }

    protected function getAverageMemoryUsage(): int
    {
        return $this->getMemoryUsage();
    }

    protected function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');

        return is_numeric($limit) ? (int) $limit : 0;
    }

    protected function getMemoryEfficiency(): float
    {
        $usage = $this->getPeakMemoryUsage();
        $limit = $this->getMemoryLimit();

        return $limit > 0 ? $usage / $limit : 0;
    }

    protected function getPeakCpuUsage(): float
    {
        return $this->getCpuUsage();
    }

    protected function getAverageCpuUsage(): float
    {
        return $this->getCpuUsage();
    }

    protected function getCpuTime(): float
    {
        return $this->getExecutionTime();
    }

    protected function getCpuEfficiency(): float
    {
        return 1.0; // Placeholder
    }

    protected function getDiskReadBytes(): int
    {
        return 0;
    }

    protected function getDiskWriteBytes(): int
    {
        return 0;
    }

    protected function getDiskReadOperations(): int
    {
        return 0;
    }

    protected function getDiskWriteOperations(): int
    {
        return 0;
    }

    protected function getDiskEfficiency(): float
    {
        return 1.0; // Placeholder
    }

    protected function getNetworkBytesSent(): int
    {
        return 0;
    }

    protected function getNetworkBytesReceived(): int
    {
        return 0;
    }

    protected function getNetworkConnections(): int
    {
        return 0;
    }

    protected function getNetworkEfficiency(): float
    {
        return 1.0; // Placeholder
    }

    protected function getInitializationTime(): float
    {
        return $this->measurements['initialization']['duration'] ?? 0.0;
    }

    protected function getProcessingTime(): float
    {
        return $this->measurements['processing']['duration'] ?? 0.0;
    }

    protected function getCleanupTime(): float
    {
        return $this->measurements['cleanup']['duration'] ?? 0.0;
    }

    protected function getWaitTime(): float
    {
        return $this->measurements['wait']['duration'] ?? 0.0;
    }

    protected function getOverheadTime(): float
    {
        $total = $this->getExecutionTime();
        $measured = $this->getInitializationTime() + $this->getProcessingTime() +
                   $this->getCleanupTime() + $this->getWaitTime();

        return max(0, $total - $measured);
    }

    protected function getBaselineExecutionTime(): float
    {
        return $this->baselineMetrics['execution_time'] ?? 60.0;
    }

    protected function getBaselineMetrics(): array
    {
        return $this->baselineMetrics;
    }

    protected function calculatePercentage(float $part, float $total): float
    {
        return $total > 0 ? ($part / $total) * 100 : 0;
    }

    protected function calculateExecutionTimeScore(): float
    {
        $current = $this->getExecutionTime();
        $baseline = $this->getBaselineExecutionTime();

        return $baseline > 0 ? min(1.0, $baseline / $current) : 0;
    }

    protected function calculateMemoryScore(): float
    {
        return 1.0 - $this->getMemoryEfficiency();
    }

    protected function calculateCpuScore(): float
    {
        return $this->getCpuEfficiency();
    }

    protected function calculateDiskScore(): float
    {
        return $this->getDiskEfficiency();
    }

    protected function calculateNetworkScore(): float
    {
        return $this->getNetworkEfficiency();
    }

    protected function calculateCostEfficiency(float $cost): float
    {
        // Calculate cost efficiency based on task value
        return 1.0; // Placeholder
    }

    protected function getCostTrend(): array
    {
        return [
            'trend' => 'stable',
            'change_percentage' => 0.0,
        ];
    }

    protected function calculateOptimizationPotential(float $currentCost): float
    {
        // Calculate potential cost savings
        return 0.2; // 20% potential savings
    }

    protected function getComparisonStatus(float $percentage): string
    {
        if ($percentage <= -10) {
            return 'improved';
        } elseif ($percentage >= 10) {
            return 'degraded';
        } else {
            return 'stable';
        }
    }

    protected function convertToCsv(array $data): string
    {
        // Convert data to CSV format
        return ''; // Placeholder
    }

    protected function convertToXml(array $data): string
    {
        // Convert data to XML format
        return ''; // Placeholder
    }
}
