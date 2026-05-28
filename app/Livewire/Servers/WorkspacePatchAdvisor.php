<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsAllowlistedManageAction;
use App\Livewire\Servers\Concerns\RunsServerInventoryProbe;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\Servers\ServerPatchAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * OS patch workspace — inventory probe rollup plus apt actions, unattended-upgrades
 * controls, and reboot guidance (formerly split across Manage → Updates).
 */
#[Layout('layouts.app')]
class WorkspacePatchAdvisor extends Component
{
    use ConfirmsActionWithModal;
    use InteractsWithServerWorkspace;
    use RequiresFeature;
    use RunsAllowlistedManageAction;
    use RunsServerInventoryProbe;

    protected string $requiredFeature = 'workspace.patch_advisor';

    public string $manage_auto_updates_interval = 'off';

    public string $settingsInventoryDepth = 'basic';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);

        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);

        $meta = $server->meta ?? [];
        $this->manage_auto_updates_interval = (string) ($meta['manage_auto_updates_interval'] ?? 'off');

        $allowedDepth = array_keys(config('server_settings.inventory_scan_depths', ['basic' => '']));
        $depth = (string) ($meta['inventory_scan_depth'] ?? 'basic');
        $this->settingsInventoryDepth = in_array($depth, $allowedDepth, true) ? $depth : 'basic';
    }

    public function saveInventoryDepthPreference(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change scan settings.'));

            return;
        }

        $allowed = array_keys(config('server_settings.inventory_scan_depths', []));
        $this->validate([
            'settingsInventoryDepth' => ['required', 'string', 'in:'.implode(',', $allowed)],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['inventory_scan_depth'] = $this->settingsInventoryDepth;
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->toastSuccess(__('Scan depth saved.'));
    }

    public function applyDetectedOsFromInventory(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change OS labels.'));

            return;
        }

        $meta = $this->server->meta ?? [];
        $key = $meta['inventory_os_detected_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return;
        }

        $meta['os_version'] = $key;
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->toastSuccess(__('OS label updated to match the last scan.'));
    }

    public function saveManageMetadata(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change patch settings.'));

            return;
        }

        $this->validate([
            'manage_auto_updates_interval' => ['required', 'string', 'in:'.implode(',', array_keys(config('server_manage.auto_update_intervals', [])))],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['manage_auto_updates_interval'] = $this->manage_auto_updates_interval;

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->toastSuccess(__('Patch preferences saved.'));
    }

    protected function forceExtendedInventoryProbe(): bool
    {
        return true;
    }

    public function render(ServerPatchAdvisor $advisor): View
    {
        $this->server->refresh();

        $consoleRun = ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->whereIn('kind', ['manage_action', 'inventory_probe'])
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();

        return view('livewire.servers.workspace-patch-advisor', [
            'report' => $advisor->forServer($this->server),
            'osVersions' => config('server_settings.os_versions', []),
            'inventoryDepths' => config('server_settings.inventory_scan_depths', []),
            'serviceActions' => config('server_manage.service_actions', []),
            'dangerousActions' => config('server_manage.dangerous_actions', []),
            'autoUpdateIntervals' => config('server_manage.auto_update_intervals', []),
            'patchConsoleRun' => $consoleRun,
            'extendedSnapshot' => is_string($this->server->meta['inventory_extended_snapshot'] ?? null)
                ? $this->server->meta['inventory_extended_snapshot']
                : null,
        ]);
    }
}
