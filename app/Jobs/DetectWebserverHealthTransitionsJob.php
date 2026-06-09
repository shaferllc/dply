<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Notifications\WebserverHealthAlertNotification;
use App\Services\Servers\WebserverHealthThresholdResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Runs after each metric ingest. Reads the new snapshot's per-engine
 * health blocks, resolves thresholds, and fires
 * {@see WebserverHealthAlertNotification} on state TRANSITIONS only
 * (tripped → not tripped, or vice-versa). Per-engine/per-metric trip
 * state is stored on server.meta.webserver_health_alert_state so the
 * job is stateless from the queue's perspective.
 *
 * Edge-triggered (not level-triggered) to avoid one-alert-per-minute
 * spam: an engine that stays "down" only generates ONE notification
 * when it goes down, and ONE more when it comes back up.
 */
class DetectWebserverHealthTransitionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $serverId) {}

    public function handle(WebserverHealthThresholdResolver $resolver): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }

        // Most recent snapshot — the one whose ingest spawned this job.
        $snapshot = $server->metricSnapshots()
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();
        if ($snapshot === null) {
            return;
        }
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $healthBlocks = is_array($payload['webserver_health'] ?? null) ? $payload['webserver_health'] : [];

        $meta = is_array($server->meta) ? $server->meta : [];
        $alertState = is_array($meta['webserver_health_alert_state'] ?? null)
            ? $meta['webserver_health_alert_state']
            : [];

        $newState = $alertState;
        $recipients = $this->recipientsFor($server);

        foreach ($healthBlocks as $block) {
            if (! is_array($block) || ! isset($block['engine'])) {
                continue;
            }
            $engine = (string) $block['engine'];

            foreach ($this->metricsToCheckFor($block) as $metric => $observed) {
                $threshold = $resolver->resolve($server, $engine, $metric);
                $isTripped = $resolver->trips($threshold, $observed);
                $wasTripped = (bool) ($alertState[$engine][$metric]['tripped'] ?? false);

                if ($isTripped === $wasTripped) {
                    // Level-state — no transition, no notification.
                    continue;
                }

                $newState[$engine][$metric] = [
                    'tripped' => $isTripped,
                    'at' => now()->toIso8601String(),
                ];

                if ($threshold === null) {
                    continue; // Recovery on a metric that no longer has a threshold — silent.
                }

                if ($recipients === []) {
                    continue;
                }

                Notification::send($recipients, new WebserverHealthAlertNotification(
                    server: $server,
                    engine: $engine,
                    metric: $metric,
                    transition: $isTripped ? 'tripped' : 'recovered',
                    severity: $threshold['severity'],
                    observedValue: (float) $observed,
                    thresholdValue: $threshold['value'],
                    comparator: $threshold['comparator'],
                ));
            }
        }

        if ($newState !== $alertState) {
            $meta['webserver_health_alert_state'] = $newState;
            $server->forceFill(['meta' => $meta])->saveQuietly();
        }
    }

    /**
     * Pull the metric values out of an engine health block. Only metrics
     * that have configured thresholds are checked.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, float>
     */
    private function metricsToCheckFor(array $block): array
    {
        $out = [];
        if (array_key_exists('active_connections', $block) && is_numeric($block['active_connections'])) {
            $out['active_connections'] = (float) $block['active_connections'];
        }

        // The agent only emits requests_per_sec for some engines (apache,
        // openlitespeed). For others we'd need to derive it server-side
        // from snapshot deltas. Skip the 5xx metric for now — the rate
        // requires two-snapshot delta and the simple "latest snapshot"
        // check this job runs against isn't sufficient. A follow-up
        // implements the delta-aware check.
        return $out;
    }

    /**
     * Org admins (owner / admin roles) receive the alert. Mirrors the
     * recipient resolution used by BackupFailureNotification.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(Server $server): Collection
    {
        $org = $server->organization;
        if ($org === null) {
            return collect();
        }

        return $org->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->get();
    }
}
