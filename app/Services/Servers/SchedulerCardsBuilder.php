<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Str;

/**
 * Assembles the per-site cards rendered on the Schedule workspace page (Q11).
 *
 * The page is site-centric (operators reason by "the marketing site's
 * scheduler", not by row); this builder pivots the underlying server-scoped
 * data (`ServerCronJob` + `server_scheduler_heartbeats`) into per-site cards
 * carrying their own state and health snapshot. The Livewire component then
 * renders the cards 1:1.
 *
 * Card states (mirrors the four enumerated in Q11):
 *  - `tracked`             — heartbeat row exists, wrapper-managed cron
 *  - `waiting`             — heartbeat row exists, no tick yet, within grace
 *  - `paused`              — wrapper-managed cron with enabled=false
 *  - `detected_unmonitored` — scheduler-shaped cron line, no wrapper, no heartbeat
 *  - `no_scheduler`        — site exists, no scheduler at all
 *
 * Summary counts on the returned array drive the Q11 top strip
 * ("3 schedulers tracked · 2 healthy · 1 stale").
 */
final class SchedulerCardsBuilder
{
    /**
     * Substrings indicating a cron command is a framework scheduler. Inherited
     * from the previous WorkspaceSchedule string-match detection — same set so
     * existing "Detected" semantics carry over unchanged.
     */
    private const SCHEDULER_PATTERNS = [
        'schedule:run' => ServerSchedulerHeartbeat::KIND_LARAVEL,
        'schedule:work' => ServerSchedulerHeartbeat::KIND_LARAVEL,
        'whenever' => ServerSchedulerHeartbeat::KIND_RAILS,
        'bin/rails runner' => ServerSchedulerHeartbeat::KIND_RAILS,
        'rake schedule' => ServerSchedulerHeartbeat::KIND_RAILS,
        'celery beat' => ServerSchedulerHeartbeat::KIND_GENERIC,
        'celerybeat' => ServerSchedulerHeartbeat::KIND_GENERIC,
    ];

    public function __construct(
        private SchedulerHealthEvaluator $health,
    ) {}

