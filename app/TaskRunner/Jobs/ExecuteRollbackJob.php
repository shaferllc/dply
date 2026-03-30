<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Jobs;

use App\Modules\TaskRunner\Contracts\HasRollback;
use App\Modules\TaskRunner\Services\RollbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ExecuteRollbackJob handles rollback execution in the background.
 * Provides queued rollback functionality for production safety.
 */
class ExecuteRollbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public HasRollback $task,
        public ?string $reason = null
    ) {
        $this->onQueue('rollbacks');
    }

    /**
     * Execute the job.
     */
    public function handle(RollbackService $rollbackService): void
    {
        Log::info('Starting rollback job execution', [
            'task_class' => get_class($this->task),
            'reason' => $this->reason,
            'attempt' => $this->attempts(),
        ]);

        try {
            $success = $rollbackService->execute($this->task, $this->reason);

            if ($success) {
                Log::info('Rollback job completed successfully', [
                    'task_class' => get_class($this->task),
                    'reason' => $this->reason,
                ]);
            } else {
                Log::error('Rollback job failed', [
                    'task_class' => get_class($this->task),
                    'reason' => $this->reason,
                ]);

                // Retry the job if we haven't exceeded max attempts
                if ($this->attempts() < $this->tries) {
                    $this->release($this->backoff);
                }
            }

        } catch (\Exception $e) {
            Log::error('Rollback job exception', [
                'task_class' => get_class($this->task),
                'reason' => $this->reason,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Retry the job if we haven't exceeded max attempts
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);
            } else {
                $this->fail($e);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Rollback job failed permanently', [
            'task_class' => get_class($this->task),
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
            'max_attempts' => $this->tries,
        ]);

        // Notify administrators of permanent rollback failure
        $this->notifyAdministrators($exception);
    }

    /**
     * Notify administrators of rollback failure.
     */
    protected function notifyAdministrators(\Throwable $exception): void
    {
        // Send notification to administrators
        // This could be email, Slack, etc.
        Log::critical('CRITICAL: Rollback job failed permanently - Administrator notification required', [
            'task_class' => get_class($this->task),
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
            'stack_trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Get the tags for the job.
     */
    public function tags(): array
    {
        return [
            'rollback',
            'task-runner',
            'task:'.get_class($this->task),
        ];
    }
}
