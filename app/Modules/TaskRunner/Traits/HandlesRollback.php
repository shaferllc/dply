<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Traits;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Exceptions\RollbackException;
use App\Modules\TaskRunner\Jobs\ExecuteRollbackJob;
use App\Modules\TaskRunner\Services\RollbackService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * HandlesRollback trait provides comprehensive rollback and recovery functionality.
 * Ensures production safety through automatic rollback mechanisms and recovery procedures.
 */
trait HandlesRollback
{
    /**
     * Rollback configuration properties.
     */
    protected bool $rollbackEnabled = true;

    protected int $rollbackTimeout = 300;

    /** @var array<string, mixed> */
    protected array $rollbackDependencies = [];

    /** @var array<string, mixed> */
    protected array $rollbackSafetyChecks = [];

    /** @var array<string, mixed> */
    protected array $rollbackData = [];

    protected ?string $rollbackScript = null;

    /** @var array<string, mixed> */
    protected array $rollbackHistory = [];

    public function supportsRollback(): bool
    {
        return $this->rollbackEnabled && ! empty($this->getRollbackScript());
    }

    public function isRollbackRequired(): bool
    {
        if (! $this->supportsRollback()) {
            return false;
        }

        // Check if task failed or timed out
        if ($this->taskModel && in_array($this->taskModel->status, [TaskStatus::Failed, TaskStatus::Timeout])) {
            return true;
        }

        // Check if task has critical errors
        if ($this->hasCriticalErrors()) {
            return true;
        }

        return false;
    }

    /**
     * Get the rollback script for this task.
     */
    public function getRollbackScript(): string
    {
        return $this->rollbackScript ?? '';
    }

    /**
     * Get the rollback timeout in seconds.
     */
    public function getRollbackTimeout(): int
    {
        return $this->rollbackTimeout;
    }

    /**
     * Get the rollback dependencies (tasks that must be rolled back first).
     */
    /** @return array<string, mixed> */
    public function getRollbackDependencies(): array
    {
        return $this->rollbackDependencies;
    }

    /** @return array<string, mixed> */
    public function getRollbackSafetyChecks(): array
    {
        return array_merge([
            'check_system_health',
            'verify_backup_integrity',
            'confirm_rollback_safety',
        ], $this->rollbackSafetyChecks);
    }

    /** @return array<string, mixed> */
    public function getRollbackData(): array
    {
        return array_merge([
            'task_id' => $this->taskModel?->id,
            'task_name' => $this->taskModel?->name,
            'rollback_timestamp' => now()->toISOString(),
            'original_status' => $this->taskModel?->status?->value,
        ], $this->rollbackData);
    }

