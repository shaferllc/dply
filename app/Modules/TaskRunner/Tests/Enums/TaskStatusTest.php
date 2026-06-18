<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Enums\TaskStatus;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskStatus Enum', function () {
    describe('enum cases', function () {
        it('has all required cases', function () {
            expect(TaskStatus::cases())->toHaveCount(8);

            expect(TaskStatus::Pending)->toBeInstanceOf(TaskStatus::class);
            expect(TaskStatus::Running)->toBeInstanceOf(TaskStatus::class);
            expect(TaskStatus::Finished)->toBeInstanceOf(TaskStatus::class);
            expect(TaskStatus::Failed)->toBeInstanceOf(TaskStatus::class);
            expect(TaskStatus::Timeout)->toBeInstanceOf(TaskStatus::class);
            expect(TaskStatus::Cancelled)->toBeInstanceOf(TaskStatus::class);
            expect(TaskStatus::UploadFailed)->toBeInstanceOf(TaskStatus::class);
            expect(TaskStatus::ConnectionFailed)->toBeInstanceOf(TaskStatus::class);
        });

        it('has correct string values', function () {
            expect(TaskStatus::Pending->value)->toBe('pending');
            expect(TaskStatus::Running->value)->toBe('running');
            expect(TaskStatus::Finished->value)->toBe('finished');
            expect(TaskStatus::Failed->value)->toBe('failed');
            expect(TaskStatus::Timeout->value)->toBe('timeout');
            expect(TaskStatus::Cancelled->value)->toBe('cancelled');
            expect(TaskStatus::UploadFailed->value)->toBe('upload_failed');
            expect(TaskStatus::ConnectionFailed->value)->toBe('connection_failed');
        });
    });

    describe('getDescription method', function () {
        it('returns correct descriptions for all statuses', function () {
            expect(TaskStatus::Pending->getDescription())->toBe('Task is waiting to be executed');
            expect(TaskStatus::Running->getDescription())->toBe('Task is currently executing');
            expect(TaskStatus::Finished->getDescription())->toBe('Task completed successfully');
            expect(TaskStatus::Failed->getDescription())->toBe('Task failed during execution');
            expect(TaskStatus::Timeout->getDescription())->toBe('Task timed out');
            expect(TaskStatus::Cancelled->getDescription())->toBe('Task was cancelled');
            expect(TaskStatus::UploadFailed->getDescription())->toBe('Task file upload failed');
            expect(TaskStatus::ConnectionFailed->getDescription())->toBe('Task connection failed');
        });
    });

    describe('getCssClass method', function () {
        it('returns correct CSS classes for all statuses', function () {
            expect(TaskStatus::Pending->getCssClass())->toBe('status-pending');
            expect(TaskStatus::Running->getCssClass())->toBe('status-running');
            expect(TaskStatus::Finished->getCssClass())->toBe('status-finished');
            expect(TaskStatus::Failed->getCssClass())->toBe('status-failed');
            expect(TaskStatus::Timeout->getCssClass())->toBe('status-timeout');
            expect(TaskStatus::Cancelled->getCssClass())->toBe('status-cancelled');
            expect(TaskStatus::UploadFailed->getCssClass())->toBe('status-upload-failed');
            expect(TaskStatus::ConnectionFailed->getCssClass())->toBe('status-connection-failed');
        });
    });

    describe('getIcon method', function () {
        it('returns correct icons for all statuses', function () {
            expect(TaskStatus::Pending->getIcon())->toBe('⏳');
            expect(TaskStatus::Running->getIcon())->toBe('🔄');
            expect(TaskStatus::Finished->getIcon())->toBe('✅');
            expect(TaskStatus::Failed->getIcon())->toBe('❌');
            expect(TaskStatus::Timeout->getIcon())->toBe('⏰');
            expect(TaskStatus::Cancelled->getIcon())->toBe('🚫');
            expect(TaskStatus::UploadFailed->getIcon())->toBe('📤');
            expect(TaskStatus::ConnectionFailed->getIcon())->toBe('🔌');
        });
    });

    describe('isComplete method', function () {
        it('returns true for completed statuses', function () {
            expect(TaskStatus::Finished->isComplete())->toBeTrue();
            expect(TaskStatus::Failed->isComplete())->toBeTrue();
            expect(TaskStatus::Timeout->isComplete())->toBeTrue();
            expect(TaskStatus::Cancelled->isComplete())->toBeTrue();
            expect(TaskStatus::UploadFailed->isComplete())->toBeTrue();
            expect(TaskStatus::ConnectionFailed->isComplete())->toBeTrue();
        });

        it('returns false for active statuses', function () {
            expect(TaskStatus::Pending->isComplete())->toBeFalse();
            expect(TaskStatus::Running->isComplete())->toBeFalse();
        });
    });

    describe('isActive method', function () {
        it('returns true for active statuses', function () {
            expect(TaskStatus::Pending->isActive())->toBeTrue();
            expect(TaskStatus::Running->isActive())->toBeTrue();
        });

        it('returns false for completed statuses', function () {
            expect(TaskStatus::Finished->isActive())->toBeFalse();
            expect(TaskStatus::Failed->isActive())->toBeFalse();
            expect(TaskStatus::Timeout->isActive())->toBeFalse();
            expect(TaskStatus::Cancelled->isActive())->toBeFalse();
            expect(TaskStatus::UploadFailed->isActive())->toBeFalse();
            expect(TaskStatus::ConnectionFailed->isActive())->toBeFalse();
        });
    });

    describe('isSuccessful method', function () {
        it('returns true only for finished status', function () {
            expect(TaskStatus::Finished->isSuccessful())->toBeTrue();
        });

        it('returns false for all other statuses', function () {
            expect(TaskStatus::Pending->isSuccessful())->toBeFalse();
            expect(TaskStatus::Running->isSuccessful())->toBeFalse();
            expect(TaskStatus::Failed->isSuccessful())->toBeFalse();
            expect(TaskStatus::Timeout->isSuccessful())->toBeFalse();
            expect(TaskStatus::Cancelled->isSuccessful())->toBeFalse();
            expect(TaskStatus::UploadFailed->isSuccessful())->toBeFalse();
            expect(TaskStatus::ConnectionFailed->isSuccessful())->toBeFalse();
        });
    });

    describe('isFailed method', function () {
        it('returns true for failed statuses', function () {
            expect(TaskStatus::Failed->isFailed())->toBeTrue();
            expect(TaskStatus::Timeout->isFailed())->toBeTrue();
            expect(TaskStatus::UploadFailed->isFailed())->toBeTrue();
            expect(TaskStatus::ConnectionFailed->isFailed())->toBeTrue();
        });

        it('returns false for non-failed statuses', function () {
            expect(TaskStatus::Pending->isFailed())->toBeFalse();
            expect(TaskStatus::Running->isFailed())->toBeFalse();
            expect(TaskStatus::Finished->isFailed())->toBeFalse();
            expect(TaskStatus::Cancelled->isFailed())->toBeFalse();
        });
    });

    describe('getCompletedStatuses static method', function () {
        it('returns all completed statuses', function () {
            $completedStatuses = TaskStatus::getCompletedStatuses();

            expect($completedStatuses)->toHaveCount(6);
            expect($completedStatuses)->toContain(TaskStatus::Finished);
            expect($completedStatuses)->toContain(TaskStatus::Failed);
            expect($completedStatuses)->toContain(TaskStatus::Timeout);
            expect($completedStatuses)->toContain(TaskStatus::Cancelled);
            expect($completedStatuses)->toContain(TaskStatus::UploadFailed);
            expect($completedStatuses)->toContain(TaskStatus::ConnectionFailed);
        });

        it('does not include active statuses', function () {
            $completedStatuses = TaskStatus::getCompletedStatuses();

            expect($completedStatuses)->not->toContain(TaskStatus::Pending);
            expect($completedStatuses)->not->toContain(TaskStatus::Running);
        });
    });

    describe('getFailedStatuses static method', function () {
        it('returns all failed statuses', function () {
            $failedStatuses = TaskStatus::getFailedStatuses();

            expect($failedStatuses)->toHaveCount(4);
            expect($failedStatuses)->toContain(TaskStatus::Failed);
            expect($failedStatuses)->toContain(TaskStatus::Timeout);
            expect($failedStatuses)->toContain(TaskStatus::UploadFailed);
            expect($failedStatuses)->toContain(TaskStatus::ConnectionFailed);
        });

        it('does not include non-failed statuses', function () {
            $failedStatuses = TaskStatus::getFailedStatuses();

            expect($failedStatuses)->not->toContain(TaskStatus::Pending);
            expect($failedStatuses)->not->toContain(TaskStatus::Running);
            expect($failedStatuses)->not->toContain(TaskStatus::Finished);
            expect($failedStatuses)->not->toContain(TaskStatus::Cancelled);
        });
    });

    describe('enum behavior', function () {
        it('can be compared directly', function () {
            expect(TaskStatus::Pending)->toBe(TaskStatus::Pending);
            expect(TaskStatus::Running)->not->toBe(TaskStatus::Finished);
        });

        it('can be used in switch statements', function () {
            $status = TaskStatus::Finished;
            $result = match ($status) {
                TaskStatus::Pending => 'waiting',
                TaskStatus::Running => 'executing',
                TaskStatus::Finished => 'completed',
                default => 'unknown',
            };

            expect($result)->toBe('completed');
        });

        it('can be serialized to string', function () {
            expect(TaskStatus::Pending->value)->toBe('pending');
            expect(TaskStatus::Running->value)->toBe('running');
        });

        it('can be created from string value', function () {
            expect(TaskStatus::from('pending'))->toBe(TaskStatus::Pending);
            expect(TaskStatus::from('running'))->toBe(TaskStatus::Running);
        });

        it('throws exception for invalid string value', function () {
            expect(fn () => TaskStatus::from('invalid'))->toThrow(ValueError::class);
        });

        it('can be safely created with tryFrom', function () {
            expect(TaskStatus::tryFrom('pending'))->toBe(TaskStatus::Pending);
            expect(TaskStatus::tryFrom('invalid'))->toBeNull();
        });
    });
});
