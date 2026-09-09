<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Jobs\CollectServerQueueSnapshotsJob;
use App\Models\Site;
use App\Models\SiteQueueSnapshot;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Support\Sites\SiteQueueAlertRules;
use Illuminate\Support\Collection;

/**
 * Turn the five-minute depth sweep into "somebody should look at this".
 *
 * Runs where the readings already are, on the back of
 * {@see CollectServerQueueSnapshotsJob} — a separate scheduled pass
 * would re-read the same rows minutes later and alert on a state that had
 * already changed.
 *
 * Every rule is stateful on purpose. A backlog that stays deep for an hour is
 * ONE problem, not twelve notifications, so a fired rule stays quiet until the
 * queue recovers or the cooldown expires; recovery clears the state so a
 * recurrence is announced again.
 */
final class SiteQueueAlertEvaluator
{
    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function evaluate(Site $site): void
    {
        $stored = (array) data_get($site->meta, 'queue_alerts', []);

        if (($stored['enabled'] ?? true) === false) {
            return;
        }

        $window = max(
            SiteQueueAlertRules::MIN_SUSTAINED_MINUTES,
            (int) ($stored['defaults']['sustained_minutes'] ?? 10),
        );

        // One query for every queue on the site: the sustained check needs the
        // window anyway, and per-queue queries would multiply by queue count on
        // a job that already runs every five minutes for every server.
        $rows = SiteQueueSnapshot::query()
            ->where('site_id', $site->id)
            ->where('captured_at', '>=', now()->subMinutes($window + 5))
            ->orderByDesc('captured_at')
            ->limit(2000)
            ->get()
            ->groupBy('queue');

        $state = (array) ($stored['state'] ?? []);
        $changed = false;

        foreach ($rows as $queue => $samples) {
            $rules = SiteQueueAlertRules::for($site, (string) $queue);

            if ($rules->isSilent()) {
                continue;
            }

            foreach ($this->rulesFor($rules, $samples, $window) as $rule => $fired) {
                $key = $queue.'|'.$rule;
                $active = isset($state[$key]);

                if (! $fired) {
                    if ($active) {
                        // Recovered. Clearing the marker is what makes the NEXT
                        // occurrence a fresh alert rather than a silent repeat.
                        unset($state[$key]);
                        $changed = true;
                    }

                    continue;
                }

                if ($active && ! $this->cooledDown((string) $state[$key])) {
                    continue;
                }

                $state[$key] = now()->toIso8601String();
                $changed = true;

                $this->publish($site, (string) $queue, $rule, $samples->first(), $rules);
            }
        }

        if ($changed) {
            $meta = is_array($site->meta) ? $site->meta : [];
            $stored['state'] = $state;
            $meta['queue_alerts'] = $stored;
            $site->forceFill(['meta' => $meta])->save();
        }
    }

    /**
     * @param  Collection<int, SiteQueueSnapshot>  $samples
     * @return array<string, bool>
     */
    private function rulesFor(SiteQueueAlertRules $rules, Collection $samples, int $window): array
    {
        $latest = $samples->first();

        if ($latest === null) {
            return [];
        }

        $sustained = $samples
            ->filter(fn (SiteQueueSnapshot $s): bool => $s->captured_at >= now()->subMinutes($window))
            ->values();

        return [
            // Jobs waiting and nothing draining them: the one failure that is
            // wrong at any depth, any hour, on any site.
            'no_worker' => $rules->noWorker
                && (int) ($latest->pending ?? 0) > 0
                && (int) ($latest->worker_processes ?? 0) === 0,

            // Deep AND staying deep. Requiring every sample in the window to be
            // over the line means a burst that drains does not page anyone;
            // two samples minimum, so a single reading cannot look sustained.
            'backlog' => $rules->pendingOver !== null
                && $sustained->count() >= 2
                && $sustained->every(fn (SiteQueueSnapshot $s): bool => (int) ($s->pending ?? 0) > $rules->pendingOver),

            // Something may be draining, but the front of the queue is stale —
            // the shape of a poison job or a worker stuck on one item.
            'stale' => $rules->oldestOverSeconds !== null
                && (int) ($latest->oldest_pending_age_s ?? 0) > $rules->oldestOverSeconds,
        ];
    }

    private function cooledDown(string $firedAt): bool
    {
        $minutes = max(5, (int) config('dply.queue_alerts.cooldown_minutes', 60));

        return strtotime($firedAt) < now()->subMinutes($minutes)->getTimestamp();
    }

    private function publish(Site $site, string $queue, string $rule, ?SiteQueueSnapshot $latest, SiteQueueAlertRules $rules): void
    {
        if ($site->organization === null) {
            return;
        }

        $pending = (int) ($latest->pending ?? 0);

        [$title, $body] = match ($rule) {
            'no_worker' => [
                __('Queue :q on :site has no worker', ['q' => $queue, 'site' => $site->name]),
                __(':n job(s) are waiting and nothing is draining them. A worker is stopped, crashed, or was never created.', ['n' => $pending]),
            ],
            'backlog' => [
                __('Queue :q on :site is backing up', ['q' => $queue, 'site' => $site->name]),
                __(':n jobs waiting, above :t for the last :m minutes.', [
                    'n' => $pending,
                    't' => (int) $rules->pendingOver,
                    'm' => $rules->sustainedMinutes,
                ]),
            ],
            default => [
                __('Queue :q on :site has a stale job', ['q' => $queue, 'site' => $site->name]),
                __('The oldest waiting job has been there :s seconds.', ['s' => (int) ($latest->oldest_pending_age_s ?? 0)]),
            ],
        };

        $this->publisher->publish(
            eventKey: $rule === 'no_worker' ? 'site.queue.no_worker' : 'site.queue.backlog',
            subject: $site,
            title: '['.config('app.name').'] '.$title,
            body: $body,
            url: route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'queue'], absolute: true),
            metadata: [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'queue' => $queue,
                'rule' => $rule,
                'pending' => $pending,
                'oldest_pending_age_s' => $latest->oldest_pending_age_s ?? null,
                'worker_processes' => $latest->worker_processes ?? null,
            ],
        );
    }
}
