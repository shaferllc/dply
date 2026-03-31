<?php

namespace App\Services\Insights;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Integrations\InsightsWebhookDispatcher;

class InsightFindingRecorder
{
    public function __construct(
        protected InsightsNotificationDispatcher $notifications,
        protected InsightCorrelationService $correlation,
        protected InsightsWebhookDispatcher $webhooks,
    ) {}

    /**
     * @param  list<InsightCandidate>  $candidates
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
            $this->webhooks->dispatchInsightResolved($org, $server, $finding->fresh());
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

        if ($existing === null) {
            $correlation = $this->correlation->correlateForNewFinding($server);
            $row = InsightFinding::query()->create([
                'server_id' => $server->id,
                'site_id' => $site?->id,
                'team_id' => $server->team_id,
                'insight_key' => $c->insightKey,
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
            if ($this->shouldNotifySubscribers($c->insightKey)) {
                $this->notifications->notifyIfSubscribed($server, $row, wasReopened: false);
            }
            if ($server->organization !== null) {
                $this->webhooks->dispatchInsightOpened($server->organization, $server, $row);
            }

            return;
        }

        if ($existing->status === InsightFinding::STATUS_OPEN) {
            $existing->forceFill([
                'severity' => $severity,
                'title' => $c->title,
                'body' => $c->body,
                'meta' => $c->meta,
                'detected_at' => $now,
            ])->save();

            return;
        }

        $existing->forceFill([
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => $severity,
            'title' => $c->title,
            'body' => $c->body,
            'meta' => $c->meta,
            'detected_at' => $now,
            'resolved_at' => null,
        ])->save();

        if ($this->shouldNotifySubscribers($c->insightKey)) {
            $this->notifications->notifyIfSubscribed($server, $existing->fresh(), wasReopened: true);
        }
        if ($server->organization !== null) {
            $this->webhooks->dispatchInsightOpened($server->organization, $server, $existing->fresh());
        }
    }

    protected function shouldNotifySubscribers(string $insightKey): bool
    {
        return (bool) config('insights.insights.'.$insightKey.'.notify_subscribers', true);
    }
}