    public function validateRollback(): bool
    {
        if (! $this->supportsRollback()) {
            Log::warning('Rollback not supported for task', [
                'task_id' => $this->taskModel?->id,
                'task_class' => static::class,
            ]);

            return false;
        }

        // Run safety checks
        foreach ($this->getRollbackSafetyChecks() as $check) {
            if (! $this->runSafetyCheck($check)) {
                Log::error('Rollback safety check failed', [
                    'task_id' => $this->taskModel?->id,
                    'check' => $check,
                ]);

                return false;
            }
        }

        // Check dependencies
        if (! $this->validateRollbackDependencies()) {
            Log::error('Rollback dependencies not satisfied', [
                'task_id' => $this->taskModel?->id,
                'dependencies' => $this->getRollbackDependencies(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Create a rollback checkpoint before task execution.
     */
    public function createRollbackCheckpoint(): bool
    {
        if (! $this->supportsRollback()) {
            return false;
        }

        try {
            $checkpoint = [
                'task_id' => $this->taskModel?->id,
                'timestamp' => now()->toISOString(),
                'state' => $this->captureCurrentState(),
                'backup_data' => $this->createBackup(),
            ];

            $this->saveCheckpoint($checkpoint);

            Log::info('Rollback checkpoint created', [
                'task_id' => $this->taskModel?->id,
                'checkpoint_id' => $checkpoint['timestamp'],
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to create rollback checkpoint', [
                'task_id' => $this->taskModel?->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Execute the rollback procedure.
     */
    public function executeRollback(?string $reason = null): bool
    {
        if (! $this->validateRollback()) {
            throw new RollbackException('Rollback validation failed');
        }

        try {
            Log::info('Starting rollback execution', [
                'task_id' => $this->taskModel?->id,
                'reason' => $reason,
            ]);

            $rollbackService = app(RollbackService::class);
            $success = $rollbackService->execute($this, $reason);

            if ($success) {
                $this->recordRollbackSuccess($reason);
                Log::info('Rollback completed successfully', [
                    'task_id' => $this->taskModel?->id,
                    'reason' => $reason,
                ]);
            } else {
                $this->recordRollbackFailure($reason);
                Log::error('Rollback failed', [
                    'task_id' => $this->taskModel?->id,
                    'reason' => $reason,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $this->recordRollbackFailure($reason, $e->getMessage());
            Log::error('Rollback exception', [
                'task_id' => $this->taskModel?->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
            throw new RollbackException('Rollback execution failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get rollback history for this task.
     */
    /** @return array<string, mixed> */
    public function getRollbackHistory(): array
    {
        return $this->rollbackHistory;
    }

    public function isRecoveryPossible(): bool
    {
        return ! empty($this->getRecoveryOptions()) && $this->hasValidCheckpoint();
    }

    /** @return array<string, mixed> */
    public function getRecoveryOptions(): array
    {
        return [
            'restore_from_checkpoint' => 'Restore from last checkpoint',
            'partial_rollback' => 'Partial rollback to safe state',
            'manual_recovery' => 'Manual recovery procedure',
            'system_restore' => 'System-level restore',
        ];
    }

    public function executeRecovery(string $recoveryType): bool
    {
        if (! $this->isRecoveryPossible()) {
            Log::warning('Recovery not possible for task', [
                'task_id' => $this->taskModel?->id,
                'recovery_type' => $recoveryType,
            ]);

            return false;
        }

        try {
            $rollbackService = app(RollbackService::class);
            $success = $rollbackService->recover($this, $recoveryType);

            Log::info('Recovery executed', [
                'task_id' => $this->taskModel?->id,
                'recovery_type' => $recoveryType,
                'success' => $success,
            ]);

            return $success;

        } catch (\Exception $e) {
            Log::error('Recovery failed', [
                'task_id' => $this->taskModel?->id,
                'recovery_type' => $recoveryType,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Set rollback configuration.
      * @param array<string, mixed> $config
     */
    public function setRollbackConfig(array $config): self
    {
        $this->rollbackEnabled = $config['enabled'] ?? true;
        $this->rollbackTimeout = $config['timeout'] ?? 300;
        $this->rollbackDependencies = $config['dependencies'] ?? [];
        $this->rollbackSafetyChecks = $config['safety_checks'] ?? [];
        $this->rollbackData = $config['data'] ?? [];
        $this->rollbackScript = $config['script'] ?? null;

        return $this;
    }

    /**
     * Enable rollback for this task.
     */
    public function enableRollback(): self
    {
        $this->rollbackEnabled = true;

        return $this;
    }

    /**
     * Disable rollback for this task.
     */
    public function disableRollback(): self
    {
        $this->rollbackEnabled = false;

        return $this;
    }

    /**
     * Set the rollback script.
     */
    public function setRollbackScript(string $script): self
    {
        $this->rollbackScript = $script;

        return $this;
    }

    /**
     * Add a rollback dependency.
     */
    public function addRollbackDependency(string $dependency): self
    {
        $this->rollbackDependencies[] = $dependency;

        return $this;
    }

    /**
     * Add a safety check.
     */
    public function addSafetyCheck(string $check): self
    {
        $this->rollbackSafetyChecks[] = $check;

        return $this;
    }

    /**
     * Schedule rollback for background execution.
     */
    public function scheduleRollback(?string $reason = null): void
    {
        if ($this->supportsRollback()) {
            ExecuteRollbackJob::dispatch($this, $reason)
                ->delay(now()->addSeconds(5));
        }
    }

    /**
     * Check if task has critical errors.
     */
    protected function hasCriticalErrors(): bool
    {
        if (! $this->taskModel) {
            return false;
        }

        // Check exit code
        if ($this->taskModel->exit_code !== null && $this->taskModel->exit_code !== 0) {
            return true;
        }

        // Check for error patterns in output
        $errorPatterns = [
            'error',
            'fatal',
            'exception',
            'failed',
            'critical',
        ];

        $output = strtolower($this->taskModel->output ?? '');
        foreach ($errorPatterns as $pattern) {
            if (str_contains($output, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run a safety check.
     */
    protected function runSafetyCheck(string $check): bool
    {
        return match ($check) {
            'check_system_health' => $this->checkSystemHealth(),
            'verify_backup_integrity' => $this->verifyBackupIntegrity(),
            'confirm_rollback_safety' => $this->confirmRollbackSafety(),
            default => $this->runCustomSafetyCheck($check),
        };
    }

    /**
     * Check system health.
     */
    protected function checkSystemHealth(): bool
    {
        // Basic system health checks
        return true; // Override in subclasses
    }

    /**
     * Verify backup integrity.
     */
    protected function verifyBackupIntegrity(): bool
    {
        // Verify backup data integrity
        return true; // Override in subclasses
    }

    /**
     * Confirm rollback safety.
     */
    protected function confirmRollbackSafety(): bool
    {
        // Confirm rollback is safe to proceed
        return true; // Override in subclasses
    }

    /**
     * Run custom safety check.
     */
    protected function runCustomSafetyCheck(string $check): bool
    {
        // Override in subclasses for custom checks
        return true;
    }

    /**
     * Validate rollback dependencies.
     */
    protected function validateRollbackDependencies(): bool
    {
        // Check if all dependencies are satisfied
        return true; // Override in subclasses
    }

    /**
     * Capture current state for checkpoint.
     */
    /** @return array<string, mixed> */
    protected function captureCurrentState(): array
    {
        return [
            'task_status' => $this->taskModel?->status?->value,
            'task_output' => $this->taskModel?->output,
            'task_exit_code' => $this->taskModel?->exit_code,
            'timestamp' => now()->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    protected function createBackup(): array
    {
        // Create backup of current state
        return []; // Override in subclasses
    }

    /**
     * @param array<string, mixed> $checkpoint
     */
    protected function saveCheckpoint(array $checkpoint): void
    {
        $filename = "rollback_checkpoint_{$this->taskModel?->id}_{$checkpoint['timestamp']}.json";
        Storage::disk('local')->put("task-runner/checkpoints/{$filename}", json_encode($checkpoint));
    }

    protected function hasValidCheckpoint(): bool
    {
        // Check if valid checkpoint exists
        return true; // Override in subclasses
    }

    /**
     * Record successful rollback.
     */
    protected function recordRollbackSuccess(?string $reason = null): void
    {
        $this->rollbackHistory[] = [
            'timestamp' => now()->toISOString(),
            'type' => 'success',
            'reason' => $reason,
        ];
    }

    /**
     * Record failed rollback.
     */
    protected function recordRollbackFailure(?string $reason = null, ?string $error = null): void
    {
        $this->rollbackHistory[] = [
            'timestamp' => now()->toISOString(),
            'type' => 'failure',
            'reason' => $reason,
            'error' => $error,
        ];
    }
}
