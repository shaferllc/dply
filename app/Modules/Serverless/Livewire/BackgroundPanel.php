<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Modules\Serverless\Console\ServerlessTickCommand;
use App\Modules\Serverless\Models\ServerlessFailedJob;
use App\Modules\Serverless\Services\InvokeFunctionTick;
use App\Modules\Serverless\Services\ServerlessQueueBackend;
use App\Modules\Serverless\Services\ServerlessQueuePump;
use App\Modules\Serverless\Support\WarmStartStatus;
use App\Support\Sites\SiteRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Background processing for a serverless function — the Laravel scheduler,
 * the queue pump, and keep-warm.
 *
 * The scheduler still rides dply's one-minute cron ({@see ServerlessTickCommand}),
 * because `schedule:run` genuinely wants a minute edge. Queue work does not:
 * {@see ServerlessQueuePump} holds several bounded `queue:work` drains open
 * at once and re-invokes while work remains, so the controls here are about
 * concurrency rather than interval.
 *
 * Failed jobs are mirrored from the function (it has no CLI to run
 * `queue:failed` against) and can be pushed back onto the queue from here.
 */
class BackgroundPanel extends Component
{
    use DispatchesToastNotifications;

    /** How many failed jobs the panel lists before "see all". */
    private const FAILED_JOBS_SHOWN = 20;

    public string $siteId = '';

    /** Bound to the concurrency input. */
    public int $max_concurrency = ServerlessQueuePump::DEFAULT_MAX_CONCURRENCY;

