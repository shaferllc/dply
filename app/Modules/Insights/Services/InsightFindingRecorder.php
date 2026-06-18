<?php

namespace App\Modules\Insights\Services;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;

class InsightFindingRecorder
{
    public function __construct(
        protected InsightsNotificationDispatcher $notifications,
        protected InsightCorrelationService $correlation,
    ) {}

    /**
     * @param  array<string, mixed> $candidates
     */
    public function syncCandidates(Server $server, ?Site $site, string $insightKey, array $candidates): void
    {
        if ($candidates === []) {
            $this->resolveAllOpenForKey($server, $site, $insightKey);

            return;
        }

        $hashes = array_map(fn (InsightCandidate $c) => $c->dedupeHash, $candidates);

        $stale = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', $insightKey)
            ->where('status', InsightFinding::STATUS_OPEN)
            ->when($site, fn ($q) => $q->where('site_id', $site->id), fn ($q) => $q->whereNull('site_id'))
            ->whereNotIn('dedupe_hash', $hashes)
            ->get();

        foreach ($stale as $row) {
            $this->resolveFinding($row);
        }

        foreach ($candidates as $c) {
            $this->upsertCandidate($server, $site, $c);
        }
    }

    protected function resolveAllOpenForKey(Server $server, ?Site $site, string $insightKey): void
    {
        $q = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', $insightKey)
            ->where('status', InsightFinding::STATUS_OPEN)
            ->when($site, fn ($q) => $q->where('site_id', $site->id), fn ($q) => $q->whereNull('site_id'));

        foreach ($q->get() as $row) {
            $this->resolveFinding($row);
        }
    }

    protected function resolveFinding(InsightFinding $finding): void
    {
        if ($finding->status !== InsightFinding::STATUS_OPEN) {
            return;
        }

        $finding->loadMissing('server.organization');
        $server = $finding->server;
        $org = $server?->organization;

        $finding->forceFill([
            'status' => InsightFinding::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        if ($org !== null && $server !== null) {
            $this->notifications->notifyIfSubscribed($server, $finding->fresh(), false, 'resolved');
        }
    }

    protected function upsertCandidate(Server $server, ?Site $site, InsightCandidate $c): void
    {
        $existing = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('insight_key', $c->insightKey)
            ->where('dedupe_hash', $c->dedupeHash)
            ->when($site, fn ($q) => $q->where('site_id', $site->id), fn ($q) => $q->whereNull('site_id'))
            ->first();

        $now = now();
        $severity = $c->severity;
        if (! in_array($severity, [
            InsightFinding::SEVERITY_INFO,
            InsightFinding::SEVERITY_WARNING,
            InsightFinding::SEVERITY_CRITICAL,
        ], true)) {
            $severity = InsightFinding::SEVERITY_WARNING;
        }

        $kind = $c->kind === InsightFinding::KIND_SUGGESTION
            ? InsightFinding::KIND_SUGGESTION
            : InsightFinding::KIND_PROBLEM;

        // Suggestion cooldown: when the user explicitly ignored this finding, don't reopen
        // it on the next scheduled run. After `cooldown_days` (default 30) it can re-emit.
        // Problems never get a cooldown — they need to surface again until actually resolved.
        if (
            $existing !== null
            && $kind === InsightFinding::KIND_SUGGESTION
            && $existing->status === InsightFinding::STATUS_IGNORED
            && $this->isWithinIgnoreCooldown($existing, $c->insightKey)
        ) {
            return;
        }

        if ($existing === null) {
            $correlation = $this->correlation->correlateForNewFinding($server);
            $row = InsightFinding::query()->create([
                'server_id' => $server->id,
                'site_id' => $site?->id,
                'team_id' => $server->team_id,
                'insight_key' => $c->insightKey,
                'kind' => $kind,
                'dedupe_hash' => $c->dedupeHash,
                'status' => InsightFinding::STATUS_OPEN,
                'severity' => $severity,
                'title' => $c->title,
                'body' => $c->body,
                'meta' => $c->meta,
                'correlation' => $correlation,
                'detected_at' => $now,
                'resolved_at' => null,
            ]);
            if ($this->shouldNotifySubscribers($c->insightKey, $kind)) {
                $this->notifications->notifyIfSubscribed($server, $row, wasReopened: false);
            }

            return;
        }

        if ($existing->status === InsightFinding::STATUS_OPEN) {
            $existing->forceFill([
                'kind' => $kind,
                'severity' => $severity,
                'title' => $c->title,
                'body' => $c->body,
                'meta' => $c->meta,
                'detected_at' => $now,
            ])->save();

            return;
        }

        $existing->forceFill([
            'kind' => $kind,
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => $severity,
            'title' => $c->title,
            'body' => $c->body,
            'meta' => $c->meta,
            'detected_at' => $now,
            'resolved_at' => null,
            // Reopened — clear any prior org-level banner ack so the
            // recurrence resurfaces in the Insights banner.
            'acknowledged_at' => null,
            'acknowledged_by_user_id' => null,
            // Reopened — clear ignore breadcrumbs so a future ignore restarts the cooldown.
            'ignored_at' => null,
            'ignored_by_user_id' => null,
        ])->save();

        if ($this->shouldNotifySubscribers($c->insightKey, $kind)) {
            $this->notifications->notifyIfSubscribed($server, $existing->fresh(), wasReopened: true);
        }
    }

    /**
     * Suggestions never page subscribers — they're tuning recommendations, not problems.
     * Per-insight `notify_subscribers => false` (e.g. heartbeat) wins regardless of kind.
     */
    protected function shouldNotifySubscribers(string $insightKey, string $kind): bool
    {
        if ($kind === InsightFinding::KIND_SUGGESTION) {
            return false;
        }

        return (bool) config('insights.insights.'.$insightKey.'.notify_subscribers', true);
    }

    /**
     * Per-insight `cooldown_days` controls how long an ignored suggestion stays silent.
     * Falls back to global `insights.suggestion_cooldown_days` (default 30). A non-positive
     * value means "no cooldown" — the suggestion can reopen immediately on next match.
     */
    protected function isWithinIgnoreCooldown(InsightFinding $finding, string $insightKey): bool
    {
        if ($finding->ignored_at === null) {
            return false;
        }
        $days = (int) (config('insights.insights.'.$insightKey.'.cooldown_days')
            ?? config('insights.suggestion_cooldown_days', 30));
        if ($days <= 0) {
            return false;
        }

        return $finding->ignored_at->copy()->addDays($days)->isFuture();
    }
}
