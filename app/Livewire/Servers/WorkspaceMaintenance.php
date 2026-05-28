<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerMaintenanceWindow;
use App\Support\Servers\MaintenanceWindow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Server-level maintenance window — suspend all eligible VM sites with one
 * toggle and a shared public suspended page message.
 */
#[Layout('layouts.app')]
class WorkspaceMaintenance extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.server_maintenance';

    public string $maintenance_until_local = '';

    public string $maintenance_note = '';

    public string $maintenance_message = '';

    public function mount(Server $server, ServerMaintenanceWindow $maintenance): void
    {
        $this->bootWorkspace($server);

        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);

        $maintenance->refreshExpired($server, auth()->user());
        $this->server->refresh();

        $state = $maintenance->state($this->server);
        if (is_array($state)) {
            $this->maintenance_note = (string) ($state['note'] ?? '');
            $this->maintenance_message = (string) ($state['message'] ?? '');

            $until = $state['until'] ?? null;
            if (is_string($until) && $until !== '') {
                try {
                    $this->maintenance_until_local = Carbon::parse($until)
                        ->timezone(config('app.timezone'))
                        ->format('Y-m-d\TH:i');
                } catch (\Throwable) {
                    $this->maintenance_until_local = '';
                }
            }
        }
    }

    public function openEnableModal(): void
    {
        $this->authorize('update', $this->server);
        $this->dispatch('open-modal', 'enable-maintenance-confirmation');
    }

    public function closeEnableModal(): void
    {
        $this->dispatch('close-modal', 'enable-maintenance-confirmation');
    }

    public function openDisableModal(): void
    {
        $this->authorize('update', $this->server);
        $this->dispatch('open-modal', 'disable-maintenance-confirmation');
    }

    public function closeDisableModal(): void
    {
        $this->dispatch('close-modal', 'disable-maintenance-confirmation');
    }

    public function enableMaintenance(ServerMaintenanceWindow $maintenance): void
    {
        $this->authorize('update', $this->server);

        if ($maintenance->isActive($this->server)) {
            $this->toastError(__('Maintenance is already active on this server.'));

            return;
        }

        $validated = $this->validate([
            'maintenance_until_local' => ['nullable', 'string'],
            'maintenance_note' => ['nullable', 'string', 'max:500'],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
        ]);

        $until = null;
        if (trim($validated['maintenance_until_local']) !== '') {
            try {
                $until = Carbon::parse(
                    $validated['maintenance_until_local'],
                    config('app.timezone'),
                )->utc();
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'maintenance_until_local' => __('Enter a valid date and time.'),
                ]);
            }

            if ($until->lte(now())) {
                throw ValidationException::withMessages([
                    'maintenance_until_local' => __('The end time must be in the future.'),
                ]);
            }
        }

        try {
            $result = $maintenance->enable(
                $this->server,
                $until,
                (string) ($validated['maintenance_note'] ?? ''),
                (string) ($validated['maintenance_message'] ?? ''),
                auth()->user(),
            );
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->server->refresh();
        $this->closeEnableModal();

        $this->toastSuccess(trans_choice(
            'Maintenance enabled — :count site suspended.|Maintenance enabled — :count sites suspended.',
            $result['suspended'],
            ['count' => $result['suspended']],
        ).' '.__('Webserver configs queued.'));
    }

    public function disableMaintenance(ServerMaintenanceWindow $maintenance): void
    {
        $this->authorize('update', $this->server);

        try {
            $result = $maintenance->disable($this->server, auth()->user());
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->server->refresh();
        $this->closeDisableModal();

        $this->toastSuccess(trans_choice(
            'Maintenance cleared — :count site resumed.|Maintenance cleared — :count sites resumed.',
            $result['resumed'],
            ['count' => $result['resumed']],
        ).' '.__('Webserver configs queued.'));
    }

    public function render(ServerMaintenanceWindow $maintenance): View
    {
        $this->server->refresh();
        $maintenance->refreshExpired($this->server, auth()->user());
        $this->server->refresh();

        $report = $maintenance->report($this->server);
        $recurringWindow = MaintenanceWindow::forServer($this->server);
        $org = $this->server->organization;
        $cronMaintenanceActive = $org !== null
            && $org->cron_maintenance_until !== null
            && now()->lt($org->cron_maintenance_until);

        return view('livewire.servers.workspace-maintenance', [
            'report' => $report,
            'active' => $report['active'],
            'state' => $report['state'],
            'preview' => $report['preview'],
            'eligibleCount' => $report['summary']['eligible'],
            'recurringWindow' => $recurringWindow,
            'cronMaintenanceActive' => $cronMaintenanceActive,
            'cronMaintenanceUntil' => $org?->cron_maintenance_until,
            'cronMaintenanceNote' => $org?->cron_maintenance_note,
        ]);
    }
}
