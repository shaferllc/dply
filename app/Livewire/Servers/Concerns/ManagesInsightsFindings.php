<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ApplyInsightFixJob;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesInsightsFindings
{


    /**
     * Open the per-finding detail modal. Scope guard: only findings on
     * THIS server (server-scoped, not site-scoped) can be inspected here —
     * site-specific findings have their own page.
     */
    public function openFindingDetail(int $findingId): void
    {
        $this->authorize('view', $this->server);

        $exists = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->whereKey($findingId)
            ->exists();

        if (! $exists) {
            return;
        }

        $this->detailFindingId = $findingId;
    }

    public function closeFindingDetail(): void
    {
        $this->detailFindingId = null;
    }

    /**
     * Decorated finding for the detail modal. Returns null when no finding
     * is selected or it can no longer be loaded (e.g., resolved away while
     * the modal was open).
     *
     * @return array{
     *     finding: InsightFinding,
     *     config: array<string, mixed>|null,
     *     label: string|null,
     *     signalRows: array<string, scalar|array<int|string, mixed>|null>,
     *     fixHistory: array{
     *         applied_at: Carbon|null,
     *         applied_by: ?string,
     *         output: ?string,
     *         failed_reason: ?string,
     *         refused_reason: ?string,
     *         backup_path: ?string,
     *     },
     *     correlationFindings: Collection<int, InsightFinding>,
     *     acknowledgedByName: ?string,
     *     ignoredByName: ?string,
     *     actions: array{
     *         canRerun: bool,
     *         canApplyFix: bool,
     *         canRevertFix: bool,
     *         canAcknowledge: bool,
     *         canUnacknowledge: bool,
     *         canIgnore: bool,
     *         canUnignore: bool,
     *     }
     * }|null
     */
    #[Computed]
    public function selectedFindingDetail(): ?array
    {
        if ($this->detailFindingId === null) {
            return null;
        }

        $finding = InsightFinding::query()
            ->with('acknowledgedBy:id,name,email')
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->whereKey($this->detailFindingId)
            ->first();

        if ($finding === null) {
            return null;
        }

        $config = config('insights.insights.'.$finding->insight_key);
        $config = is_array($config) ? $config : null;

        $meta = is_array($finding->meta) ? $finding->meta : [];
        $signal = is_array($meta['signal'] ?? null) ? $meta['signal'] : [];
        // Flatten nested signal arrays so the modal can render a single
        // key/value table without needing recursive markup.
        $signalRows = $signal === [] ? [] : Arr::dot($signal);

        $parseTs = static fn (mixed $v): ?\Illuminate\Support\Carbon => (is_string($v) && $v !== '') ? Carbon::parse($v) : null;

        $appliedAt = $parseTs($meta['fix_applied_at'] ?? null);
        $failedAt = $parseTs($meta['fix_failed_at'] ?? null);
        $refusedAt = $parseTs($meta['fix_refused_at'] ?? null);
        $runStartedAt = $parseTs($meta['fix_run_started_at'] ?? null);

        $appliedByName = null;
        $appliedById = $meta['fix_applied_by'] ?? $meta['fix_failed_by'] ?? $meta['fix_refused_by'] ?? null;
        if (is_int($appliedById) || (is_string($appliedById) && $appliedById !== '')) {
            $appliedByName = User::query()->whereKey($appliedById)->value('name');
        }

        // Derive a single run status from the terminal/in-flight meta
        // keys. The job (ApplyInsightFixJob) writes one of:
        //   fix_applied_at  → succeeded
        //   fix_failed_at   → failed
        //   fix_refused_at  → refused at preflight
        // runFix() stamps fix_run_started_at and clears the terminal
        // keys, so the modal can show "queued" until the job lands.
        $runStatus = match (true) {
            $appliedAt !== null => 'succeeded',
            $failedAt !== null => 'failed',
            $refusedAt !== null => 'refused',
            $runStartedAt !== null => 'queued',
            default => 'idle',
        };

        $ignoredByName = null;
        if ($finding->ignored_by_user_id !== null) {
            $ignoredByName = User::query()->whereKey($finding->ignored_by_user_id)->value('name');
        }

        $correlationIds = [];
        if (is_array($finding->correlation)) {
            foreach ($finding->correlation as $entry) {
                if (is_int($entry)) {
                    $correlationIds[] = $entry;
                } elseif (is_array($entry) && isset($entry['finding_id']) && is_int($entry['finding_id'])) {
                    $correlationIds[] = $entry['finding_id'];
                }
            }
        }
        $correlationFindings = $correlationIds === []
            ? collect()
            : InsightFinding::query()
                ->where('server_id', $this->server->id)
                ->whereNull('site_id')
                ->whereKey($correlationIds)
                ->where('id', '!=', $finding->id)
                ->orderByDesc('detected_at')
                ->limit(10)
                ->get(['id', 'insight_key', 'severity', 'status', 'title', 'detected_at']);

        $fixConfig = is_array($config['fix'] ?? null) ? $config['fix'] : null;
        $hasFixHandler = $fixConfig !== null && ($fixConfig['handler'] ?? null);
        $backupPath = is_string($meta['backup_path'] ?? null) ? $meta['backup_path'] : null;

        $isOpen = $finding->isOpen();
        $isProblem = $finding->kind !== InsightFinding::KIND_SUGGESTION;

        // canRunFix: handler is wired AND the fix isn't currently
        // in-flight. The button re-enables once a terminal key lands.
        $fixInFlight = $runStatus === 'queued';
        $canRunFix = $isOpen && $hasFixHandler && ! $fixInFlight;

        return [
            'finding' => $finding,
            'config' => $config,
            'label' => is_string($config['label'] ?? null) ? $config['label'] : null,
            'signalRows' => $signalRows,
            'fixHistory' => [
                'run_status' => $runStatus,
                'run_started_at' => $runStartedAt,
                'applied_at' => $appliedAt,
                'failed_at' => $failedAt,
                'refused_at' => $refusedAt,
                'applied_by' => $appliedByName,
                'output' => is_string($meta['fix_output'] ?? null) ? $meta['fix_output'] : null,
                'failed_reason' => is_string($meta['fix_failure_reason'] ?? null) ? $meta['fix_failure_reason'] : null,
                'refused_reason' => is_string($meta['fix_refusal_reason'] ?? null) ? $meta['fix_refusal_reason'] : null,
                'backup_path' => $backupPath,
            ],
            'correlationFindings' => $correlationFindings,
            'acknowledgedByName' => $finding->acknowledgedBy?->name,
            'ignoredByName' => $ignoredByName,
            'actions' => [
                'canRerun' => true,
                'canRunFix' => $canRunFix,
                'canApplyFix' => $isOpen && $hasFixHandler,
                'canRevertFix' => $backupPath !== null && $backupPath !== '',
                'canAcknowledge' => $isOpen && $isProblem && $finding->acknowledged_at === null,
                'canUnacknowledge' => $isOpen && $isProblem && $finding->acknowledged_at !== null,
                'canIgnore' => $isOpen && ! $isProblem,
                'canUnignore' => $finding->isIgnored(),
                'fixInFlight' => $fixInFlight,
            ],
        ];
    }

    public function unignoreFinding(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_IGNORED)
            ->whereKey($findingId)
            ->first();

        if ($finding === null) {
            return;
        }

        // Reopen and clear ignore breadcrumbs so a future ignore restarts the cooldown clock.
        $finding->forceFill([
            'status' => InsightFinding::STATUS_OPEN,
            'ignored_at' => null,
            'ignored_by_user_id' => null,
        ])->save();

        $org = $this->server->organization;
        if ($org instanceof Organization) {
            audit_log($org, $user, 'insight.unignored', $this->server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
            ]);
        }

        $this->closeFindingDetailIfMatches($finding->id);
    }

    public function ignoreFinding(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        // Ignore is for suggestions only. Problems should be fixed or auto-resolved, not silenced.
        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->where('kind', InsightFinding::KIND_SUGGESTION)
            ->whereKey($findingId)
            ->first();

        if ($finding === null) {
            return;
        }

        $finding->forceFill([
            'status' => InsightFinding::STATUS_IGNORED,
            'ignored_at' => now(),
            'ignored_by_user_id' => $user->id,
        ])->save();

        $org = $this->server->organization;
        if ($org instanceof Organization) {
            audit_log($org, $user, 'insight.ignored', $this->server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
            ]);
        }

        $this->closeFindingDetailIfMatches($finding->id);
    }

    public function unacknowledgeFinding(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        // Only acknowledged-and-still-open findings can be un-acknowledged. We don't reach back
        // into resolved/ignored to prevent surprising state transitions.
        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->whereNotNull('acknowledged_at')
            ->whereKey($findingId)
            ->first();

        if ($finding === null) {
            return;
        }

        $finding->forceFill([
            'acknowledged_at' => null,
            'acknowledged_by_user_id' => null,
        ])->save();

        $org = $this->server->organization;
        if ($org instanceof Organization) {
            audit_log($org, $user, 'insight.unacknowledged', $this->server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
                'severity' => $finding->severity,
            ]);
        }

        $this->closeFindingDetailIfMatches($finding->id);
    }

    public function acknowledgeFinding(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->whereNull('acknowledged_at')
            ->whereKey($findingId)
            ->first();

        if ($finding === null) {
            return;
        }

        $finding->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $user->id,
        ])->save();

        $org = $this->server->organization;
        if ($org instanceof Organization) {
            audit_log($org, $user, 'insight.acknowledged', $this->server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
                'severity' => $finding->severity,
            ]);
        }

        $this->closeFindingDetailIfMatches($finding->id);
    }

    /**
     * Close the detail modal only when the action targeted the *currently
     * displayed* finding. Without this guard, a row-level action button
     * (e.g., the existing inline "Acknowledge" on the banner) would
     * unexpectedly close an open detail modal pointing at a *different*
     * finding.
     */
    protected function closeFindingDetailIfMatches(int $findingId): void
    {
        if ($this->detailFindingId === $findingId) {
            $this->closeFindingDetail();
        }
    }
}
