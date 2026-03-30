<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Contracts\HasRollback;
use Tests\TestCase;

uses(TestCase::class);

describe('HasRollback Contract', function () {
    describe('interface contract', function () {
        it('defines all required methods', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $methods = $reflection->getMethods();

            $methodNames = array_map(fn ($method) => $method->getName(), $methods);
            expect($methodNames)->toContain(
                'supportsRollback',
                'isRollbackRequired',
                'getRollbackScript',
                'getRollbackTimeout',
                'getRollbackDependencies',
                'getRollbackSafetyChecks',
                'getRollbackData',
                'validateRollback',
                'createRollbackCheckpoint',
                'executeRollback',
                'getRollbackHistory',
                'isRecoveryPossible',
                'getRecoveryOptions',
                'executeRecovery'
            );
        });

        it('has correct supportsRollback method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('supportsRollback');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct isRollbackRequired method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('isRollbackRequired');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getRollbackScript method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('getRollbackScript');

            expect($method->getReturnType()->getName())->toBe('string');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getRollbackTimeout method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('getRollbackTimeout');

            expect($method->getReturnType()->getName())->toBe('int');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getRollbackDependencies method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('getRollbackDependencies');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getRollbackSafetyChecks method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('getRollbackSafetyChecks');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getRollbackData method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('getRollbackData');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct validateRollback method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('validateRollback');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct createRollbackCheckpoint method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('createRollbackCheckpoint');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct executeRollback method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('executeRollback');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(1);

            $reasonParam = $method->getParameters()[0];
            expect($reasonParam->getName())->toBe('reason');
            expect($reasonParam->getType()->getName())->toBe('string');
            expect($reasonParam->allowsNull())->toBeTrue();
            expect($reasonParam->isOptional())->toBeTrue();
        });

        it('has correct getRollbackHistory method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('getRollbackHistory');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct isRecoveryPossible method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('isRecoveryPossible');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct getRecoveryOptions method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('getRecoveryOptions');

            expect($method->getReturnType()->getName())->toBe('array');
            expect($method->getParameters())->toHaveCount(0);
        });

        it('has correct executeRecovery method signature', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $method = $reflection->getMethod('executeRecovery');

            expect($method->getReturnType()->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(1);

            $recoveryTypeParam = $method->getParameters()[0];
            expect($recoveryTypeParam->getName())->toBe('recoveryType');
            expect($recoveryTypeParam->getType()->getName())->toBe('string');
        });
    });

    describe('method accessibility', function () {
        it('ensures all methods are public', function () {
            $reflection = new ReflectionClass(HasRollback::class);
            $methods = $reflection->getMethods();

            foreach ($methods as $method) {
                expect($method->isPublic())->toBeTrue();
                expect($method->isAbstract())->toBeTrue();
            }
        });
    });

    describe('return type validation', function () {
        it('validates boolean return types', function () {
            $reflection = new ReflectionClass(HasRollback::class);

            $booleanMethods = [
                'supportsRollback',
                'isRollbackRequired',
                'validateRollback',
                'createRollbackCheckpoint',
                'executeRollback',
                'isRecoveryPossible',
                'executeRecovery',
            ];

            foreach ($booleanMethods as $methodName) {
                $method = $reflection->getMethod($methodName);
                expect($method->getReturnType()->getName())->toBe('bool');
            }
        });

        it('validates array return types', function () {
            $reflection = new ReflectionClass(HasRollback::class);

            $arrayMethods = [
                'getRollbackDependencies',
                'getRollbackSafetyChecks',
                'getRollbackData',
                'getRollbackHistory',
                'getRecoveryOptions',
            ];

            foreach ($arrayMethods as $methodName) {
                $method = $reflection->getMethod($methodName);
                expect($method->getReturnType()->getName())->toBe('array');
            }
        });

        it('validates string return types', function () {
            $reflection = new ReflectionClass(HasRollback::class);

            $getRollbackScript = $reflection->getMethod('getRollbackScript');
            expect($getRollbackScript->getReturnType()->getName())->toBe('string');
        });

        it('validates integer return types', function () {
            $reflection = new ReflectionClass(HasRollback::class);

            $getRollbackTimeout = $reflection->getMethod('getRollbackTimeout');
            expect($getRollbackTimeout->getReturnType()->getName())->toBe('int');
        });
    });

    describe('parameter validation', function () {
        it('validates string parameters', function () {
            $reflection = new ReflectionClass(HasRollback::class);

            $executeRecovery = $reflection->getMethod('executeRecovery');
            $recoveryTypeParam = $executeRecovery->getParameters()[0];
            expect($recoveryTypeParam->getType()->getName())->toBe('string');
        });

        it('validates nullable string parameters', function () {
            $reflection = new ReflectionClass(HasRollback::class);

            $executeRollback = $reflection->getMethod('executeRollback');
            $reasonParam = $executeRollback->getParameters()[0];
            expect($reasonParam->getType()->getName())->toBe('string');
            expect($reasonParam->allowsNull())->toBeTrue();
            expect($reasonParam->isOptional())->toBeTrue();
        });
    });

    describe('contract semantics', function () {
        it('defines rollback support methods', function () {
            $reflection = new ReflectionClass(HasRollback::class);

            $supportsRollback = $reflection->getMethod('supportsRollback');
            expect($supportsRollback->getReturnType()->getName())->toBe('bool');

            $isRollbackRequired = $reflection->getMethod('isRollbackRequired');
            expect($isRollbackRequired->getReturnType()->getName())->toBe('bool');
        });

        it('defines rollback execution methods', function () {
            $reflection = new ReflectionClass(HasRollback::class);

            $validateRollback = $reflection->getMethod('validateRollback');
            expect($validateRollback->getReturnType()->getName())->toBe('bool');

            $createRollbackCheckpoint = $reflection->getMethod('createRollbackCheckpoint');
            expect($createRollbackCheckpoint->getReturnType()->getName())->toBe('bool');

            $executeRollback = $reflection->getMethod('executeRollback');
            expect($executeRollback->getReturnType()->getName())->toBe('bool');
        });

        it('defines recovery methods', function () {
            $reflection = new ReflectionClass(HasRollback::class);

            $isRecoveryPossible = $reflection->getMethod('isRecoveryPossible');
            expect($isRecoveryPossible->getReturnType()->getName())->toBe('bool');

            $getRecoveryOptions = $reflection->getMethod('getRecoveryOptions');
            expect($getRecoveryOptions->getReturnType()->getName())->toBe('array');

            $executeRecovery = $reflection->getMethod('executeRecovery');
            expect($executeRecovery->getReturnType()->getName())->toBe('bool');
        });
    });
});
