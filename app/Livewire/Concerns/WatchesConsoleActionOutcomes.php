<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\ConsoleAction;

/**
 * After dispatching a queued console-action job, watch the seeded row until it
 * reaches a terminal state and toast success or failure. Surfaces "queue worker
 * not running" when the row stays queued past
 * {@see config('console_actions.queued_stalled_after_seconds')}.
 */
trait WatchesConsoleActionOutcomes
{
    public ?string $watchedConsoleRunId = null;

    public ?string $watchedConsoleSuccessToast = null;

    public ?string $watchedConsoleFailureToast = null;

    /**
     * Poll target (also invoked from render while a watch is active).
     */
    public function resolveWatchedConsoleAction(): void
    {
        if ($this->watchedConsoleRunId === null) {
            return;
        }

        $row = ConsoleAction::query()->find($this->watchedConsoleRunId);
        if ($row === null || $row->isDismissed()) {
            $this->clearWatchedConsoleAction();

            return;
        }

        if ($row->isInFlight() && ! $row->isStale()) {
            return;
        }

        if ($row->status === ConsoleAction::STATUS_COMPLETED) {
            $this->toastSuccess($this->watchedConsoleSuccessToast ?? __('Finished.'));
        } else {
            $message = trim((string) ($row->error ?? ''));
            if ($message === '') {
                $message = $this->watchedConsoleFailureToast ?? __('Background task did not finish.');
            }
            if ($row->isQueuedStalled()) {
                $message = $message.' '.ConsoleAction::queueWorkerStalledMessage();
            } elseif ($row->isStale() && $row->status === ConsoleAction::STATUS_RUNNING) {
                $message = $message.' '.__('The worker may have stopped before this task finished.');
            }
            $this->toastError($message);
        }

        $this->clearWatchedConsoleAction();
    }

    protected function watchConsoleAction(
        ConsoleAction $run,
        string $successToast,
        ?string $failureToast = null,
    ): void {
        $this->watchedConsoleRunId = (string) $run->id;
        $this->watchedConsoleSuccessToast = $successToast;
        $this->watchedConsoleFailureToast = $failureToast;
    }

    protected function clearWatchedConsoleAction(): void
    {
        $this->watchedConsoleRunId = null;
        $this->watchedConsoleSuccessToast = null;
        $this->watchedConsoleFailureToast = null;
    }

    /**
     * Queued toast copy shared by console-backed dispatches.
     */
    protected function toastConsoleActionQueued(): void
    {
        $this->toastSuccess(__('Queued — the console banner will confirm when it finishes.'));
    }
}
