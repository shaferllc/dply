<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Services;

use App\Modules\TaskRunner\Contracts\HasAnalytics;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AnalyticsService handles performance analytics, metrics collection, and optimization insights.
 * Provides comprehensive performance monitoring and analysis capabilities.
 */
class AnalyticsService
{
    /**
     * Record a performance metric.
     */
    public function recordMetric(string $taskId, string $metric, mixed $value, array $context = []): void
    {
        $data = [
            'task_id' => $taskId,
            'metric' => $metric,
            'value' => $value,
            'context' => $context,
            'timestamp' => now()->toISOString(),
        ];

        // Store in cache for quick access
        $cacheKey = "task_metric_{$taskId}_{$metric}";
        Cache::put($cacheKey, $data, now()->addHours(24));

        // Store in database for historical analysis
        $this->storeMetricInDatabase($data);

        Log::info('Performance metric recorded', $data);
    }

    /**
     * Calculate performance trends for a task.
     */
    public function calculateTrends(HasAnalytics $task): array
    {
        $taskId = $task->getPerformanceMetrics()['task_id'] ?? null;

        if (! $taskId) {
            return [];
        }

        $metrics = $this->getHistoricalMetrics($taskId);

        if (empty($metrics)) {
            return [];
        }

        $trends = [];
        $metricTypes = ['execution_time', 'memory_usage', 'cpu_usage', 'success_rate'];

        foreach ($metricTypes as $metricType) {
            $values = array_column($metrics, $metricType);
            $trends[$metricType] = $this->calculateTrend($values);
        }

        return $trends;
    }

    /**
     * Generate optimization insights.
     */
    public function generateOptimizationInsights(HasAnalytics $task): array
    {
        $insights = [];
        $metrics = $task->getPerformanceMetrics();
        $resourceMetrics = $task->getResourceMetrics();

        // Memory optimization insights
        if ($resourceMetrics['memory']['memory_efficiency'] > 0.8) {
            $insights[] = [
                'type' => 'memory_optimization',
                'priority' => 'high',
                'title' => 'High Memory Usage Detected',
                'description' => 'Task is using more than 80% of available memory. Consider optimizing data structures or implementing pagination.',
                'impact' => 'High memory usage can lead to system instability and performance degradation.',
                'recommendations' => [
                    'Implement data pagination',
                    'Use lazy loading for large datasets',
                    'Optimize database queries',
                    'Consider caching frequently accessed data',
                ],
                'estimated_improvement' => '20-40% memory reduction',
            ];
        }

        // CPU optimization insights
        if ($resourceMetrics['cpu']['cpu_efficiency'] < 0.5) {
            $insights[] = [
                'type' => 'cpu_optimization',
                'priority' => 'medium',
                'title' => 'Low CPU Efficiency',
                'description' => 'Task is not efficiently utilizing CPU resources. Consider parallelization or algorithm optimization.',
                'impact' => 'Suboptimal CPU usage indicates potential for performance improvements.',
                'recommendations' => [
                    'Implement parallel processing',
                    'Optimize algorithm complexity',
                    'Use background jobs for heavy operations',
                    'Consider caching expensive computations',
                ],
                'estimated_improvement' => '30-50% performance improvement',
            ];
        }

        // Execution time insights
        $baselineTime = $task->getBaselineExecutionTime() ?? 60;
        if ($metrics['execution_time'] > $baselineTime * 1.5) {
            $insights[] = [
                'type' => 'execution_time_optimization',
                'priority' => 'high',
                'title' => 'Slow Execution Time',
                'description' => 'Task execution time is significantly higher than baseline. Review algorithm efficiency and resource usage.',
                'impact' => 'Slow execution affects user experience and system throughput.',
                'recommendations' => [
                    'Profile the task to identify bottlenecks',
                    'Optimize database queries',
                    'Implement caching strategies',
                    'Consider breaking large tasks into smaller chunks',
                ],
                'estimated_improvement' => '40-60% execution time reduction',
            ];
        }

        // I/O optimization insights
        if ($resourceMetrics['disk']['disk_efficiency'] < 0.6) {
            $insights[] = [
                'type' => 'io_optimization',
                'priority' => 'medium',
                'title' => 'Inefficient Disk I/O',
                'description' => 'Task is performing inefficient disk operations. Consider batch operations and caching.',
                'impact' => 'Poor I/O performance can significantly slow down task execution.',
                'recommendations' => [
                    'Implement batch database operations',
                    'Use database indexing',
                    'Cache frequently accessed data',
                    'Optimize file operations',
                ],
                'estimated_improvement' => '25-45% I/O improvement',
            ];
        }

        return $insights;
    }

