<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Events\TaskProgress;
use App\Modules\TaskRunner\Traits\HasProgressTracking;
use Illuminate\Support\Facades\Event;

describe('HasProgressTracking Trait (Complex)', function () {
    beforeEach(function () {
        $this->logger = Mockery::mock(StreamingLoggerInterface::class);
        app()->instance(StreamingLoggerInterface::class, $this->logger);
        Event::fake();
        $this->testClass = new class
        {
            use HasProgressTracking;

            public function getTaskId(): string
            {
                return 'test-task';
            }

            public function getPendingTask()
            {
                return (object) ['id' => 1];
            }
        };
    });

    it('initializes, adds, updates, and resets progress', function () {
        $this->logger->shouldReceive('streamProgress')->times(1);
        $this->testClass->initializeProgress(2, ['A', 'B']);
        $this->testClass->addProgressStep('C');
        expect($this->testClass->getTotalSteps())->toBe(3);
        $this->testClass->updateStepDescription(2, 'B2');
        expect($this->testClass->getStepDescription(2))->toBe('B2');
        $this->testClass->resetProgress();
        expect($this->testClass->getCurrentStep())->toBe(0);
    });

    it('streams progress and dispatches event on nextStep', function () {
        $this->testClass->initializeProgress(2, ['A', 'B']);
        $this->logger->shouldReceive('streamProgress')->once();
        $this->testClass->nextStep();
        Event::assertDispatched(TaskProgress::class);
    });

    it('sets step and completes task', function () {
        $this->testClass->initializeProgress(2, ['A', 'B']);
        $this->logger->shouldReceive('streamProgress')->twice();
        $this->testClass->setStep(2);
        $this->testClass->completeTask('done');
        expect($this->testClass->isCompleted())->toBeTrue();
    });

    it('handles edge cases for progress percentage', function () {
        $this->testClass->initializeProgress(0);
        expect($this->testClass->getProgressPercentage())->toBe(0.0);
        $this->testClass->initializeProgress(2);
        $this->testClass->nextStep();
        expect($this->testClass->getProgressPercentage())->toBe(50.0);
    });
});
