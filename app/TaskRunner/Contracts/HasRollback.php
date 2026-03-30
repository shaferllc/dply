<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Contracts;

use App\Modules\TaskRunner\Models\Task;

/**
 * HasRollback contract for tasks that support rollback and recovery functionality.
 * Provides production safety through automatic rollback mechanisms and recovery procedures.
 */
interface HasRollback
{
    /**
     * Check if rollback is supported for this task.
     */
    public function supportsRollback(): bool;

    /**
     * Check if rollback is required based on current state.
     */
    public function isRollbackRequired(): bool;

    /**
     * Get the rollback script for this task.
     */
    public function getRollbackScript(): string;

    /**
     * Get the rollback timeout in seconds.
     */
    public function getRollbackTimeout(): int;

    /**
     * Get the rollback dependencies (tasks that must be rolled back first).
     */
    public function getRollbackDependencies(): array;

    /**
     * Get the rollback safety checks that must pass before rollback.
     */
    public function getRollbackSafetyChecks(): array;

    /**
     * Get the rollback data (state to restore).
     */
    public function getRollbackData(): array;

    /**
     * Validate that rollback can be performed safely.
     */
    public function validateRollback(): bool;

    /**
     * Create a rollback checkpoint before task execution.
     */
    public function createRollbackCheckpoint(): bool;

    /**
     * Execute the rollback procedure.
     */
    public function executeRollback(?string $reason = null): bool;

    /**
     * Get rollback history for this task.
     */
    public function getRollbackHistory(): array;

    /**
     * Check if recovery is possible for this task.
     */
    public function isRecoveryPossible(): bool;

    /**
     * Get recovery options for this task.
     */
    public function getRecoveryOptions(): array;

    /**
     * Execute recovery procedure.
     */
    public function executeRecovery(string $recoveryType): bool;
}
