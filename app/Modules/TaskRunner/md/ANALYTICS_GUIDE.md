# TaskRunner Performance Analytics Guide

The TaskRunner module now includes comprehensive performance analytics and optimization insights. This guide covers all aspects of the analytics functionality for monitoring, analyzing, and optimizing task performance.

## Overview

The performance analytics system provides detailed monitoring, metrics collection, and optimization insights to help identify bottlenecks, track performance trends, and improve task efficiency. It includes real-time monitoring, historical analysis, and actionable recommendations.

## Core Components

### 1. HasAnalytics Contract
The `HasAnalytics` contract defines the interface for tasks that support performance analytics:

```php
interface HasAnalytics
{
    public function isAnalyticsEnabled(): bool;
    public function getPerformanceMetrics(): array;
    public function getResourceMetrics(): array;
    public function getExecutionTimeBreakdown(): array;
    public function getOptimizationRecommendations(): array;
    public function getPerformanceTrends(): array;
    public function getBottleneckAnalysis(): array;
    public function getCostAnalysis(): array;
    public function getEfficiencyScore(): float;
    public function getPerformanceAlerts(): array;
    public function recordMetric(string $metric, mixed $value, array $context = []): void;
    public function startMeasurement(string $measurement): void;
    public function endMeasurement(string $measurement): float;
    public function getPerformanceSummary(): array;
    public function exportPerformanceData(string $format = 'json'): string;
    public function compareWithBaseline(): array;
}
```

### 2. HandlesAnalytics Trait
The `HandlesAnalytics` trait provides comprehensive analytics functionality:

```php
trait HandlesAnalytics
{
    // Analytics support
    public function isAnalyticsEnabled(): bool;
    public function getPerformanceMetrics(): array;
    public function getResourceMetrics(): array;
    public function getExecutionTimeBreakdown(): array;
    
    // Optimization insights
    public function getOptimizationRecommendations(): array;
    public function getBottleneckAnalysis(): array;
    public function getCostAnalysis(): array;
    public function getEfficiencyScore(): float;
    
    // Performance monitoring
    public function getPerformanceAlerts(): array;
    public function getPerformanceTrends(): array;
    public function recordMetric(string $metric, mixed $value, array $context = []): void;
    public function startMeasurement(string $measurement): void;
    public function endMeasurement(string $measurement): float;
    
    // Configuration
    public function setAnalyticsConfig(array $config): self;
    public function enableAnalytics(): self;
    public function disableAnalytics(): self;
    public function addPerformanceAlert(array $alert): self;
}
```

### 3. AnalyticsService
The `AnalyticsService` handles analytics operations and insights:

```php
class AnalyticsService
{
    public function recordMetric(string $taskId, string $metric, mixed $value, array $context = []): void;
    public function calculateTrends(HasAnalytics $task): array;
    public function generateOptimizationInsights(HasAnalytics $task): array;
    public function generatePerformanceReport(HasAnalytics $task): array;
    public function compareTasks(array $taskIds): array;
    public function generateDashboardData(): array;
}
```

## Usage Examples

