<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Traits;

/**
 * HandlesComprehensiveMonitoring trait provides unified monitoring, analytics, performance measurement, and resource monitoring.
 * This trait combines all monitoring functionality into a single, comprehensive interface.
 */
trait HandlesComprehensiveMonitoring
{
    use HandlesMonitoring, HandlesPerformanceMeasurement, HandlesResourceMonitoring {
        HandlesResourceMonitoring::setResourceLimit insteadof HandlesMonitoring;
        HandlesResourceMonitoring::getResourceLimits insteadof HandlesMonitoring;
    }

    /**
     * Get comprehensive monitoring dashboard.
     */
    public function getComprehensiveDashboard(): array
    {
        return [
            'task_info' => [
                'id' => $this->task?->id,
                'name' => $this->task?->name,
                'status' => $this->task?->status?->value,
            ],
            'monitoring' => [
                'health_status' => $this->getHealthStatus(),
                'monitoring_metrics' => $this->getMonitoringMetrics(),
                'alert_rules' => $this->getAlertRules(),
                'recent_alerts' => $this->getRecentAlerts(),
                'monitoring_history' => array_slice($this->monitoringHistory, -10),
            ],
            'analytics' => [
                'performance_metrics' => $this->getPerformanceMetrics(),
                'resource_metrics' => $this->getResourceMetrics(),
                'execution_breakdown' => $this->getExecutionTimeBreakdown(),
                'efficiency_score' => $this->getEfficiencyScore(),
                'optimization_recommendations' => $this->getOptimizationRecommendations(),
                'bottlenecks' => $this->getBottleneckAnalysis(),
                'cost_analysis' => $this->getCostAnalysis(),
                'performance_alerts' => $this->getPerformanceAlerts(),
                'trends' => $this->getPerformanceTrends(),
            ],
            'performance_measurement' => [
                'measurements' => $this->getMeasurements(),
                'timers' => $this->getTimers(),
                'profiles' => $this->getProfiles(),
                'benchmarks' => $this->getBenchmarks(),
            ],
            'resource_monitoring' => [
                'current_metrics' => $this->getResourceMetrics(),
                'resource_history' => array_slice($this->resourceHistory, -10),
                'resource_trends' => $this->getResourceTrends(),
                'resource_limits' => $this->getResourceLimits(),
                'resource_alerts' => $this->getResourceAlerts(),
                'resource_efficiency_score' => $this->getResourceEfficiencyScore(),
            ],
            'summary' => [
                'overall_health_score' => $this->getOverallHealthScore(),
                'overall_efficiency_score' => $this->getOverallEfficiencyScore(),
                'total_alerts' => count($this->monitoringAlerts) + count($this->performanceAlerts) + count($this->resourceAlerts),
                'critical_alerts' => $this->getCriticalAlertsCount(),
                'timestamp' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Get overall health score combining all monitoring aspects.
     */
    public function getOverallHealthScore(): float
    {
        $scores = [
            'monitoring_health' => $this->getHealthStatus()['overall_health_score'],
            'resource_efficiency' => $this->getResourceEfficiencyScore(),
            'performance_efficiency' => $this->getEfficiencyScore(),
        ];

        // Weighted average
        $weights = [
            'monitoring_health' => 0.4,
            'resource_efficiency' => 0.3,
            'performance_efficiency' => 0.3,
        ];

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($scores as $aspect => $score) {
            $totalScore += $score * $weights[$aspect];
            $totalWeight += $weights[$aspect];
        }

        return $totalWeight > 0 ? $totalScore / $totalWeight : 0;
    }

    /**
     * Get overall efficiency score.
     */
    public function getOverallEfficiencyScore(): float
    {
        $scores = [
            'performance_efficiency' => $this->getEfficiencyScore(),
            'resource_efficiency' => $this->getResourceEfficiencyScore(),
            'memory_efficiency' => 1.0 - $this->getMemoryEfficiency(),
            'cpu_efficiency' => $this->getCpuEfficiency(),
        ];

        // Weighted average
        $weights = [
            'performance_efficiency' => 0.3,
            'resource_efficiency' => 0.3,
            'memory_efficiency' => 0.2,
            'cpu_efficiency' => 0.2,
        ];

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($scores as $aspect => $score) {
            $totalScore += $score * $weights[$aspect];
            $totalWeight += $weights[$aspect];
        }

        return $totalWeight > 0 ? $totalScore / $totalWeight : 0;
    }

    /**
     * Get critical alerts count.
     */
    public function getCriticalAlertsCount(): int
    {
        $criticalCount = 0;

        // Count critical monitoring alerts
        foreach ($this->monitoringAlerts as $alert) {
            if ($alert['severity'] === 'critical') {
                $criticalCount++;
            }
        }

        // Count critical performance alerts
        foreach ($this->performanceAlerts as $alert) {
            if ($alert['severity'] === 'critical') {
                $criticalCount++;
            }
        }

        // Count critical resource alerts
        foreach ($this->resourceAlerts as $alert) {
            if ($alert['severity'] === 'critical') {
                $criticalCount++;
            }
        }

        return $criticalCount;
    }

    /**
     * Get all alerts from all monitoring systems.
     */
    public function getAllAlerts(): array
    {
        return [
            'monitoring_alerts' => $this->monitoringAlerts,
            'performance_alerts' => $this->performanceAlerts,
            'resource_alerts' => $this->resourceAlerts,
            'total_count' => count($this->monitoringAlerts) + count($this->performanceAlerts) + count($this->resourceAlerts),
            'critical_count' => $this->getCriticalAlertsCount(),
        ];
    }

    /**
     * Export comprehensive monitoring data.
     */
    public function exportComprehensiveData(string $format = 'json'): string
    {
        $data = [
            'task_info' => [
                'id' => $this->task?->id,
                'name' => $this->task?->name,
            ],
            'comprehensive_dashboard' => $this->getComprehensiveDashboard(),
            'all_alerts' => $this->getAllAlerts(),
            'export_timestamp' => now()->toISOString(),
        ];

        return match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            'csv' => $this->convertToCsv($data),
            'xml' => $this->convertToXml($data),
            default => json_encode($data, JSON_PRETTY_PRINT),
        };
    }

    /**
     * Start comprehensive monitoring for all aspects.
     */
    public function startComprehensiveMonitoring(): void
    {
        $this->enableMonitoring();
        $this->enableAnalytics();
        $this->startMeasurement('comprehensive_monitoring');
        $this->recordResourceUsage();

        $this->recordMonitoringEvent('comprehensive_monitoring_started');
    }

    /**
     * Stop comprehensive monitoring.
     */
    public function stopComprehensiveMonitoring(): void
    {
        $this->endMeasurement('comprehensive_monitoring');
        $this->recordResourceUsage();

        $this->recordMonitoringEvent('comprehensive_monitoring_stopped');
    }

    /**
     * Get comprehensive monitoring status.
     */
    public function getComprehensiveStatus(): array
    {
        return [
            'monitoring_enabled' => $this->isMonitoringEnabled(),
            'analytics_enabled' => $this->isAnalyticsEnabled(),
            'overall_health_score' => $this->getOverallHealthScore(),
            'overall_efficiency_score' => $this->getOverallEfficiencyScore(),
            'critical_alerts_count' => $this->getCriticalAlertsCount(),
            'total_alerts_count' => count($this->monitoringAlerts) + count($this->performanceAlerts) + count($this->resourceAlerts),
            'status' => $this->getComprehensiveStatusString(),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get comprehensive status string.
     */
    protected function getComprehensiveStatusString(): string
    {
        $healthScore = $this->getOverallHealthScore();
        $criticalAlerts = $this->getCriticalAlertsCount();

        if ($criticalAlerts > 0) {
            return 'critical';
        } elseif ($healthScore < 0.5) {
            return 'unhealthy';
        } elseif ($healthScore < 0.8) {
            return 'warning';
        } else {
            return 'healthy';
        }
    }

    /**
     * Run comprehensive health check.
     */
    public function runComprehensiveHealthCheck(): array
    {
        $results = [
            'monitoring_health' => $this->getHealthStatus(),
            'resource_health' => [
                'violations' => $this->checkResourceLimits(),
                'efficiency' => $this->getResourceEfficiencyScore(),
            ],
            'performance_health' => [
                'efficiency' => $this->getEfficiencyScore(),
                'bottlenecks' => $this->getBottleneckAnalysis(),
            ],
            'overall_health' => [
                'score' => $this->getOverallHealthScore(),
                'status' => $this->getComprehensiveStatusString(),
                'critical_alerts' => $this->getCriticalAlertsCount(),
            ],
            'timestamp' => now()->toISOString(),
        ];

        $this->recordMonitoringEvent('comprehensive_health_check', $results);

        return $results;
    }

    /**
     * Get comprehensive recommendations.
     */
    public function getComprehensiveRecommendations(): array
    {
        $recommendations = [];

        // Performance recommendations
        $performanceRecommendations = $this->getOptimizationRecommendations();
        foreach ($performanceRecommendations as $rec) {
            $recommendations[] = array_merge($rec, ['category' => 'performance']);
        }

        // Resource recommendations
        $resourceViolations = $this->checkResourceLimits();
        foreach ($resourceViolations as $violation) {
            $recommendations[] = [
                'category' => 'resource',
                'type' => 'resource_limit_exceeded',
                'priority' => $violation['severity'] === 'critical' ? 'high' : 'medium',
                'description' => ucfirst($violation['resource']).' usage exceeds limit by '.round($violation['percentage'], 1).'%',
                'impact' => 'high',
                'effort' => 'medium',
            ];
        }

        // Memory leak recommendations
        if ($this->detectMemoryLeak()) {
            $recommendations[] = [
                'category' => 'resource',
                'type' => 'memory_leak',
                'priority' => 'high',
                'description' => 'Potential memory leak detected. Review memory allocation patterns.',
                'impact' => 'high',
                'effort' => 'high',
            ];
        }

        // Sort by priority
        usort($recommendations, function ($a, $b) {
            $priorityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];

            return $priorityOrder[$b['priority']] <=> $priorityOrder[$a['priority']];
        });

        return $recommendations;
    }
}
