<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

describe('HasCallbacks Contract', function () {
    describe('interface contract', function () {
        it('defines all required methods', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $methods = $reflection->getMethods();

            $methodNames = array_map(fn ($method) => $method->getName(), $methods);
            expect($methodNames)->toContain(
                'handleCallback',
                'getCallbackUrl',
                'getCallbackData',
                'getCallbackHeaders',
                'getCallbackTimeout',
                'isCallbacksEnabled',
                'getCallbackRetryConfig',
                'validateCallbackData'
            );
        });

        it('has correct handleCallback method signature', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('handleCallback');

            expect($method->getReturnType()->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(3);

            $taskParam = $method->getParameters()[0];
            expect($taskParam->getName())->toBe('task');
            expect($taskParam->getType()->getName())->toBe(Task::class);

            $requestParam = $method->getParameters()[1];
            expect($requestParam->getName())->toBe('request');
            expect($requestParam->getType()->getName())->toBe(Request::class);

            $typeParam = $method->getParameters()[2];
            expect($typeParam->getName())->toBe('type');
            expect($typeParam->getType()->getName())->toBe(CallbackType::class);
        });

        it('has correct getCallbackUrl method signature', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('getCallbackUrl');

            expect($method->getReturnType()->getName())->toBe('string');
            expect($method->getReturnType()->allowsNull())->toBeTrue();
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getCallbackData method signature', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('getCallbackData');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getCallbackHeaders method signature', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('getCallbackHeaders');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getCallbackTimeout method signature', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('getCallbackTimeout');

            expect($method->getReturnType()->getName())->toBe('int');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct isCallbacksEnabled method signature', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('isCallbacksEnabled');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getCallbackRetryConfig method signature', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('getCallbackRetryConfig');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct validateCallbackData method signature', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('validateCallbackData');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(1);

            $dataParam = $method->getParameters()[0];
            expect($dataParam->getName())->toBe('data');
            expect($dataParam->getType()->getName())->toBe('array');
        });
    });

    describe('method accessibility', function () {
        it('ensures all methods are public', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $methods = $reflection->getMethods();

            foreach ($methods as $method) {
                expect($method->isPublic())->toBeTrue();
                expect($method->isAbstract())->toBeTrue();
            }
        });
    });

    describe('callback type handling', function () {
        it('supports all callback types', function () {
            $callbackTypes = CallbackType::cases();

            foreach ($callbackTypes as $callbackType) {
                expect($callbackType)->toBeInstanceOf(CallbackType::class);
                expect($callbackType->value)->toBeString();
                expect($callbackType->getDescription())->toBeString();
            }
        });

        it('validates callback type enum values', function () {
            $expectedValues = [
                'custom', 'timeout', 'failed', 'finished', 'started',
                'progress', 'cancelled', 'paused', 'resumed',
            ];

            $actualValues = array_map(fn ($type) => $type->value, CallbackType::cases());
            expect($actualValues)->toBe($expectedValues);
        });
    });

    describe('contract compliance requirements', function () {
        it('requires Task model compatibility', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('handleCallback');
            $taskParam = $method->getParameters()[0];

            expect($taskParam->getType()->getName())->toBe(Task::class);
        });

        it('requires Request compatibility', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('handleCallback');
            $requestParam = $method->getParameters()[1];

            expect($requestParam->getType()->getName())->toBe(Request::class);
        });

        it('requires CallbackType enum compatibility', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);
            $method = $reflection->getMethod('handleCallback');
            $typeParam = $method->getParameters()[2];

            expect($typeParam->getType()->getName())->toBe(CallbackType::class);
        });
    });

    describe('return type validation', function () {
        it('validates boolean return types', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);

            $isCallbacksEnabled = $reflection->getMethod('isCallbacksEnabled');
            expect($isCallbacksEnabled->getReturnType()->getName())->toBe('bool');

            $validateCallbackData = $reflection->getMethod('validateCallbackData');
            expect($validateCallbackData->getReturnType()->getName())->toBe('bool');
        });

        it('validates array return types', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);

            $getCallbackData = $reflection->getMethod('getCallbackData');
            expect($getCallbackData->getReturnType()->getName())->toBe('array');

            $getCallbackHeaders = $reflection->getMethod('getCallbackHeaders');
            expect($getCallbackHeaders->getReturnType()->getName())->toBe('array');

            $getCallbackRetryConfig = $reflection->getMethod('getCallbackRetryConfig');
            expect($getCallbackRetryConfig->getReturnType()->getName())->toBe('array');
        });

        it('validates integer return types', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);

            $getCallbackTimeout = $reflection->getMethod('getCallbackTimeout');
            expect($getCallbackTimeout->getReturnType()->getName())->toBe('int');
        });

        it('validates nullable string return types', function () {
            $reflection = new ReflectionClass(HasCallbacks::class);

            $getCallbackUrl = $reflection->getMethod('getCallbackUrl');
            expect($getCallbackUrl->getReturnType()->getName())->toBe('string');
            expect($getCallbackUrl->getReturnType()->allowsNull())->toBeTrue();
        });
    });
});
