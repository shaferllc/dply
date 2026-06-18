<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Contracts\HasAnalytics;
use Tests\TestCase;

uses(TestCase::class);

describe('HasAnalytics Contract', function () {
    describe('interface contract', function () {
        it('defines all required methods', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $methods = $reflection->getMethods();

            $methodNames = array_map(fn ($method) => $method->getName(), $methods);
            expect($methodNames)->toContain(
                'isAnalyticsEnabled',
                'getPerformanceMetrics',
                'getResourceMetrics',
                'getExecutionTimeBreakdown',
                'getOptimizationRecommendations',
                'getPerformanceTrends',
                'getBottleneckAnalysis',
                'getCostAnalysis',
                'getEfficiencyScore',
                'getPerformanceAlerts',
                'recordMetric',
                'startMeasurement',
                'endMeasurement',
                'getPerformanceSummary',
                'exportPerformanceData',
                'compareWithBaseline'
            );
        });

        it('has correct isAnalyticsEnabled method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('isAnalyticsEnabled');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getPerformanceMetrics method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('getPerformanceMetrics');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getResourceMetrics method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('getResourceMetrics');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getExecutionTimeBreakdown method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('getExecutionTimeBreakdown');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getOptimizationRecommendations method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('getOptimizationRecommendations');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getPerformanceTrends method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('getPerformanceTrends');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getBottleneckAnalysis method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('getBottleneckAnalysis');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getCostAnalysis method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('getCostAnalysis');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getEfficiencyScore method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('getEfficiencyScore');

            expect($method->getReturnType()->getName())->toBe('float');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getPerformanceAlerts method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('getPerformanceAlerts');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct recordMetric method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('recordMetric');

            expect($method->getReturnType()->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(3);

            $metricParam = $method->getParameters()[0];
            expect($metricParam->getName())->toBe('metric');
            expect($metricParam->getType()->getName())->toBe('string');

            $valueParam = $method->getParameters()[1];
            expect($valueParam->getName())->toBe('value');
            expect($valueParam->getType()->getName())->toBe('mixed');

            $contextParam = $method->getParameters()[2];
            expect($contextParam->getName())->toBe('context');
            expect($contextParam->getType()->getName())->toBe('array');
            expect($contextParam->isOptional())->toBeTrue();
        });

        it('has correct startMeasurement method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('startMeasurement');

            expect($method->getReturnType()->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(1);

            $measurementParam = $method->getParameters()[0];
            expect($measurementParam->getName())->toBe('measurement');
            expect($measurementParam->getType()->getName())->toBe('string');
        });

        it('has correct endMeasurement method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('endMeasurement');

            expect($method->getReturnType()->getName())->toBe('float');
            expect($method->getParameters())->toHaveCount(1);

            $measurementParam = $method->getParameters()[0];
            expect($measurementParam->getName())->toBe('measurement');
            expect($measurementParam->getType()->getName())->toBe('string');
        });

        it('has correct getPerformanceSummary method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('getPerformanceSummary');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct exportPerformanceData method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('exportPerformanceData');

            expect($method->getReturnType()->getName())->toBe('string');
            expect($method->getParameters())->toHaveCount(1);

            $formatParam = $method->getParameters()[0];
            expect($formatParam->getName())->toBe('format');
            expect($formatParam->getType()->getName())->toBe('string');
            expect($formatParam->isOptional())->toBeTrue();
        });

        it('has correct compareWithBaseline method signature', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $method = $reflection->getMethod('compareWithBaseline');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });
    });

    describe('method accessibility', function () {
        it('ensures all methods are public', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);
            $methods = $reflection->getMethods();

            foreach ($methods as $method) {
                expect($method->isPublic())->toBeTrue();
                expect($method->isAbstract())->toBeTrue();
            }
        });
    });

    describe('return type validation', function () {
        it('validates boolean return types', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);

            $isAnalyticsEnabled = $reflection->getMethod('isAnalyticsEnabled');
            expect($isAnalyticsEnabled->getReturnType()->getName())->toBe('bool');
        });

        it('validates array return types', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);

            $arrayMethods = [
                'getPerformanceMetrics',
                'getResourceMetrics',
                'getExecutionTimeBreakdown',
                'getOptimizationRecommendations',
                'getPerformanceTrends',
                'getBottleneckAnalysis',
                'getCostAnalysis',
                'getPerformanceAlerts',
                'getPerformanceSummary',
                'compareWithBaseline',
            ];

            foreach ($arrayMethods as $methodName) {
                $method = $reflection->getMethod($methodName);
                expect($method->getReturnType()->getName())->toBe('array');
            }
        });

        it('validates float return types', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);

            $getEfficiencyScore = $reflection->getMethod('getEfficiencyScore');
            expect($getEfficiencyScore->getReturnType()->getName())->toBe('float');

            $endMeasurement = $reflection->getMethod('endMeasurement');
            expect($endMeasurement->getReturnType()->getName())->toBe('float');
        });

        it('validates string return types', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);

            $exportPerformanceData = $reflection->getMethod('exportPerformanceData');
            expect($exportPerformanceData->getReturnType()->getName())->toBe('string');
        });

        it('validates void return types', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);

            $recordMetric = $reflection->getMethod('recordMetric');
            expect($recordMetric->getReturnType()->getName())->toBe('void');

            $startMeasurement = $reflection->getMethod('startMeasurement');
            expect($startMeasurement->getReturnType()->getName())->toBe('void');
        });
    });

    describe('parameter validation', function () {
        it('validates string parameters', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);

            $recordMetric = $reflection->getMethod('recordMetric');
            $metricParam = $recordMetric->getParameters()[0];
            expect($metricParam->getType()->getName())->toBe('string');

            $startMeasurement = $reflection->getMethod('startMeasurement');
            $measurementParam = $startMeasurement->getParameters()[0];
            expect($measurementParam->getType()->getName())->toBe('string');

            $endMeasurement = $reflection->getMethod('endMeasurement');
            $measurementParam = $endMeasurement->getParameters()[0];
            expect($measurementParam->getType()->getName())->toBe('string');

            $exportPerformanceData = $reflection->getMethod('exportPerformanceData');
            $formatParam = $exportPerformanceData->getParameters()[0];
            expect($formatParam->getType()->getName())->toBe('string');
        });

        it('validates mixed parameters', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);

            $recordMetric = $reflection->getMethod('recordMetric');
            $valueParam = $recordMetric->getParameters()[1];
            expect($valueParam->getType()->getName())->toBe('mixed');
        });

        it('validates array parameters', function () {
            $reflection = new ReflectionClass(HasAnalytics::class);

            $recordMetric = $reflection->getMethod('recordMetric');
            $contextParam = $recordMetric->getParameters()[2];
            expect($contextParam->getType()->getName())->toBe('array');
        });
    });
});
