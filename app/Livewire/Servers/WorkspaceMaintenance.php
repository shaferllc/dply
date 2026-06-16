<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesMaintenanceNotifications;
use App\Livewire\Servers\Concerns\ManagesPreferredMaintenanceSchedule;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsServerMaintenanceActions;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\AuditLog;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\ServerMaintenanceWindow;
use App\Support\Servers\MaintenanceWindow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
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
    use ManagesPreferredMaintenanceSchedule;
    use RendersWorkspacePlaceholder;
    use RequiresFeature;
    use RunsServerMaintenanceActions;

    protected string $requiredFeature = 'workspace.server_maintenance';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    /** In-page sub-tab: 'window', 'operations', 'schedule', or 'notifications'. */
    #[Url(as: 'tab', except: 'window', history: true)]
    public string $maintenance_tab = 'window';

    public string $maintenance_until_local = '';

    /**
     * IANA timezone of the operator's browser, captured client-side so the
     * end-time field reads as local wall-clock instead of UTC. Falls back to
     * the app timezone when JS is unavailable or sends a bad value.
     */
    public string $maintenance_timezone = '';

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
        $this->loadPreferredMaintenanceSchedule();

        // A ?tab= value from the URL bypasses setMaintenanceTab()'s allowlist;
        // clamp unknown values back to the default so every panel isn't hidden.
        $this->setMaintenanceTab($this->maintenance_tab);

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
                    // Render as a UTC wall-clock string; the field's Alpine
                    // wrapper re-localizes it to the operator's browser tz.
                    $this->maintenance_until_local = Carbon::parse($until)
                        ->utc()
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

        // Validate the window up front so an invalid end time surfaces inline
        // under the field instead of silently failing behind the open modal.
        $this->resolveMaintenanceUntil();

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

        // Re-validate at confirm time; if the window has since gone invalid,
        // close the modal so the inline error is visible rather than hidden
        // behind it.
        try {
            $until = $this->resolveMaintenanceUntil();
        } catch (ValidationException $e) {
            $this->closeEnableModal();

            throw $e;
        }

        try {
            $result = $maintenance->enable(
                $this->server,
                $until,
                $this->maintenance_note,
                $this->maintenance_message,
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

    /**
     * Validate the maintenance form and resolve the optional end time to UTC.
     *
     * Shared by {@see openEnableModal()} (gate before the confirm modal opens)
     * and {@see enableMaintenance()} (re-check at confirm). Throws a
     * {@see ValidationException} with an inline message when the end time is
     * unparseable or not in the future.
     */
    protected function resolveMaintenanceUntil(): ?Carbon
    {
        $validated = $this->validate([
            'maintenance_until_local' => ['nullable', 'string'],
            'maintenance_note' => ['nullable', 'string', 'max:500'],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
        ]);

        if (trim($validated['maintenance_until_local']) === '') {
            return null;
        }

        try {
            $until = Carbon::parse(
                $validated['maintenance_until_local'],
                $this->operatorTimezone(),
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

        return $until;
    }

    /**
     * Resolve the timezone the operator's end time is expressed in. Trusts the
     * browser-supplied {@see $maintenance_timezone} only when it is a known
     * IANA identifier; otherwise falls back to the app timezone (UTC).
     */
    protected function operatorTimezone(): string
    {
        $tz = trim($this->maintenance_timezone);

        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }

        return config('app.timezone');
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

    /**
     * Recent visitor-maintenance enable/disable events for this server, read
     * from the audit log (already written by ServerMaintenanceWindow).
     *
     * @return list<array{action: string, label: string, at: \Illuminate\Support\Carbon, by: ?string, detail: ?string, ok: bool}>
     */
    protected function maintenanceHistory(int $limit = 12): array
    {
        $rows = AuditLog::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->whereIn('action', ['server.maintenance.enabled', 'server.maintenance.disabled'])
            ->with('user:id,name,email')
            ->latest()
            ->limit($limit)
            ->get();

        return $rows->map(function (AuditLog $row): array {
            $values = is_array($row->new_values) ? $row->new_values : [];
            $enabled = $row->action === 'server.maintenance.enabled';

            if ($enabled) {
                $count = (int) ($values['suspended_count'] ?? 0);
                $detail = trans_choice(':count site suspended|:count sites suspended', $count, ['count' => $count]);
                if (! empty($values['auto_expired'])) {
                    $detail .= ' · '.__('auto-expired');
                }
            } else {
                $count = (int) ($values['resumed_count'] ?? 0);
                $detail = trans_choice(':count site resumed|:count sites resumed', $count, ['count' => $count]);
                if (! empty($values['auto_expired'])) {
                    $detail .= ' · '.__('auto-cleared on expiry');
                }
            }

            return [
                'action' => $row->action,
                'label' => $enabled ? __('Maintenance enabled') : __('Maintenance cleared'),
                'at' => $row->created_at,
                'by' => $row->user?->name ?: $row->user?->email,
                'detail' => $detail,
                'ok' => ! $enabled,
            ];
        })->all();
    }

    /**
     * Sites on this server whose most recent webserver-config apply failed —
     * the silent failure mode that can leave a box broken after a maintenance
     * toggle. Surfaced with a one-click re-apply.
     *
     * @return list<array{site_id: string, name: string, error: string, at: ?\Illuminate\Support\Carbon}>
     */
    protected function recentApplyFailures(): array
    {
        $siteIds = $this->server->sites->pluck('id')->map(fn ($id): string => (string) $id)->all();
        if ($siteIds === []) {
            return [];
        }

        $latest = ConsoleAction::query()
            ->where('kind', 'webserver_config')
            ->whereIn('subject_id', $siteIds)
            ->whereIn('id', function ($q) use ($siteIds): void {
                $q->selectRaw('max(id)')
                    ->from('console_actions')
                    ->where('kind', 'webserver_config')
                    ->whereIn('subject_id', $siteIds)
                    ->groupBy('subject_id');
            })
            ->where('status', 'failed')
            ->whereNull('dismissed_at')
            ->get();

        $names = $this->server->sites->keyBy(fn (Site $s): string => (string) $s->id);

        return $latest->map(fn (ConsoleAction $a): array => [
            'site_id' => (string) $a->subject_id,
            'name' => $names->get((string) $a->subject_id)?->name ?? (string) $a->subject_id,
            'error' => trim((string) ($a->error ?? '')) ?: __('Webserver config apply failed.'),
            'at' => $a->finished_at ?? $a->created_at,
        ])->values()->all();
    }

    /**
     * Re-dispatch the webserver-config apply for a site whose last apply failed
     * (e.g. a maintenance toggle left it broken).
     */
    public function reapplyWebserverConfig(string $siteId): void
    {
        $this->authorize('update', $this->server);

        $site = $this->server->sites->firstWhere('id', $siteId);
        if ($site === null) {
            $this->toastError(__('Unknown site.'));

            return;
        }

        ApplySiteWebserverConfigJob::dispatch((string) $site->id, (string) auth()->id());
        $this->toastSuccess(__('Re-applying webserver config for :site…', ['site' => $site->name]));
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
            'maintenanceWeekdays' => config('server_settings.maintenance_weekdays', []),
            'maintenanceHistory' => $this->maintenanceHistory(),
            'applyFailures' => $this->recentApplyFailures(),
            'canEditSchedule' => ! $this->currentUserIsDeployer(),
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