    /**
     * Generate performance report.
     */
    public function generatePerformanceReport(HasAnalytics $task): array
    {
        $metrics = $task->getPerformanceMetrics();
        $resourceMetrics = $task->getResourceMetrics();
        $breakdown = $task->getExecutionTimeBreakdown();
        $insights = $this->generateOptimizationInsights($task);

        return [
            'summary' => [
                'task_id' => $metrics['task_id'],
                'task_name' => $metrics['task_name'],
                'execution_time' => $metrics['execution_time'],
                'efficiency_score' => $metrics['efficiency_score'],
                'status' => $this->getPerformanceStatus($metrics['efficiency_score']),
            ],
            'resource_usage' => [
                'memory' => [
                    'usage' => $resourceMetrics['memory']['peak_usage'],
                    'efficiency' => $resourceMetrics['memory']['memory_efficiency'],
                    'status' => $this->getResourceStatus($resourceMetrics['memory']['memory_efficiency']),
                ],
                'cpu' => [
                    'usage' => $resourceMetrics['cpu']['average_usage'],
                    'efficiency' => $resourceMetrics['cpu']['cpu_efficiency'],
                    'status' => $this->getResourceStatus($resourceMetrics['cpu']['cpu_efficiency']),
                ],
                'disk' => [
                    'read_bytes' => $resourceMetrics['disk']['read_bytes'],
                    'write_bytes' => $resourceMetrics['disk']['write_bytes'],
                    'efficiency' => $resourceMetrics['disk']['disk_efficiency'],
                    'status' => $this->getResourceStatus($resourceMetrics['disk']['disk_efficiency']),
                ],
            ],
            'execution_breakdown' => $breakdown,
            'optimization_insights' => $insights,
            'recommendations' => $task->getOptimizationRecommendations(),
            'trends' => $task->getPerformanceTrends(),
            'alerts' => $task->getPerformanceAlerts(),
            'cost_analysis' => $task->getCostAnalysis(),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Compare performance across multiple tasks.
     */
    public function compareTasks(array $taskIds): array
    {
        $comparison = [];

        foreach ($taskIds as $taskId) {
            $task = Task::find($taskId);
            if ($task) {
                $comparison[$taskId] = [
                    'task_name' => $task->name,
                    'execution_time' => $task->getExecutionTime(),
                    'memory_usage' => $task->getMemoryUsage(),
                    'success_rate' => $task->getSuccessRate(),
                    'efficiency_score' => $task->getEfficiencyScore(),
                ];
            }
        }

        // Calculate averages and rankings
        $averages = $this->calculateAverages($comparison);
        $rankings = $this->calculateRankings($comparison);

        return [
            'tasks' => $comparison,
            'averages' => $averages,
            'rankings' => $rankings,
            'best_performer' => $this->findBestPerformer($comparison),
            'worst_performer' => $this->findWorstPerformer($comparison),
        ];
    }

    /**
     * Generate performance dashboard data.
     */
    public function generateDashboardData(): array
    {
        $recentTasks = Task::where('created_at', '>=', now()->subDays(7))->get();

        $dashboardData = [
            'overview' => [
                'total_tasks' => $recentTasks->count(),
                'successful_tasks' => $recentTasks->where('status', 'finished')->count(),
                'failed_tasks' => $recentTasks->whereIn('status', ['failed', 'timeout'])->count(),
                'average_execution_time' => $recentTasks->avg('execution_time'),
                'average_efficiency_score' => $recentTasks->avg('efficiency_score'),
            ],
            'performance_trends' => $this->getPerformanceTrends($recentTasks),
            'resource_usage' => $this->getResourceUsageSummary($recentTasks),
            'top_optimization_opportunities' => $this->getTopOptimizationOpportunities($recentTasks),
            'performance_alerts' => $this->getRecentPerformanceAlerts(),
        ];

        return $dashboardData;
    }

    /**
     * Store metric in database.
     */
    protected function storeMetricInDatabase(array $data): void
    {
        try {
            DB::table('task_metrics')->insert($data);
        } catch (\Exception $e) {
            Log::error('Failed to store metric in database', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }

    /**
     * Get historical metrics for a task.
     */
    protected function getHistoricalMetrics(string $taskId): array
    {
        try {
            return DB::table('task_metrics')
                ->where('task_id', $taskId)
                ->orderBy('timestamp', 'desc')
                ->limit(50)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to get historical metrics', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Calculate trend for a series of values.
     */
    protected function calculateTrend(array $values): array
    {
        if (count($values) < 2) {
            return ['trend' => 'stable', 'change_percentage' => 0];
        }

        $first = $values[0];
        $last = end($values);

        if ($first == 0) {
            return ['trend' => 'stable', 'change_percentage' => 0];
        }

        $changePercentage = (($last - $first) / $first) * 100;

        return [
            'trend' => $this->getTrendDirection($changePercentage),
            'change_percentage' => round($changePercentage, 2),
            'first_value' => $first,
            'last_value' => $last,
        ];
    }

    /**
     * Get trend direction based on percentage change.
     */
    protected function getTrendDirection(float $changePercentage): string
    {
        if ($changePercentage <= -5) {
            return 'improving';
        } elseif ($changePercentage >= 5) {
            return 'degrading';
        } else {
            return 'stable';
        }
    }

    /**
     * Get performance status based on efficiency score.
     */
    protected function getPerformanceStatus(float $efficiencyScore): string
    {
        if ($efficiencyScore >= 0.8) {
            return 'excellent';
        } elseif ($efficiencyScore >= 0.6) {
            return 'good';
        } elseif ($efficiencyScore >= 0.4) {
            return 'fair';
        } else {
            return 'poor';
        }
    }

    /**
     * Get resource status based on efficiency.
     */
    protected function getResourceStatus(float $efficiency): string
    {
        if ($efficiency >= 0.7) {
            return 'efficient';
        } elseif ($efficiency >= 0.5) {
            return 'moderate';
        } else {
            return 'inefficient';
        }
    }

    /**
     * Calculate averages for task comparison.
     */
    protected function calculateAverages(array $comparison): array
    {
        $metrics = ['execution_time', 'memory_usage', 'success_rate', 'efficiency_score'];
        $averages = [];

        foreach ($metrics as $metric) {
            $values = array_column($comparison, $metric);
            $averages[$metric] = count($values) > 0 ? array_sum($values) / count($values) : 0;
        }

        return $averages;
    }

    /**
     * Calculate rankings for task comparison.
     */
    protected function calculateRankings(array $comparison): array
    {
        $rankings = [];

        foreach ($comparison as $taskId => $data) {
            $rankings[$taskId] = [
                'execution_time_rank' => $this->getRank($data['execution_time'], array_column($comparison, 'execution_time')),
                'memory_usage_rank' => $this->getRank($data['memory_usage'], array_column($comparison, 'memory_usage')),
                'success_rate_rank' => $this->getRank($data['success_rate'], array_column($comparison, 'success_rate')),
                'efficiency_score_rank' => $this->getRank($data['efficiency_score'], array_column($comparison, 'efficiency_score')),
            ];
        }

        return $rankings;
    }

    /**
     * Get rank of a value in an array.
     */
    protected function getRank(float $value, array $values): int
    {
        $sorted = $values;
        sort($sorted);

        return array_search($value, $sorted) + 1;
    }

    /**
     * Find best performing task.
     */
    protected function findBestPerformer(array $comparison): ?string
    {
        $bestTaskId = null;
        $bestScore = -1;

        foreach ($comparison as $taskId => $data) {
            if ($data['efficiency_score'] > $bestScore) {
                $bestScore = $data['efficiency_score'];
                $bestTaskId = $taskId;
            }
        }

        return $bestTaskId;
    }

    /**
     * Find worst performing task.
     */
    protected function findWorstPerformer(array $comparison): ?string
    {
        $worstTaskId = null;
        $worstScore = PHP_FLOAT_MAX;

        foreach ($comparison as $taskId => $data) {
            if ($data['efficiency_score'] < $worstScore) {
                $worstScore = $data['efficiency_score'];
                $worstTaskId = $taskId;
            }
        }

        return $worstTaskId;
    }

    /**
     * Get performance trends for recent tasks.
     */
    protected function getPerformanceTrends($tasks): array
    {
        // Group tasks by day and calculate daily averages
        $dailyData = $tasks->groupBy(function ($task) {
            return $task->created_at->format('Y-m-d');
        })->map(function ($dayTasks) {
            return [
                'execution_time' => $dayTasks->avg('execution_time'),
                'efficiency_score' => $dayTasks->avg('efficiency_score'),
                'success_rate' => $dayTasks->where('status', 'finished')->count() / $dayTasks->count(),
            ];
        });

        return $dailyData->toArray();
    }

    /**
     * Get resource usage summary.
     */
    protected function getResourceUsageSummary($tasks): array
    {
        return [
            'average_memory_usage' => $tasks->avg('memory_usage'),
            'peak_memory_usage' => $tasks->max('memory_usage'),
            'average_cpu_usage' => $tasks->avg('cpu_usage'),
            'total_disk_io' => $tasks->sum('disk_read_bytes') + $tasks->sum('disk_write_bytes'),
        ];
    }

    /**
     * Get top optimization opportunities.
     */
    protected function getTopOptimizationOpportunities($tasks): array
    {
        return $tasks->where('efficiency_score', '<', 0.6)
            ->sortBy('efficiency_score')
            ->take(5)
            ->map(function ($task) {
                return [
                    'task_id' => $task->id,
                    'task_name' => $task->name,
                    'efficiency_score' => $task->efficiency_score,
                    'execution_time' => $task->execution_time,
                ];
            })
            ->toArray();
    }

    /**
     * Get recent performance alerts.
     */
    protected function getRecentPerformanceAlerts(): array
    {
        // This would fetch recent alerts from the database
        return [];
    }
}
