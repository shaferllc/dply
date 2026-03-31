<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Exceptions;

use Exception;

/**
 * RollbackException is thrown when rollback operations fail.
 * Provides detailed error information for rollback failures.
 */
class RollbackException extends Exception
{
    /**
     * The task ID that failed to rollback.
     */
    protected ?string $taskId = null;

    /**
     * The rollback reason.
     */
    protected ?string $rollbackReason = null;

    /**
     * Additional rollback context.
     */
    protected array $context = [];

    /**
     * Create a new RollbackException instance.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Exception $previous = null,
        ?string $taskId = null,
        ?string $rollbackReason = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);

        $this->taskId = $taskId;
        $this->rollbackReason = $rollbackReason;
        $this->context = $context;
    }

    /**
     * Get the task ID.
     */
    public function getTaskId(): ?string
    {
        return $this->taskId;
    }

    /**
     * Get the rollback reason.
     */
    public function getRollbackReason(): ?string
    {
        return $this->rollbackReason;
    }

    /**
     * Get the rollback context.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Create a RollbackException for validation failure.
     */
    public static function validationFailed(string $taskId, array $validationErrors): self
    {
        return new self(
            'Rollback validation failed: '.implode(', ', $validationErrors),
            0,
            null,
            $taskId,
            'validation_failed',
            ['validation_errors' => $validationErrors]
        );
    }

    /**
     * Create a RollbackException for execution failure.
     */
    public static function executionFailed(string $taskId, string $reason, string $error): self
    {
        return new self(
            "Rollback execution failed: {$error}",
            0,
            null,
            $taskId,
            $reason,
            ['execution_error' => $error]
        );
    }

    /**
     * Create a RollbackException for dependency failure.
     */
    public static function dependencyFailed(string $taskId, array $dependencies): self
    {
        return new self(
            'Rollback dependencies not satisfied',
            0,
            null,
            $taskId,
            'dependency_failed',
            ['dependencies' => $dependencies]
        );
    }

    /**
     * Create a RollbackException for safety check failure.
     */
    public static function safetyCheckFailed(string $taskId, string $check, string $reason): self
    {
        return new self(
            "Rollback safety check failed: {$check} - {$reason}",
            0,
            null,
            $taskId,
            'safety_check_failed',
            ['failed_check' => $check, 'check_reason' => $reason]
        );
    }
}
