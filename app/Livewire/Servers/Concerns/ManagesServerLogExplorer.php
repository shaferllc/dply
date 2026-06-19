<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ErrorEvent;
use App\Models\SiteDeployment;
use App\Models\SiteUptimeIncident;
use App\Modules\Logs\Services\LogExplorerQuery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;

/**
 * Filter state + fetch for the ClickHouse-backed log explorer (the "shipped logs"
 * view in the Logs workspace). Degrades gracefully: if ClickHouse is unreachable
 * or the schema is missing, returns an error flag rather than 500ing the page.
 *
 * Requires the host component to expose $server ({@see InteractsWithServerWorkspace}).
 */
trait ManagesServerLogExplorer
{
    #[Url(as: 'q', except: '')]
    public string $logExplorerSearch = '';

    #[Url(as: 'lvl', except: '')]
    public string $logExplorerLevel = '';

    #[Url(as: 'src', except: '')]
    public string $logExplorerSource = '';

    public int $logExplorerRange = 60;

    public int $logExplorerLimit = 100;

    /**
     * Opt-in auto-refresh for the shipped-logs view — polls the ClickHouse read
     * on an interval so it tails like the live viewer, without any SSH. Forced off
     * while pinned to a correlation window (a fixed slice shouldn't move).
     */
    public bool $logExplorerLive = false;

    public function toggleLogExplorerLive(): void
    {
        $this->logExplorerLive = ! $this->logExplorerLive;
    }

    public function loadMoreLogExplorer(): void
    {
        $max = $this->isLogExplorerWindowed() ? 2000 : 1000;
        $this->logExplorerLimit = min($max, $this->logExplorerLimit + 200);
    }

    /**
     * Correlation-histogram granularity: 'day' | 'hour' | 'minute'. Each grain
     * also fixes the rolling span (30 days / 24 hours / 60 minutes) and the next
     * finer grain to drill into — day→hour→minute, and a minute bar pins the table.
     */
    #[Url(as: 'g', except: 'hour')]
    public string $logHistogramGranularity = 'hour';

    /**
     * When drilling, the start of the focused region (ISO8601 UTC) — e.g. the day
     * you clicked, now shown as 24 hourly bars. Empty = rolling window ending now.
     */
    #[Url(as: 'hf', except: '')]
    public string $logHistogramFocusFrom = '';

    public function setLogHistogramGranularity(string $granularity): void
    {
        $this->logHistogramGranularity = in_array($granularity, ['day', 'hour', 'minute'], true) ? $granularity : 'hour';
        $this->logHistogramFocusFrom = '';
    }

    public function resetLogHistogram(): void
    {
        $this->logHistogramGranularity = 'hour';
        $this->logHistogramFocusFrom = '';
    }

    /**
     * Click a histogram bar. day → zoom to that day at hour grain; hour → zoom to
     * that hour at minute grain; minute → pin the log table to that exact minute
     * (the leaf — there's nothing finer to bucket, so jump to the lines).
     */
    public function drillLogHistogram(string $bucketStartIso): void
    {
        $start = $this->parseExplorerInstant($bucketStartIso);
        if ($start === null) {
            return;
        }

        switch ($this->logHistogramGranularity) {
            case 'day':
                $this->logHistogramGranularity = 'hour';
                $this->logHistogramFocusFrom = $start->utc()->toIso8601String();
                break;
            case 'hour':
                $this->logHistogramGranularity = 'minute';
                $this->logHistogramFocusFrom = $start->utc()->toIso8601String();
                break;
            default: // minute — leaf: pin the table to this minute
                $this->logExplorerFrom = $start->utc()->toIso8601String();
                $this->logExplorerTo = $start->copy()->addMinute()->utc()->toIso8601String();
                $this->logExplorerLive = false;
                $this->logExplorerLimit = 200;
        }
    }

    /**
     * @return array{bucket:int,count:int,finer:?string,label:string}
     */
    private function histogramSpec(): array
    {
        return match ($this->logHistogramGranularity) {
            'minute' => ['bucket' => 60, 'count' => 60, 'finer' => null, 'label' => 'H:i'],
            'day' => ['bucket' => 86400, 'count' => 30, 'finer' => 'hour', 'label' => 'M j'],
            default => ['bucket' => 3600, 'count' => 24, 'finer' => 'minute', 'label' => 'M j H:00'],
        };
    }

