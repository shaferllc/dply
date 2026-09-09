<?php

declare(strict_types=1);

namespace App\Livewire\Backups\Concerns;

use App\Models\BackupConfiguration;
use App\Models\BackupSchedule;
use App\Models\Organization;
use App\Models\Server;
use Cron\CronExpression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Create, retime and retarget a {@see BackupSchedule} from a Backups type tab.
 *
 * Until now the type tabs could pause, resume and run a schedule but not change
 * one — changing a cadence meant leaving for the server workspace, which is a
 * strange trip when the tab already lists every schedule in the org.
 *
 * Shared for the same reason as {@see RunsBackupSchedules} and
 * {@see SummarisesBackupRuns}: Databases, Files and Snapshots all need it, and
 * three copies is how the schedule UI drifted apart the first time
 * (docs/adr/backups-as-a-product.md, decision 8).
 *
 * @phpstan-require-extends Component
 */
trait EditsBackupSchedules
{
    public bool $showScheduleModal = false;

    /** Set when retiming an existing schedule; null when creating one. */
    public ?string $editing_schedule_id = null;

    /** @var array<string, mixed> */
    public array $scheduleForm = [
        'target_type' => BackupSchedule::TARGET_DATABASE,
        'target_id' => '',
        'server_id' => '',
        'cadence' => '0 3 * * *',
        'cron_expression' => '0 3 * * *',
        'backup_configuration_id' => '',
        'notify_on_failure' => true,
        'is_active' => true,
    ];

    /**
     * The cadences worth one click. "Custom" hands the operator the raw cron
     * field — the preset list is a shortcut, never a cage.
     *
     * @return array<string, string>
     */
    public function scheduleCadenceOptions(): array
    {
        return [
            '0 * * * *' => __('Every hour'),
            '0 */6 * * *' => __('Every 6 hours'),
            '0 */12 * * *' => __('Every 12 hours'),
            '0 3 * * *' => __('Daily at 03:00'),
            '0 3 * * 0' => __('Weekly, Sunday 03:00'),
            'custom' => __('Custom cron…'),
        ];
    }

    /**
     * Destinations this org can ship to, for the picker.
     *
     * @return Collection<int, BackupConfiguration>
     */
    public function scheduleDestinationOptions(): Collection
    {
        $org = $this->scheduleOrganization();

        return $org instanceof Organization
            ? $org->backupConfigurations()->orderBy('name')->get()
            : collect();
    }

    public function openScheduleModal(string $targetType, string $targetId, string $serverId): void
    {
        $server = $this->authorizedServer($serverId);
        if (! $server instanceof Server) {
            return;
        }

        $this->resetErrorBag();
        $this->editing_schedule_id = null;
        $this->scheduleForm = [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'server_id' => (string) $server->id,
            'cadence' => '0 3 * * *',
            'cron_expression' => '0 3 * * *',
            'backup_configuration_id' => '',
            'notify_on_failure' => true,
            'is_active' => true,
        ];
        $this->showScheduleModal = true;
    }

    public function editSchedule(string $scheduleId): void
    {
        $schedule = BackupSchedule::with('server')->find($scheduleId);
        if (! $schedule instanceof BackupSchedule || ! $schedule->server instanceof Server) {
            $this->toastError(__('That schedule is no longer available.'));

            return;
        }

        Gate::authorize('update', $schedule->server);

        $this->resetErrorBag();
        $known = array_key_exists($schedule->cron_expression, $this->scheduleCadenceOptions());

        $this->scheduleForm = [
            'target_type' => $schedule->target_type,
            'target_id' => (string) $schedule->target_id,
            'server_id' => (string) $schedule->server_id,
            // An expression that isn't one of the presets must open on "Custom",
            // or saving would silently retime it to the default.
            'cadence' => $known ? $schedule->cron_expression : 'custom',
            'cron_expression' => $schedule->cron_expression,
            'backup_configuration_id' => (string) ($schedule->backup_configuration_id ?? ''),
            'notify_on_failure' => (bool) $schedule->notify_on_failure,
            'is_active' => (bool) $schedule->is_active,
        ];

        $this->editing_schedule_id = (string) $schedule->id;
        $this->showScheduleModal = true;
    }

    public function closeScheduleModal(): void
    {
        $this->showScheduleModal = false;
        $this->editing_schedule_id = null;
        $this->resetErrorBag();
    }

