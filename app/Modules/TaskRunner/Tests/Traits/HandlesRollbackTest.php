<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Exceptions\RollbackException;
use App\Modules\TaskRunner\Services\RollbackService;
use App\Modules\TaskRunner\Traits\HandlesRollback;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

describe('HandlesRollback Trait (Complex)', function () {
    beforeEach(function () {
        $this->rollbackService = Mockery::mock(RollbackService::class);
        app()->instance(RollbackService::class, $this->rollbackService);
        Log::spy();
        Storage::shouldReceive('disk')->andReturnSelf();
        Storage::shouldReceive('put');

        $this->testClass = new class
        {
            use HandlesRollback;

            public $task;

            public function __construct()
            {
                $this->task = (object) [
                    'id' => 1,
                    'name' => 'Test',
                    'status' => TaskStatus::Failed,
                    'exit_code' => 1,
                    'output' => 'error',
                ];
            }

            protected function validateRollbackDependencies(): bool
            {
                return true;
            }

            protected function runSafetyCheck(string $check): bool
            {
                return true;
            }

            protected function hasValidCheckpoint(): bool
            {
                return true;
            }
        };
    });

    it('supports enabling/disabling rollback and setting script', function () {
        $this->testClass->disableRollback();
        expect($this->testClass->supportsRollback())->toBeFalse();
        $this->testClass->enableRollback();
        $this->testClass->setRollbackScript('echo rollback');
        expect($this->testClass->supportsRollback())->toBeTrue();
    });

    it('validates rollback with all checks passing', function () {
        $this->testClass->setRollbackScript('echo rollback');
        expect($this->testClass->validateRollback())->toBeTrue();
    });

    it('fails validation if rollback not supported', function () {
        $this->testClass->disableRollback();
        expect($this->testClass->validateRollback())->toBeFalse();
    });

    it('creates rollback checkpoint and logs', function () {
        $this->testClass->setRollbackScript('echo rollback');
        expect($this->testClass->createRollbackCheckpoint())->toBeTrue();
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'Rollback checkpoint created'));
    });

    it('executes rollback successfully and logs', function () {
        $this->testClass->setRollbackScript('echo rollback');
        $this->rollbackService->shouldReceive('execute')->once()->andReturn(true);
        expect($this->testClass->executeRollback('test'))->toBeTrue();
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'Rollback completed successfully'));
    });

    it('records rollback failure and throws exception', function () {
        $this->testClass->setRollbackScript('echo rollback');
        $this->rollbackService->shouldReceive('execute')->once()->andThrow(new Exception('fail'));
        expect(fn () => $this->testClass->executeRollback('fail'))->toThrow(RollbackException::class);
        Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'Rollback exception'));
    });

    it('checks if rollback is required for failed status and critical errors', function () {
        $this->testClass->setRollbackScript('echo rollback');
        $this->testClass->task->status = TaskStatus::Failed;
        expect($this->testClass->isRollbackRequired())->toBeTrue();
        $this->testClass->task->status = TaskStatus::Timeout;
        expect($this->testClass->isRollbackRequired())->toBeTrue();
        $this->testClass->task->status = TaskStatus::Pending;
        $this->testClass->task->output = 'fatal error';
        expect($this->testClass->isRollbackRequired())->toBeTrue();
    });

    it('handles recovery logic', function () {
        $this->testClass->setRollbackScript('echo rollback');
        $this->rollbackService->shouldReceive('recover')->once()->andReturn(true);
        expect($this->testClass->executeRecovery('restore_from_checkpoint'))->toBeTrue();
    });

    it('returns rollback data, dependencies, and history', function () {
        $this->testClass->setRollbackScript('echo rollback');
        $this->testClass->addRollbackDependency('dep');
        $this->testClass->recordRollbackSuccess('ok');
        $this->testClass->recordRollbackFailure('fail', 'err');
        $data = $this->testClass->getRollbackData();
        expect($data)->toHaveKey('task_id');
        expect($this->testClass->getRollbackDependencies())->toContain('dep');
        $history = $this->testClass->getRollbackHistory();
        expect($history)->toHaveCount(2);
    });
});
