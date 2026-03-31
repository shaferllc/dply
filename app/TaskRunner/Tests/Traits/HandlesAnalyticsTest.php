<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Services\AnalyticsService;
use App\Modules\TaskRunner\Traits\HandlesAnalytics;
use Illuminate\Support\Facades\Cache;

describe('HandlesAnalytics Trait (Complex)', function () {
    beforeEach(function () {
        $this->analyticsService = Mockery::mock(AnalyticsService::class);
        app()->instance(AnalyticsService::class, $this->analyticsService);
        Cache::shouldReceive('get')->andReturn([]);
        Cache::shouldReceive('put');
        $this->testClass = new class
        {
            use HandlesAnalytics;

            public $task;

            public function __construct()
            {
                $this->task = (object) [
                    'id' => 1,
                    'name' => 'Test',
                    'created_at' => now(),
                    'updated_at' => now()->addSeconds(10),
                    'status' => null,
                    'exit_code' => 0,
                    'output' => '',
                    'getDuration' => fn () => 10,
                    'getOutput' => fn () => '',
                ];
            }
        };
    });

    it('returns performance and resource metrics', function () {
        $metrics = $this->testClass->getPerformanceMetrics();
        expect($metrics)->toHaveKey('task_id');
        $resource = $this->testClass->getResourceMetrics();
        expect($resource)->toBeArray();
    });

    it('returns bottleneck analysis and efficiency', function () {
        $bottlenecks = $this->testClass->getBottleneckAnalysis();
        expect($bottlenecks)->toBeArray();
        $eff = $this->testClass->getMemoryEfficiency();
        expect($eff)->toBeFloat();
    });

    it('returns trends from cache or AnalyticsService', function () {
        $this->testClass->task->id = 2;
        Cache::shouldReceive('get')->andReturn([]);
        $this->analyticsService->shouldReceive('calculateTrends')->andReturn(['trend' => 1]);
        Cache::shouldReceive('put');
        $trends = $this->testClass->getTrends();
        expect($trends)->toHaveKey('trend');
    });
});
