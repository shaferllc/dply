<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Exceptions\ServerlessDeployCancelledException;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Records fine-grained serverless deploy progress so the deploy journey can
 * show live sub-steps — the FaaS counterpart to the per-step list on a VM
 * provision journey.
 *
 * A DigitalOcean Functions deploy runs as one synchronous job, so there is
 * no phase runner emitting structured results. This recorder lets the
 * artifact builder and action deployer mark sub-steps as they go: each call
 * upserts a step into the running deployment's `phase_results['serverless']`
 * list, which the polling journey renders.
 *
 * It locates the deployment from the Site itself (there is exactly one
 * running deploy per site — the deploy lock guarantees it), so callers need
 * no SiteDeployment handle. When nothing is running — e.g. the builder is
 * exercised directly in a test — every call is a silent no-op.
 */
class ServerlessDeployProgress
{
    /** phase_results key the journey reads sub-steps from. */
    public const PHASE = 'serverless';

    public const STATE_ACTIVE = 'active';

    public const STATE_DONE = 'done';

    public const STATE_FAILED = 'failed';

    /** Cache key prefix holding a pending cancel request (the deployment id). */
    private const CANCEL_PREFIX = 'serverless-deploy-cancel:';

    /**
     * Flag the running deploy for cancellation. The next step checkpoint
     * aborts it. Keyed by deployment id so a stale request can never kill a
     * later deploy of the same function.
     */
    public function requestCancel(Site $site, string $deploymentId): void
    {
        Cache::put(self::CANCEL_PREFIX.$site->id, $deploymentId, now()->addMinutes(15));
    }

    /**
     * Abort the deploy if the operator has requested cancellation. Called at
     * each step boundary, so cancellation lands between steps (it cannot
     * interrupt an in-flight composer install or upload mid-stream).
     */
    public function checkpoint(Site $site): void
    {
        $requested = Cache::get(self::CANCEL_PREFIX.$site->id);
        if ($requested === null) {
            return;
        }

        $deployment = $this->runningDeployment($site);
        if ($deployment !== null && $requested === $deployment->id) {
            Cache::forget(self::CANCEL_PREFIX.$site->id);
            throw new ServerlessDeployCancelledException('Deploy cancelled by operator.');
        }
    }

    public function active(Site $site, string $key, string $label, string $detail = ''): void
    {
        $this->checkpoint($site);
        $this->step($site, $key, $label, self::STATE_ACTIVE, $detail);
    }

    public function done(Site $site, string $key, string $label, string $detail = ''): void
    {
        $this->step($site, $key, $label, self::STATE_DONE, $detail);
    }

    /**
     * Upsert one sub-step into the running deployment's serverless phase.
     *
     * Each step carries timing: `active` stamps `started_at`; `done` /
     * `failed` stamp `finished_at` and compute `duration_ms` against the
     * step's own start — so the journey can show how long each step took.
     */
    public function step(Site $site, string $key, string $label, string $state, string $detail = ''): void
    {
        $deployment = $this->runningDeployment($site);

        if ($deployment === null) {
            return;
        }

        $steps = $deployment->phaseSteps(self::PHASE);

        $existing = null;
        $index = null;
        foreach ($steps as $i => $step) {
            if (($step['key'] ?? null) === $key) {
                $existing = $step;
                $index = $i;
                break;
            }
        }

        $now = now();
        $startedAt = is_string($existing['started_at'] ?? null) ? $existing['started_at'] : null;
        if ($startedAt === null && $state === self::STATE_ACTIVE) {
            $startedAt = $now->toIso8601String();
        }

        $finishedAt = null;
        $durationMs = null;
        if (in_array($state, [self::STATE_DONE, self::STATE_FAILED], true)) {
            $finishedAt = $now->toIso8601String();
            if ($startedAt !== null) {
                $durationMs = max(0, (int) round(Carbon::parse($startedAt)->diffInMilliseconds($now)));
            }
        }

        $entry = [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'detail' => $detail,
            'ok' => $state === self::STATE_DONE,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
        ];

        if ($index === null) {
            $steps[] = $entry;
        } else {
            $steps[$index] = $entry;
        }

        $deployment->recordPhaseResults(self::PHASE, array_values($steps));
    }

    private function runningDeployment(Site $site): ?SiteDeployment
    {
        return SiteDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->latest('created_at')
            ->first();
    }
}
