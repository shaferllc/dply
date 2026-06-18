<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Contracts\TaskDispatcherInterface;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\TaskDispatcher;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskDispatcherInterface Contract', function () {
    describe('interface contract', function () {
        it('defines the required dispatch method', function () {
            $reflection = new ReflectionClass(TaskDispatcherInterface::class);
            $methods = $reflection->getMethods();

            expect($methods)->toHaveCount(1);
            expect($methods[0]->getName())->toBe('dispatch');
        });

        it('has correct method signature', function () {
            $reflection = new ReflectionClass(TaskDispatcherInterface::class);
            $method = $reflection->getMethod('dispatch');

            expect($method->getReturnType()->getName())->toBe(ProcessOutput::class);
            expect($method->getParameters())->toHaveCount(2);

            $commandParam = $method->getParameters()[0];
            expect($commandParam->getName())->toBe('command');
            expect($commandParam->getType()->getName())->toBe('string');
            expect($commandParam->isOptional())->toBeFalse();

            $argumentsParam = $method->getParameters()[1];
            expect($argumentsParam->getName())->toBe('arguments');
            expect($argumentsParam->getType()->getName())->toBe('array');
            expect($argumentsParam->isOptional())->toBeTrue();
            expect($argumentsParam->getDefaultValue())->toBe([]);
        });
    });

    describe('implementation requirements', function () {
        it('TaskDispatcher implements the interface', function () {
            // Test that TaskDispatcher implements the interface
            expect(TaskDispatcher::class)->toImplement(TaskDispatcherInterface::class);
        });
    });

    describe('contract compliance', function () {
        it('validates method accessibility', function () {
            $reflection = new ReflectionClass(TaskDispatcherInterface::class);
            $method = $reflection->getMethod('dispatch');

            expect($method->isPublic())->toBeTrue();
            expect($method->isAbstract())->toBeTrue();
        });
    });
});
