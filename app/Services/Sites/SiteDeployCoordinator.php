<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Jobs\RunSiteDeploymentJob;
use App\Jobs\RunSiteFixerJob;
use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Support\Sites\SiteFixers;
use App\Support\Sites\SiteSyncPeers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * The single home for the deploy actions + state that the main Deploy page
 * ({@see \App\Livewire\Sites\DeploymentsList}) and the deploy sidebar
 * ({@see \App\Livewire\Sites\DeployControl}) both drive. Before this, each
 * component re-implemented "is a deploy running", the seed-lock-and-dispatch
 * primitive, the Sync fan-out, and the smart-fix runner — so the two surfaces
 * drifted (different poll intervals, duplicated logic) and could both fire a
 * deploy. Routing every action through here makes them ONE thing:
 *
 *  - {@see deploy()}    seeds the optimistic lock + dispatches, refusing a
 *                       second deploy while one is already in progress.
 *  - {@see sync()}      the multi-site batch ("deploy together"), once.
 *  - {@see runFixer()}  the failed-deploy smart-fix, one in flight at a time.
 *  - {@see status()}    the read-side snapshot both surfaces render from.
 *
 * Billing-pause gating stays in the components (it dispatches Livewire events /
 * toasts); callers must pre-check it before calling deploy()/sync().
 */
class SiteDeployCoordinator
{
    /** Optimistic "a deploy is queued/running" marker (set on click, 600s TTL). */
    public const ACTIVE_KEY_PREFIX = 'site-deploy-active:';

    /** The launched sync batch for a context site — drives the combined console. */
    public const SYNC_BATCH_KEY_PREFIX = 'site-sync-batch:';

    /** Persisted Sync peer selection, mirrored across the page + sidebar. */
    public const SYNC_SELECTION_KEY_PREFIX = 'site-sync-selection:';

    /**
     * Seed the optimistic deploy marker and queue the deploy on a worker. SSH
     * must never block a Livewire/HTTP request, so the clone/build/release run
     * off-request. Refuses (returns false) when a deploy is already in progress
     * for the site — the single double-fire guard both surfaces share.
     */
    public function deploy(
        Site $site,
        string $trigger = SiteDeployment::TRIGGER_MANUAL,
        ?string $auditUserId = null,
        ?string $resumeFromDeploymentId = null,
        ?string $ephemeralIdentityToken = null,
    ): bool {
        if ($this->inProgress($site)) {
            return false;
        }

        Cache::put(self::ACTIVE_KEY_PREFIX.$site->id, [
            'started_at' => now()->toIso8601String(),
            'deployment_id' => null,
        ], 600);

        RunSiteDeploymentJob::dispatch(
            $site->fresh() ?? $site,
            $trigger,
            auditUserId: $auditUserId,
            resumeFromDeploymentId: $resumeFromDeploymentId,
            ephemeralIdentityToken: $ephemeralIdentityToken,
        );

        return true;
    }