    /**
     * The correlation histogram + event overlay for the current granularity/focus.
     * Gap-filled buckets (zero-count buckets included so the time axis is honest)
     * with error/warn/other split, plus deploys, error events and uptime incidents
     * positioned by time across the same window. Never throws.
     *
     * @return array{available:bool, buckets:list<array<string,mixed>>, events:list<array<string,mixed>>, granularity:string, can_drill:bool, focused:bool, from:string, to:string, max:int}
     */
    protected function loadLogHistogram(): array
    {
        $spec = $this->histogramSpec();
        $bucket = $spec['bucket'];
        $span = $bucket * $spec['count'];

        $focus = $this->parseExplorerInstant($this->logHistogramFocusFrom);
        $to = $focus !== null ? $focus->copy()->addSeconds($span) : CarbonImmutable::now();
        $from = $focus !== null ? $focus : CarbonImmutable::now()->subSeconds($span);

        $empty = [
            'available' => false,
            'buckets' => [],
            'events' => [],
            'granularity' => $this->logHistogramGranularity,
            'can_drill' => $spec['finer'] !== null || $this->logHistogramGranularity === 'minute',
            'focused' => $focus !== null,
            'from' => $from->utc()->toIso8601String(),
            'to' => $to->utc()->toIso8601String(),
            'max' => 0,
        ];

        try {
            $rows = app(LogExplorerQuery::class)->histogram($this->server, $from, $to, $bucket);
        } catch (\Throwable $e) {
            Log::warning('server_logs.histogram.query_failed', [
                'server_id' => $this->server->id,
                'message' => $e->getMessage(),
            ]);

            return $empty;
        }

        // Index query rows by their bucket-start epoch for gap-filling.
        $byEpoch = [];
        foreach ($rows as $r) {
            try {
                $byEpoch[CarbonImmutable::parse($r['bucket'], 'UTC')->getTimestamp()] = $r;
            } catch (\Throwable) {
                continue;
            }
        }

        // Bucket boundaries align to epoch multiples (matching ClickHouse's
        // toStartOfInterval), so floor the window start to a bucket edge.
        $startEpoch = intdiv($from->getTimestamp(), $bucket) * $bucket;
        $endEpoch = $to->getTimestamp();
        $totalSpan = max(1, $endEpoch - $startEpoch);

        $buckets = [];
        $max = 1;
        for ($epoch = $startEpoch; $epoch <= $endEpoch; $epoch += $bucket) {
            $row = $byEpoch[$epoch] ?? null;
            $total = (int) ($row['total'] ?? 0);
            $errors = (int) ($row['errors'] ?? 0);
            $warns = (int) ($row['warns'] ?? 0);
            $others = max(0, $total - $errors - $warns);
            $max = max($max, $total);

            $at = CarbonImmutable::createFromTimestamp($epoch, 'UTC');
            $buckets[] = [
                'start' => $at->toIso8601String(),
                'label' => $at->format($spec['label']),
                'total' => $total,
                'errors' => $errors,
                'warns' => $warns,
                'others' => $others,
                'x_pct' => round((($epoch - $startEpoch) / $totalSpan) * 100, 3),
            ];
        }

        return [
            'available' => true,
            'buckets' => $buckets,
            'events' => $this->loadLogTimelineEvents($from, $to, $startEpoch, $endEpoch),
            'granularity' => $this->logHistogramGranularity,
            'can_drill' => $spec['finer'] !== null || $this->logHistogramGranularity === 'minute',
            'focused' => $focus !== null,
            'from' => $from->utc()->toIso8601String(),
            'to' => $to->utc()->toIso8601String(),
            'max' => $max,
        ];
    }

