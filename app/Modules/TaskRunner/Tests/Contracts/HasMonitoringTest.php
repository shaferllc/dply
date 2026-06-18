<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Contracts\HasMonitoring;
use Tests\TestCase;

uses(TestCase::class);

describe('HasMonitoring Contract', function () {
    describe('interface contract', function () {
        it('defines all required methods', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $methods = $reflection->getMethods();

            $methodNames = array_map(fn ($method) => $method->getName(), $methods);
            expect($methodNames)->toContain(
                'isMonitoringEnabled',
                'getHealthStatus',
                'performHealthCheck',
                'getMonitoringMetrics',
                'getAlertRules',
                'checkAlerts',
                'getMonitoringConfig',
                'getPerformanceThresholds',
                'getResourceLimits',
                'getMonitoringHistory',
                'recordMonitoringEvent',
                'setMonitoringConfig',
                'enableMonitoring',
                'disableMonitoring',
                'getMonitoringDashboard',
                'exportMonitoringData',
                'getMonitoringAlerts',
                'acknowledgeAlert',
                'getMonitoringStatus'
            );
        });

        it('has correct isMonitoringEnabled method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('isMonitoringEnabled');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getHealthStatus method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('getHealthStatus');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct performHealthCheck method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('performHealthCheck');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getMonitoringMetrics method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('getMonitoringMetrics');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getAlertRules method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('getAlertRules');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct checkAlerts method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('checkAlerts');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getMonitoringConfig method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('getMonitoringConfig');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getPerformanceThresholds method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('getPerformanceThresholds');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getResourceLimits method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('getResourceLimits');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getMonitoringHistory method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('getMonitoringHistory');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct recordMonitoringEvent method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('recordMonitoringEvent');

            expect($method->getReturnType()->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(2);

            $eventParam = $method->getParameters()[0];
            expect($eventParam->getName())->toBe('event');
            expect($eventParam->getType()->getName())->toBe('string');

            $dataParam = $method->getParameters()[1];
            expect($dataParam->getName())->toBe('data');
            expect($dataParam->getType()->getName())->toBe('array');
            expect($dataParam->isOptional())->toBeTrue();
        });

        it('has correct setMonitoringConfig method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('setMonitoringConfig');

            expect($method->getReturnType()->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(1);

            $configParam = $method->getParameters()[0];
            expect($configParam->getName())->toBe('config');
            expect($configParam->getType()->getName())->toBe('array');
        });

        it('has correct enableMonitoring method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('enableMonitoring');

            expect($method->getReturnType()->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct disableMonitoring method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('disableMonitoring');

            expect($method->getReturnType()->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getMonitoringDashboard method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('getMonitoringDashboard');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct exportMonitoringData method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('exportMonitoringData');

            expect($method->getReturnType()->getName())->toBe('string');
            expect($method->getParameters())->toHaveCount(1);

            $formatParam = $method->getParameters()[0];
            expect($formatParam->getName())->toBe('format');
            expect($formatParam->getType()->getName())->toBe('string');
            expect($formatParam->isOptional())->toBeTrue();
        });

        it('has correct getMonitoringAlerts method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('getMonitoringAlerts');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct acknowledgeAlert method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('acknowledgeAlert');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(1);

            $alertIdParam = $method->getParameters()[0];
            expect($alertIdParam->getName())->toBe('alertId');
            expect($alertIdParam->getType()->getName())->toBe('string');
        });

        it('has correct getMonitoringStatus method signature', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $method = $reflection->getMethod('getMonitoringStatus');

            expect($method->getReturnType()->getName())->toBe('string');
            expect($method->getParameters())->toHaveCount(0);
        });
    });

    describe('method accessibility', function () {
        it('ensures all methods are public', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);
            $methods = $reflection->getMethods();

            foreach ($methods as $method) {
                expect($method->isPublic())->toBeTrue();
                expect($method->isAbstract())->toBeTrue();
            }
        });
    });

    describe('return type validation', function () {
        it('validates boolean return types', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);

            $isMonitoringEnabled = $reflection->getMethod('isMonitoringEnabled');
            expect($isMonitoringEnabled->getReturnType()->getName())->toBe('bool');

            $performHealthCheck = $reflection->getMethod('performHealthCheck');
            expect($performHealthCheck->getReturnType()->getName())->toBe('bool');

            $acknowledgeAlert = $reflection->getMethod('acknowledgeAlert');
            expect($acknowledgeAlert->getReturnType()->getName())->toBe('bool');
        });

        it('validates array return types', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);

            $arrayMethods = [
                'getHealthStatus',
                'getMonitoringMetrics',
                'getAlertRules',
                'checkAlerts',
                'getMonitoringConfig',
                'getPerformanceThresholds',
                'getResourceLimits',
                'getMonitoringHistory',
                'getMonitoringDashboard',
                'getMonitoringAlerts',
            ];

            foreach ($arrayMethods as $methodName) {
                $method = $reflection->getMethod($methodName);
                expect($method->getReturnType()->getName())->toBe('array');
            }
        });

        it('validates string return types', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);

            $exportMonitoringData = $reflection->getMethod('exportMonitoringData');
            expect($exportMonitoringData->getReturnType()->getName())->toBe('string');

            $getMonitoringStatus = $reflection->getMethod('getMonitoringStatus');
            expect($getMonitoringStatus->getReturnType()->getName())->toBe('string');
        });

        it('validates void return types', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);

            $recordMonitoringEvent = $reflection->getMethod('recordMonitoringEvent');
            expect($recordMonitoringEvent->getReturnType()->getName())->toBe('void');

            $setMonitoringConfig = $reflection->getMethod('setMonitoringConfig');
            expect($setMonitoringConfig->getReturnType()->getName())->toBe('void');

            $enableMonitoring = $reflection->getMethod('enableMonitoring');
            expect($enableMonitoring->getReturnType()->getName())->toBe('void');

            $disableMonitoring = $reflection->getMethod('disableMonitoring');
            expect($disableMonitoring->getReturnType()->getName())->toBe('void');
        });
    });

    describe('parameter validation', function () {
        it('validates string parameters', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);

            $recordMonitoringEvent = $reflection->getMethod('recordMonitoringEvent');
            $eventParam = $recordMonitoringEvent->getParameters()[0];
            expect($eventParam->getType()->getName())->toBe('string');

            $acknowledgeAlert = $reflection->getMethod('acknowledgeAlert');
            $alertIdParam = $acknowledgeAlert->getParameters()[0];
            expect($alertIdParam->getType()->getName())->toBe('string');

            $exportMonitoringData = $reflection->getMethod('exportMonitoringData');
            $formatParam = $exportMonitoringData->getParameters()[0];
            expect($formatParam->getType()->getName())->toBe('string');
        });

        it('validates array parameters', function () {
            $reflection = new ReflectionClass(HasMonitoring::class);

            $recordMonitoringEvent = $reflection->getMethod('recordMonitoringEvent');
            $dataParam = $recordMonitoringEvent->getParameters()[1];
            expect($dataParam->getType()->getName())->toBe('array');

            $setMonitoringConfig = $reflection->getMethod('setMonitoringConfig');
            $configParam = $setMonitoringConfig->getParameters()[0];
            expect($configParam->getType()->getName())->toBe('array');
        });
    });
});