### Basic Task with Analytics

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class AnalyticsTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        // Configure analytics
        $this->setAnalyticsConfiguration([
            'enabled' => true,
            'baseline' => [
                'execution_time' => 30,
                'memory_usage' => 50 * 1024 * 1024, // 50MB
                'cpu_usage' => 0.5,
            ],
        ]);
    }

    public function render(): string
    {
        // Start performance measurement
        $this->startPerformanceMeasurement('initialization');
        
        $script = "echo 'Starting analytics task...'";
        
        // End initialization measurement
        $this->endPerformanceMeasurement('initialization');
        
        // Start processing measurement
        $this->startPerformanceMeasurement('processing');
        
        $script .= "\necho 'Processing data...'";
        $script .= "\nsleep 2"; // Simulate processing
        
        // End processing measurement
        $this->endPerformanceMeasurement('processing');
        
        // Record custom metrics
        $this->recordPerformanceMetric('data_processed', 1000, ['unit' => 'records']);
        $this->recordPerformanceMetric('cache_hits', 85, ['unit' => 'percentage']);
        
        return $script;
    }
}
```

### Advanced Task with Detailed Analytics

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class DatabaseOptimizationTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        
        $this->setAnalyticsConfiguration([
            'enabled' => true,
            'baseline' => [
                'execution_time' => 120,
                'memory_usage' => 100 * 1024 * 1024,
                'database_queries' => 50,
                'cache_efficiency' => 0.8,
            ],
        ]);
    }

    public function render(): string
    {
        $this->startPerformanceMeasurement('database_optimization');
        
        $script = "echo 'Starting database optimization...'";
        
        // Database backup
        $this->startPerformanceMeasurement('backup');
        $script .= "\necho 'Creating backup...'";
        $script .= "\nmysqldump -u root -p database > backup.sql";
        $this->endPerformanceMeasurement('backup');
        
        // Index optimization
        $this->startPerformanceMeasurement('index_optimization');
        $script .= "\necho 'Optimizing indexes...'";
        $script .= "\nmysql -u root -p -e 'OPTIMIZE TABLE users, posts, comments;'";
        $this->endPerformanceMeasurement('index_optimization');
        
        // Query optimization
        $this->startPerformanceMeasurement('query_optimization');
        $script .= "\necho 'Analyzing slow queries...'";
        $script .= "\nmysql -u root -p -e 'ANALYZE TABLE users, posts, comments;'";
        $this->endPerformanceMeasurement('query_optimization');
        
        $this->endPerformanceMeasurement('database_optimization');
        
        // Record detailed metrics
        $this->recordPerformanceMetric('tables_optimized', 3);
        $this->recordPerformanceMetric('backup_size_mb', 150);
        $this->recordPerformanceMetric('index_improvement_percentage', 25);
        
        return $script;
    }

    protected function getMemoryEfficiency(): float
    {
        // Custom memory efficiency calculation
        $peakUsage = $this->getPeakMemoryUsage();
        $limit = $this->getMemoryLimit();
        
        // Consider this task efficient if using less than 70% of memory
        return $peakUsage < ($limit * 0.7) ? 0.9 : 0.6;
    }

    protected function getCpuEfficiency(): float
    {
        // Custom CPU efficiency calculation
        $executionTime = $this->getExecutionTime();
        $baselineTime = $this->getBaselineExecutionTime();
        
        // Consider efficient if within 20% of baseline
        return $executionTime <= ($baselineTime * 1.2) ? 0.8 : 0.5;
    }
}
```

### Task with Performance Monitoring

```php
<?php

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\BaseTask;

class MonitoredTask extends BaseTask
{
    public function render(): string
    {
        $this->startPerformanceMeasurement('total_execution');
        
        $script = "echo 'Starting monitored task...'";
        
        // Monitor each step
        $steps = ['validation', 'processing', 'output'];
        
        foreach ($steps as $step) {
            $this->startPerformanceMeasurement($step);
            
            $script .= "\necho 'Executing {$step} step...'";
            $script .= "\nsleep 1"; // Simulate step execution
            
            $duration = $this->endPerformanceMeasurement($step);
            
            // Record step-specific metrics
            $this->recordPerformanceMetric("{$step}_duration", $duration);
            $this->recordPerformanceMetric("{$step}_memory", memory_get_usage());
            
            // Add alerts for slow steps
            if ($duration > 2.0) {
                $this->addPerformanceAlert([
                    'type' => 'slow_step',
                    'severity' => 'warning',
                    'message' => "Step '{$step}' took {$duration}s to complete",
                    'step' => $step,
                    'duration' => $duration,
                ]);
            }
        }
        
        $this->endPerformanceMeasurement('total_execution');
        
        return $script;
    }
}
```

## Performance Metrics

### Core Metrics

```php
$metrics = $task->getPerformanceMetrics();

// Returns:
[
    'task_id' => 'task-uuid',
    'task_name' => 'My Task',
    'execution_time' => 45.2,
    'memory_usage' => 52428800, // 50MB
    'cpu_usage' => 0.75,
    'disk_io' => [
        'read_bytes' => 1048576,
        'write_bytes' => 524288,
    ],
    'network_io' => [
        'bytes_sent' => 1024,
        'bytes_received' => 2048,
    ],
    'success_rate' => 0.95,
    'error_rate' => 0.05,
    'throughput' => 0.022, // tasks per second
    'latency' => 45.2,
    'efficiency_score' => 0.82,
    'timestamp' => '2024-01-01T12:00:00Z',
]
```

### Resource Metrics

```php
$resourceMetrics = $task->getResourceMetrics();

// Returns:
[
    'memory' => [
        'peak_usage' => 52428800,
        'average_usage' => 41943040,
        'memory_limit' => 134217728,
        'memory_efficiency' => 0.39,
    ],
    'cpu' => [
        'peak_usage' => 0.85,
        'average_usage' => 0.65,
        'cpu_time' => 45.2,
        'cpu_efficiency' => 0.76,
    ],
    'disk' => [
        'read_bytes' => 1048576,
        'write_bytes' => 524288,
        'read_operations' => 100,
        'write_operations' => 50,
        'disk_efficiency' => 0.88,
    ],
    'network' => [
        'bytes_sent' => 1024,
        'bytes_received' => 2048,
        'connections' => 5,
        'network_efficiency' => 0.92,
    ],
]
```