    public function mount(Site $site, ServerlessQueuePump $pump): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
        $this->max_concurrency = $pump->config($site)['max_concurrency'];
    }

    private function site(): Site
    {
        // Through the registry: the sibling serverless panels on this page each
        // resolved the same row, so one render issued the sites SELECT per panel.
        return app(SiteRegistry::class)->findOrFail($this->siteId);
    }

    public function toggle(): void
    {
        $enabled = $this->flip('background_enabled');

        $this->toastSuccess($enabled
            ? __('Background processing enabled — the scheduler runs every minute and queued jobs drain as they arrive.')
            : __('Background processing disabled.'));
    }

    public function toggleKeepWarm(): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $enabled = $site->setServerlessKeepWarm(! $site->serverlessKeepWarmEnabled());

        if ($enabled && $site->serverlessBackgroundProcessingEnabled()) {
            $this->toastSuccess(__('Warm start saved. Background processing already holds the function warm, so dply will not send a second ping.'));

            return;
        }

        $this->toastSuccess($enabled
            ? __('Warm start on — dply pings the function every minute so visitors skip the cold start. No redeploy needed.')
            : __('Warm start off — the first request after idle pays the full boot cost.'));
    }

    /**
     * Set how many queue drains may run against this function at once.
     *
     * This is the throughput dial: each unit is one more concurrent
     * invocation the pump may hold open, and each invocation is billed. The
     * hard ceiling is enforced here and again in the pump, so a crafted
     * request cannot exceed it.
     */
    public function saveConcurrency(ServerlessQueuePump $pump): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $this->validate([
            'max_concurrency' => 'required|integer|min:1|max:'.ServerlessQueuePump::MAX_CONCURRENCY_CEILING,
        ]);

        $this->writeQueueConfig($site, ['max_concurrency' => $this->max_concurrency]);

        // Re-read through the pump so the input always shows the value that
        // is actually in force, not the one that was typed.
        $this->max_concurrency = $pump->config($site->fresh() ?? $site)['max_concurrency'];

        $this->toastSuccess(trans_choice(
            '{1}Queue concurrency set to :count drain at a time.|[2,*]Queue concurrency set to :count concurrent drains.',
            $this->max_concurrency,
            ['count' => $this->max_concurrency],
        ));
    }

    /**
     * Push failed jobs back onto the function's queue, then wake the pump so
     * they start draining immediately rather than at the next tick.
     */
    public function retryFailedJobs(InvokeFunctionTick $tick, ServerlessQueuePump $pump, string $id = 'all'): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $job = null;
        if ($id !== 'all') {
            $job = ServerlessFailedJob::query()
                ->where('site_id', $site->id)
                ->where('uuid', $id)
                ->first();

            if ($job === null) {
                $this->toastError(__('That failed job is no longer recorded.'));

                return;
            }
        }

        try {
            $result = $tick->retryFailedJobs($site, $id);
        } catch (Throwable $e) {
            $this->toastError(__('Could not reach the function: :error', ['error' => $e->getMessage()]));

            return;
        }

        if (! $result['ok']) {
            $this->toastError(__('The function rejected the retry. Check the deploy log for details.'));

            return;
        }

        // Mark what was sent back so the list distinguishes "still failed"
        // from "re-queued". The app's own failed_jobs row is the real state;
        // this is dply's mirror of the action taken.
        $query = ServerlessFailedJob::query()->where('site_id', $site->id)->whereNull('retried_at');
        if ($job !== null) {
            $query->where('uuid', $job->uuid);
        }
        $query->update(['retried_at' => now()]);

        $pump->wake($site);
        unset($this->failedJobs, $this->failedJobCount);

        $this->toastSuccess($job !== null
            ? __('Job re-queued.')
            : __('All failed jobs re-queued.'));
    }

    /** Drop a mirrored failure from dply without touching the app's queue. */
    public function dismissFailedJob(string $failedJobId): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        ServerlessFailedJob::query()
            ->where('site_id', $site->id)
            ->whereKey($failedJobId)
            ->delete();

        unset($this->failedJobs, $this->failedJobCount);

        $this->toastSuccess(__('Failure dismissed.'));
    }

    public function clearFailedJobs(): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $deleted = ServerlessFailedJob::query()->where('site_id', $site->id)->delete();

        unset($this->failedJobs, $this->failedJobCount);

        $this->toastSuccess(trans_choice(
            '{0}Nothing to clear.|{1}:count failure cleared.|[2,*]:count failures cleared.',
            $deleted,
            ['count' => $deleted],
        ));
    }

    /** @return Collection<int, ServerlessFailedJob> */
    #[Computed]
    public function failedJobs()
    {
        return ServerlessFailedJob::query()
            ->where('site_id', $this->siteId)
            ->orderByDesc('failed_at')
            ->limit(self::FAILED_JOBS_SHOWN)
            ->get();
    }

    #[Computed]
    public function failedJobCount(): int
    {
        return ServerlessFailedJob::query()->where('site_id', $this->siteId)->count();
    }

    /**
     * The most recent dply tick against this function — the panel's proof
     * that warming is actually happening, or that nothing is pinging at all.
     *
     * @return array{human: string, iso: string, ok: bool, durationMs: int, cold: bool, task: string, stale: bool}|null
     */
    #[Computed]
    public function lastTick(): ?array
    {
        return WarmStartStatus::for($this->site());
    }

    private function flip(string $key): bool
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $meta = $site->meta;
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $value = ! ($serverless[$key] ?? false);
        $serverless[$key] = $value;
        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();

        return $value;
    }

    /**
     * Merge keys into `meta.serverless.queue` — the pump's config block.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeQueueConfig(Site $site, array $values): void
    {
        $meta = $site->meta;
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $queue = is_array($serverless['queue'] ?? null) ? $serverless['queue'] : [];

        $serverless['queue'] = array_merge($queue, $values);
        $meta['serverless'] = $serverless;

        $site->forceFill(['meta' => $meta])->save();
    }

    /**
     * Give the function a managed dply Queue and point it there.
     *
     * The preferred repair for both broken backends, and the reason the panel
     * leads with it: a namespace is a row, so it exists the moment this
     * returns — where "provision a Redis" is a paid cluster and a wait. The
     * env lands in the site's `.env`, so it reaches the running function on
     * the next deploy, same as every other managed resource.
     */
    public function useDplyQueue(ServerlessQueueBackend $backend): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        if (! $backend->wireDplyQueue($site)) {
            $this->toastError(__('dply Queue is not available for this organization yet.'));

            return;
        }

        $this->toastSuccess(__('Managed queue created and wired. Redeploy to apply it to the running function.'));
    }

    /**
     * Point the queue at the provisioned Redis.
     *
     * The one-click fix for the two backends that cannot work on a function.
     * Offered only when a Redis is actually online — otherwise the operator
     * needs to provision one first, which the panel says instead.
     */
    public function useProvisionedRedis(ServerlessQueueBackend $backend): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        if (! $backend->wireRedis($site)) {
            $this->toastError(__('No provisioned Redis is online for this function yet.'));

            return;
        }

        $this->toastSuccess(__('Queue pointed at the provisioned Redis. Redeploy to apply it to the running function.'));
    }

    public function render(ServerlessQueuePump $pump, ServerlessQueueBackend $backend): View
    {
        $site = $this->site();
        $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];
        $config = $pump->config($site);

        // One classifier behind the panel, the pump, and the doctor, so the
        // UI can never call a backend healthy that the pump refuses to drain.
        $queue = $backend->classify($site);
        $managed = $backend->managedQueue($site);

        return view('livewire.serverless.background-panel', [
            'enabled' => $site->serverlessBackgroundProcessingEnabled(),
            'keepWarm' => $site->serverlessKeepWarmEnabled(),
            'deployed' => trim((string) ($serverless['action_url'] ?? '')) !== '',
            'queueEnabled' => $config['enabled'],
            'activeSlots' => $pump->activeSlots($site),
            'maxConcurrencyCeiling' => ServerlessQueuePump::MAX_CONCURRENCY_CEILING,
            'queueConnection' => $queue['connection'],
            'queueState' => $queue['state'],
            'queueReason' => $queue['reason'],
            'queueBlocked' => ! $backend->canDrain($site),
            'canUseRedis' => $queue['fixable_with_redis'],
            'canUseDply' => $queue['fixable_with_dply'],

            // Flattened to primitives: the view needs an endpoint and three
            // counts, not a model it could lazily load half the schema off.
            'managedQueue' => $managed === null ? null : [
                'id' => $managed['namespace']->id,
                'name' => $managed['namespace']->name,
                'status' => $managed['namespace']->status,
                'endpoint' => $managed['endpoint'],
                'depth' => $managed['depth']?->toArray(),
            ],

            // A namespace can outlive the connection pointing at it — someone
            // may edit QUEUE_CONNECTION by hand in the Environment panel. Say
            // which is true rather than inferring one from the other.
            'usingManagedQueue' => $queue['connection'] === 'dply',
        ]);
    }
}
