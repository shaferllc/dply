<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Livewire\Servers\Concerns\ClonesServer;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesServerConfigPreview;
use App\Livewire\Servers\Concerns\ManagesServerEdgeProxy;
use App\Livewire\Servers\Concerns\ManagesServerGitIdentity;
use App\Livewire\Servers\Concerns\ManagesServerInventoryProbe;
use App\Livewire\Servers\Concerns\ManagesServerLogo;
use App\Livewire\Servers\Concerns\ManagesServerMiseRuntimes;
use App\Livewire\Servers\Concerns\ManagesServerRemoteTask;
use App\Livewire\Servers\Concerns\ManagesServerToolActions;
use App\Livewire\Servers\Concerns\ManagesServerWebserverSwitch;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsServerInventoryProbe;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Models\ServerSystemdServiceState;
use App\Services\Servers\ServerManageToolsReport;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceManage extends Component
{
    use ClonesServer;
    use ConfirmsActionWithModal;
    use DismissesConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesServerConfigPreview;
    use ManagesServerEdgeProxy;
    use ManagesServerGitIdentity;
    use ManagesServerInventoryProbe;
    use ManagesServerLogo;
    use ManagesServerMiseRuntimes;
    use ManagesServerRemoteTask;
    use ManagesServerToolActions;
    use ManagesServerWebserverSwitch;
    use RendersWorkspacePlaceholder;
    use RunsServerInventoryProbe;

    /** @var string Manage sub-page slug (see config server_manage.workspace_tabs). */
    public string $section = 'overview';

    public function mount(Server $server, ?string $section = null): void
    {
        if ($section === null) {
            $this->redirect(route('servers.manage', ['server' => $server, 'section' => 'overview']), navigate: true);

            return;
        }

        // 'web' was promoted to its own top-level sidebar entry (servers.webserver) so
        // operators get to the picker / cascade modal / switch history without
        // drilling through Manage. Old deep links + bookmarks redirect.
        // Note: this redirect runs only when WorkspaceWebserver inherits via parent::mount();
        // since WorkspaceWebserver's mount() passes 'web' explicitly, the check below
        // is the back-compat path for direct /manage/web URLs only — by the time the
        // child class is mounted, the route has already routed to /webserver.
        if ($section === 'web' && static::class === self::class) {
            $this->redirect(route('servers.webserver', ['server' => $server]), navigate: true);

            return;
        }

        // 'services' was retired from the Manage workspace_tabs because the
        // standalone /services page is the canonical surface. Redirect deep
        // links instead of 404-ing — bookmarks and any cached external URLs
        // (digest emails, etc.) keep working.
        if ($section === 'services') {
            $this->redirect(route('servers.services', ['server' => $server]), navigate: true);

            return;
        }

        if ($section === 'updates' && Feature::active('workspace.patch_advisor')) {
            $this->redirect(route('servers.patches', $server), navigate: true);

            return;
        }

        if ($section === 'configuration') {
            $this->redirect(route('servers.configuration', ['server' => $server]), navigate: true);

            return;
        }

        // Subclasses (currently WorkspaceWebserver) get a small section allowlist
        // extension so their inherited mount() can pass a logical section name
        // ('web') that's no longer in workspace_tabs config — the tab strip in the
        // Manage view doesn't render 'web' anymore but the inherited state still
        // needs a non-null $section for the rest of the flow.
        $allowed = array_keys(config('server_manage.workspace_tabs', []));
        if (static::class !== self::class) {
            $allowed[] = 'web';
        }
        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;

        $this->bootWorkspace($server);
        $meta = $server->meta ?? [];
        $this->manage_auto_updates_interval = (string) ($meta['manage_auto_updates_interval'] ?? 'off');

        if ($section === 'tools') {
            $this->hydrateGitDeployIdentityForm();
        }
    }

    /**
     * Required by {@see DismissesConsoleActionRun}: identifies which model the
     * banner is scoped to. WorkspaceManage's banner shows server-level runs
     * (webserver_switch, etc.), so the subject is the server.
     */
    protected function consoleActionSubject(): Model
    {
        return $this->server;
    }

    /**
     * Override the trait placeholder so the Manage sub-tab strip stays
     * visible (with the destination section highlighted) while the body
     * lazy-loads — only the content area below the sub-tabs skeletons.
     */
    public function placeholder(): View
    {
        return view('livewire.servers.partials.workspace-subtab-placeholder', [
            'server' => $this->server,
            'active' => 'manage',
            'title' => __('Manage'),
            'tabs' => $this->manageWorkspaceTabs(),
            'section' => $this->section,
            'routeName' => 'servers.manage',
            'idPrefix' => 'manage-tab-',
            'ariaLabel' => __('Manage categories'),
        ]);
    }

    public function render(ServerManageToolsReport $toolsReport): View
    {
        // No $this->server->refresh() here: Livewire re-resolves the bound
        // model from the database on every request (route binding on first
        // load, the Eloquent synthesizer on later updates), so the row is
        // already current at render time. The poll/action handlers that mutate
        // the server (pollManageInventoryState, saveManageMetadata,
        // runPostMiseInventoryRefresh) refresh it themselves, so refreshing
        // again here only doubled the `select * from servers` per render.
        $recentActions = $this->section === 'overview'
            ? ServerManageAction::query()
                ->where('server_id', $this->server->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
            : collect();

        $serviceActions = config('server_manage.service_actions', []);

        // Quick-actions allowlist for the overview tile. Each key maps to one
        // or more systemd unit prefixes; the button is hidden if none of the
        // matching units exist on this server (e.g. a Valkey-only host
        // shouldn't surface "Reload NGINX" or "Restart PHP-FPM"). Lookup is
        // O(1) via the systemd-state table — populated by the inventory probe.
        $serviceActionUnitMatchers = [
            'reload_nginx' => ['nginx.service'],
            'restart_nginx' => ['nginx.service'],
            'restart_php_fpm' => ['php8.3-fpm.service', 'php8.2-fpm.service', 'php8.1-fpm.service', 'php8.4-fpm.service', 'php8.0-fpm.service', 'php7.4-fpm.service'],
            'reload_php_fpm' => ['php8.3-fpm.service', 'php8.2-fpm.service', 'php8.1-fpm.service', 'php8.4-fpm.service', 'php8.0-fpm.service', 'php7.4-fpm.service'],
            'restart_redis' => ['redis-server.service', 'redis.service'],
            // 'apt_update' has no service prerequisite — always available on Debian/Ubuntu.
        ];

        $installedUnits = ServerSystemdServiceState::query()
            ->where('server_id', $this->server->id)
            ->where(function ($q) {
                $q->whereNull('unit_file_state')
                    ->orWhere('unit_file_state', '!=', 'not-found');
            })
            ->pluck('unit')
            ->all();
        $installedUnitsSet = array_flip($installedUnits);

        $quickActionKeys = array_values(array_filter(
            array_keys($serviceActions),
            function (string $key) use ($serviceActionUnitMatchers, $installedUnitsSet): bool {
                if (! isset($serviceActionUnitMatchers[$key])) {
                    // No prerequisite declared (e.g. apt_update) — let it
                    // through, falls under "universal" maintenance actions.
                    return true;
                }
                foreach ($serviceActionUnitMatchers[$key] as $unit) {
                    if (isset($installedUnitsSet[$unit])) {
                        return true;
                    }
                }

                return false;
            },
        ));

        $activeMiseRuntimeOps = $this->section === 'tools'
            ? $this->activeMiseRuntimeOperations()
            : [];

        $activeToolActionOps = $this->section === 'tools'
            ? $this->activeToolActionOperations()
            : [];

        return view('livewire.servers.workspace-manage', [
            'configPreviews' => config('server_manage.config_previews', []),
            'serviceActions' => $serviceActions,
            'quickActionKeys' => $quickActionKeys,
            'dangerousActions' => config('server_manage.dangerous_actions', []),
            'autoUpdateIntervals' => config('server_manage.auto_update_intervals', []),
            'recentActions' => $recentActions,
            'toolsReport' => $this->section === 'tools'
                ? $toolsReport->build($this->server, $serviceActions)
                : null,
            'activeMiseRuntimeOps' => $activeMiseRuntimeOps,
            'activeToolActionOps' => $activeToolActionOps,
            'pendingToolActionKey' => $this->pendingToolActionKey,
            'miseReprobePending' => $this->miseReprobePending,
            'toolsPanel' => $this->toolsPanel,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'manageTabs' => $this->manageWorkspaceTabs(),
        ]);
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    protected function manageWorkspaceTabs(): array
    {
        $tabs = config('server_manage.workspace_tabs', []);

        if (Feature::active('workspace.patch_advisor')) {
            unset($tabs['updates']);
        }

        return $tabs;
    }

    protected function forceExtendedInventoryProbe(): bool
    {
        return true;
    }
}
