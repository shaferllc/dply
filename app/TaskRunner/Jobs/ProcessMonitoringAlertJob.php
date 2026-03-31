<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Jobs;

use App\Modules\TaskRunner\Services\MonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessMonitoringAlertJob handles monitoring alert processing in the background.
 * Provides queued alert processing for production-ready monitoring.
 */
class ProcessMonitoringAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $alert,
        public ?string $taskId = null
    ) {
        $this->onQueue('monitoring');
    }

    /**
     * Execute the job.
     */
    public function handle(MonitoringService $monitoringService): void
    {
        Log::info('Starting monitoring alert processing', [
            'alert_id' => $this->alert['id'],
            'task_id' => $this->taskId,
            'severity' => $this->alert['severity'],
            'attempt' => $this->attempts(),
        ]);

        try {
            // Process the alert
            $monitoringService->processAlert($this->alert, $this->taskId);

            Log::info('Monitoring alert processed successfully', [
                'alert_id' => $this->alert['id'],
                'task_id' => $this->taskId,
                'severity' => $this->alert['severity'],
            ]);

        } catch (\Exception $e) {
            Log::error('Monitoring alert processing failed', [
                'alert_id' => $this->alert['id'],
                'task_id' => $this->taskId,
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
        Log::error('Monitoring alert job failed permanently', [
            'alert_id' => $this->alert['id'],
            'task_id' => $this->taskId,
            'error' => $exception->getMessage(),
            'max_attempts' => $this->tries,
        ]);

        // Notify administrators of permanent alert processing failure
        $this->notifyAdministrators($exception);
    }

    /**
     * Notify administrators of alert processing failure.
     */
    protected function notifyAdministrators(\Throwable $exception): void
    {
        // Send notification to administrators
        Log::critical('CRITICAL: Monitoring alert processing failed permanently - Administrator notification required', [
            'alert_id' => $this->alert['id'],
            'task_id' => $this->taskId,
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
            'monitoring',
            'alert',
            'task-runner',
            'severity:'.$this->alert['severity'],
        ];
    }
}
