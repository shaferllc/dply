<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

class TaskMetricsDashboard extends Component
{
    public array $metrics = [
        'active_tasks' => 0,
        'total_tasks_today' => 0,
        'success_rate' => 0.0,
        'average_execution_time' => 0.0,
        'failed_tasks' => 0,
        'cpu_usage' => 0.0,
        'memory_usage' => 0.0,
        'queue_size' => 0,
    ];

    public array $recentTasks = [];

    public array $taskHistory = [];

    public string $timeRange = '1h';

    public bool $autoRefresh = true;

    public function mount()
    {
        $this->loadMetrics();
        $this->loadRecentTasks();
        $this->loadTaskHistory();
    }

    public function render()
    {
        return view('task-runner::livewire.task-metrics-dashboard', [
            'metrics' => $this->metrics,
            'recentTasks' => $this->recentTasks,
            'taskHistory' => $this->taskHistory,
        ]);
    }

    /**
     * Load current metrics.
     */
    public function loadMetrics(): void
    {
        // In a real implementation, you'd fetch these from a database or cache
        $this->metrics = [
            'active_tasks' => $this->getActiveTaskCount(),
            'total_tasks_today' => $this->getTotalTasksToday(),
            'success_rate' => $this->getSuccessRate(),
            'average_execution_time' => $this->getAverageExecutionTime(),
            'failed_tasks' => $this->getFailedTaskCount(),
            'cpu_usage' => $this->getCpuUsage(),
            'memory_usage' => $this->getMemoryUsage(),
            'queue_size' => $this->getQueueSize(),
        ];
    }

    /**
     * Load recent tasks.
     */
    public function loadRecentTasks(): void
    {
        // In a real implementation, you'd fetch from database
        $this->recentTasks = [
            [
                'id' => 'task-1',
                'name' => 'Database Backup',
                'status' => 'completed',
                'started_at' => now()->subMinutes(5),
                'completed_at' => now()->subMinutes(3),
                'duration' => 120,
                'exit_code' => 0,
            ],
            [
                'id' => 'task-2',
                'name' => 'File Sync',
                'status' => 'running',
                'started_at' => now()->subMinutes(2),
                'completed_at' => null,
                'duration' => null,
                'exit_code' => null,
            ],
        ];
    }

    /**
     * Load task history.
     */
    public function loadTaskHistory(): void
    {
        // In a real implementation, you'd fetch from database
        $this->taskHistory = [
            'hourly' => $this->generateHourlyData(),
            'daily' => $this->generateDailyData(),
        ];
    }

    /**
     * Handle task events from WebSocket.
     */
    #[On('echo:task-runner,TaskEvent')]
    public function handleTaskEvent(array $eventData): void
    {
        $this->loadMetrics();
        $this->loadRecentTasks();
    }

    /**
     * Handle metrics updates from WebSocket.
     */
    #[On('echo:task-runner,Metrics')]
    public function handleMetricsUpdate(array $metricsData): void
    {
        $this->metrics = array_merge($this->metrics, $metricsData['metrics'] ?? []);
    }

    /**
     * Refresh metrics manually.
     */
    public function refreshMetrics(): void
    {
        $this->loadMetrics();
        $this->loadRecentTasks();
        $this->loadTaskHistory();
    }

    /**
     * Toggle auto-refresh.
     */
    public function toggleAutoRefresh(): void
    {
        $this->autoRefresh = ! $this->autoRefresh;
    }

    /**
     * Set time range.
     */
    public function setTimeRange(string $range): void
    {
        $this->timeRange = $range;
        $this->loadTaskHistory();
    }

    /**
     * Get active task count.
     */
    protected function getActiveTaskCount(): int
    {
        // In a real implementation, you'd check running processes or database
        return rand(0, 5);
    }

    /**
     * Get total tasks today.
     */
    protected function getTotalTasksToday(): int
    {
        // In a real implementation, you'd query database
        return rand(50, 200);
    }

    /**
     * Get success rate.
     */
    protected function getSuccessRate(): float
    {
        // In a real implementation, you'd calculate from database
        return round(rand(85, 99) / 100, 2);
    }

    /**
     * Get average execution time.
     */
    protected function getAverageExecutionTime(): float
    {
        // In a real implementation, you'd calculate from database
        return round(rand(10, 300) / 10, 1);
    }

    /**
     * Get failed task count.
     */
    protected function getFailedTaskCount(): int
    {
        // In a real implementation, you'd query database
        return rand(0, 10);
    }

    /**
     * Get CPU usage.
     */
    protected function getCpuUsage(): float
    {
        // In a real implementation, you'd get from system metrics
        return round(rand(10, 80) / 10, 1);
    }

    /**
     * Get memory usage.
     */
    protected function getMemoryUsage(): float
    {
        // In a real implementation, you'd get from system metrics
        return round(rand(20, 90) / 10, 1);
    }

    /**
     * Get queue size.
     */
    protected function getQueueSize(): int
    {
        // In a real implementation, you'd check job queue
        return rand(0, 20);
    }

    /**
     * Generate hourly data for charts.
     */
    protected function generateHourlyData(): array
    {
        $data = [];
        for ($i = 23; $i >= 0; $i--) {
            $hour = now()->subHours($i)->format('H:00');
            $data[] = [
                'hour' => $hour,
                'tasks' => rand(0, 15),
                'successful' => rand(0, 12),
                'failed' => rand(0, 3),
            ];
        }

        return $data;
    }

    /**
     * Generate daily data for charts.
     */
    protected function generateDailyData(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->format('D');
            $data[] = [
                'day' => $day,
                'tasks' => rand(50, 200),
                'successful' => rand(45, 190),
                'failed' => rand(0, 10),
            ];
        }

        return $data;
    }

    /**
     * Get status color for a task.
     */
    public function getStatusColor(string $status): string
    {
        return match ($status) {
            'completed' => 'text-green-600',
            'running' => 'text-blue-600',
            'failed' => 'text-red-600',
            'pending' => 'text-yellow-600',
            default => 'text-gray-600',
        };
    }

    /**
     * Get status icon for a task.
     */
    public function getStatusIcon(string $status): string
    {
        return match ($status) {
            'completed' => '✅',
            'running' => '🔄',
            'failed' => '❌',
            'pending' => '⏳',
            default => '❓',
        };
    }

    /**
     * Format duration in human readable format.
     */
    public function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return 'N/A';
        }

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return "{$minutes}m {$remainingSeconds}s";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return "{$hours}h {$remainingMinutes}m";
    }

    /**
     * Get metrics trend (up, down, or stable).
     */
    public function getMetricsTrend(string $metric): string
    {
        // In a real implementation, you'd compare with previous period
        $trends = ['up', 'down', 'stable'];

        return $trends[array_rand($trends)];
    }

    /**
     * Get trend color.
     */
    public function getTrendColor(string $trend): string
    {
        return match ($trend) {
            'up' => 'text-green-600',
            'down' => 'text-red-600',
            'stable' => 'text-gray-600',
            default => 'text-gray-600',
        };
    }

    /**
     * Get trend icon.
     */
    public function getTrendIcon(string $trend): string
    {
        return match ($trend) {
            'up' => '↗️',
            'down' => '↘️',
            'stable' => '→',
            default => '→',
        };
    }
}