    /**
     * Deploys, error events and uptime incidents within the histogram window,
     * each positioned by time as an x-percentage across the axis — the "events"
     * half of "events vs logs". Capped so a noisy window can't flood the overlay.
     *
     * @return list<array{type:string,label:string,time:string,x_pct:float}>
     */
    private function loadLogTimelineEvents(CarbonInterface $from, CarbonInterface $to, int $startEpoch, int $endEpoch): array
    {
        $totalSpan = max(1, $endEpoch - $startEpoch);
        $pos = static function (?CarbonInterface $at) use ($startEpoch, $totalSpan): ?float {
            if ($at === null) {
                return null;
            }

            return round(max(0.0, min(100.0, (($at->getTimestamp() - $startEpoch) / $totalSpan) * 100)), 3);
        };

        $events = [];
        $siteIds = $this->server->sites->pluck('id')->all();

        if ($siteIds !== []) {
            $deploys = SiteDeployment::query()
                ->whereIn('site_id', $siteIds)
                ->where('started_at', '<=', $to)
                ->where(function ($q) use ($from): void {
                    $q->whereNull('finished_at')->orWhere('finished_at', '>=', $from);
                })
                ->with('site:id,name')
                ->orderByDesc('started_at')
                ->limit(40)
                ->get();

            foreach ($deploys as $deploy) {
                $at = ($deploy->finished_at ?? $deploy->started_at)?->utc();
                $x = $pos($at);
                if ($x === null) {
                    continue;
                }
                $events[] = [
                    'type' => 'deploy',
                    'label' => __('Deploy · :site (:status)', ['site' => $deploy->site?->name ?? $deploy->site_id, 'status' => $deploy->status]),
                    'time' => $at->toIso8601String(),
                    'x_pct' => $x,
                ];
            }

            $incidents = SiteUptimeIncident::query()
                ->whereIn('site_id', $siteIds)
                ->where('started_at', '>=', $from)
                ->where('started_at', '<=', $to)
                ->orderByDesc('started_at')
                ->limit(40)
                ->get();

            foreach ($incidents as $incident) {
                $x = $pos($incident->started_at?->utc());
                if ($x === null) {
                    continue;
                }
                $events[] = [
                    'type' => 'incident',
                    'label' => __('Incident · :severity', ['severity' => $incident->severity]),
                    'time' => $incident->started_at->utc()->toIso8601String(),
                    'x_pct' => $x,
                ];
            }
        }

        $errors = ErrorEvent::query()
            ->where('server_id', $this->server->id)
            ->whereNotNull('occurred_at')
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to)
            ->orderByDesc('occurred_at')
            ->limit(40)
            ->get();

        foreach ($errors as $error) {
            $x = $pos($error->occurred_at?->utc());
            if ($x === null) {
                continue;
            }
            $events[] = [
                'type' => 'error',
                'label' => __('Error · :title', ['title' => \Illuminate\Support\Str::limit((string) $error->title, 60)]),
                'time' => $error->occurred_at->utc()->toIso8601String(),
                'x_pct' => $x,
            ];
        }

