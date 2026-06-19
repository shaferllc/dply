<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Modules\Logs\Services\ServerLogCorrelator;
use Carbon\CarbonInterface;

/**
 * Tier-1 dply Logs correlation for an event WINDOW (a deploy's
 * started_at..finished_at, a downtime incident's started_at..resolved_at): jump
 * from the event straight into the host log slice that surrounded it. The
 * window-based sibling of {@see CorrelatesErrorLogs} (which is instant-centred),
 * sharing the same SSH-free ClickHouse read + drawer
 * (livewire.partials.window-logs-drawer).
 *
 * Requires the host component to expose a `$server` property (the server whose
 * logs to read). The host supplies its own public action that resolves the
 * window from its domain object and calls {@see presentWindowLogs()}.
 */
trait CorrelatesWindowLogs
{
    /** Drawer state for the "logs across this window" slice. */
    public bool $windowLogsOpen = false;

    public ?string $windowLogsTitle = null;

    /** @var array{from:string,to:string,logs:list<array<string,mixed>>}|null */
    public ?array $windowLogsResult = null;

    /** True when the server ships logs — gates the window→logs affordance. */
    public function getShowWindowLogCorrelationProperty(): bool
    {
        return (bool) $this->server->logAgent?->isRunning();
    }

    public function closeWindowLogs(): void
    {
        $this->windowLogsOpen = false;
        $this->windowLogsResult = null;
        $this->windowLogsTitle = null;
    }

    /**
     * Open the drawer with the host log slice across [$from, $to] (padded by the
     * correlator). A ClickHouse READ, not SSH — safe to run inline. No-ops when
     * the server isn't shipping logs so a stale affordance can't open an empty
     * drawer.
     */
    protected function presentWindowLogs(CarbonInterface $from, CarbonInterface $to, ?string $title = null): void
    {
        if (! $this->showWindowLogCorrelation) {
            return;
        }

        $this->windowLogsTitle = $title;
        $this->windowLogsResult = app(ServerLogCorrelator::class)->inWindow($this->server, $from, $to);
        $this->windowLogsOpen = true;
    }
}
