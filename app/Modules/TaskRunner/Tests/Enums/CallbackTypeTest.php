<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Enums\CallbackType;
use Tests\TestCase;

uses(TestCase::class);

describe('CallbackType Enum', function () {
    describe('enum cases', function () {
        it('has all required cases', function () {
            expect(CallbackType::cases())->toHaveCount(9);

            expect(CallbackType::Custom)->toBeInstanceOf(CallbackType::class);
            expect(CallbackType::Timeout)->toBeInstanceOf(CallbackType::class);
            expect(CallbackType::Failed)->toBeInstanceOf(CallbackType::class);
            expect(CallbackType::Finished)->toBeInstanceOf(CallbackType::class);
            expect(CallbackType::Started)->toBeInstanceOf(CallbackType::class);
            expect(CallbackType::Progress)->toBeInstanceOf(CallbackType::class);
            expect(CallbackType::Cancelled)->toBeInstanceOf(CallbackType::class);
            expect(CallbackType::Paused)->toBeInstanceOf(CallbackType::class);
            expect(CallbackType::Resumed)->toBeInstanceOf(CallbackType::class);
        });

        it('has correct string values', function () {
            expect(CallbackType::Custom->value)->toBe('custom');
            expect(CallbackType::Timeout->value)->toBe('timeout');
            expect(CallbackType::Failed->value)->toBe('failed');
            expect(CallbackType::Finished->value)->toBe('finished');
            expect(CallbackType::Started->value)->toBe('started');
            expect(CallbackType::Progress->value)->toBe('progress');
            expect(CallbackType::Cancelled->value)->toBe('cancelled');
            expect(CallbackType::Paused->value)->toBe('paused');
            expect(CallbackType::Resumed->value)->toBe('resumed');
        });
    });

    describe('getDescription method', function () {
        it('returns correct descriptions for all callback types', function () {
            expect(CallbackType::Custom->getDescription())->toBe('Custom callback');
            expect(CallbackType::Timeout->getDescription())->toBe('Task timed out');
            expect(CallbackType::Failed->getDescription())->toBe('Task failed');
            expect(CallbackType::Finished->getDescription())->toBe('Task finished successfully');
            expect(CallbackType::Started->getDescription())->toBe('Task started execution');
            expect(CallbackType::Progress->getDescription())->toBe('Task progress update');
            expect(CallbackType::Cancelled->getDescription())->toBe('Task was cancelled');
            expect(CallbackType::Paused->getDescription())->toBe('Task was paused');
            expect(CallbackType::Resumed->getDescription())->toBe('Task was resumed');
        });
    });

    describe('getHttpMethod method', function () {
        it('returns POST for all callback types', function () {
            foreach (CallbackType::cases() as $callbackType) {
                expect($callbackType->getHttpMethod())->toBe('POST');
            }
        });
    });

    describe('isCompletion method', function () {
        it('returns true for completion callback types', function () {
            expect(CallbackType::Finished->isCompletion())->toBeTrue();
            expect(CallbackType::Failed->isCompletion())->toBeTrue();
            expect(CallbackType::Timeout->isCompletion())->toBeTrue();
            expect(CallbackType::Cancelled->isCompletion())->toBeTrue();
        });

        it('returns false for non-completion callback types', function () {
            expect(CallbackType::Custom->isCompletion())->toBeFalse();
            expect(CallbackType::Started->isCompletion())->toBeFalse();
            expect(CallbackType::Progress->isCompletion())->toBeFalse();
            expect(CallbackType::Paused->isCompletion())->toBeFalse();
            expect(CallbackType::Resumed->isCompletion())->toBeFalse();
        });
    });

    describe('isFailure method', function () {
        it('returns true for failure callback types', function () {
            expect(CallbackType::Failed->isFailure())->toBeTrue();
            expect(CallbackType::Timeout->isFailure())->toBeTrue();
        });

        it('returns false for non-failure callback types', function () {
            expect(CallbackType::Custom->isFailure())->toBeFalse();
            expect(CallbackType::Finished->isFailure())->toBeFalse();
            expect(CallbackType::Started->isFailure())->toBeFalse();
            expect(CallbackType::Progress->isFailure())->toBeFalse();
            expect(CallbackType::Cancelled->isFailure())->toBeFalse();
            expect(CallbackType::Paused->isFailure())->toBeFalse();
            expect(CallbackType::Resumed->isFailure())->toBeFalse();
        });
    });

    describe('isSuccess method', function () {
        it('returns true only for finished callback type', function () {
            expect(CallbackType::Finished->isSuccess())->toBeTrue();
        });

        it('returns false for all other callback types', function () {
            expect(CallbackType::Custom->isSuccess())->toBeFalse();
            expect(CallbackType::Timeout->isSuccess())->toBeFalse();
            expect(CallbackType::Failed->isSuccess())->toBeFalse();
            expect(CallbackType::Started->isSuccess())->toBeFalse();
            expect(CallbackType::Progress->isSuccess())->toBeFalse();
            expect(CallbackType::Cancelled->isSuccess())->toBeFalse();
            expect(CallbackType::Paused->isSuccess())->toBeFalse();
            expect(CallbackType::Resumed->isSuccess())->toBeFalse();
        });
    });

    describe('isLifecycle method', function () {
        it('returns true for lifecycle callback types', function () {
            expect(CallbackType::Started->isLifecycle())->toBeTrue();
            expect(CallbackType::Paused->isLifecycle())->toBeTrue();
            expect(CallbackType::Resumed->isLifecycle())->toBeTrue();
            expect(CallbackType::Cancelled->isLifecycle())->toBeTrue();
        });

        it('returns false for non-lifecycle callback types', function () {
            expect(CallbackType::Custom->isLifecycle())->toBeFalse();
            expect(CallbackType::Timeout->isLifecycle())->toBeFalse();
            expect(CallbackType::Failed->isLifecycle())->toBeFalse();
            expect(CallbackType::Finished->isLifecycle())->toBeFalse();
            expect(CallbackType::Progress->isLifecycle())->toBeFalse();
        });
    });

    describe('getPriority method', function () {
        it('returns correct priority levels for all callback types', function () {
            // High priority (1)
            expect(CallbackType::Failed->getPriority())->toBe(1);
            expect(CallbackType::Timeout->getPriority())->toBe(1);

            // Medium priority (2)
            expect(CallbackType::Finished->getPriority())->toBe(2);
            expect(CallbackType::Cancelled->getPriority())->toBe(2);

            // Normal priority (3)
            expect(CallbackType::Started->getPriority())->toBe(3);
            expect(CallbackType::Progress->getPriority())->toBe(3);

            // Low priority (4)
            expect(CallbackType::Paused->getPriority())->toBe(4);
            expect(CallbackType::Resumed->getPriority())->toBe(4);
            expect(CallbackType::Custom->getPriority())->toBe(4);
        });

        it('groups callback types by priority correctly', function () {
            $highPriority = array_filter(CallbackType::cases(), fn ($type) => $type->getPriority() === 1);
            $mediumPriority = array_filter(CallbackType::cases(), fn ($type) => $type->getPriority() === 2);
            $normalPriority = array_filter(CallbackType::cases(), fn ($type) => $type->getPriority() === 3);
            $lowPriority = array_filter(CallbackType::cases(), fn ($type) => $type->getPriority() === 4);

            expect($highPriority)->toHaveCount(2);
            expect($mediumPriority)->toHaveCount(2);
            expect($normalPriority)->toHaveCount(2);
            expect($lowPriority)->toHaveCount(3);
        });
    });

    describe('callback type categorization', function () {
        it('correctly categorizes completion callbacks', function () {
            $completionCallbacks = array_filter(CallbackType::cases(), fn ($type) => $type->isCompletion());
            expect($completionCallbacks)->toHaveCount(4);

            $completionValues = array_map(fn ($type) => $type->value, $completionCallbacks);
            expect($completionValues)->toContain('finished', 'failed', 'timeout', 'cancelled');
        });

        it('correctly categorizes failure callbacks', function () {
            $failureCallbacks = array_filter(CallbackType::cases(), fn ($type) => $type->isFailure());
            expect($failureCallbacks)->toHaveCount(2);

            $failureValues = array_map(fn ($type) => $type->value, $failureCallbacks);
            expect($failureValues)->toContain('failed', 'timeout');
        });

        it('correctly categorizes lifecycle callbacks', function () {
            $lifecycleCallbacks = array_filter(CallbackType::cases(), fn ($type) => $type->isLifecycle());
            expect($lifecycleCallbacks)->toHaveCount(4);

            $lifecycleValues = array_map(fn ($type) => $type->value, $lifecycleCallbacks);
            expect($lifecycleValues)->toContain('started', 'paused', 'resumed', 'cancelled');
        });
    });

    describe('enum behavior', function () {
        it('can be compared directly', function () {
            expect(CallbackType::Custom)->toBe(CallbackType::Custom);
            expect(CallbackType::Finished)->not->toBe(CallbackType::Failed);
        });

        it('can be used in switch statements', function () {
            $callbackType = CallbackType::Finished;
            $result = match ($callbackType) {
                CallbackType::Custom => 'custom',
                CallbackType::Finished => 'success',
                CallbackType::Failed => 'error',
                default => 'unknown',
            };

            expect($result)->toBe('success');
        });

        it('can be serialized to string', function () {
            expect(CallbackType::Custom->value)->toBe('custom');
            expect(CallbackType::Finished->value)->toBe('finished');
        });

        it('can be created from string value', function () {
            expect(CallbackType::from('custom'))->toBe(CallbackType::Custom);
            expect(CallbackType::from('finished'))->toBe(CallbackType::Finished);
        });

        it('throws exception for invalid string value', function () {
            expect(fn () => CallbackType::from('invalid'))->toThrow(ValueError::class);
        });

        it('can be safely created with tryFrom', function () {
            expect(CallbackType::tryFrom('custom'))->toBe(CallbackType::Custom);
            expect(CallbackType::tryFrom('invalid'))->toBeNull();
        });
    });

    describe('priority ordering', function () {
        it('can be sorted by priority', function () {
            $sortedCallbacks = CallbackType::cases();
            usort($sortedCallbacks, fn ($a, $b) => $a->getPriority() <=> $b->getPriority());

            // Check that high priority callbacks come first
            expect($sortedCallbacks[0]->getPriority())->toBe(1);
            expect($sortedCallbacks[1]->getPriority())->toBe(1);

            // Check that low priority callbacks come last
            $lastIndex = count($sortedCallbacks) - 1;
            expect($sortedCallbacks[$lastIndex]->getPriority())->toBe(4);
        });

        it('maintains consistent priority ordering', function () {
            $callbacks = CallbackType::cases();
            $priorities = array_map(fn ($type) => $type->getPriority(), $callbacks);

            // All priorities should be between 1 and 4
            expect(min($priorities))->toBe(1);
            expect(max($priorities))->toBe(4);
        });
    });
});