    public function saveSchedule(): void
    {
        $server = $this->authorizedServer((string) ($this->scheduleForm['server_id'] ?? ''));
        if (! $server instanceof Server) {
            return;
        }

        $this->resetErrorBag();

        $cron = $this->scheduleForm['cadence'] === 'custom'
            ? trim((string) $this->scheduleForm['cron_expression'])
            : (string) $this->scheduleForm['cadence'];

        if (! CronExpression::isValidExpression($cron)) {
            $this->addError('scheduleForm.cron_expression', __('That is not a valid cron expression. Five fields, for example "0 3 * * *".'));

            return;
        }

        $destinationId = trim((string) ($this->scheduleForm['backup_configuration_id'] ?? ''));
        if ($destinationId !== '' && ! $this->destinationBelongsToOrg($destinationId)) {
            $this->addError('scheduleForm.backup_configuration_id', __('That destination is no longer available.'));

            return;
        }

        $payload = [
            'cron_expression' => $cron,
            'backup_configuration_id' => $destinationId !== '' ? $destinationId : null,
            'notify_on_failure' => (bool) ($this->scheduleForm['notify_on_failure'] ?? true),
            'is_active' => (bool) ($this->scheduleForm['is_active'] ?? true),
        ];

        if ($this->editing_schedule_id !== null) {
            $schedule = BackupSchedule::find($this->editing_schedule_id);
            if (! $schedule instanceof BackupSchedule) {
                $this->toastError(__('That schedule is no longer available.'));
                $this->closeScheduleModal();

                return;
            }

            $before = [
                'cron_expression' => $schedule->cron_expression,
                'backup_configuration_id' => $schedule->backup_configuration_id,
            ];
            $schedule->update($payload);

            $this->auditSchedule($server, 'backup.schedule.updated', $schedule, $before, $payload);
            $this->closeScheduleModal();
            $this->toastSuccess(__('Schedule updated.'));

            return;
        }

        $schedule = BackupSchedule::create($payload + [
            // Derived from the target, never from the caller: a schedule whose
            // server_id disagrees with where its target lives is the drift that
            // made "Run now" fail on an otherwise healthy schedule.
            'server_id' => (string) ($this->serverIdForTarget(
                (string) $this->scheduleForm['target_type'],
                (string) $this->scheduleForm['target_id'],
            ) ?? $server->id),
            'target_type' => (string) $this->scheduleForm['target_type'],
            'target_id' => (string) $this->scheduleForm['target_id'],
        ]);

        $this->auditSchedule($server, 'backup.schedule.created', $schedule, null, $payload);
        $this->closeScheduleModal();
        $this->toastSuccess(__('Schedule created.'));
    }

    public function deleteSchedule(string $scheduleId): void
    {
        $schedule = BackupSchedule::with('server')->find($scheduleId);
        if (! $schedule instanceof BackupSchedule || ! $schedule->server instanceof Server) {
            return;
        }

        Gate::authorize('update', $schedule->server);

        $snapshot = [
            'cron_expression' => $schedule->cron_expression,
            'target_type' => $schedule->target_type,
            'target_id' => $schedule->target_id,
        ];

        $server = $schedule->server;
        $schedule->delete();

        $this->auditSchedule($server, 'backup.schedule.deleted', null, $snapshot, null);
        $this->closeScheduleModal();
        // Says what stops, not just what was removed — deleting a schedule means
        // nothing captures this target again until one is recreated.
        $this->toastSuccess(__('Schedule removed. Nothing will back this target up automatically now.'));
    }

    /** Where the target actually lives, so server_id can never drift from it. */
    private function serverIdForTarget(string $targetType, string $targetId): ?string
    {
        $server = match ($targetType) {
            BackupSchedule::TARGET_DATABASE => \App\Models\ServerDatabase::find($targetId)?->server_id,
            BackupSchedule::TARGET_SITE_FILES => \App\Models\Site::find($targetId)?->server_id,
            default => null,
        };

        return $server === null ? null : (string) $server;
    }

    /** Resolve a server the current user may actually manage. */
    private function authorizedServer(string $serverId): ?Server
    {
        $server = Server::find($serverId);
        if (! $server instanceof Server) {
            $this->toastError(__('That server is no longer available.'));

            return null;
        }

        Gate::authorize('update', $server);

        return $server;
    }

    private function destinationBelongsToOrg(string $destinationId): bool
    {
        $org = $this->scheduleOrganization();

        return $org instanceof Organization
            && $org->backupConfigurations()->whereKey($destinationId)->exists();
    }

    private function scheduleOrganization(): ?Organization
    {
        return Auth::user()?->currentOrganization();
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function auditSchedule(Server $server, string $event, ?BackupSchedule $schedule, ?array $before, ?array $after): void
    {
        $org = $server->organization;
        if ($org === null) {
            return;
        }

        audit_log($org, Auth::user(), $event, $schedule, $before, $after);
    }
}
