<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Jobs\CollectServerQueueSnapshotsJob;
use App\Models\Site;
use App\Models\SiteQueueSnapshot;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Support\Sites\SiteQueueAlertRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
    /**
     * Consecutive failed reads before anyone is paged. One dropped SSH
     * connection is noise; two sweeps in a row is ten minutes of blindness.
     */
    public const UNREADABLE_AFTER_FAILURES = 2;

    private const UNREADABLE_KEY = '*|unreadable';

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
            ->get()
            ->groupBy('queue');

        $paused = array_keys((array) data_get($site->meta, 'queue_paused', []));
        $state = (array) ($stored['state'] ?? []);
        $changed = false;

        // Being here means the sweep just read this site: that is the
        // recovery for an unreadable alert.
        $this->step($state, self::UNREADABLE_KEY, false, $changed);

        foreach ($rows as $queue => $samples) {
            $rules = SiteQueueAlertRules::for($site, (string) $queue);

            if ($rules->isSilent()) {
                continue;
            }

            $isPaused = in_array((string) $queue, $paused, true);

            foreach ($this->rulesFor($rules, $samples, $window, $isPaused) as $rule => $fired) {
                if ($this->step($state, $queue.'|'.$rule, $fired, $changed)) {
                    $this->publish($site, (string) $queue, $rule, $samples, $window, $rules);
                }
            }
        }

        if ($changed) {
            $stored['state'] = $state;
            $site->putMeta('queue_alerts', $stored);
        }
    }

    /**
     * The sweep could not read this site — SSH failed, or the app did not
     * answer. Silence used to read as health; after enough misses in a row it
     * pages instead.
     */
    public function evaluateUnreadable(Site $site): void
    {
        $stored = (array) data_get($site->meta, 'queue_alerts', []);

        if (($stored['enabled'] ?? true) === false) {
            return;
        }

        $error = (array) data_get($site->meta, 'queue_read_error', []);
        $state = (array) ($stored['state'] ?? []);
        $changed = false;

        $fired = (int) ($error['failures'] ?? 0) >= self::UNREADABLE_AFTER_FAILURES;

        if ($this->step($state, self::UNREADABLE_KEY, $fired, $changed)) {
            $this->publishUnreadable($site, (string) ($error['error'] ?? ''));
        }

        if ($changed) {
            $stored['state'] = $state;
            $site->putMeta('queue_alerts', $stored);
        }
    }

    /**
     * Advance one rule's state; true when it should notify now.
     *
     * @param  array<string, mixed>  $state
     */
    private function step(array &$state, string $key, bool $fired, bool &$changed): bool
    {
        $active = isset($state[$key]);

        if (! $fired) {
            if ($active) {
                // Recovered. Clearing the marker is what makes the NEXT
                // occurrence a fresh alert rather than a silent repeat.
                unset($state[$key]);
                $changed = true;
            }

            return false;
        }

        if ($active && ! $this->cooledDown((string) $state[$key])) {
            return false;
        }

        $state[$key] = now()->toIso8601String();
        $changed = true;

        return true;
    }

    /**
     * @param  Collection<int, SiteQueueSnapshot>  $samples
     * @return array<string, bool>
     */
    private function rulesFor(SiteQueueAlertRules $rules, Collection $samples, int $window, bool $isPaused): array
    {
        $latest = $samples->first();

        if ($latest === null) {
            return [];
        }

        $sustained = $this->within($samples, $window);

        return [
            // A burst of failures — the shape of a deploy that broke every
            // job. Counted from the failer's own total, so retrying or
            // clearing failed jobs reads as zero new failures, never as news.
            'failures' => $rules->failuresAtLeast !== null
                && $this->newFailures($sustained) >= $rules->failuresAtLeast,

            // Jobs waiting and nothing draining them: the one failure that is
            // wrong at any depth, any hour, on any site. Null processes means
            // the count could not be read — unknown is not zero, and paging on
            // it told every healthy queue:work site it had no worker. A paused
            // queue has no worker because someone stopped it on purpose.
            'no_worker' => $rules->noWorker
                && ! $isPaused
                && (int) ($latest->pending ?? 0) > 0
                && $latest->worker_processes !== null
                && (int) $latest->worker_processes === 0,

            // Deep AND staying deep. Requiring every sample in the window to be
            // over the line means a burst that drains does not page anyone;
            // two samples minimum, so a single reading cannot look sustained.
            // Still applies while paused: a pause that keeps filling is news.
            'backlog' => $rules->pendingOver !== null
                && $sustained->count() >= 2
                && $sustained->every(fn (SiteQueueSnapshot $s): bool => (int) ($s->pending ?? 0) > $rules->pendingOver),

            // Something may be draining, but the front of the queue is stale —
            // the shape of a poison job or a worker stuck on one item.
            'stale' => $rules->oldestOverSeconds !== null
                && (int) ($latest->oldest_pending_age_s ?? 0) > $rules->oldestOverSeconds,
        ];
    }

    /**
     * @param  Collection<int, SiteQueueSnapshot>  $samples  newest first
     * @return Collection<int, SiteQueueSnapshot>
     */
    private function within(Collection $samples, int $window): Collection
    {
        return $samples
            ->filter(fn (SiteQueueSnapshot $s): bool => $s->captured_at >= now()->subMinutes($window))
            ->values();
    }

    /**
     * Failures added between the oldest and newest sample in the window. A
     * total that went down was retried or cleared, which is zero new ones.
     *
     * @param  Collection<int, SiteQueueSnapshot>  $samples  newest first
     */
    private function newFailures(Collection $samples): int
    {
        $newest = $samples->first()?->failed_total;
        $oldest = $samples->last()?->failed_total;

        if ($samples->count() < 2 || $newest === null || $oldest === null) {
            return 0;
        }

        return max(0, $newest - $oldest);
    }

    private function cooledDown(string $firedAt): bool
    {
        $minutes = max(5, (int) config('dply.queue_alerts.cooldown_minutes', 60));

        return strtotime($firedAt) < now()->subMinutes($minutes)->getTimestamp();
    }

    /**
     * @param  Collection<int, SiteQueueSnapshot>  $samples  newest first
     */
    private function publish(Site $site, string $queue, string $rule, Collection $samples, int $window, SiteQueueAlertRules $rules): void
    {
        if ($site->organization === null) {
            return;
        }

        $latest = $samples->first();
        $pending = (int) ($latest->pending ?? 0);

        [$title, $body] = match ($rule) {
            'failures' => [
                __('Jobs are failing on :q on :site', ['q' => $queue, 'site' => $site->name]),
                trim(__(':n job(s) failed in the last :m minutes.', [
                    'n' => $this->newFailures($this->within($samples, $window)),
                    'm' => $window,
                ]).' '.($latest?->last_failure !== null ? __('Latest: :e', ['e' => $latest->last_failure]) : '')),
            ],
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
            eventKey: match ($rule) {
                'no_worker' => 'site.queue.no_worker',
                'failures' => 'site.queue.failures',
                default => 'site.queue.backlog',
            },
            subject: $site,
            title: '['.config('app.name').'] '.$title,
            body: $body,
            // Straight to the failed list: the exception is the first thing
            // anyone opens the page to read.
            url: $this->queueUrl($site).($rule === 'failures' ? '?activity=failed' : ''),
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

    private function publishUnreadable(Site $site, string $error): void
    {
        if ($site->organization === null) {
            return;
        }

        $this->publisher->publish(
            eventKey: 'site.queue.unreadable',
            subject: $site,
            title: '['.config('app.name').'] '.__('Can’t read the queues on :site', ['site' => $site->name]),
            body: __('The last :n queue checks failed, so nothing on this site is being watched. :e', [
                'n' => self::UNREADABLE_AFTER_FAILURES,
                'e' => Str::limit($error, 300),
            ]),
            url: $this->queueUrl($site),
            metadata: [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'rule' => 'unreadable',
                'error' => Str::limit($error, 300),
            ],
        );
    }

    private function queueUrl(Site $site): string
    {
        return route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'queue'], absolute: true);
    }
}
