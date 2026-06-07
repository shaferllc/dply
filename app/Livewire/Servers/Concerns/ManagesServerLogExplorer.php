<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Services\Logs\LogExplorerQuery;
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

    public function clearLogExplorerFilters(): void
    {
        $this->logExplorerSearch = '';
        $this->logExplorerLevel = '';
        $this->logExplorerSource = '';
        $this->logExplorerRange = 60;
    }

    /**
     * Fetch results for the current filters. Never throws — a ClickHouse outage or
     * missing schema surfaces as ['available' => false] so the tab still renders.
     *
     * @return array{available:bool, rows:list<array<string,mixed>>, error:?string}
     */
    protected function loadLogExplorer(): array
    {
        try {
            $rows = app(LogExplorerQuery::class)->recent($this->server, [
                'search' => $this->logExplorerSearch,
                'level' => $this->logExplorerLevel,
                'source' => $this->logExplorerSource,
                'range_minutes' => $this->logExplorerRange,
                'limit' => $this->logExplorerLimit,
            ]);

            return ['available' => true, 'rows' => $rows, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('server_logs.explorer.query_failed', [
                'server_id' => $this->server->id,
                'message' => $e->getMessage(),
            ]);

            return ['available' => false, 'rows' => [], 'error' => $e->getMessage()];
        }
    }
}
