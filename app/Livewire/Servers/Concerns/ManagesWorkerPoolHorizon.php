<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\CollectWorkerPoolHorizonSnapshotJob;
use App\Jobs\DetectWorkerPoolHorizonConfigJob;
use App\Jobs\PushWorkerPoolHorizonConfigJob;
use App\Jobs\RunWorkerPoolTestJobsJob;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerPoolManager;
use App\Support\WorkerPools\WorkerPoolHorizonConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesWorkerPoolHorizon
{


    /**
     * Persist the pool's Horizon config to meta, then project it onto every
     * member box as HORIZON_* env vars and restart their workers.
     */
    public function saveHorizonConfig(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        // Validate queue tokens before saving. The FIRST queue becomes the
        // dispatch target (REDIS_QUEUE), so a malformed/empty value silently
        // breaks routing (this is how a typo'd queue once stuck the control plane).
        $queueTokens = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->hz_queues)),
            fn (string $q): bool => $q !== '',
        ));
        if ($queueTokens === []) {
            $this->toastError(__('Add at least one queue — the first is the dispatch target (REDIS_QUEUE).'));

            return;
        }
        foreach ($queueTokens as $q) {
            if (! preg_match('/^[A-Za-z0-9_:.\-]+$/', $q)) {
                $this->toastError(__('Invalid queue name “:q” — use letters, digits, and _ : . - only.', ['q' => $q]));

                return;
            }
        }

        $previousManager = $pool->processManager();
        $newManager = in_array($this->hz_process_manager, [WorkerPool::PM_SYSTEMD, WorkerPool::PM_SUPERVISOR], true)
            ? $this->hz_process_manager
            : WorkerPool::PM_SYSTEMD;

        $meta = is_array($pool->meta) ? $pool->meta : [];
        $meta['horizon_config'] = [
            'queues' => $this->hz_queues,
            'min_processes' => $this->hz_min_processes,
            'max_processes' => $this->hz_max_processes,
            'balance' => $this->hz_balance,
            'memory' => $this->hz_memory,
            'timeout' => $this->hz_timeout,
            'tries' => $this->hz_tries,
        ];
        $meta['process_manager'] = $newManager;
        $pool->forceFill(['meta' => $meta])->save();
        $this->hz_process_manager = $newManager;

        // Re-read through the normaliser so the form reflects the clamped/cleaned
        // values that were actually stored (and will be pushed to the boxes).
        $normalised = WorkerPoolHorizonConfig::for($pool->refresh());
        $this->hz_queues = implode(', ', $normalised['queues']);
        $this->hz_min_processes = $normalised['min_processes'];
        $this->hz_max_processes = $normalised['max_processes'];
        $this->hz_balance = $normalised['balance'];
        $this->hz_memory = $normalised['memory'];
        $this->hz_timeout = $normalised['timeout'];
        $this->hz_tries = $normalised['tries'];

        PushWorkerPoolHorizonConfigJob::dispatch((string) $pool->id);

        // Switching process manager re-provisions every member's worker daemons
        // under the new backend and tears down the old one (systemd⇄supervisor).
        if ($newManager !== $previousManager) {
            app(WorkerPoolManager::class)->ensureWorkersAcrossPool($pool->refresh(), auth()->user());
            $this->toastSuccess(__('Switching workers to :pm — re-provisioning daemons on each member over SSH.', [
                'pm' => $newManager === WorkerPool::PM_SUPERVISOR ? 'Supervisor' : 'systemd',
            ]));

            return;
        }

        $this->toastSuccess(__('Horizon config saved — applying to workers over SSH (they restart in a few seconds).'));
    }

    /**
     * Auto-detect the queues the pool's app actually uses (and a right-sized
     * process/memory recommendation) by introspecting a member over SSH. The
     * result lands on the pool meta and is surfaced as a one-click suggestion —
     * it NEVER pushes to the boxes. The operator reviews, applies, then saves.
     */
    public function detectHorizonConfig(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $this->hzDetecting = true;
        $this->hzDetectRequestedAt = now()->toIso8601String();
        DetectWorkerPoolHorizonConfigJob::dispatch((string) $pool->id);

        $this->toastSuccess(__('Detecting the app\'s queues over SSH — suggestions appear in a few seconds.'));
    }

    /**
     * Polled (every few seconds) while a detection is in flight: clears the
     * spinner once the job has written a result newer than our request. Each
     * poll re-renders, so the blade picks up the fresh suggestion from meta.
     */
    public function checkHorizonDetection(): void
    {
        if (! $this->hzDetecting) {
            return;
        }

        $pool = $this->pool();
        $detection = is_array($pool?->meta['horizon_detection'] ?? null) ? $pool->meta['horizon_detection'] : [];
        $detectedAt = is_string($detection['detected_at'] ?? null) ? $detection['detected_at'] : null;

        // ISO8601 timestamps compare lexicographically; a result at/after our
        // request stamp is this run's.
        if ($detectedAt !== null && ($this->hzDetectRequestedAt === null || $detectedAt >= $this->hzDetectRequestedAt)) {
            $this->hzDetecting = false;
        }
    }

    /**
     * Copy the detected recommendation into the form fields. Does NOT save or
     * push — the operator still clicks Save & apply (which validates and writes
     * the boxes via PushWorkerPoolHorizonConfigJob).
     */
    public function applyDetectedHorizonConfig(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $detection = is_array($pool->meta['horizon_detection'] ?? null) ? $pool->meta['horizon_detection'] : [];
        $rec = is_array($detection['recommended'] ?? null) ? $detection['recommended'] : [];
        if (empty($rec['queues']) || ! is_array($rec['queues'])) {
            $this->toastError(__('No detection result to apply yet — run Detect first.'));

            return;
        }

        $this->hz_queues = implode(', ', array_map('strval', $rec['queues']));
        $this->hz_min_processes = (int) ($rec['min_processes'] ?? $this->hz_min_processes);
        $this->hz_max_processes = (int) ($rec['max_processes'] ?? $this->hz_max_processes);
        $this->hz_memory = (int) ($rec['memory'] ?? $this->hz_memory);
        $this->hz_timeout = (int) ($rec['timeout'] ?? $this->hz_timeout);
        if (isset($rec['tries'])) {
            $this->hz_tries = (int) $rec['tries'];
        }
        if (in_array($rec['balance'] ?? null, WorkerPoolHorizonConfig::BALANCES, true)) {
            $this->hz_balance = (string) $rec['balance'];
        }

        // Re-sync the Alpine queue preview (its x-data initialised from the old value).
        $this->dispatch('horizon-config-applied', queues: $this->hz_queues);
        $this->toastSuccess(__('Suggestions applied to the form — review, then Save & apply to push to the workers.'));
    }

    /**
     * Pool-wide Horizon control: pause / continue / terminate (restart) /
     * snapshot, applied to every member's Horizon over SSH.
     */
    public function controlPoolHorizon(string $action, WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $action = in_array($action, ['horizon:pause', 'horizon:continue', 'horizon:terminate', 'horizon:snapshot'], true)
            ? $action
            : 'horizon:snapshot';

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $n = 0;
        foreach ($pool->servers as $member) {
            if ($manager->controlMemberWorkers($member, $action, auth()->user())) {
                $n++;
            }
        }

        $verb = [
            'horizon:pause' => __('Pausing Horizon'),
            'horizon:continue' => __('Resuming Horizon'),
            'horizon:terminate' => __('Restarting Horizon'),
            'horizon:snapshot' => __('Snapshotting Horizon metrics'),
        ][$action];
        $this->toastSuccess(__(':verb on :n member(s).', ['verb' => $verb, 'n' => $n]));
    }

    /**
     * Pull a Horizon-style metrics snapshot (failed/completed/pending, jobs per
     * minute, per-queue workload, recent failed jobs) from the app's Horizon
     * over SSH into the pool's Horizon tab.
     */
    public function refreshHorizon(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $this->tab = 'horizon';
        CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id);
        $this->toastSuccess(__('Pulling Horizon metrics over SSH — the dashboard updates in a few seconds.'));
    }

    /**
     * Dispatch test jobs from the Horizon tab and auto-pull a fresh snapshot a
     * few seconds later, so the recent/pending lists below show whether Horizon
     * actually picked the jobs up. Stays on the Horizon tab (unlike runTestJobs,
     * which streams to the Traffic tab's console).
     */
    public function runHorizonTestJobs(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $this->tab = 'horizon';
        RunWorkerPoolTestJobsJob::dispatch((string) $pool->id, 5, (string) (auth()->id() ?? '') ?: null);
        // Re-snapshot after the workers have had time to process the closures so
        // the recent jobs list reflects them (the test probe waits ~7s on-box).
        CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id)->delay(now()->addSeconds(14));
        $this->toastSuccess(__('Dispatching 5 test jobs — the dashboard refreshes in ~15s once the workers pick them up.'));
    }

    /**
     * Keep the Horizon tab fresh without the operator clicking Refresh: while the
     * tab is open, re-pull the SSH snapshot on a throttle. Stops re-pulling once
     * the app reports Horizon isn't installed (one pull is enough to learn that),
     * and skips when a recent pull is still fresh/in-flight so concurrent SSH
     * pulls don't pile up. Wired to a wire:poll on the Horizon tab.
     */
    public function pollHorizon(): void
    {
        if ($this->tab !== 'horizon') {
            return;
        }

        $pool = $this->pool();
        if (! $pool) {
            return;
        }

        $hz = is_array($pool->meta['horizon'] ?? null) ? $pool->meta['horizon'] : [];
        if (($hz['horizon_installed'] ?? null) === false) {
            return;
        }

        // ~12s throttle: the snapshot job updates last_attempt_at when it runs;
        // we also stamp it optimistically below so the next poll (every 10s)
        // doesn't double-dispatch before the in-flight pull lands.
        $last = ! empty($hz['last_attempt_at']) ? Carbon::parse($hz['last_attempt_at']) : null;
        if ($last !== null && $last->gt(now()->subSeconds(12))) {
            return;
        }

        $hz['last_attempt_at'] = now()->toIso8601String();
        $meta = is_array($pool->meta) ? $pool->meta : [];
        $meta['horizon'] = $hz;
        $pool->forceFill(['meta' => $meta])->save();

        CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id);
    }
}
