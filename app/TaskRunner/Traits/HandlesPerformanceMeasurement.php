<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Traits;

/**
 * HandlesPerformanceMeasurement trait provides detailed performance measurement and timing functionality.
 * Focuses on micro-benchmarking, timing breakdowns, and performance profiling.
 */
trait HandlesPerformanceMeasurement
{
    /**
     * Performance measurement properties.
     */
    protected array $measurements = [];

    protected array $timers = [];

    protected array $profiles = [];

    protected array $benchmarks = [];

    /**
     * Start performance measurement.
     */
    public function startMeasurement(string $measurement): void
    {
        $this->measurements[$measurement] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(),
            'start_peak_memory' => memory_get_peak_usage(),
        ];
    }

    /**
     * End performance measurement.
     */
    public function endMeasurement(string $measurement): float
    {
        if (! isset($this->measurements[$measurement])) {
            return 0.0;
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $endPeakMemory = memory_get_peak_usage();

        $duration = $endTime - $this->measurements[$measurement]['start_time'];
        $memoryUsed = $endMemory - $this->measurements[$measurement]['start_memory'];
        $peakMemoryIncrease = $endPeakMemory - $this->measurements[$measurement]['start_peak_memory'];

        $this->measurements[$measurement]['end_time'] = $endTime;
        $this->measurements[$measurement]['duration'] = $duration;
        $this->measurements[$measurement]['memory_used'] = $memoryUsed;
        $this->measurements[$measurement]['peak_memory_increase'] = $peakMemoryIncrease;

        return $duration;
    }

    /**
     * Start a timer.
     */
    public function startTimer(string $timerName): void
    {
        $this->timers[$timerName] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(),
        ];
    }

    /**
     * End a timer and get duration.
     */
    public function endTimer(string $timerName): float
    {
        if (! isset($this->timers[$timerName])) {
            return 0.0;
        }

        $endTime = microtime(true);
        $duration = $endTime - $this->timers[$timerName]['start_time'];

        $this->timers[$timerName]['end_time'] = $endTime;
        $this->timers[$timerName]['duration'] = $duration;

        return $duration;
    }

    /**
     * Get timer duration without ending it.
     */
    public function getTimerDuration(string $timerName): float
    {
        if (! isset($this->timers[$timerName])) {
            return 0.0;
        }

        $currentTime = microtime(true);

        return $currentTime - $this->timers[$timerName]['start_time'];
    }

    /**
     * Start profiling a section of code.
     */
    public function startProfile(string $profileName): void
    {
        $this->profiles[$profileName] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(),
            'start_peak_memory' => memory_get_peak_usage(),
            'calls' => ($this->profiles[$profileName]['calls'] ?? 0) + 1,
        ];
    }

    /**
     * End profiling and get results.
     */
    public function endProfile(string $profileName): array
    {
        if (! isset($this->profiles[$profileName])) {
            return [];
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $endPeakMemory = memory_get_peak_usage();

        $duration = $endTime - $this->profiles[$profileName]['start_time'];
        $memoryUsed = $endMemory - $this->profiles[$profileName]['start_memory'];
        $peakMemoryIncrease = $endPeakMemory - $this->profiles[$profileName]['start_peak_memory'];

        $profile = [
            'name' => $profileName,
            'duration' => $duration,
            'memory_used' => $memoryUsed,
            'peak_memory_increase' => $peakMemoryIncrease,
            'calls' => $this->profiles[$profileName]['calls'],
            'average_duration' => $duration / $this->profiles[$profileName]['calls'],
            'timestamp' => now()->toISOString(),
        ];

        $this->profiles[$profileName]['last_result'] = $profile;

        return $profile;
    }

    /**
     * Run a benchmark with multiple iterations.
     */
    public function runBenchmark(string $benchmarkName, callable $callback, int $iterations = 1000): array
    {
        $this->startMeasurement($benchmarkName);

        $results = [];
        $totalTime = 0;
        $totalMemory = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            $startMemory = memory_get_usage();

            $result = $callback();

            $endTime = microtime(true);
            $endMemory = memory_get_usage();

            $duration = $endTime - $startTime;
            $memoryUsed = $endMemory - $startMemory;

            $results[] = [
                'iteration' => $i + 1,
                'duration' => $duration,
                'memory_used' => $memoryUsed,
                'result' => $result,
            ];

            $totalTime += $duration;
            $totalMemory += $memoryUsed;
        }

        $this->endMeasurement($benchmarkName);

        $benchmark = [
            'name' => $benchmarkName,
            'iterations' => $iterations,
            'total_time' => $totalTime,
            'total_memory' => $totalMemory,
            'average_time' => $totalTime / $iterations,
            'average_memory' => $totalMemory / $iterations,
            'min_time' => min(array_column($results, 'duration')),
            'max_time' => max(array_column($results, 'duration')),
            'min_memory' => min(array_column($results, 'memory_used')),
            'max_memory' => max(array_column($results, 'memory_used')),
            'results' => $results,
            'timestamp' => now()->toISOString(),
        ];

        $this->benchmarks[$benchmarkName] = $benchmark;

        return $benchmark;
    }

    /**
     * Get all measurements.
     */
    public function getMeasurements(): array
    {
        return $this->measurements;
    }

    /**
     * Get all timers.
     */
    public function getTimers(): array
    {
        return $this->timers;
    }

    /**
     * Get all profiles.
     */
    public function getProfiles(): array
    {
        return $this->profiles;
    }

    /**
     * Get all benchmarks.
     */
    public function getBenchmarks(): array
    {
        return $this->benchmarks;
    }

    /**
     * Get measurement by name.
     */
    public function getMeasurement(string $measurementName): ?array
    {
        return $this->measurements[$measurementName] ?? null;
    }

    /**
     * Get timer by name.
     */
    public function getTimer(string $timerName): ?array
    {
        return $this->timers[$timerName] ?? null;
    }

    /**
     * Get profile by name.
     */
    public function getProfile(string $profileName): ?array
    {
        return $this->profiles[$profileName] ?? null;
    }

    /**
     * Get benchmark by name.
     */
    public function getBenchmark(string $benchmarkName): ?array
    {
        return $this->benchmarks[$benchmarkName] ?? null;
    }

    /**
     * Clear all measurements.
     */
    public function clearMeasurements(): void
    {
        $this->measurements = [];
    }

    /**
     * Clear all timers.
     */
    public function clearTimers(): void
    {
        $this->timers = [];
    }

    /**
     * Clear all profiles.
     */
    public function clearProfiles(): void
    {
        $this->profiles = [];
    }

    /**
     * Clear all benchmarks.
     */
    public function clearBenchmarks(): void
    {
        $this->benchmarks = [];
    }

    /**
     * Get performance summary.
     */
    public function getPerformanceSummary(): array
    {
        return [
            'measurements' => $this->measurements,
            'timers' => $this->timers,
            'profiles' => $this->profiles,
            'benchmarks' => $this->benchmarks,
            'total_measurements' => count($this->measurements),
            'total_timers' => count($this->timers),
            'total_profiles' => count($this->profiles),
            'total_benchmarks' => count($this->benchmarks),
        ];
    }

    /**
     * Export performance data.
     */
    public function exportPerformanceData(string $format = 'json'): string
    {
        $data = $this->getPerformanceSummary();

        return match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            'csv' => $this->convertToCsv($data),
            'xml' => $this->convertToXml($data),
            default => json_encode($data, JSON_PRETTY_PRINT),
        };
    }

    /**
     * Get execution time breakdown.
     */
    public function getExecutionTimeBreakdown(): array
    {
        $totalTime = $this->getExecutionTime();

        return [
            'total_execution_time' => $totalTime,
            'initialization_time' => $this->getInitializationTime(),
            'processing_time' => $this->getProcessingTime(),
            'cleanup_time' => $this->getCleanupTime(),
            'wait_time' => $this->getWaitTime(),
            'overhead_time' => $this->getOverheadTime(),
            'breakdown_percentage' => [
                'initialization' => $this->calculatePercentage($this->getInitializationTime(), $totalTime),
                'processing' => $this->calculatePercentage($this->getProcessingTime(), $totalTime),
                'cleanup' => $this->calculatePercentage($this->getCleanupTime(), $totalTime),
                'wait' => $this->calculatePercentage($this->getWaitTime(), $totalTime),
                'overhead' => $this->calculatePercentage($this->getOverheadTime(), $totalTime),
            ],
        ];
    }

    // Helper methods that can be overridden by other traits

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

    protected function calculatePercentage(float $part, float $total): float
    {
        return $total > 0 ? ($part / $total) * 100 : 0;
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