    /**
     * @return array{
     *   cards: list<array<string,mixed>>,
     *   stats: array{
     *     healthy: int,
     *     waiting: int,
     *     amber: int,
     *     red: int,
     *     paused: int,
     *     unmonitored: int,
     *     tracked_total: int,
     *     no_scheduler_sites: int,
     *   },
     * }
     */
    public function build(Server $server, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();

        $sites = Site::query()
            ->where('server_id', $server->id)
            ->orderBy('name')
            ->get();

        $heartbeats = ServerSchedulerHeartbeat::query()
            ->where('server_id', $server->id)
            ->get()
            ->keyBy(fn (ServerSchedulerHeartbeat $hb) => $this->key($hb->site_id, $hb->scheduler_kind));

        $cronJobs = ServerCronJob::query()
            ->where('server_id', $server->id)
            ->get();

        // Bucket scheduler-shaped cron jobs by (site_id, kind) so each card
        // can pick the relevant row in O(1). Non-scheduler crons are ignored.
        $cronBySite = [];
        foreach ($cronJobs as $cron) {
            $kind = $this->kindForCommand((string) $cron->command);
            if ($kind === null) {
                continue;
            }
            $siteId = $cron->site_id;
            if ($siteId === null) {
                continue;
            }
            $cronBySite[$this->key($siteId, $kind)] = $cron;
        }

        $cards = [];
        $stats = [
            'healthy' => 0,
            'waiting' => 0,
            'amber' => 0,
            'red' => 0,
            'paused' => 0,
            'unmonitored' => 0,
            // tracked_total = healthy + waiting + amber + red (everything
            // wrapper-managed and not paused). Drives the "X schedulers
            // tracked" headline on the summary strip.
            'tracked_total' => 0,
            'no_scheduler_sites' => 0,
        ];

        foreach ($sites as $site) {
            // A site can in theory have multiple schedulers (Laravel + Rails),
            // but v1 expects one per site per Q5's unique key. Iterate over
            // distinct (site_id, kind) keys derived from heartbeats + crons.
            $keysForSite = [];
            foreach ($heartbeats as $hb) {
                if ($hb->site_id === $site->id) {
                    $keysForSite[] = $this->key($site->id, $hb->scheduler_kind);
                }
            }
            foreach (array_keys($cronBySite) as $key) {
                if (str_starts_with($key, $site->id.'::') && ! in_array($key, $keysForSite, true)) {
                    $keysForSite[] = $key;
                }
            }

            if ($keysForSite === []) {
                $cards[] = $this->emptyCard($site);
                $stats['no_scheduler_sites']++;

                continue;
            }

            foreach ($keysForSite as $key) {
                $hb = $heartbeats->get($key);
                $cron = $cronBySite[$key] ?? null;
                $kind = $this->kindFromKey($key);

                if ($hb !== null) {
                    $state = $this->health->evaluate($hb, $cron, $now);
                    $cards[] = [
                        'site' => $site,
                        'state' => $state === SchedulerHealthEvaluator::STATE_PAUSED ? 'paused' : 'tracked',
                        'health' => $state,
                        'cron_job' => $cron,
                        'heartbeat' => $hb,
                        'kind' => $hb->scheduler_kind,
                        'cron_expression' => (string) $hb->cron_expression,
                        'last_tick_at' => $hb->last_tick_at,
                        'next_run_at' => $this->nextRunAt((string) $hb->cron_expression, $now),
                    ];
                    $bucket = $this->statBucketFor($state);
                    $stats[$bucket]++;
                    if ($state !== SchedulerHealthEvaluator::STATE_PAUSED) {
                        $stats['tracked_total']++;
                    }

                    continue;
                }

                // No heartbeat but a scheduler-shaped cron exists — the
                // "detected but unmonitored" state from Q6.
                $cards[] = [
                    'site' => $site,
                    'state' => 'detected_unmonitored',
                    'health' => null,
                    'cron_job' => $cron,
                    'heartbeat' => null,
                    'kind' => $kind,
                    'cron_expression' => (string) ($cron?->cron_expression ?? ''),
                    'last_tick_at' => null,
                    'next_run_at' => $this->nextRunAt((string) ($cron?->cron_expression ?? ''), $now),
                ];
                $stats['unmonitored']++;
            }
        }

        return ['cards' => $cards, 'stats' => $stats];
    }

    private function emptyCard(Site $site): array
    {
        return [
            'site' => $site,
            'state' => 'no_scheduler',
            'health' => null,
            'cron_job' => null,
            'heartbeat' => null,
            'kind' => null,
            'cron_expression' => null,
            'last_tick_at' => null,
            'next_run_at' => null,
        ];
    }

    private function statBucketFor(string $state): string
    {
        return match ($state) {
            SchedulerHealthEvaluator::STATE_HEALTHY => 'healthy',
            SchedulerHealthEvaluator::STATE_WAITING => 'waiting',
            SchedulerHealthEvaluator::STATE_AMBER => 'amber',
            SchedulerHealthEvaluator::STATE_RED => 'red',
            SchedulerHealthEvaluator::STATE_PAUSED => 'paused',
            default => 'healthy',
        };
    }

    private function kindForCommand(string $command): ?string
    {
        $lc = Str::lower($command);
        foreach (self::SCHEDULER_PATTERNS as $needle => $kind) {
            if (str_contains($lc, $needle)) {
                return $kind;
            }
        }

        return null;
    }

    private function key(string $siteId, string $kind): string
    {
        return $siteId.'::'.$kind;
    }

    private function kindFromKey(string $key): string
    {
        return explode('::', $key, 2)[1] ?? '';
    }

    private function nextRunAt(string $expression, Carbon $now): ?Carbon
    {
        $expression = trim($expression);
        if ($expression === '' || ! CronExpression::isValidExpression($expression)) {
            return null;
        }
        try {
            return Carbon::instance((new CronExpression($expression))->getNextRunDate($now));
        } catch (\Throwable) {
            return null;
        }
    }
}
