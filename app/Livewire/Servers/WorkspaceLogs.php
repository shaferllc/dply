<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesServerLogExplorer;
use App\Livewire\Servers\Concerns\ManagesServerLogShipping;
use App\Livewire\Servers\Concerns\ManagesServerSystemLogs;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\ServerSystemLogsReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceLogs extends Component
{
    use DispatchesToastNotifications;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesServerLogExplorer;
    use ManagesServerLogShipping;
    use ManagesServerSystemLogs;
    use RendersWorkspacePlaceholder;

    /** @var list<string> */
    public const LOGS_TABS = ['viewer', 'overview', 'sources', 'shipping', 'related'];

    #[Url(as: 'tab', except: 'viewer')]
    public string $logsTab = 'viewer';

    public bool $logOptionsMenuOpen = false;

    public bool $logSourceMenuOpen = false;

    public function toggleLogSourceMenu(): void
    {
        $this->logSourceMenuOpen = ! $this->logSourceMenuOpen;
        if ($this->logSourceMenuOpen) {
            $this->logOptionsMenuOpen = false;
        }
    }

    public function closeLogSourceMenu(): void
    {
        $this->logSourceMenuOpen = false;
    }

    public function selectLogSourceFromMenu(string $key): void
    {
        $this->selectLogSource($key);
        $this->logSourceMenuOpen = false;
    }

    public function setLogsWorkspaceTab(string $tab): void
    {
        $this->logsTab = in_array($tab, self::LOGS_TABS, true) ? $tab : 'viewer';
    }

    /** Pick a source from the catalog tab and jump to the live viewer. */
    public function selectLogSourceFromCatalog(string $key): void
    {
        $this->selectLogSource($key);
        $this->logsTab = 'viewer';
    }

    public function toggleLogOptionsMenu(): void
    {
        $this->logOptionsMenuOpen = ! $this->logOptionsMenuOpen;
        if ($this->logOptionsMenuOpen) {
            $this->logSourceMenuOpen = false;
        }
    }

    public function closeLogOptionsMenu(): void
    {
        $this->logOptionsMenuOpen = false;
    }

    public function applyLogViewerSettingsAndCloseMenu(): void
    {
        $this->applyLogTailLines();
        $this->closeLogOptionsMenu();
    }

    public function refreshSystemLogAndCloseMenu(): void
    {
        $this->refreshSystemLog();
        $this->closeLogOptionsMenu();
    }

    public function clearLogDisplayAndCloseMenu(): void
    {
        $this->clearLogDisplay();
        $this->closeLogOptionsMenu();
    }

    public function resetLogFilterAndCloseMenu(): void
    {
        $this->resetLogViewerFilters();
        $this->closeLogOptionsMenu();
    }

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->server->loadMissing(['organization', 'sites', 'logAgent']);
        $this->bootServerLogs();
        $this->bootLogShipping();
        $this->logsTab = in_array($this->logsTab, self::LOGS_TABS, true) ? $this->logsTab : 'viewer';
    }

    #[On('server-workspace-log-snapshot')]
    public function onServerWorkspaceLogSnapshot(mixed $payload = []): void
    {
        $this->authorize('view', $this->server);
        $data = is_array($payload) ? $payload : [];
        $this->mergeRemoteLogFromBroadcast($data);
    }

    public function render(ServerSystemLogsReport $logsReport): View
    {
        $logSources = $this->availableLogSources();
        $this->server->loadMissing('organization');
        $this->server->load('logAgent');

        return view('livewire.servers.workspace-logs', [
            'logSources' => $logSources,
            'logBroadcastEchoSubscribable' => $this->logBroadcastEchoSubscribable(),
            'report' => $logsReport->build($this->server, $logSources, [
                'log_key' => $this->logKey,
                'log_total_lines' => $this->logTotalLines,
                'log_filtered_lines' => $this->logFilteredLines,
                'log_last_fetched_at' => $this->logLastFetchedAt,
                'log_auto_refresh' => $this->logAutoRefresh,
                'log_auto_refresh_seconds' => $this->logAutoRefreshSeconds,
                'log_time_range_minutes' => $this->logTimeRangeMinutes,
                'remote_log_error' => $this->remoteLogError,
                'log_last_fetch_truncated' => $this->logLastFetchTruncated,
                'log_last_fetch_raw_bytes' => $this->logLastFetchRawBytes,
                'log_broadcast_subscribable' => $this->logBroadcastEchoSubscribable(),
            ]),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            // Only query ClickHouse when the Shipping tab is actually open.
            'logExplorer' => $this->logsTab === 'shipping' ? $this->loadLogExplorer() : null,
        ]);
    }
}
