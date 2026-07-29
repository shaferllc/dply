<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Models\ServerBackupSchedule;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Backups\Models\SiteFileBackup;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Modules\Backups\Services\SiteFileBackupExporter;
use App\Support\Servers\DatabaseBackupSettings;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use Illuminate\Support\Collection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerBackupDatabases
{


    public function saveDatabaseBackupSettings(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'db_backup_default_kind' => 'required|in:remote_server,destination',
            'db_backup_configuration_id' => 'nullable|string',
            'db_backup_remote_max_value' => 'nullable|numeric|min:1|max:1048576',
            'db_backup_remote_max_unit' => 'required|in:MB,GB',
        ]);

        if ($this->db_backup_default_kind === DatabaseBackupSettings::KIND_DESTINATION && $this->db_backup_configuration_id === '') {
            $this->addError('db_backup_configuration_id', __('Pick a backup destination when using S3 storage.'));

            return;
        }

        $maxBytes = null;
        if ($this->db_backup_remote_max_value !== '') {
            $factor = $this->db_backup_remote_max_unit === 'MB' ? 1024 * 1024 : 1024 * 1024 * 1024;
            $maxBytes = (int) round((float) $this->db_backup_remote_max_value * $factor);
        }

        $settings = new DatabaseBackupSettings(
            defaultKind: $this->db_backup_default_kind,
            backupConfigurationId: $this->db_backup_configuration_id !== '' ? $this->db_backup_configuration_id : null,
            remoteMaxBytes: $maxBytes,
        );

        $meta = $this->server->meta ?? [];
        $meta['database_backup'] = $settings->toMetaArray();
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();

        $this->toastSuccess(__('Database backup storage settings saved.'));
    }

    protected function hydrateDatabaseBackupSettings(): void
    {
        $settings = DatabaseBackupSettings::fromServer($this->server);
        $this->db_backup_default_kind = $settings->defaultKind;
        $this->db_backup_configuration_id = $settings->backupConfigurationId ?? '';
        $bytes = $settings->remoteMaxBytes ?? (int) config('server_database.remote_backup_max_bytes_per_server', 10 * 1024 * 1024 * 1024);
        // Show sub-GB caps in MB so small values aren't awkward decimals (0.1 GB → 100 MB).
        if ($bytes < 1024 * 1024 * 1024) {
            $this->db_backup_remote_max_unit = 'MB';
            $this->db_backup_remote_max_value = (string) max(1, (int) round($bytes / 1024 / 1024));
        } else {
            $this->db_backup_remote_max_unit = 'GB';
            $this->db_backup_remote_max_value = (string) round($bytes / 1024 / 1024 / 1024, 1);
        }
    }

    /**
     * Databases that sites on THIS server attach to but which are hosted on a
     * DIFFERENT server (remote bindings). They're still ServerDatabase rows, so
     * a backup of one runs — correctly — on its own home server: the export job
     * resolves $db->server and dumps over that box's local socket. We surface
     * them here as a convenience for the consuming server. Scoped to the same
     * organization and, in site context, to the focused site's bindings.
     *
     * @return Collection<int, ServerDatabase>
     */
    private function remoteAttachedDatabases(): Collection
    {
        $siteScope = $this->siteDedicatedContext ? $this->context_site_id : null;

        $targetIds = SiteBinding::query()
            ->where('target_type', 'server_database')
            ->whereHas('site', fn ($q) => $q
                ->where('server_id', $this->server->id)
                ->when($siteScope !== null, fn ($q2) => $q2->whereKey($siteScope)))
            ->pluck('target_id')
            ->filter()
            ->unique()
            ->all();

        if ($targetIds === []) {
            return collect();
        }

        return ServerDatabase::query()
            ->whereIn('id', $targetIds)
            ->where('server_id', '!=', $this->server->id)
            ->whereHas('server', fn ($q) => $q->where('organization_id', $this->server->organization_id))
            ->with('server')
            ->orderBy('name')
            ->get();
    }

    /**
     * Resolve a database backup this server's workspace may act on: its database
     * is hosted here, OR it's one a site here attaches to remotely (the same set
     * surfaced in history). Returns null when not found or not allowed — so a
     * remote-attached backup no longer 404s the download/delete action. The
     * downloader streams from the database's own home server regardless.
     */
    private function resolveDatabaseBackupForServer(string $backupId): ?ServerDatabaseBackup
    {
        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->with('serverDatabase.server')
            ->first();

        $db = $backup?->serverDatabase;
        if ($db === null) {
            return null;
        }

        $allowed = (string) $db->server_id === (string) $this->server->id
            || $this->remoteAttachedDatabases()->contains('id', $db->id);

        return $allowed ? $backup : null;
    }

    /**
     * Off-render-path probe (wire:init) that lists databases actually present on
     * the server — registered or not — so the Quick-download card can offer an
     * instant dump of each. Mirrors the drift analyzer's live listing. Runs in
     * server context only; site context already scopes to linked databases. Never
     * throws into the render path — engine probe failures degrade to an empty list.
     */
    public function detectLiveDatabases(
        ServerDatabaseHostCapabilities $capabilities,
        ServerDatabaseProvisioner $provisioner,
    ): void {
        $this->liveDbDetected = true;

        if ($this->context_site_id !== null) {
            return;
        }

        $this->authorize('update', $this->server);

        $caps = $capabilities->forServer($this->server);
        $targets = [];

        if (($caps['mysql'] ?? false) || ($caps['mariadb'] ?? false)) {
            try {
                foreach ($provisioner->listMysqlDatabaseNames($this->server) as $name) {
                    $targets[] = ['engine' => 'mysql', 'name' => $name];
                }
            } catch (\Throwable) {
                // ignore — engine present but unreachable
            }
        }

        if ($caps['postgres'] ?? false) {
            try {
                foreach ($provisioner->listPostgresDatabaseNames($this->server) as $name) {
                    $targets[] = ['engine' => 'postgres', 'name' => $name];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $this->liveDbDumpTargets = $targets;
    }

    private function dispatchScheduleDatabase(ServerBackupSchedule $schedule): void
    {
        $database = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($schedule->target_id)
            ->first();
        if ($database === null) {
            $this->toastError(__('Schedule target database is missing.'));

            return;
        }

        $backup = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);

        app(DatabaseBackupExporter::class)->prepareBackupRow(
            $backup,
            $this->server,
            $schedule->backup_configuration_id,
        );

        $runId = $this->startBackupConsoleRun(
            'backup_database',
            __('Database — :name', ['name' => $database->name]),
            (string) $backup->id,
            'database',
        );

        ExportServerDatabaseBackupJob::dispatch($backup->id, $runId);
        $this->dispatchBackupNotification('run_started', [__('Database — :name', ['name' => $database->name])], [
            'backup_type' => 'database',
            'backup_id' => (string) $backup->id,
            'database_id' => (string) $database->id,
            'scheduled' => true,
        ]);
        $this->dispatch('dply-console-action-focus');
    }

    private function dispatchScheduleSiteFiles(ServerBackupSchedule $schedule): void
    {
        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($schedule->target_id)
            ->first();
        if ($site === null) {
            $this->toastError(__('Schedule target site is missing.'));

            return;
        }

        $backup = SiteFileBackup::create([
            'site_id' => $site->id,
            'user_id' => auth()->id(),
            'status' => SiteFileBackup::STATUS_PENDING,
        ]);
        $runId = $this->startBackupConsoleRun(
            'backup_site_files',
            __('Site files — :name', ['name' => $site->name]),
            (string) $backup->id,
            'site_files',
        );

        ExportSiteFileBackupJob::dispatch($backup->id, $runId);
        $this->dispatchBackupNotification('run_started', [__('Site files — :name', ['name' => $site->name])], [
            'backup_type' => 'site_files',
            'backup_id' => (string) $backup->id,
            'site_id' => (string) $site->id,
            'scheduled' => true,
        ]);
        $this->dispatch('dply-console-action-focus');
    }

    public function deleteDatabaseBackup(string $backupId): void
    {
        $this->authorize('update', $this->server);

        $backup = $this->resolveDatabaseBackupForServer($backupId);
        if ($backup === null) {
            return;
        }

        app(DatabaseBackupExporter::class)->deleteArtifact($backup);
        $this->purgeBackupStagings($backup);
        $snapshot = [
            'backup_id' => (string) $backup->id,
            'server_database_id' => (string) $backup->server_database_id,
            'storage_kind' => $backup->storage_kind,
            'status' => $backup->status,
        ];
        $backup->delete();

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.database.deleted', $this->server, $snapshot, null);
        }

        $this->dispatchBackupNotification('deleted', [__('Database backup')], [
            'backup_type' => 'database',
            'backup_id' => $snapshot['backup_id'],
            'server_database_id' => $snapshot['server_database_id'],
        ]);

        $this->toastSuccess(__('Backup deleted.'));
    }

    public function deleteFileBackup(string $backupId): void
    {
        $this->authorize('update', $this->server);

        $backup = SiteFileBackup::query()
            ->whereKey($backupId)
            ->whereHas('site', fn ($q) => $q->where('server_id', $this->server->id))
            ->first();
        if ($backup === null) {
            return;
        }

        app(SiteFileBackupExporter::class)->deleteArtifact($backup);
        $this->purgeBackupStagings($backup);
        $snapshot = [
            'backup_id' => (string) $backup->id,
            'site_id' => (string) $backup->site_id,
            'disk_path' => $backup->disk_path,
            'status' => $backup->status,
        ];
        $backup->delete();

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.site_files.deleted', $this->server, $snapshot, null);
        }

        $this->dispatchBackupNotification('deleted', [__('Site files backup')], [
            'backup_type' => 'site_files',
            'backup_id' => $snapshot['backup_id'],
            'site_id' => $snapshot['site_id'],
        ]);

        $this->toastSuccess(__('Backup deleted.'));
    }
}
