<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerManageToolsReport;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\EdgeProxyWorkspaceViewData;
use App\Support\Servers\ServerConsoleActionLookup;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

/**
 * Top-level "Edge proxy" workspace — optional L7 reverse proxy in front of the
 * server's webserver (Traefik, HAProxy, …). Peer to {@see WorkspaceWebserver}.
 */
#[Layout('layouts.app')]
class WorkspaceEdgeProxy extends WorkspaceWebserver
{
    #[Url(as: 'tab', except: 'overview')]
    public string $workspace_tab = 'overview';

    public function mount(Server $server, ?string $section = null): void
    {
        if ($this->engine_subtab === 'config' && in_array($this->workspace_tab, ['traefik', 'haproxy'], true)) {
            $this->redirect($this->configurationUrlForEngineTab($this->workspace_tab), navigate: true);

            return;
        }

        parent::mount($server, 'web');
    }

    protected function configurationFromContext(): string
    {
        return 'edge-proxy';
    }

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = array_merge(['overview', 'change'], array_keys(EdgeProxyWorkspaceViewData::edgeProxyCatalog()));
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'overview';
        $this->engine_subtab = 'overview';
        $this->resetConfigEditorState();
        $this->resetLogViewerState();
    }

    public function render(ServerManageToolsReport $toolsReport): View
    {
        $consoleLookup = app(ServerConsoleActionLookup::class);
        if ($consoleLookup->shouldRefreshServerMeta($this->server, 'edge-proxy')) {
            $this->server->refresh();
        }

        $this->pickupQueuedConfigLoad();
        $this->pickupQueuedConfigWrite();
        $this->pickupQueuedConfigValidate();

        $configFiles = [];
        if ($this->engine_subtab === 'config'
            && in_array($this->workspace_tab, ['traefik', 'haproxy'], true)
            && $this->serverOpsReady()) {
            $cacheKey = 'dply.edge-proxy-config-files:'.$this->server->id.':'.$this->workspace_tab;
            try {
                $configFiles = Cache::remember(
                    $cacheKey,
                    10,
                    fn () => app(RemoteWebserverConfigService::class)->listFiles($this->server, $this->workspace_tab),
                );
            } catch (\Throwable) {
                $configFiles = [];
            }
        }

        return view('livewire.servers.workspace-edge-proxy', array_merge(
            EdgeProxyWorkspaceViewData::for($this->server, $this),
            $this->webserverConfigRevisionViewData(),
            [
                'configPreviews' => config('server_manage.config_previews', []),
                'serviceActions' => config('server_manage.service_actions', []),
                'dangerousActions' => config('server_manage.dangerous_actions', []),
                'autoUpdateIntervals' => config('server_manage.auto_update_intervals', []),
                'webserverConfigLayout' => config('server_manage.webserver_config_layout', []),
                'webserverConfigFiles' => $configFiles,
                'deletionSummary' => $this->showRemoveServerModal
                    ? ServerRemovalAdvisor::summary($this->server)
                    : null,
            ],
        ));
    }
}