### Execution Time Breakdown

```php
$breakdown = $task->getExecutionTimeBreakdown();

// Returns:
[
    'total_execution_time' => 45.2,
    'initialization_time' => 2.1,
    'processing_time' => 40.5,
    'cleanup_time' => 1.8,
    'wait_time' => 0.5,
    'overhead_time' => 0.3,
    'breakdown_percentage' => [
        'initialization' => 4.6,
        'processing' => 89.6,
        'cleanup' => 4.0,
        'wait' => 1.1,
        'overhead' => 0.7,
    ],
]
```

## Optimization Insights

### Automatic Recommendations

```php
$recommendations = $task->getOptimizationRecommendations();

// Returns:
[
    [
        'type' => 'memory_optimization',
        'priority' => 'high',
        'description' => 'Memory usage is inefficient. Consider optimizing data structures or implementing caching.',
        'impact' => 'medium',
        'effort' => 'medium',
    ],
    [
        'type' => 'cpu_optimization',
        'priority' => 'medium',
        'description' => 'CPU usage indicates potential for parallelization or algorithm optimization.',
        'impact' => 'high',
        'effort' => 'high',
    ],
    [
        'type' => 'execution_time_optimization',
        'priority' => 'high',
        'description' => 'Execution time is significantly higher than baseline. Review algorithm efficiency.',
        'impact' => 'high',
        'effort' => 'medium',
    ],
]
```

### Bottleneck Analysis

```php
$bottlenecks = $task->getBottleneckAnalysis();

// Returns:
[
    [
        'type' => 'execution_time',
        'severity' => 'high',
        'description' => 'Execution time is significantly higher than expected',
        'current_value' => 45.2,
        'expected_value' => 30.0,
        'impact' => 'Major performance degradation',
    ],
    [
        'type' => 'memory_usage',
        'severity' => 'medium',
        'description' => 'Memory usage is inefficient',
        'current_value' => 0.39,
        'expected_value' => 0.8,
        'impact' => 'Potential memory leaks or inefficient data handling',
    ],
]
```

## Cost Analysis

### Resource Cost Calculation

```php
$costAnalysis = $task->getCostAnalysis();

// Returns:
[
    'compute_cost' => 0.0013, // $0.0013
    'memory_cost' => 0.0026,  // $0.0026
    'total_cost' => 0.0039,   // $0.0039
    'cost_per_minute' => 0.0052,
    'cost_efficiency' => 0.85,
    'cost_trend' => [
        'trend' => 'stable',
        'change_percentage' => 0.0,
    ],
    'optimization_potential' => 0.2, // 20% potential savings
]
```

## Performance Monitoring

### Custom Metrics Recording

```php
// Record custom metrics
$task->recordPerformanceMetric('database_queries', 150, ['type' => 'select']);
$task->recordPerformanceMetric('cache_hits', 85, ['unit' => 'percentage']);
$task->recordPerformanceMetric('files_processed', 1000, ['size_mb' => 50]);

// Start and end measurements
$task->startPerformanceMeasurement('data_processing');
// ... perform operations ...
$duration = $task->endPerformanceMeasurement('data_processing');
```

### Performance Alerts

```php
// Add custom alerts
$task->addPerformanceAlert([
    'type' => 'high_memory_usage',
    'severity' => 'warning',
    'message' => 'Memory usage exceeded 80% threshold',
    'current_value' => 85,
    'threshold' => 80,
    'timestamp' => now()->toISOString(),
]);

// Get all alerts
$alerts = $task->getPerformanceAlerts();
```

## Configuration

### Task-Level Configuration

```php
$task->setAnalyticsConfiguration([
    'enabled' => true,
    'baseline' => [
        'execution_time' => 60,
        'memory_usage' => 50 * 1024 * 1024, // 50MB
        'cpu_usage' => 0.5,
        'success_rate' => 0.95,
    ],
    'thresholds' => [
        'memory_warning' => 0.7,
        'memory_critical' => 0.9,
        'execution_time_warning' => 1.5,
        'execution_time_critical' => 2.0,
    ],
    'monitoring' => [
        'record_custom_metrics' => true,
        'enable_alerts' => true,
        'track_trends' => true,
    ],
]);
```

### Global Configuration

Add to `config/task-runner.php`:

