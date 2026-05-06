<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Edge\EdgeRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Inspects the backend's reported status for one edge site and
 * transitions the local Site row to match. Runs once per edge
 * site per pass — driven by the dply:edge:poll-status CLI on a
 * 60-second cron.
 *
 * Backend status values are normalized to one of:
 *   - 'active'         → maps to Site::STATUS_CONTAINER_ACTIVE
 *   - 'provisioning'   → keeps STATUS_CONTAINER_PROVISIONING
 *   - 'failed'         → STATUS_CONTAINER_FAILED
 *   - 'unknown'        → no transition (treat as still polling)
 *
 * The backend.inspect() result also carries a live_url that we
 * persist into meta when present — handles the "ingress URL not
 * known until provisioning completes" case for both DO + App
 * Runner.
 */
class PollEdgeStatusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $site->usesContainerRuntime()) {
            return;
        }

        $backend = EdgeRouter::backendFor($site);
        $credential = EdgeRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            return;
        }

        try {
            $result = $backend->inspect($site, $credential);
        } catch (\Throwable $e) {
            // Inspect failures are transient — the backend may
            // be rate limiting us, or the region/account briefly
            // out. Don't transition status; record the most
            // recent error for visibility.
            $meta = is_array($site->meta) ? $site->meta : [];
            $meta['container'] = is_array($meta['container'] ?? null) ? $meta['container'] : [];
            $meta['container']['last_poll_error'] = $e->getMessage();
            $meta['container']['last_poll_at'] = now()->toIso8601String();
            $site->update(['meta' => $meta]);

            return;
        }

        $newStatus = $this->mapPhase($result['phase']);
        $update = [];

        if ($newStatus !== null && $newStatus !== $site->status) {
            $update['status'] = $newStatus;
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['container'] = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $meta['container']['last_phase'] = $result['phase'];
        $meta['container']['last_poll_at'] = now()->toIso8601String();
        unset($meta['container']['last_poll_error']);

        if (is_string($result['live_url']) && $result['live_url'] !== '') {
            $meta['container']['live_url'] = $result['live_url'];
        }

        $update['meta'] = $meta;
        $site->update($update);
    }

    private function mapPhase(string $phase): ?string
    {
        $phase = strtoupper($phase);

        // DO App Platform phases: ACTIVE, ACTIVE_RUNNING, BUILDING,
        //   DEPLOYING, ERROR, SUPERSEDED, PENDING_DEPLOY, etc.
        // App Runner statuses: RUNNING, OPERATION_IN_PROGRESS,
        //   CREATE_FAILED, DELETED, DELETE_FAILED, PAUSED, PAUSING.
        return match (true) {
            in_array($phase, ['ACTIVE', 'ACTIVE_RUNNING', 'RUNNING'], true) => Site::STATUS_CONTAINER_ACTIVE,
            in_array($phase, ['ERROR', 'CREATE_FAILED', 'DELETE_FAILED'], true) => Site::STATUS_CONTAINER_FAILED,
            in_array($phase, ['BUILDING', 'DEPLOYING', 'PENDING_DEPLOY', 'OPERATION_IN_PROGRESS'], true) => Site::STATUS_CONTAINER_PROVISIONING,
            default => null,
        };
    }
}
