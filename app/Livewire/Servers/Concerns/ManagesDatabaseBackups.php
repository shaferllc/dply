<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use App\Livewire\Concerns\AuthorsBackupDestinations;
use App\Models\BackupConfiguration;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseBackup;
use App\Modules\Backups\Services\DatabaseBackupDownloader;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseRemoteExec;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDatabaseBackups
{
    public string $import_target_db_id = '';

    /**
     * State for the "Back up" modal. The operator picks where the dump lands:
     * an existing org S3 destination, a brand-new S3 destination (created inline
     * via the AuthorsBackupDestinations trait form), or the default server disk.
     */
    public ?string $backup_modal_db_id = null;

    /** 'existing' | 'new' | 'server' */
    public string $backup_destination_mode = 'existing';

    public ?string $backup_destination_id = null;

    /** @var array<string, mixed> Trait-shaped destination form for the 'new' mode. */
    public array $backupDestinationForm = [];

    public $import_sql_file = null;

    /**
     * Org-level S3-compatible backup destinations this server can reuse. Keyed
     * for the modal's "existing destination" picker.
     *
     * @return Collection<int, BackupConfiguration>
     */
    protected function s3BackupDestinations(): Collection
    {
        if ($this->server->organization_id === null) {
            return collect();
        }

        return BackupConfiguration::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereIn('provider', self::S3_BACKUP_PROVIDERS)
            ->orderBy('name')
            ->get();
    }

    public function openBackupModal(string $databaseId): void
    {
        $this->authorize('update', $this->server);

        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($databaseId)
            ->first();
        if (! $db) {
            return;
        }

        $this->resetErrorBag();
        $this->backup_modal_db_id = $db->id;
        $this->backupDestinationForm = $this->emptyDestinationForm();
        // Default the inline form to Custom S3 (the B2/R2/Wasabi/MinIO/Spaces catch-all).
        $this->backupDestinationForm['provider'] = BackupConfiguration::PROVIDER_CUSTOM_S3;

        $existing = $this->s3BackupDestinations();
        $this->backup_destination_mode = $existing->isNotEmpty() ? 'existing' : 'new';
        $this->backup_destination_id = $existing->first()?->id;

        $this->dispatch('open-modal', 'backup-database-modal');
    }

    public function closeBackupModal(): void
    {
        $this->dispatch('close-modal', 'backup-database-modal');
        $this->backup_modal_db_id = null;
        $this->backup_destination_id = null;
        $this->backup_destination_mode = 'existing';
        $this->backupDestinationForm = [];
        $this->resetErrorBag();
    }

    /**
     * Queue a backup for the database in the modal, routing it to the chosen
     * destination: an existing S3 destination, a new S3 destination created
     * inline, or the server's default on-disk storage.
     */
    public function runDatabaseBackup(
        DatabaseBackupExporter $exporter,
    ): void {
        $this->authorize('update', $this->server);

        if (! $this->backup_modal_db_id) {
            return;
        }

        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->backup_modal_db_id)
            ->firstOrFail();

        $configId = null;

        if ($this->backup_destination_mode === 'new') {
            if ($this->server->organization_id === null) {
                $this->toastError(__('This server has no organization to attach a backup destination to.'));

                return;
            }

            $provider = (string) ($this->backupDestinationForm['provider'] ?? '');
            if (! in_array($provider, self::S3_BACKUP_PROVIDERS, true)) {
                $this->addError('backupDestinationForm.provider', __('Database backups support S3-compatible destinations only (AWS S3, Custom S3, or DigitalOcean Spaces).'));

                return;
            }

            $this->validate($this->destinationFormRules('backupDestinationForm', $provider));

            $destination = BackupConfiguration::query()->create([
                'organization_id' => $this->server->organization_id,
                'created_by_user_id' => auth()->id(),
                'name' => $this->backupDestinationForm['name'],
                'provider' => $provider,
                'config' => $this->extractDestinationConfig($this->backupDestinationForm),
            ]);

            $configId = $destination->id;
        } elseif ($this->backup_destination_mode === 'existing') {
            $destination = $this->s3BackupDestinations()->firstWhere('id', $this->backup_destination_id);
            if (! $destination) {
                $this->addError('backup_destination_id', __('Pick a backup destination, or add a new one.'));

                return;
            }
            $configId = $destination->id;
        }

        $backup = ServerDatabaseBackup::query()->create([
            'server_database_id' => $db->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);
        $exporter->prepareBackupRow($backup, $this->server, $configId);
        dispatch(new ExportServerDatabaseBackupJob($backup->id));

        $this->closeBackupModal();
        $this->toastSuccess($configId !== null
            ? __('Backup queued to S3. Refresh in a few moments and download from the Backups tab.')
            : __('Backup queued. Refresh in a few moments and download from the Backups tab.'));
    }

    public function downloadBackup(string $backupId, DatabaseBackupDownloader $downloader): StreamedResponse|Response|null
    {
        $this->authorize('update', $this->server);
        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->with('serverDatabase')
            ->firstOrFail();

        $extension = $backup->serverDatabase?->engine === 'sqlite' ? 'db' : 'sql';
        $filename = ($backup->serverDatabase?->name ?? 'database').'-'.$backup->id.'.'.$extension;

        try {
            return $downloader->response($backup, $filename);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return null;
        }
    }

    public function deleteDatabaseBackup(string $backupId, DatabaseBackupExporter $exporter): void
    {
        $this->authorize('update', $this->server);

        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->first();

        if ($backup === null) {
            return;
        }

        $exporter->deleteArtifact($backup);

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

        $this->toastSuccess(__('Backup deleted.'));
    }

    public function importSql(
        ServerDatabaseRemoteExec $remoteExec,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);
        $maxBytes = $this->server->organization?->databaseImportMaxBytes()
            ?? (int) config('server_database.import_max_bytes', 10485760);
        $maxKb = max(1, (int) ceil($maxBytes / 1024));

        $this->validate([
            'import_target_db_id' => 'required|ulid|exists:server_databases,id',
            'import_sql_file' => ['required', 'file', 'mimes:txt,sql', 'max:'.$maxKb],
        ]);

        $db = ServerDatabase::query()->where('server_id', $this->server->id)->whereKey($this->import_target_db_id)->firstOrFail();
        $contents = file_get_contents($this->import_sql_file->getRealPath());
        if (! is_string($contents)) {
            $this->toastError(__('Could not read the uploaded file.'));

            return;
        }

        try {
            if ($db->engine === 'postgres') {
                $remoteExec->postgresImportFromString($db->server, $db->name, $db->username, $db->password, $contents, 600, $maxBytes);
            } else {
                $remoteExec->mysqlImportFromString($db->server, $db->name, $db->username, $db->password, $contents, 600, $maxBytes);
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_IMPORT_RAN, [
            'server_database_id' => $db->id,
            'bytes' => strlen($contents),
        ], auth()->user());
        $this->import_sql_file = null;
        $this->toastSuccess(__('Import finished.'));
    }
}