```php
return [
    'analytics' => [
        'enabled' => true,
        'storage' => [
            'metrics_retention_days' => 30,
            'trends_retention_days' => 90,
            'cache_ttl_hours' => 24,
        ],
        'thresholds' => [
            'memory_efficiency_warning' => 0.7,
            'memory_efficiency_critical' => 0.5,
            'cpu_efficiency_warning' => 0.6,
            'cpu_efficiency_critical' => 0.4,
            'execution_time_warning_multiplier' => 1.5,
            'execution_time_critical_multiplier' => 2.0,
        ],
        'monitoring' => [
            'enable_real_time_monitoring' => true,
            'enable_historical_analysis' => true,
            'enable_cost_analysis' => true,
            'enable_optimization_recommendations' => true,
        ],
        'alerts' => [
            'enable_email_alerts' => false,
            'enable_slack_alerts' => false,
            'alert_threshold' => 'warning',
        ],
    ],
];
```

## Performance Reports

### Generate Comprehensive Report

```php
$report = $task->generatePerformanceReport();

// Returns detailed report with:
// - Performance summary
// - Resource usage analysis
// - Execution breakdown
// - Optimization insights
// - Recommendations
// - Trends
// - Alerts
// - Cost analysis
```

### Export Performance Data

```php
// Export as JSON
$jsonData = $task->exportPerformanceData('json');

// Export as CSV
$csvData = $task->exportPerformanceData('csv');

// Export as XML
$xmlData = $task->exportPerformanceData('xml');
```

## Dashboard Integration

### Generate Dashboard Data

```php
$analyticsService = app(AnalyticsService::class);
$dashboardData = $analyticsService->generateDashboardData();

// Returns:
[
    'overview' => [
        'total_tasks' => 150,
        'successful_tasks' => 142,
        'failed_tasks' => 8,
        'average_execution_time' => 45.2,
        'average_efficiency_score' => 0.82,
    ],
    'performance_trends' => [...],
    'resource_usage' => [...],
    'top_optimization_opportunities' => [...],
    'performance_alerts' => [...],
]
```

### Task Comparison

```php
$comparison = $analyticsService->compareTasks(['task-1', 'task-2', 'task-3']);

// Returns comparison data with:
// - Individual task metrics
// - Averages
// - Rankings
// - Best/worst performers
```

## Best Practices

### 1. Enable Analytics for Critical Tasks

```php
class CriticalTask extends BaseTask
{
    public function __construct()
    {
        parent::__construct();
        $this->enableAnalytics();
        $this->setAnalyticsConfiguration([
            'enabled' => true,
            'baseline' => $this->getBaselineMetrics(),
        ]);
    }
}
```

### 2. Monitor Performance Continuously

```php
public function render(): string
{
    $this->startPerformanceMeasurement('total');
    
    // Monitor each phase
    $this->startPerformanceMeasurement('setup');
    // Setup code...
    $this->endPerformanceMeasurement('setup');
    
    $this->startPerformanceMeasurement('processing');
    // Processing code...
    $this->endPerformanceMeasurement('processing');
    
    $this->endPerformanceMeasurement('total');
    
    return $script;
}
```

### 3. Record Custom Metrics

```php
// Record business-specific metrics
$this->recordPerformanceMetric('records_processed', 1000);
$this->recordPerformanceMetric('api_calls', 25);
$this->recordPerformanceMetric('cache_miss_rate', 0.15);
```

### 4. Set Appropriate Baselines

```php
protected function getBaselineMetrics(): array
{
    return [
        'execution_time' => 30.0,
        'memory_usage' => 50 * 1024 * 1024, // 50MB
        'cpu_usage' => 0.5,
        'success_rate' => 0.95,
    ];
}
```

### 5. Monitor Trends Over Time

```php
// Check performance trends
$trends = $task->getPerformanceTrends();

if ($trends['execution_time']['trend'] === 'degrading') {
    // Performance is getting worse
    Log::warning('Task performance is degrading', $trends);
}
```

## Troubleshooting

### Common Issues

1. **High Memory Usage**: Check for memory leaks, optimize data structures
2. **Slow Execution**: Profile the task, identify bottlenecks
3. **Low CPU Efficiency**: Consider parallelization, optimize algorithms
4. **I/O Bottlenecks**: Implement caching, optimize database queries

### Debug Commands

```bash
# View analytics for a specific task
php artisan task:analytics task-id

# Generate performance report
php artisan task:performance-report task-id

# Compare multiple tasks
php artisan task:compare-tasks task-1 task-2 task-3

# Export analytics data
php artisan task:export-analytics task-id --format=json
```

This comprehensive analytics system provides detailed performance monitoring, optimization insights, and actionable recommendations to improve task efficiency and system performance. 