        return $events;
    }

    /** @var list<int> */
    public array $logExplorerRangeOptions = [15, 60, 360, 1440];

    /**
     * When set (via a correlation deep-link, e.g. error → logs), the explorer is
     * PINNED to this explicit [from, to] slice instead of the rolling recent
     * window. ISO8601 UTC; empty = live mode.
     */
    #[Url(as: 'from', except: '')]
    public string $logExplorerFrom = '';

    #[Url(as: 'to', except: '')]
    public string $logExplorerTo = '';

    public function clearLogExplorerFilters(): void
    {
        $this->logExplorerSearch = '';
        $this->logExplorerLevel = '';
        $this->logExplorerSource = '';
        $this->logExplorerRange = 60;
        $this->backToLiveLogs();
    }

    /** True when pinned to an explicit [from, to] slice (a correlation jump). */
    public function isLogExplorerWindowed(): bool
    {
        return $this->parseExplorerInstant($this->logExplorerFrom) !== null
            && $this->parseExplorerInstant($this->logExplorerTo) !== null;
    }

    /** Drop the pinned window — return to the rolling recent-N-minutes view. */
    public function backToLiveLogs(): void
    {
        $this->logExplorerFrom = '';
        $this->logExplorerTo = '';
        $this->logExplorerLimit = 100;
    }

    private function parseExplorerInstant(string $value): ?CarbonInterface
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetch results for the current filters. Never throws — a ClickHouse outage or
     * missing schema surfaces as ['available' => false] so the tab still renders.
     * Branches to a fixed [from, to] window when pinned (correlation jump), else
     * the rolling recent window.
     *
     * `entries` interleaves the log rows with deploy MARKERS (the reverse of the
     * deploy→logs jump: deploys overlapping the visible window shown inline in the
     * stream, so "this release went out here" sits right against the log lines it
     * produced). Each entry is tagged `kind` => 'log' | 'deploy'.
     *
     * @return array{available:bool, rows:list<array<string,mixed>>, entries:list<array<string,mixed>>, deploy_count:int, error:?string, windowed:bool, from:?string, to:?string}
     */
    protected function loadLogExplorer(): array
    {
        $from = $this->parseExplorerInstant($this->logExplorerFrom);
        $to = $this->parseExplorerInstant($this->logExplorerTo);
        $windowed = $from !== null && $to !== null;

        // The effective window the table covers, in either mode — also the bounds
        // we look for overlapping deploys within.
        $windowTo = $windowed ? $to : CarbonImmutable::now();
        $windowFrom = $windowed ? $from : CarbonImmutable::now()->subMinutes(max(1, $this->logExplorerRange));

        try {
            $query = app(LogExplorerQuery::class);
            $filters = [
                'search' => $this->logExplorerSearch,
                'level' => $this->logExplorerLevel,
                'source' => $this->logExplorerSource,
            ];

            $rows = $windowed
                ? $query->window($this->server, $from, $to, $filters + ['limit' => 500])
                : $query->recent($this->server, $filters + [
                    'range_minutes' => $this->logExplorerRange,
                    'limit' => $this->logExplorerLimit,
                ]);

            // Deploy markers only make sense against an unfiltered stream — a
            // level/source/search facet narrows to log lines, so hide the markers
            // then rather than show them floating without their surrounding context.
            $deploys = ($this->logExplorerSearch === '' && $this->logExplorerLevel === '' && $this->logExplorerSource === '')
                ? $this->loadDeployMarkers($windowFrom, $windowTo)
                : [];

            $entries = $this->mergeExplorerEntries($rows, $deploys, ascending: $windowed);

            return [
                'available' => true,
                'rows' => $rows,
                'entries' => $entries,
                'deploy_count' => count($deploys),
                'error' => null,
                'windowed' => $windowed,
                'from' => $windowed ? $from->utc()->toIso8601String() : null,
                'to' => $windowed ? $to->utc()->toIso8601String() : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('server_logs.explorer.query_failed', [
                'server_id' => $this->server->id,
                'message' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'rows' => [],
                'entries' => [],
                'deploy_count' => 0,
                'error' => $e->getMessage(),
                'windowed' => $windowed,
                'from' => null,
                'to' => null,
            ];
        }
    }

    /**
     * VM/site deploys on this server whose activity overlaps [$from, $to], newest
     * first, as marker entries to interleave into the log stream. Anchored on
     * finished_at (the cutover — when the log behaviour actually changes), falling
     * back to started_at/created_at for an in-flight or unfinished run.
     *
     * @return list<array<string,mixed>>
     */
    private function loadDeployMarkers(CarbonInterface $from, CarbonInterface $to): array
    {
        $siteIds = $this->server->sites->pluck('id')->all();
        if ($siteIds === []) {
            return [];
        }

        $deploys = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->where('started_at', '<=', $to)
            // overlap: finished within/after the window, or still running (null)
            ->where(function ($q) use ($from): void {
                $q->whereNull('finished_at')->orWhere('finished_at', '>=', $from);
            })
            ->with('site:id,name,server_id')
            ->orderByDesc('started_at')
            ->limit(100)
            ->get();

        $markers = [];
        foreach ($deploys as $deploy) {
            $anchor = ($deploy->finished_at ?? $deploy->started_at ?? $deploy->created_at)?->utc();
            if ($anchor === null) {
                continue;
            }

            $markers[] = [
                'kind' => 'deploy',
                'sort' => $anchor->valueOf(),
                'timestamp' => $anchor->format('Y-m-d H:i:s.v'),
                'deployment_id' => $deploy->id,
                'site' => $deploy->site,
                'site_name' => $deploy->site?->name ?? $deploy->site_id,
                'status' => (string) $deploy->status,
                'running' => $deploy->finished_at === null,
            ];
        }

        return $markers;
    }

    /**
     * Merge log rows + deploy markers into one timestamp-ordered list. Logs carry
     * a 'timestamp' string in UTC ('Y-m-d H:i:s[.v]'); markers carry a numeric
     * 'sort'. Direction matches the table: ascending for a pinned window
     * (chronological), descending for the rolling recent view (newest first).
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>  $markers
     * @return list<array<string,mixed>>
     */
    private function mergeExplorerEntries(array $rows, array $markers, bool $ascending): array
    {
        $entries = $markers;
        foreach ($rows as $row) {
            $ts = (string) ($row['timestamp'] ?? '');
            $sort = 0;
            if ($ts !== '') {
                try {
                    $sort = CarbonImmutable::parse($ts, 'UTC')->valueOf();
                } catch (\Throwable) {
                    $sort = 0;
                }
            }
            $entries[] = ['kind' => 'log', 'sort' => $sort] + $row;
        }

        usort($entries, static fn (array $a, array $b): int => $ascending
            ? $a['sort'] <=> $b['sort']
            : $b['sort'] <=> $a['sort']);

        return $entries;
    }
}
