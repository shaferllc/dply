<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Services\Servers\ServerManageToolsReport;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\EdgeProxyWorkspaceViewData;
use App\Support\Servers\ServerConsoleActionLookup;
use Illuminate\Contracts\View\View;
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
        parent::mount($server, 'web');
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
        // Edge proxy ships behind a coming-soon teaser until the L7 routing UI is
        // finished — render the preview in place of the real (partial) workspace.
        if (in_array('edge-proxy', config('server_workspace.coming_soon_keys', []), true)) {
            return view('livewire.servers.workspace-edge-proxy-preview', [
                'server' => $this->server,
            ]);
        }

        $consoleLookup = app(ServerConsoleActionLookup::class);
        if ($consoleLookup->shouldRefreshServerMeta($this->server, 'edge-proxy')) {
            $this->server->refresh();
        }

        $this->pickupQueuedConfigLoad();
        $this->pickupQueuedConfigWrite();
        $this->pickupQueuedConfigValidate();

        // Listing loaded off the render path via wire:init (inherited
        // loadWebserverConfigFiles); render() does no SSH.
        $configFiles = $this->engine_subtab === 'config' ? $this->webserverConfigFilesRaw : [];

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
