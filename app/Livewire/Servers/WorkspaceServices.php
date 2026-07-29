<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesServerSystemdServices;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\Server;
use App\Models\ServerSystemdServiceAuditEvent;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\ServicesWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceServices extends Component
{
    use RendersWorkspacePlaceholder;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.services';

    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesServerSystemdServices;
    use WithPagination;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        // Inventory + activity are hydrated in render() based on the active tab
        // (and any open modals), which runs on this initial request too — so we
        // don't hydrate here as well or every page load fires the systemd state
        // + notification-subscription selects twice.
        $this->hydrateSystemdSyncBannerDismissalFromSession();
    }

    public function setServicesWorkspaceTab(string $tab): void
    {
        $allowed = ['inventory', 'activity'];
        $this->services_workspace_tab = in_array($tab, $allowed, true) ? $tab : 'inventory';
        $this->resetPage('serviceActivity');
    }

    /**
     * Merged Services card skeleton (hide-hero) so lazy load matches the page
     * instead of flashing a separate title card + generic pulses.
     */
    public function placeholder(): View
    {
        if ($this->server === null) {
            return view('livewire.servers.partials.workspace-placeholder-empty');
        }

        return view('livewire.servers.partials.workspace-services-placeholder', [
            'server' => $this->server,
        ]);
    }

    public function render(): View
    {
        $allowedTabs = ['inventory', 'activity'];
        if (! in_array($this->services_workspace_tab, $allowedTabs, true)) {
            $this->services_workspace_tab = 'inventory';
        }

        $tab = $this->services_workspace_tab;
        $needsInventory = $tab === 'inventory';
        $needsActivity = $tab === 'activity';

        $needsInventoryData = $needsInventory
            || $this->showSystemdActionConfirm
            || $this->showCustomSystemdModal;

        $needsServerRefresh = $needsInventory
            || $needsActivity
            || $this->showRemoveServerModal
            || $this->showSystemdActionConfirm
            || $this->showCustomSystemdModal
            || $this->showSystemdStatusModal
            || $this->showSystemdNotifyModal
            || $this->systemdRemoteTaskId !== null
            || in_array($this->systemdActionBannerStatus, ['queued', 'running'], true);

        if ($needsServerRefresh) {
            $this->server->refresh();
        }

        if ($needsInventoryData) {
            $this->hydrateSystemdInventoryFromDatabase();
        }

        // Use a view-only name — Livewire merges public props over view data, so a
        // paginator named `$systemdServiceActivity` would be overwritten by the
        // public array property of the same name.
        $activityEvents = null;
        if ($needsActivity) {
            $perPage = max(10, min(50, (int) config('server_services.systemd_services_activity_per_page', 20)));
            $activityEvents = ServerSystemdServiceAuditEvent::query()
                ->where('server_id', $this->server->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate($perPage, pageName: 'serviceActivity')
                ->through(static fn (ServerSystemdServiceAuditEvent $e): array => [
                    'at' => $e->occurred_at->toIso8601String(),
                    'kind' => $e->kind,
                    'unit' => $e->unit,
                    'label' => $e->label,
                    'detail' => $e->detail,
                ]);
        }

        $this->server->loadMissing(['organization']);

        $deployerSystemdLocked = $this->currentUserIsDeployer()
            && ! (bool) ($this->server->organization?->mergedServicesPreferences()['deployer_systemd_actions_enabled'] ?? false);

        $filteredSystemdInventory = $needsInventoryData
            ? $this->systemdFilteredInventoryRows()
            : [];

        return view('livewire.servers.workspace-services', array_merge(
            ServicesWorkspaceViewData::for(
                $this->server,
                $this,
                includeBannerContext: $this->serverOpsReady(),
                includeInventoryContext: $needsInventoryData,
                includeActivityContext: $needsActivity,
                activityEvents: $activityEvents,
                systemdInventoryFetchedAt: $this->systemdInventoryFetchedAt,
            ),
            [
                'opsReady' => $this->serverOpsReady(),
                'isDeployer' => $this->currentUserIsDeployer(),
                'deployerSystemdLocked' => $deployerSystemdLocked,
                'deletionSummary' => $this->showRemoveServerModal
                    ? ServerRemovalAdvisor::summary($this->server)
                    : null,
                'filteredSystemdInventory' => $filteredSystemdInventory,
            ],
        ));
    }
}
