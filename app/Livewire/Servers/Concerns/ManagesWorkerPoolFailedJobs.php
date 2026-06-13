<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\CollectWorkerPoolHorizonSnapshotJob;
use App\Services\WorkerPools\WorkerPoolManager;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesWorkerPoolFailedJobs
{


    /**
     * Retry / delete failed jobs on the pool's app over SSH. $uuid is a single
     * failed-job UUID, or null/'all' to act on every failed job.
     */
    public function retryFailedJob(?string $uuid = null, ?WorkerPoolManager $manager = null): void
    {
        $this->queueFailedAction('queue:retry', $uuid, $manager);
    }

    public function forgetFailedJob(string $uuid, ?WorkerPoolManager $manager = null): void
    {
        $this->queueFailedAction('queue:forget', $uuid, $manager);
    }

    public function retryAllFailed(?WorkerPoolManager $manager = null): void
    {
        $this->queueFailedAction('queue:retry', 'all', $manager);
    }

    public function flushFailed(?WorkerPoolManager $manager = null): void
    {
        $this->queueFailedAction('queue:flush', null, $manager);
    }

    private function queueFailedAction(string $action, ?string $arg, ?WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);
        $manager ??= app(WorkerPoolManager::class);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        if (! $manager->controlPrimaryQueue($pool, $action, $arg, auth()->user())) {
            $this->toastError(__('No app site to manage failed jobs on.'));

            return;
        }

        $this->tab = 'horizon';
        $label = match ($action) {
            'queue:retry' => $arg && $arg !== 'all' ? __('Retrying failed job') : __('Retrying all failed jobs'),
            'queue:forget' => __('Deleting failed job'),
            'queue:flush' => __('Flushing all failed jobs'),
            default => __('Updating failed jobs'),
        };
        // Re-pull the snapshot shortly so the list reflects the change.
        CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id);
        $this->toastSuccess($label.' — '.__('the Horizon tab will refresh.'));
    }
}
