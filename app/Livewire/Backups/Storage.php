<?php

namespace App\Livewire\Backups;

use App\Livewire\Backups\Concerns\SummarisesBackupRuns;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Servers\Concerns\ManagesBackupDestinationModal;
use App\Models\BackupConfiguration;
use App\Models\BackupSchedule;
use App\Models\Organization;
use App\Models\ServerDatabaseBackup;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The Storage tab: the buckets and remotes an organization owns, and — the part
 * that was missing — what is actually using each one.
 *
 * Deliberately NOT behind the `workspace.backups` flag like the four type tabs.
 * These rows are credentials the customer owns; hiding them when a flag flips
 * would strand them. See docs/adr/backups-as-a-product.md, decision 13.
 */
#[Layout('layouts.app')]
class Storage extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    // Brings BOTH create modes — "connect existing" and "provision a new
    // bucket" — plus AuthorsBackupDestinations, which this page already used.
    use ManagesBackupDestinationModal;
    use SummarisesBackupRuns;

    /** @var array<string, mixed> */
    public array $editForm = [];

    public ?string $editing_id = null;

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', BackupConfiguration::class);
        $this->destinationForm = $this->emptyDestinationForm();
        $this->editForm = $this->emptyDestinationForm();
    }

    /**
     * The trait creates the row; auditing is this surface's business. Covers
     * both modes — a pasted bucket and a freshly provisioned one.
     */
    protected function onBackupDestinationCreated(BackupConfiguration $destination): void
    {
        $org = $destination->organization ?? Auth::user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        audit_log($org, Auth::user(), 'backup.destination.created', $destination, null, [
            'name' => $destination->name,
            'provider' => $destination->provider,
        ]);
    }

    public function startEdit(string $id): void
    {
        $config = BackupConfiguration::query()->findOrFail($id);

        $this->authorize('update', $config);

        $this->editing_id = $config->id;
        $this->editForm = $this->emptyDestinationForm();
        $this->editForm['name'] = $config->name;
        $this->editForm['provider'] = $config->provider;
        $this->hydrateDestinationFormFromConfig($this->editForm, $config->provider, $config->config);
    }

    public function cancelEdit(): void
    {
        $this->editing_id = null;
        $this->editForm = $this->emptyDestinationForm();
    }

    public function updateConfiguration(): void
    {
        if ($this->editing_id === null) {
            return;
        }

        $model = BackupConfiguration::query()->findOrFail($this->editing_id);

        $this->authorize('update', $model);

        $this->resetErrorBag();
        $this->validate($this->destinationFormRules('editForm', $this->editForm['provider'] ?? ''));
        $this->validateDestinationFormExtras('editForm', $this->editForm);

        $before = [
            'name' => $model->name,
            'provider' => $model->provider,
        ];

        $model->update([
            'name' => $this->editForm['name'],
            'provider' => $this->editForm['provider'],
            'config' => $this->extractDestinationConfig($this->editForm),
        ]);

        if ($org = $model->organization ?? Auth::user()?->currentOrganization()) {
            audit_log($org, Auth::user(), 'backup.destination.updated', $model, $before, [
                'name' => $model->name,
                'provider' => $model->provider,
            ]);
        }

        $this->cancelEdit();
        $this->toastSuccess(__('Backup destination updated.'));
    }

    public function deleteConfiguration(string $id): void
    {
        $config = BackupConfiguration::query()->findOrFail($id);

        $this->authorize('delete', $config);

        $org = $config->organization ?? Auth::user()?->currentOrganization();
        $snapshot = [
            'configuration_id' => (string) $config->id,
            'name' => $config->name,
            'provider' => $config->provider,
        ];

        $config->delete();

        if ($org) {
            audit_log($org, Auth::user(), 'backup.destination.deleted', null, $snapshot, null);
        }

        if ($this->editing_id === $id) {
            $this->cancelEdit();
        }

        $this->toastSuccess(__('Backup destination removed.'));
    }

    public function render(): View
    {
        $org = Auth::user()?->currentOrganization();

        $configurations = collect();
        if ($org !== null) {
            $query = $org->backupConfigurations()->orderBy('name');
            $term = trim($this->search);
            if ($term !== '') {
                $query->where('name', 'like', '%'.$term.'%');
            }
            $configurations = $query->get();
        }

        if ($org === null) {
            return view('livewire.backups.storage', [
                'configurations' => $configurations,
                'organization' => null,
                'usage' => [],
                'scheduleCounts' => collect(),
                'writes' => collect(),
                'trends' => [],
                'activity' => $this->dailyActivity(ServerDatabaseBackup::query()->whereRaw('1 = 0')),
                'metrics' => [
                    'destinations' => 0,
                    'providers' => 0,
                    'allProviders' => count(BackupConfiguration::providers()),
                    'shipping' => 0,
                    'dumpSchedules' => 0,
                    'onServer' => 0,
                    'coverage' => 0,
                    'storage' => Number::fileSize(0),
                ],
            ]);
        }

        $serverIds = $org->servers()->pluck('id');
        $configIds = $org->backupConfigurations()->pluck('id');

        // How many schedules point at each destination, across every target type.
        // The delete confirmation quotes this, so it has to be the true FK count
        // rather than only the kinds that actually ship bytes.
        $scheduleCounts = BackupSchedule::query()
            ->whereIn('server_id', $serverIds)
            ->whereIn('backup_configuration_id', $configIds)
            ->selectRaw('backup_configuration_id, count(*) as total')
            ->groupBy('backup_configuration_id')
            ->pluck('total', 'backup_configuration_id');

        // Coverage is measured over DATABASE schedules only. Site archives have
        // no `backup_configuration_id` on their artifact at all (they land on the
        // server or the control plane) and provider images live in the customer's
        // provider account — counting either against destination coverage would
        // manufacture a gap nobody can close. Same capability-aware rule the
        // Files and Snapshots tabs use.
        $dumpSchedules = BackupSchedule::query()
            ->whereIn('server_id', $serverIds)
            ->where('target_type', BackupSchedule::TARGET_DATABASE)
            ->where('is_active', true)
            ->get(['backup_configuration_id']);
        $shipping = $dumpSchedules->whereNotNull('backup_configuration_id')->count();

        $ownedDumps = fn ($q) => $q->whereIn('server_id', $serverIds);

        // Per-destination write stats. One grouped query rather than N per row.
        $writeStats = ServerDatabaseBackup::query()
            ->whereHas('serverDatabase', $ownedDumps)
            ->whereIn('backup_configuration_id', $configIds)
            ->where('status', ServerDatabaseBackup::STATUS_COMPLETED)
            ->selectRaw('backup_configuration_id, count(*) as total, sum(bytes) as bytes, max(created_at) as last_write')
            ->groupBy('backup_configuration_id')
            ->toBase()
            ->get()
            ->keyBy('backup_configuration_id');

        $usage = [];
        foreach ($configurations as $configuration) {
            $stats = $writeStats->get($configuration->id);

            $usage[$configuration->id] = [
                'schedules' => (int) ($scheduleCounts[$configuration->id] ?? 0),
                'writes' => (int) ($stats->total ?? 0),
                'bytes' => (int) ($stats->bytes ?? 0),
                'lastWriteAt' => $stats?->last_write ? Carbon::parse($stats->last_write) : null,
            ];
        }

        $writes = ServerDatabaseBackup::query()
            ->whereHas('serverDatabase', $ownedDumps)
            ->whereNotNull('backup_configuration_id')
            ->with(['serverDatabase.server', 'backupConfiguration'])
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return view('livewire.backups.storage', [
            'configurations' => $configurations,
            'organization' => $org,
            'usage' => $usage,
            'scheduleCounts' => $scheduleCounts,
            'writes' => $writes,
            'trends' => $this->recentSizes(
                ServerDatabaseBackup::query()
                    ->whereHas('serverDatabase', $ownedDumps)
                    ->whereIn('backup_configuration_id', $configIds),
                'backup_configuration_id',
            ),
            'activity' => $this->dailyActivity(
                ServerDatabaseBackup::query()
                    ->whereHas('serverDatabase', $ownedDumps)
                    ->whereNotNull('backup_configuration_id'),
            ),
            'metrics' => [
                'destinations' => $configurations->count(),
                'providers' => $configurations->pluck('provider')->unique()->count(),
                'allProviders' => count(BackupConfiguration::providers()),
                'shipping' => $shipping,
                'dumpSchedules' => $dumpSchedules->count(),
                'onServer' => $dumpSchedules->count() - $shipping,
                'coverage' => $dumpSchedules->count() > 0
                    ? (int) round($shipping / $dumpSchedules->count() * 100)
                    : 0,
                'storage' => Number::fileSize((int) collect($usage)->sum('bytes')),
            ],
        ]);
    }

}
