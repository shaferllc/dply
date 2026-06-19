<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\SiteDeployment;
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
