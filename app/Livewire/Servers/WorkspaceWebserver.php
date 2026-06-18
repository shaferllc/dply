<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Servers\Concerns\LoadsLiveServerCerts;
use App\Livewire\Servers\Concerns\ManagesApacheWebserver;
use App\Livewire\Servers\Concerns\ManagesCaddyWebserver;
use App\Livewire\Servers\Concerns\ManagesEnvoyWebserver;
use App\Livewire\Servers\Concerns\ManagesHaproxyWebserver;
use App\Livewire\Servers\Concerns\ManagesNginxWebserver;
use App\Livewire\Servers\Concerns\ManagesOlsWebserver;
use App\Livewire\Servers\Concerns\ManagesOpenRestyWebserver;
use App\Livewire\Servers\Concerns\ManagesTraefikWebserver;
use App\Livewire\Servers\Concerns\ManagesWebserverConfigFiles;
use App\Livewire\Servers\Concerns\ManagesWebserverConfigRevisions;
use App\Livewire\Servers\Concerns\ManagesWebserverDriftSmoke;
use App\Livewire\Servers\Concerns\ManagesWebserverLiveState;
use App\Livewire\Servers\Concerns\ManagesWebserverLogs;
use App\Livewire\Servers\Concerns\ManagesWebserverNotifications;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerManageToolsReport;
use App\Services\Servers\ServerMetricsRangeQuery;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\TraefikEntrypointsConfig;
use App\Support\Servers\ServerConsoleActionLookup;
use App\Support\Servers\WebserverWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Top-level "Webserver" workspace — gives the per-server webserver picker grid +
 * cascade modal + audit history its own sidebar entry, peer to PHP / Caches /
 * Cron, rather than living nested under Manage > Web.
 *
 * Extends {@see WorkspaceManage} so all the switch state, switch methods,
 * service-action plumbing (runAllowlistedAction et al), banner concerns, and
 * console-action dismissal are inherited unchanged. The only differences:
 *
 *   - `mount()` accepts no `?section` query string (this isn't a sub-tab
 *     anymore) — section is fixed at 'web' so the parent's render share +
 *     trait-internal asserts continue working.
 *   - `render()` points at `workspace-webserver.blade.php`, a thin orchestrator
 *     that lazy-renders tab partials under `partials/webserver/` inside
 *     {@see <x-server-workspace-layout>} with `active="webserver"`.
 *   - Adds Tools / Logs / Config sub-tabs and their backing Livewire methods
 *     (load/save/validate/restore for config; tail for logs). Path safety and
 *     atomic-write semantics live in {@see RemoteWebserverConfigService}.
 *
 * Result: clicking "Webserver" in the sidebar lands on the same content,
 * scoped + framed as a peer workspace rather than nested.
 */
#[Layout('layouts.app')]
class WorkspaceWebserver extends WorkspaceManage
{
    use CreatesNotificationChannelInline;
    use LoadsLiveServerCerts;
    use ManagesApacheWebserver;
    use ManagesCaddyWebserver;
    use ManagesEnvoyWebserver;
    use ManagesHaproxyWebserver;
    use ManagesNginxWebserver;
    use ManagesOlsWebserver;
    use ManagesOpenRestyWebserver;
    use ManagesTraefikWebserver;
    use ManagesWebserverConfigFiles;
    use ManagesWebserverConfigRevisions;
    use ManagesWebserverDriftSmoke;
    use ManagesWebserverLiveState;
    use ManagesWebserverLogs;
    use ManagesWebserverNotifications;

    /**
     * Second-level tab within this workspace — mirrors WorkspaceDatabases /
     * WorkspaceCaches: an "overview" tab, one tab per webserver in the
     * catalog (currently active gets an Active badge; the rest let the
     * operator open the cascade-switch modal), and an "advanced" tab that
     * collects PHP-FPM, TLS, and the switch-history table.
     *
     * Allowed values are validated in {@see setWorkspaceTab()}; an unknown
     * value falls back to 'overview' rather than throwing.
     *
     * Bound to ?tab= so an operator can deep-link / share the URL and the
     * tab they were on restores on load.
     */
    #[Url(as: 'tab', except: 'overview')]
    public string $workspace_tab = 'overview';

    /**
     * Per-engine sub-tab. Originally just `overview` / `info`; now also
     * `logs` (live access + error tail) and `config` (file editor) plus the
     * per-engine live-state sub-tabs (vhosts / listeners / routers / etc.).
     * Validated in {@see setEngineSubtab()}; unknown values fall back to
     * `overview`.
     *
     * Bound to ?sub= so deep-linking to e.g. ?tab=openlitespeed&sub=cache
     * lands the operator directly on that sub-tab.
     */
    #[Url(as: 'sub', except: 'overview')]
    public string $engine_subtab = 'overview';

