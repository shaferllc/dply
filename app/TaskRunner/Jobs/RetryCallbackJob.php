<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Jobs;

use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Services\CallbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RetryCallbackJob handles retrying failed callbacks with exponential backoff.
 */
class RetryCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public HasCallbacks $task,
        public CallbackType $type,
        public array $additionalData = [],
        public int $attempt = 1
    ) {
        $this->onQueue('callbacks');
    }

    /**
     * Execute the job.
     */
    public function handle(CallbackService $callbackService): void
    {
        Log::info('Retrying callback', [
            'task_class' => get_class($this->task),
            'callback_type' => $this->type->value,
            'attempt' => $this->attempt,
            'url' => $this->task->getCallbackUrl(),
        ]);

        $success = $callbackService->send($this->task, $this->type, $this->additionalData);

        if (! $success && $this->attempt < $this->tries) {
            $retryConfig = $this->task->getCallbackRetryConfig();
            $delay = $retryConfig['delay'] ?? 5;
            $backoffMultiplier = $retryConfig['backoff_multiplier'] ?? 2;

            $nextDelay = $delay * ($backoffMultiplier ** ($this->attempt - 1));

            Log::info('Scheduling next callback retry', [
                'task_class' => get_class($this->task),
                'callback_type' => $this->type->value,
                'next_attempt' => $this->attempt + 1,
                'delay' => $nextDelay,
            ]);

            static::dispatch($this->task, $this->type, $this->additionalData, $this->attempt + 1)
                ->delay(now()->addSeconds($nextDelay));
        } elseif (! $success) {
            Log::error('Callback retry exhausted', [
                'task_class' => get_class($this->task),
                'callback_type' => $this->type->value,
                'max_attempts' => $this->tries,
                'url' => $this->task->getCallbackUrl(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('RetryCallbackJob failed', [
            'task_class' => get_class($this->task),
            'callback_type' => $this->type->value,
            'attempt' => $this->attempt,
            'error' => $exception->getMessage(),
            'url' => $this->task->getCallbackUrl(),
        ]);
    }
}
