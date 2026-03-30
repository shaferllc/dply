<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Traits\HandlesMonitoring;
use Illuminate\Support\Facades\Log;

describe('HandlesMonitoring Trait (Complex)', function () {
    beforeEach(function () {
        Log::spy();
        $this->testClass = new class
        {
            use HandlesMonitoring;

            public $task;

            public function __construct()
            {
                $this->task = (object) [
                    'status' => TaskStatus::Pending,
                    'created_at' => now(),
                ];
            }

            protected function getResourceUsage(): array
            {
                return ['memory_limit' => 100, 'cpu_limit' => 0.5];
            }

            protected function getMonitoringMetrics(): array
            {
                return ['max_execution_time' => 100, 'max_memory_usage' => 0.5, 'max_cpu_usage' => 0.4, 'max_error_rate' => 0.01, 'min_availability' => 1, 'max_response_time' => 10];
            }
        };
    });

    it('returns health status and performs health check', function () {
        $status = $this->testClass->getHealthStatus();
        expect($status)->toHaveKey('status');
        expect($this->testClass->performHealthCheck())->toBeTrue();
    });

    it('returns monitoring metrics and config', function () {
        $metrics = $this->testClass->getMonitoringMetrics();
        expect($metrics)->toBeArray();
        $config = $this->testClass->getMonitoringConfig();
        expect($config)->toHaveKey('enabled');
    });

    it('checks alerts and triggers processing', function () {
        $alerts = $this->testClass->checkAlerts();
        expect($alerts)->toBeArray();
    });

    it('handles unhealthy task and logs', function () {
        $this->testClass->task->status = TaskStatus::Failed;
        $status = $this->testClass->getHealthStatus();
        expect($status['status'])->toBe('unhealthy');
    });
});