    /**
     * Deploy the chosen peers together (parallel fan-out), authorising each and
     * routing it through {@see deploy()} so the in-progress guard applies
     * uniformly. Persists the launched batch so the combined console survives a
     * reload. Peers are resolved from the shared {@see SiteSyncPeers} set.
     *
     * @param  list<string>  $peerIds
     * @return array{queued: list<string>, skipped: int}
     */
    public function sync(Site $context, array $peerIds, ?string $auditUserId = null): array
    {
        $ids = array_values(array_unique(array_map('strval', $peerIds)));
        $peers = SiteSyncPeers::forSite($context)->keyBy(fn (Site $s): string => (string) $s->id);

        $queued = [];
        $skipped = 0;
        foreach ($ids as $id) {
            $peer = $peers->get($id);
            if ($peer === null || ! Gate::allows('update', $peer)) {
                $skipped++;

                continue;
            }
            if ($this->deploy($peer, SiteDeployment::TRIGGER_MANUAL, $auditUserId)) {
                $queued[] = (string) $peer->id;
            } else {
                $skipped++;
            }
        }

        if ($queued !== []) {
            Cache::put(self::SYNC_BATCH_KEY_PREFIX.$context->id, [
                'ids' => $queued,
                'started_at' => now()->toIso8601String(),
            ], 1800);
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /** Clear the launched sync batch for a context site (back to peer selection). */
    public function clearSyncBatch(Site $context): void
    {
        Cache::forget(self::SYNC_BATCH_KEY_PREFIX.$context->id);
    }

    /**
     * Run a smart fixer detected from the failed deploy output (e.g. "npm not
     * found" → install Node.js & npm). Refuses (returns null) when a fixer is
     * already in flight for the site or the key is unknown. Returns the queued
     * ConsoleAction so the caller can stream its output.
     */
    public function runFixer(Site $site, string $key, ?string $auditUserId = null): ?ConsoleAction
    {
        if ($this->inFlightFixer($site) !== null) {
            return null;
        }

        $spec = SiteFixers::spec($key);
        if ($spec === null) {
            return null;
        }

        $run = ConsoleAction::query()->create([
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'kind' => 'site_remediate',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => (string) $spec['label'],
            'user_id' => $auditUserId,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        RunSiteFixerJob::dispatch((string) $run->id, (string) $site->id, $key);

        return $run;
    }

    /** Assemble the one snapshot both surfaces render from. */
    public function status(Site $site): DeployStatus
    {
        $latest = $this->latestDeployment($site);

        return new DeployStatus(
            latest: $latest,
            inProgress: $this->inProgress($site, $latest),
            lock: $this->deployLockInfo($site),
            syncBatch: $this->syncBatch($site),
            selectedPeerIds: $this->selectedPeerIds($site),
            fixerInFlight: $this->inFlightFixer($site),
            completedFixerKeys: $this->completedFixerKeys($site, $latest),
        );
    }

    public function latestDeployment(Site $site): ?SiteDeployment
    {
        return $site->deployments()->latest()->first();
    }

    /**
     * @return array{started_at?: string, deployment_id?: ?string}|null
     */
    public function deployLockInfo(Site $site): ?array
    {
        return Cache::get(self::ACTIVE_KEY_PREFIX.$site->id);
    }

    /**
     * Whether the deploy button should show the spinning "Deploying…" state.
     *
     * True only while a run is genuinely live: the latest deployment is RUNNING,
     * or the optimistic lock is still held AND no terminal run has landed since
     * it was taken. The lock is a 600s marker set on click; a self-deploy can
     * kill the worker that runs RunSiteDeploymentJob's lock-cleanup `finally`,
     * leaving the lock set for its full TTL — so we stop as soon as THIS run
     * lands terminal, and otherwise honour the lock only within the brief
     * queue-pickup window. (Previously duplicated verbatim in the trait's
     * deployIsInProgress() and DeployControl::inProgress().)
     */
    public function inProgress(Site $site, ?SiteDeployment $latest = null): bool
    {
        $latest ??= $this->latestDeployment($site);

        if ($latest !== null && $latest->status === SiteDeployment::STATUS_RUNNING) {
            return true;
        }

        $lock = $this->deployLockInfo($site);
        $startedAt = isset($lock['started_at']) ? Carbon::parse($lock['started_at']) : null;
        if ($startedAt === null) {
            return false;
        }

        $terminalSinceLock = $latest !== null
            && in_array($latest->status, [
                SiteDeployment::STATUS_FAILED,
                SiteDeployment::STATUS_SUCCESS,
                SiteDeployment::STATUS_SKIPPED,
            ], true)
            && $latest->created_at?->greaterThanOrEqualTo($startedAt->subSeconds(2));

        return ! $terminalSinceLock && $startedAt->greaterThan(now()->subSeconds(90));
    }

    /**
     * @return array{ids: list<string>, started_at?: string}|null
     */
    public function syncBatch(Site $context): ?array
    {
        $batch = Cache::get(self::SYNC_BATCH_KEY_PREFIX.$context->id);
        if (! is_array($batch) || ! is_array($batch['ids'] ?? null)) {
            return null;
        }

        return ['ids' => array_values(array_map('strval', $batch['ids'])), 'started_at' => $batch['started_at'] ?? null];
    }

    /**
     * The persisted Sync peer selection, defaulting to every peer the first time
     * (matches the surfaces' historic "select all" default). Mirrored across the
     * page + sidebar so a tick in one shows in the other.
     *
     * @return list<string>
     */
    public function selectedPeerIds(Site $context): array
    {
        $stored = Cache::get(self::SYNC_SELECTION_KEY_PREFIX.$context->id);
        if (is_array($stored)) {
            return array_values(array_map('strval', $stored));
        }

        return SiteSyncPeers::forSite($context)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Persist the Sync peer selection (kept to the valid peer set) so both
     * surfaces read the same checkboxes.
     *
     * @param  list<string>  $peerIds
     * @return list<string>  the stored (validated) selection
     */
    public function setSelectedPeerIds(Site $context, array $peerIds): array
    {
        $valid = SiteSyncPeers::forSite($context)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $selection = array_values(array_intersect(
            array_map('strval', $peerIds),
            $valid,
        ));

        Cache::put(self::SYNC_SELECTION_KEY_PREFIX.$context->id, $selection, 1800);

        return $selection;
    }

    /**
     * The smart-fix that's still queued/running for this site, if any, so its
     * "Processing…" state survives a reload and both surfaces disable their fix
     * buttons identically.
     */
    public function inFlightFixer(Site $site): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->id)
            ->where('kind', 'site_remediate')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->latest()
            ->first();
    }

    /**
     * Fixer keys already run since the latest deploy finished — dropped from the
     * "Suggested fixes" list so a fix isn't offered twice. Scoped to fixes run
     * after the deploy so a recurrence still surfaces the fix again.
     *
     * @return list<string>
     */
    public function completedFixerKeys(Site $site, ?SiteDeployment $latest = null): array
    {
        $latest ??= $this->latestDeployment($site);
        $since = $latest?->finished_at ?? $latest?->created_at;

        $query = ConsoleAction::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->id)
            ->where('kind', 'site_remediate')
            ->where('status', ConsoleAction::STATUS_COMPLETED);

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $query->get(['label'])
            ->map(fn (ConsoleAction $run): ?string => SiteFixers::keyForLabel((string) $run->label))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