    // ---- Config editor state -----------------------------------------

    // ---- Log viewer state --------------------------------------------

    /**
     * Time range for the per-engine Overview health charts. One of the
     * ServerMetricsRangeQuery::RANGES keys: '1h', '6h', '24h', '7d'.
     * Persisted in localStorage on the client (keyed per server) so the
     * operator's preference survives reloads.
     */
    public string $engine_metrics_range = '1h';

    /**
     * Set while a queued file-load is in flight. The render loop watches
     * the matching ConsoleAction row and, once it goes to completed,
     * pulls the cached read result and drops it into the editor buffer.
     */
    public ?string $pending_load_console_id = null;

    public ?string $pending_load_path = null;

    // ---- Cross-engine TLS certificates dashboard (Health tab card).
    // State + loader live in LoadsLiveServerCerts ($liveCerts*), shared with the
    // server cert-inventory page; the SSH sweep runs async in ScanServerLiveCertsJob.

    public function mount(Server $server, ?string $section = null): void
    {
        // Force the inherited 'web' section state — the parent's render share
        // and any internal asserts on $section still resolve correctly without
        // requiring the operator to type `?section=web` on the URL.
        parent::mount($server, 'web');

        // If the URL restored a deep-link straight to the OLS Cache sub-tab,
        // populate the form so it renders with current server values on the
        // first paint instead of waiting for an extra round-trip.
        if ($this->workspace_tab === 'openlitespeed' && $this->engine_subtab === 'cache') {
            $this->loadOlsCacheConfig();
        }
        if ($this->workspace_tab === 'nginx' && $this->engine_subtab === 'cache') {
            $this->loadNginxCacheConfig();
        }
        if ($this->workspace_tab === 'apache' && $this->engine_subtab === 'cache') {
            $this->loadApacheCacheConfig();
        }

        // Eager-load the Health tab's drift detector so it paints on first
        // render. The TLS cert card loads async via the shared partial's
        // wire:init (loadLiveCerts) so its SSH sweep stays off the request.
        if ($this->workspace_tab === 'health' && $this->serverOpsReady()) {
            $this->loadDriftDetector();
        }
        // Other engine sub-tab data loads deferred via wire:init → loadActiveEngineSubtabData().
    }

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = ['overview', 'change', 'health', 'nginx', 'caddy', 'apache', 'openlitespeed', 'advanced', 'notifications'];
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'overview';
        // Reset the sub-tab on every top-level switch so the operator always
        // lands on the actionable view first. Skipping this would leave
        // Caddy on `info` after they navigated away from Nginx's `info`.
        $this->engine_subtab = 'overview';
        $this->resetConfigEditorState();
        $this->resetLogViewerState();

        if ($this->workspace_tab === 'health' && $this->serverOpsReady()) {
            $this->loadDriftDetector();
        }
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->workspace_tab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

    /**
     * Range setter for the per-engine Overview health charts. Validates
     * against ServerMetricsRangeQuery's known ranges; falls back to '1h'.
     */
    public function setEngineMetricsRange(string $range): void
    {
        $allowed = array_keys(ServerMetricsRangeQuery::RANGES);
        $this->engine_metrics_range = in_array($range, $allowed, true) ? $range : '1h';
    }

    public function setEngineSubtab(string $subtab): void
    {
        $this->engine_subtab = $subtab;
    }

    public function updatedEngineSubtab(): void
    {
        $allowed = [
            'overview', 'info', 'logs', 'config',
            // OLS
            'vhosts', 'listeners', 'extapps', 'modules', 'cache',
            // nginx
            'hosts', 'upstreams', 'certs', 'modules', 'workers',
            // caddy (routes/upstreams/certs share with nginx; admin + snippets are unique)
            'routes', 'admin', 'snippets', 'modules',
            // apache (vhosts/workers/certs shared; modules unique)
            'modules',
            // traefik
            'routers', 'services', 'middlewares', 'entrypoints',
            'tcprouters', 'tcpservices', 'udprouters', 'udpservices', 'tls', 'providers',
            'static', 'dynamic',
            // haproxy
            'frontends', 'backends', 'ssl', 'runtime',
            // envoy
            'listeners', 'clusters', 'runtime', 'virtualhosts', 'stats', 'static',
        ];

        if ($this->engine_subtab === 'tools') {
            $this->engine_subtab = 'overview';
        }

        if (! in_array($this->engine_subtab, $allowed, true)) {
            $this->engine_subtab = 'overview';
        }

        if ($this->engine_subtab !== 'config') {
            $this->resetConfigEditorState();
        }
        if ($this->engine_subtab !== 'logs') {
            $this->resetLogViewerState();
        }

        // Close transient sub-tab UI; keep loaded SSH snapshots until explicit refresh.
        if ($this->engine_subtab !== 'listeners') {
            $this->ols_listeners_show_add = false;
        }
        if ($this->engine_subtab !== 'snippets') {
            $this->caddy_snippets_show_add = false;
        }
        if (! ($this->workspace_tab === 'caddy' && $this->engine_subtab === 'modules')) {
            $this->caddy_modules_show_add = false;
            $this->caddy_modules_show_browse = false;
        }
        if ($this->engine_subtab !== 'routes') {
            $this->caddy_custom_routes_show_add = false;
        }
        if ($this->engine_subtab !== 'upstreams') {
            $this->nginx_upstreams_show_add = false;
        }
        if ($this->engine_subtab !== 'hosts') {
            $this->nginx_custom_hosts_show_add = false;
        }
        if ($this->engine_subtab !== 'vhosts') {
            $this->apache_custom_vhosts_show_add = false;
        }
        if ($this->engine_subtab !== 'frontends') {
            $this->haproxy_frontends_show_add = false;
        }
        if ($this->engine_subtab !== 'backends') {
            $this->haproxy_backends_show_add = false;
        }
    }

