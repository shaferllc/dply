<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesMaintenanceNotifications;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsServerMaintenanceActions;
use App\Models\Server;
use App\Services\Servers\ServerMaintenanceWindow;
use App\Support\Servers\MaintenanceWindow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Server-level maintenance window — suspend all eligible VM sites with one
 * toggle and a shared public suspended page message.
 *
 * When {@see workspace.server_maintenance} is off but
 * {@see workspace.server_maintenance_preview} is on, the canonical
 * /maintenance URL renders the coming-soon teaser in place of the full
 * workspace.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceMaintenance extends Component
{
    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;
    use ManagesMaintenanceNotifications;
    use RendersWorkspacePlaceholder;
    use RequiresFeature;
    use RunsServerMaintenanceActions;

    protected string $requiredFeature = 'workspace.server_maintenance';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    /** In-page sub-tab: 'window', 'operations', 'schedule', or 'notifications'. */
    public string $maintenance_tab = 'window';

    public string $maintenance_until_local = '';

    public string $maintenance_note = '';

    public string $maintenance_message = '';

    /** Action key awaiting confirmation in the operations confirm modal. */
    public ?string $pendingActionKey = null;

    public function mount(Server $server): void
    {
        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);

        if (! Feature::active('workspace.server_maintenance')) {
            if (workspace_server_maintenance_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->bootWorkspace($server);

        $maintenance = app(ServerMaintenanceWindow::class);
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

    public function bootedRequiresFeature(): void
    {
        if ($this->comingSoonPreview) {
            return;
        }

        $flag = $this->requiredFeature ?? '';
        if ($flag !== '' && ! Feature::active($flag)) {
            abort(404);
        }
    }

    public function setMaintenanceTab(string $tab): void
    {
        $this->maintenance_tab = in_array($tab, ['window', 'operations', 'schedule', 'notifications'], true)
            ? $tab
            : 'window';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->maintenance_tab = 'notifications';
        $this->notif_channel_id = $channelId;
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

    /**
     * Stage an operation for the confirm modal (validated against the allowlist).
     */
    public function confirmAction(string $key): void
    {
        $this->authorize('update', $this->server);

        if (! in_array($key, $this->maintenanceActionKeys(), true) || $this->maintenanceActionDef($key) === null) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $this->pendingActionKey = $key;
        $this->dispatch('open-modal', 'maintenance-operation-confirmation');
    }

    public function closeActionModal(): void
    {
        $this->pendingActionKey = null;
        $this->dispatch('close-modal', 'maintenance-operation-confirmation');
    }

    public function runConfirmedAction(): void
    {
        $key = $this->pendingActionKey;
        $this->pendingActionKey = null;
        $this->dispatch('close-modal', 'maintenance-operation-confirmation');

        if ($key !== null) {
            $this->runMaintenanceAction($key);
        }
    }

    /**
     * Grouped operations resolved to UI metadata from the server_manage defs.
     *
     * @return list<array{title: string, actions: list<array{key: string, label: string, description: string, confirm: string, danger: bool}>}>
     */
    protected function maintenanceOperationGroups(): array
    {
        $groups = config('server_maintenance.operations', []);
        $dangerous = config('server_manage.dangerous_actions', []);
        $extraDanger = ['apt_dist_upgrade', 'docker_system_prune', 'docker_image_prune', 'docker_volume_prune'];

        $resolved = [];
        foreach ((is_array($groups) ? $groups : []) as $title => $keys) {
            $actions = [];
            foreach ((array) $keys as $key) {
                $def = $this->maintenanceActionDef((string) $key);
                if ($def === null) {
                    continue;
                }

                $actions[] = [
                    'key' => (string) $key,
                    'label' => (string) ($def['label'] ?? $key),
                    'description' => (string) ($def['description'] ?? ''),
                    'confirm' => (string) ($def['confirm'] ?? __('Run this operation on the server?')),
                    'danger' => array_key_exists($key, $dangerous) || in_array($key, $extraDanger, true),
                ];
            }

            if ($actions !== []) {
                $resolved[] = ['title' => (string) $title, 'actions' => $actions];
            }
        }

        return $resolved;
    }

    public function render(ServerMaintenanceWindow $maintenance): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-maintenance-preview');
        }

        $this->server->refresh();
        $maintenance->refreshExpired($this->server, auth()->user());
        $this->server->refresh();

        $report = $maintenance->report($this->server);
        $recurringWindow = MaintenanceWindow::forServer($this->server);
        $org = $this->server->organization;
        $cronMaintenanceActive = $org !== null
            && $org->cron_maintenance_until !== null
            && now()->lt($org->cron_maintenance_until);

        $pendingAction = $this->pendingActionKey !== null
            ? collect($this->maintenanceOperationGroups())
                ->flatMap(fn (array $g): array => $g['actions'])
                ->firstWhere('key', $this->pendingActionKey)
            : null;

        $bannerStatus = match ($this->maintenanceTaskStatus) {
            'finished' => 'completed',
            'failed' => 'failed',
            default => 'running',
        };

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
            'operationGroups' => $this->maintenanceOperationGroups(),
            'opsReady' => $this->serverOpsReady() && ! $this->currentUserIsDeployer(),
            'pendingAction' => $pendingAction,
            'bannerStatus' => $bannerStatus,
            'bannerBusy' => $this->maintenanceTaskBusy(),
            'bannerOutputLines' => $this->remote_output !== null && $this->remote_output !== ''
                ? preg_split('/\r\n|\r|\n/', rtrim($this->remote_output))
                : [],
            'notifChannels' => $this->maintenance_tab === 'notifications' ? $this->assignableMaintenanceNotificationChannels() : collect(),
            'notifSubscriptions' => $this->maintenance_tab === 'notifications' ? $this->maintenanceNotificationSubscriptions() : collect(),
            'notifEventLabels' => $this->maintenance_tab === 'notifications' ? $this->maintenanceEventLabels() : [],
        ]);
    }
}
