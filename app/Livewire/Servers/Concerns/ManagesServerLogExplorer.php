<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

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
     * @return array{available:bool, rows:list<array<string,mixed>>, error:?string, windowed:bool, from:?string, to:?string}
     */
    protected function loadLogExplorer(): array
    {
        $from = $this->parseExplorerInstant($this->logExplorerFrom);
        $to = $this->parseExplorerInstant($this->logExplorerTo);
        $windowed = $from !== null && $to !== null;

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

            return [
                'available' => true,
                'rows' => $rows,
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
                'error' => $e->getMessage(),
                'windowed' => $windowed,
                'from' => null,
                'to' => null,
            ];
        }
    }
}