    private function mergeTraefikStaticEntrypointsIntoMeta(): void
    {
        $server = $this->server->fresh();
        $meta = (array) ($server->meta ?? []);
        $state = data_get($meta, 'webserver_live_state.traefik');

        if (
            ! is_array($state)
            || ! empty($state['units']['entrypoints'] ?? [])
        ) {
            return;
        }

        $read = app(TraefikEntrypointsConfig::class)->read($server);
        $entrypoints = $read['entrypoints'] ?? [];

        if (empty($entrypoints)) {
            return;
        }

        $state['units']['entrypoints'] = array_map(
            static fn (array $ep): array => [
                'name' => $ep['name'],
                'address' => $ep['address'],
                'transport' => 'static',
                'status' => 'configured',
            ],
            $entrypoints
        );

        $meta['webserver_live_state']['traefik'] = $state;

        $server->update(['meta' => $meta]);
    }

    public function syncManageRemoteTaskFromCache(): void
    {
        if (empty($this->manageRemoteTaskId)) {
            return;
        }

        $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        if (! is_array($payload)) {
            return;
        }

        $status = (string) ($payload['status'] ?? '');
        $taskName = $this->manageRemoteTaskName;

        parent::syncManageRemoteTaskFromCache();

        if ($status === 'finished') {
            switch ($taskName) {
                case 'manage-action:repair_caddy_php_fpm_upstream':
                    $this->reapplyCaddySiteConfigsAfterPhpRepair();
                    break;
                case 'manage-config:caddy-modules-rebuild':
                case 'manage-config:caddy-modules-restore':
                    $this->server->refresh();
                    if ($this->workspace_tab === 'caddy' && $this->engine_subtab === 'modules') {
                        $this->loadCaddyModulesInventory();
                    }
                    break;
            }

            if ($this->shouldRefreshWebserverLiveStateAfterRemoteTask($taskName)
                && $this->isEngineLiveStateSubtab($this->engine_subtab, $this->workspace_tab)) {
                $this->ensureEngineLiveState(forceFresh: true);
            }
        }

    }

    public function render(ServerManageToolsReport $toolsReport): View
    {
        $consoleLookup = app(ServerConsoleActionLookup::class);
        if ($consoleLookup->shouldRefreshServerMeta($this->server, 'webserver')) {
            $this->server->refresh();
        }

        if (auth()->check() && auth()->user()?->currentOrganization()) {
            Feature::loadMissing(['workspace.webserver_config_diff']);
        }

        $this->pickupQueuedConfigLoad();
        $this->pickupQueuedConfigWrite();
        $this->pickupQueuedConfigValidate();

        // Picker listing is loaded off the render path (loadWebserverConfigFiles
        // via wire:init) and held in $webserverConfigFilesRaw — render() does NO
        // SSH, so the pickup poll can tick safely without blocking the request.
        $configFiles = $this->engine_subtab === 'config' ? $this->webserverConfigFilesRaw : [];

        return view('livewire.servers.workspace-webserver', array_merge(
            WebserverWorkspaceViewData::for($this->server, $this),
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
                'notifChannels' => $this->workspace_tab === 'notifications' ? $this->assignableWebserverNotificationChannels() : collect(),
                'notifSubscriptions' => $this->workspace_tab === 'notifications' ? $this->webserverNotificationSubscriptions() : collect(),
                'notifEventLabels' => $this->workspace_tab === 'notifications' ? $this->webserverEventLabels() : [],
            ],
        ));
    }
}
