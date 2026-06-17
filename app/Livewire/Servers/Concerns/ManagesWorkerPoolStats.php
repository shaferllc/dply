<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\CollectWorkerPoolStatsJob;
use App\Jobs\RunWorkerPoolTestJobsJob;
use App\Models\ConsoleAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesWorkerPoolStats
{


    /**
     * Refresh per-member host + worker + Redis stats over SSH (queued, never
     * inline) so the Traffic tab shows live numbers. Switches to the Traffic
     * tab so the operator sees the result land.
     */
    public function collectStats(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $this->tab = 'traffic';
        CollectWorkerPoolStatsJob::dispatch((string) $pool->id);
        $this->toastSuccess(__('Refreshing worker stats over SSH — numbers update in a few seconds.'));
    }

    /**
     * Dispatch a handful of throwaway queued closures onto the app's queue and
     * verify the workers process them — streamed to the test console below.
     */
    public function runTestJobs(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $this->tab = 'traffic';
        RunWorkerPoolTestJobsJob::dispatch((string) $pool->id, 5, (string) (auth()->id() ?? '') ?: null);
        $this->toastSuccess(__('Dispatching 5 test jobs — watch the test console for whether the workers process them.'));
    }

    /**
     * Latest non-dismissed stats-probe console run, so Refresh stats shows the
     * raw per-member probe output (incl. Redis errors) for debugging.
     */
    public function statsRun(): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'worker_pool_stats')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Latest non-dismissed test-jobs console run (server subject), for the
     * Traffic tab's test console banner.
     */
    /**
     * Real-time per-job event pushed from a worker box (via Reverb → Echo →
     * Livewire). Prepend to the live feed if it's for this pool; cap the window.
     *
     * @param  array<string, mixed>  $job
     */
    #[On('worker-pool-job')]
    public function onWorkerPoolJob(string $poolId, array $job): void
    {
        if ($poolId !== (string) ($this->server->worker_pool_id ?? '')) {
            return;
        }

        array_unshift($this->liveJobs, [
            'name' => (string) ($job['name'] ?? 'job'),
            'queue' => (string) ($job['queue'] ?? '?'),
            'status' => (string) ($job['status'] ?? 'processing'),
            // dply-clock timestamp (stamped at ingest) — rendered as "x ago"
            // against dply's now(), never the box's clock.
            'received_at' => (float) ($job['received_at'] ?? 0),
        ]);
        $this->liveJobs = array_slice($this->liveJobs, 0, 30);
    }

    /**
     * The Live-jobs feed for the Horizon tab. Real-time Echo events win when they
     * arrive; otherwise — the common case, since most pools have no per-job
     * broadcaster wired on the workers — fall back to the freshest jobs from the
     * Horizon snapshot pulled over SSH, so the panel actually fills in whenever
     * Horizon has activity instead of sitting on "waiting for job activity…".
     *
     * @return list<array{name: string, queue: string, status: string, received_at: float}>
     */
    public function liveJobsFeed(): array
    {
        if ($this->liveJobs !== []) {
            return $this->liveJobs;
        }

        $pool = $this->pool();
        $hz = is_array($pool?->meta['horizon'] ?? null) ? $pool->meta['horizon'] : [];
        // No fallback when the app doesn't ship Horizon (nothing to read).
        if (($hz['horizon_installed'] ?? null) === false) {
            return [];
        }

        $collectedAt = ! empty($hz['collected_at'])
            ? Carbon::parse($hz['collected_at'])->getTimestamp()
            : now()->getTimestamp();

        // Snapshot jobs carry `age` (seconds, computed on the box at collection):
        // rebuild a dply-clock `received_at` so the view's "x ago" renders right.
        $map = fn (array $jobs, ?string $forceStatus): array => collect($jobs)->map(fn ($j) => [
            'name' => (string) ($j['name'] ?? 'job'),
            'queue' => (string) ($j['queue'] ?? '?'),
            'status' => $forceStatus ?? (string) ($j['status'] ?? 'processing'),
            'received_at' => isset($j['age'])
                ? (float) $collectedAt - (float) $j['age']
                : (float) $collectedAt,
        ])->all();

        $feed = array_merge(
            $map((array) ($hz['recent_jobs'] ?? []), null),
            $map((array) ($hz['pending_jobs'] ?? []), 'pending'),
        );

        usort($feed, fn ($a, $b) => $b['received_at'] <=> $a['received_at']);

        return array_slice($feed, 0, 30);
    }

    public function testRun(): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'worker_pool_test')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();
    }
}
