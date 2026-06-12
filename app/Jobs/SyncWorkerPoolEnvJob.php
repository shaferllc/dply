<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Jobs\Concerns\WritesPoolMemberEnv;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SiteEnvPusher;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Opt-in propagation of a site's .env to its worker-pool replicas.
 *
 * Worker-pool replicas (created by {@see \App\Services\WorkerPools\WorkerWorkloadReplayer})
 * are full COPIES of the primary site's env at scale-up time, not derived
 * workers — so a later edit to the primary's variables never reaches them and
 * they silently drift. The Environment tab's "Sync to workers" action dispatches
 * this job to project the primary's variables onto every replica.
 *
 * Each replica keeps the small set of keys it legitimately owns differently —
 * its queue wiring ({@see \App\Models\Concerns\Site\DerivesWorkerEnvironment::WORKER_OVERRIDE_KEYS}),
 * its `HORIZON_*` tuning (owned by {@see PushWorkerPoolHorizonConfigJob}), and
 * `DPLY_WORKER_ROLE=replica`. Everything else is overwritten/added from the
 * primary. Replica-only extra keys are left intact (additive merge, never a
 * destructive mirror). Reuses {@see WritesPoolMemberEnv} so the per-member push
 * is identical to the Horizon-config path: push + restart only when a value
 * actually changed.
 *
 * One-in-flight-per-primary via {@see ShouldBeUnique} so a double-click can't
 * stack concurrent fan-outs over the same replicas.
 */
class SyncWorkerPoolEnvJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction, WritesPoolMemberEnv;

    /** A real push failure should fail the run once, not re-fan-out the whole pool. */
    public int $maxExceptions = 1;

    /** Safety TTL on the per-site uniqueness lock; comfortably longer than a fan-out takes. */
    public int $uniqueFor = 600;

    public function __construct(
        public string $primarySiteId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'console-action:env_push_workers:'.$this->primarySiteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->primarySiteId);
    }

    protected function consoleKind(): string
    {
        return 'env_push_workers';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        DotEnvFileParser $parser,
        DotEnvFileWriter $writer,
        SiteEnvPusher $pusher,
        SiteSystemdProvisioner $provisioner,
    ): void {
        $primary = Site::query()->find($this->primarySiteId);
        if (! $primary) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $replicas = $this->replicaSitesFor($primary);

            if ($replicas->isEmpty()) {
                $emit->success(__('No worker replicas are attached to this site — nothing to sync.'));
                $this->completeConsoleAction();

                return;
            }

            // The primary's variables minus the keys each replica owns for itself.
            $parsed = $parser->parse((string) ($primary->env_file_content ?? ''));
            $projected = array_filter(
                $parsed['variables'],
                fn (string $key): bool => ! $this->isReplicaOwnedKey($key),
                ARRAY_FILTER_USE_KEY,
            );

            $emit->step('sync', __('Projecting :n variable(s) to :r replica(s)', [
                'n' => count($projected),
                'r' => $replicas->count(),
            ]));

            $synced = 0;
            $unchanged = 0;

            foreach ($replicas as $replica) {
                $label = $replica->server?->name ?: $replica->slug ?: (string) $replica->id;
                $emit->step('sync', __('Syncing :label', ['label' => $label]));

                $changed = $this->applyEnvToMember(
                    $replica,
                    $projected,
                    $parser,
                    $writer,
                    $pusher,
                    $provisioner,
                    restart: true,
                );

                $changed ? $synced++ : $unchanged++;
            }

            $emit->success(__(':synced replica(s) updated, :unchanged already in sync.', [
                'synced' => $synced,
                'unchanged' => $unchanged,
            ]));
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'sync');
            $this->failConsoleAction($e->getMessage());

            Log::warning('SyncWorkerPoolEnvJob failed', [
                'primary_site_id' => $this->primarySiteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Every replica site cloned from this primary, found by the marker
     * {@see \App\Services\WorkerPools\WorkerWorkloadReplayer} writes into meta.
     *
     * @return Collection<int, Site>
     */
    private function replicaSitesFor(Site $primary): Collection
    {
        return Site::query()
            ->where('meta->replicated_from_site_id', (string) $primary->id)
            ->with('server')
            ->get();
    }

    /**
     * A key the replica is allowed to keep its own value for: its queue wiring +
     * APP_URL ({@see \App\Models\Concerns\Site\DerivesWorkerEnvironment::WORKER_OVERRIDE_KEYS}),
     * its HORIZON_* tuning, and the role marker the replayer flips to `replica`.
     */
    private function isReplicaOwnedKey(string $key): bool
    {
        return Site::isWorkerOverrideKey($key) || $key === 'DPLY_WORKER_ROLE';
    }
}
