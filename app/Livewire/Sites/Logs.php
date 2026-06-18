<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Servers\Concerns\ManagesServerSystemLogs;
use App\Livewire\Servers\WorkspaceLogs;
use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\ServerSystemLogsReport;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The site's "Logs" workspace — the server logs experience scoped to one site:
 * the same Viewer / Overview / Sources tabs and live SSH-tail panel, but the
 * source catalog is limited to this site's vhost access/error, app log, Horizon
 * log, and platform activity (see {@see ManagesServerSystemLogs::availableLogSources()}
 * when scopedSite is set). Server-wide sources and Vector shipping stay on the
 * server logs workspace, one click away. Mirrors {@see WorkspaceLogs}.
 */
#[Layout('layouts.app')]
class Logs extends Component
{
    use DispatchesToastNotifications;
    use ManagesServerSystemLogs;

    /** @var list<string> */
    public const LOGS_TABS = ['viewer', 'overview', 'sources'];

    public Server $server;

    #[Url(as: 'tab', except: 'viewer')]
    public string $logsTab = 'viewer';

    public bool $logOptionsMenuOpen = false;

    public bool $logSourceMenuOpen = false;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->scopedSite = $site;
        $this->bootServerLogs();
        $this->logsTab = in_array($this->logsTab, self::LOGS_TABS, true) ? $this->logsTab : 'viewer';
    }

    public function setLogsWorkspaceTab(string $tab): void
    {
        $this->logsTab = in_array($tab, self::LOGS_TABS, true) ? $tab : 'viewer';
    }

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

    #[On('server-workspace-log-snapshot')]
    public function onServerWorkspaceLogSnapshot(mixed $payload = []): void
    {
        $this->authorize('view', $this->server);
        $this->authorize('view', $this->scopedSite);
        $data = is_array($payload) ? $payload : [];
        $this->mergeRemoteLogFromBroadcast($data);
    }

    public function render(ServerSystemLogsReport $logsReport): View
    {
        $this->server->refresh();
        $site = $this->scopedSite;
        $logSources = $this->availableLogSources();
        $runtimeMode = $site->runtimeTargetMode();

        // App-log stream is only meaningful when the site routes logs to a dply
        // Realtime channel (mirrors the Logs settings partial's gate).
        $loggingBinding = $site->bindings->firstWhere('type', 'logging');
        $hasDplyRealtime = collect(is_array($loggingBinding?->config) ? ($loggingBinding->config['channels'] ?? []) : [])
            ->contains(fn ($c) => is_array($c) && ($c['type'] ?? null) === 'dply_realtime');

        return view('livewire.sites.logs', [
            'site' => $site,
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
            'settingsSidebarItems' => SiteSettingsSidebar::items($site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'logs',
            'runtimeMode' => $runtimeMode,
            'hasDplyRealtime' => $hasDplyRealtime,
        ]);
    }
}
