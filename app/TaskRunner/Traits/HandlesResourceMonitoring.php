<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Traits;

/**
 * HandlesResourceMonitoring trait provides comprehensive resource monitoring and usage tracking.
 * Focuses on memory, CPU, disk, and network resource monitoring.
 */
trait HandlesResourceMonitoring
{
    /**
     * Resource monitoring properties.
     */
    protected array $resourceMetrics = [];

    protected array $resourceHistory = [];

    protected array $resourceLimits = [];

    protected array $resourceAlerts = [];

    /**
     * Get resource usage metrics.
     */
    public function getResourceMetrics(): array
    {
        return [
            'memory' => [
                'current_usage' => $this->getCurrentMemoryUsage(),
                'peak_usage' => $this->getPeakMemoryUsage(),
                'average_usage' => $this->getAverageMemoryUsage(),
                'memory_limit' => $this->getMemoryLimit(),
                'memory_efficiency' => $this->getMemoryEfficiency(),
                'memory_available' => $this->getAvailableMemory(),
            ],
            'cpu' => [
                'current_usage' => $this->getCurrentCpuUsage(),
                'peak_usage' => $this->getPeakCpuUsage(),
                'average_usage' => $this->getAverageCpuUsage(),
                'cpu_time' => $this->getCpuTime(),
                'cpu_efficiency' => $this->getCpuEfficiency(),
                'cpu_cores' => $this->getCpuCores(),
            ],
            'disk' => [
                'read_bytes' => $this->getDiskReadBytes(),
                'write_bytes' => $this->getDiskWriteBytes(),
                'read_operations' => $this->getDiskReadOperations(),
                'write_operations' => $this->getDiskWriteOperations(),
                'disk_efficiency' => $this->getDiskEfficiency(),
                'disk_usage_percent' => $this->getDiskUsagePercent(),
                'free_space' => $this->getFreeDiskSpace(),
            ],
            'network' => [
                'bytes_sent' => $this->getNetworkBytesSent(),
                'bytes_received' => $this->getNetworkBytesReceived(),
                'connections' => $this->getNetworkConnections(),
                'network_efficiency' => $this->getNetworkEfficiency(),
                'bandwidth_usage' => $this->getBandwidthUsage(),
            ],
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Record resource usage.
     */
    public function recordResourceUsage(): void
    {
        $metrics = $this->getResourceMetrics();
        $this->resourceHistory[] = $metrics;

        // Keep only last 1000 records
        if (count($this->resourceHistory) > 1000) {
            $this->resourceHistory = array_slice($this->resourceHistory, -1000);
        }
    }

    /**
     * Get resource usage history.
     */
    public function getResourceHistory(): array
    {
        return $this->resourceHistory;
    }

    /**
     * Get resource usage trends.
     */
    public function getResourceTrends(): array
    {
        if (empty($this->resourceHistory)) {
            return [];
        }

        $recent = array_slice($this->resourceHistory, -10);
        $older = array_slice($this->resourceHistory, -20, 10);

        if (empty($older)) {
            return [];
        }

        $trends = [];
        foreach (['memory', 'cpu', 'disk', 'network'] as $resource) {
            $recentAvg = array_sum(array_column($recent, $resource.'.current_usage')) / count($recent);
            $olderAvg = array_sum(array_column($older, $resource.'.current_usage')) / count($older);

            $change = $recentAvg - $olderAvg;
            $percentage = $olderAvg > 0 ? ($change / $olderAvg) * 100 : 0;

            $trends[$resource] = [
                'current_average' => $recentAvg,
                'previous_average' => $olderAvg,
                'change' => $change,
                'percentage_change' => $percentage,
                'trend' => $percentage > 5 ? 'increasing' : ($percentage < -5 ? 'decreasing' : 'stable'),
            ];
        }

        return $trends;
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
     * Check resource limits.
     */
    public function checkResourceLimits(): array
    {
        $violations = [];
        $limits = $this->getResourceLimits();
        $metrics = $this->getResourceMetrics();

        // Check memory limit
        if (isset($limits['memory_limit']) && $metrics['memory']['current_usage'] > $limits['memory_limit']) {
            $violations[] = [
                'resource' => 'memory',
                'current' => $metrics['memory']['current_usage'],
                'limit' => $limits['memory_limit'],
                'percentage' => ($metrics['memory']['current_usage'] / $limits['memory_limit']) * 100,
                'severity' => 'critical',
            ];
        }

        // Check CPU limit
        if (isset($limits['cpu_limit']) && $metrics['cpu']['current_usage'] > $limits['cpu_limit']) {
            $violations[] = [
                'resource' => 'cpu',
                'current' => $metrics['cpu']['current_usage'],
                'limit' => $limits['cpu_limit'],
                'percentage' => ($metrics['cpu']['current_usage'] / $limits['cpu_limit']) * 100,
                'severity' => 'warning',
            ];
        }

        // Check disk limit
        if (isset($limits['disk_limit']) && $metrics['disk']['read_bytes'] + $metrics['disk']['write_bytes'] > $limits['disk_limit']) {
            $violations[] = [
                'resource' => 'disk',
                'current' => $metrics['disk']['read_bytes'] + $metrics['disk']['write_bytes'],
                'limit' => $limits['disk_limit'],
                'percentage' => (($metrics['disk']['read_bytes'] + $metrics['disk']['write_bytes']) / $limits['disk_limit']) * 100,
                'severity' => 'warning',
            ];
        }

        return $violations;
    }

    /**
     * Get resource alerts.
     */
    public function getResourceAlerts(): array
    {
        $alerts = [];
        $metrics = $this->getResourceMetrics();
        $violations = $this->checkResourceLimits();

        foreach ($violations as $violation) {
            $alerts[] = [
                'type' => 'resource_limit_exceeded',
                'resource' => $violation['resource'],
                'severity' => $violation['severity'],
                'message' => ucfirst($violation['resource']).' usage exceeds limit',
                'current_value' => $violation['current'],
                'limit' => $violation['limit'],
                'percentage' => $violation['percentage'],
                'timestamp' => now()->toISOString(),
            ];
        }

        // Check for memory leaks
        if ($this->detectMemoryLeak()) {
            $alerts[] = [
                'type' => 'memory_leak_detected',
                'severity' => 'critical',
                'message' => 'Potential memory leak detected',
                'timestamp' => now()->toISOString(),
            ];
        }

        // Check for high CPU usage
        if ($metrics['cpu']['current_usage'] > 0.9) {
            $alerts[] = [
                'type' => 'high_cpu_usage',
                'severity' => 'warning',
                'message' => 'CPU usage is very high',
                'current_value' => $metrics['cpu']['current_usage'],
                'threshold' => 0.9,
                'timestamp' => now()->toISOString(),
            ];
        }

        return array_merge($alerts, $this->resourceAlerts);
    }

    /**
     * Add resource alert.
     */
    public function addResourceAlert(array $alert): self
    {
        $this->resourceAlerts[] = $alert;

        return $this;
    }

    /**
     * Get resource efficiency score.
     */
    public function getResourceEfficiencyScore(): float
    {
        $metrics = $this->getResourceMetrics();
        $scores = [];

        // Memory efficiency (lower usage is better)
        $scores['memory'] = 1.0 - $metrics['memory']['memory_efficiency'];

        // CPU efficiency (higher usage can be good if efficient)
        $scores['cpu'] = $metrics['cpu']['cpu_efficiency'];

        // Disk efficiency
        $scores['disk'] = $metrics['disk']['disk_efficiency'];

        // Network efficiency
        $scores['network'] = $metrics['network']['network_efficiency'];

        // Weighted average
        $weights = [
            'memory' => 0.4,
            'cpu' => 0.3,
            'disk' => 0.2,
            'network' => 0.1,
        ];

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($scores as $resource => $score) {
            $totalScore += $score * $weights[$resource];
            $totalWeight += $weights[$resource];
        }

        return $totalWeight > 0 ? $totalScore / $totalWeight : 0;
    }

    /**
     * Export resource data.
     */
    public function exportResourceData(string $format = 'json'): string
    {
        $data = [
            'current_metrics' => $this->getResourceMetrics(),
            'history' => $this->resourceHistory,
            'trends' => $this->getResourceTrends(),
            'limits' => $this->getResourceLimits(),
            'alerts' => $this->getResourceAlerts(),
            'efficiency_score' => $this->getResourceEfficiencyScore(),
        ];

        return match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            'csv' => $this->convertToCsv($data),
            'xml' => $this->convertToXml($data),
            default => json_encode($data, JSON_PRETTY_PRINT),
        };
    }

    // Memory methods

    protected function getCurrentMemoryUsage(): int
    {
        return memory_get_usage(true);
    }

    protected function getPeakMemoryUsage(): int
    {
        return memory_get_peak_usage(true);
    }

    protected function getAverageMemoryUsage(): int
    {
        if (empty($this->resourceHistory)) {
            return $this->getCurrentMemoryUsage();
        }

        $memoryValues = array_column($this->resourceHistory, 'memory.current_usage');

        return (int) (array_sum($memoryValues) / count($memoryValues));
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

    protected function getAvailableMemory(): int
    {
        $limit = $this->getMemoryLimit();
        $usage = $this->getCurrentMemoryUsage();

        return max(0, $limit - $usage);
    }

    protected function detectMemoryLeak(): bool
    {
        if (count($this->resourceHistory) < 10) {
            return false;
        }

        $recent = array_slice($this->resourceHistory, -5);
        $older = array_slice($this->resourceHistory, -10, 5);

        $recentAvg = array_sum(array_column($recent, 'memory.current_usage')) / count($recent);
        $olderAvg = array_sum(array_column($older, 'memory.current_usage')) / count($older);

        // If memory usage is consistently increasing by more than 10%
        return $recentAvg > $olderAvg * 1.1;
    }

    // CPU methods

    protected function getCurrentCpuUsage(): float
    {
        // This would be implemented with system calls
        return 0.0;
    }

    protected function getPeakCpuUsage(): float
    {
        return $this->getCurrentCpuUsage();
    }

    protected function getAverageCpuUsage(): float
    {
        if (empty($this->resourceHistory)) {
            return $this->getCurrentCpuUsage();
        }

        $cpuValues = array_column($this->resourceHistory, 'cpu.current_usage');

        return array_sum($cpuValues) / count($cpuValues);
    }

    protected function getCpuTime(): float
    {
        return $this->getExecutionTime();
    }

    protected function getCpuEfficiency(): float
    {
        return 1.0; // Placeholder
    }

    protected function getCpuCores(): int
    {
        return 1; // Placeholder
    }

    // Disk methods

    protected function getDiskReadBytes(): int
    {
        return 0; // Would be implemented with system calls
    }

    protected function getDiskWriteBytes(): int
    {
        return 0; // Would be implemented with system calls
    }

    protected function getDiskReadOperations(): int
    {
        return 0; // Would be implemented with system calls
    }

    protected function getDiskWriteOperations(): int
    {
        return 0; // Would be implemented with system calls
    }

    protected function getDiskEfficiency(): float
    {
        return 1.0; // Placeholder
    }

    protected function getDiskUsagePercent(): float
    {
        return 0.0; // Would be implemented with system calls
    }

    protected function getFreeDiskSpace(): int
    {
        return 0; // Would be implemented with system calls
    }

    // Network methods

    protected function getNetworkBytesSent(): int
    {
        return 0; // Would be implemented with system calls
    }

    protected function getNetworkBytesReceived(): int
    {
        return 0; // Would be implemented with system calls
    }

    protected function getNetworkConnections(): int
    {
        return 0; // Would be implemented with system calls
    }

    protected function getNetworkEfficiency(): float
    {
        return 1.0; // Placeholder
    }

    protected function getBandwidthUsage(): array
    {
        return [
            'sent' => $this->getNetworkBytesSent(),
            'received' => $this->getNetworkBytesReceived(),
        ];
    }

    // Helper methods

}
