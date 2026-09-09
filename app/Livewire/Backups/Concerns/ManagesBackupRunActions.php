<?php

declare(strict_types=1);

namespace App\Livewire\Backups\Concerns;

use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Modules\Backups\Jobs\RestoreServerDatabaseBackupJob;
use App\Modules\Backups\Services\DatabaseBackupDownloader;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-run actions on the Backups type tabs: download, delete, and restore.
 *
 * Restore was reachable only through `dply:db:restore` on the CLI, which meant
 * the one operation an operator needs under pressure was the one they had to
 * SSH somewhere to perform. Surfacing it here is the point — but it overwrites a
 * live database, so it is deliberately the most guarded action in the product:
 * a typed confirmation of the database name, never a single click.
 *
 * @phpstan-require-extends Component
 */
trait ManagesBackupRunActions
{
    public bool $showRestoreModal = false;

    public ?string $restoring_backup_id = null;

    /** Operator must type this exactly — a mistyped restore is unrecoverable. */
    public string $restore_confirm_name = '';

    /** Blank restores over the original database; set to fork into another. */
    public string $restore_target_database = '';

    public function downloadRun(string $backupId, DatabaseBackupDownloader $downloader): StreamedResponse|Response|null
    {
        $backup = $this->authorizedRun($backupId);
        if ($backup === null) {
            return null;
        }

        $extension = $backup->serverDatabase?->engine === 'sqlite' ? 'db' : 'sql';
        if (str_ends_with((string) $backup->destination_path, '.gz') || str_ends_with((string) $backup->s3_key, '.gz')) {
            $extension .= '.gz';
        }

        // Name it for a human's Downloads folder, not for our storage layout.
        $filename = sprintf(
            '%s-%s.%s',
            $backup->serverDatabase->name,
            $backup->created_at->format('Ymd-His'),
            $extension,
        );

        try {
            return $downloader->response($backup, $filename);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return null;
        }
    }

    public function deleteRun(string $backupId, DatabaseBackupExporter $exporter): void
    {
        $backup = $this->authorizedRun($backupId);
        if ($backup === null) {
            return;
        }

        try {
            $exporter->deleteArtifact($backup);
        } catch (\Throwable $e) {
            // The row still goes: a stuck artifact must not leave an
            // undeletable entry in the history forever.
            $this->toastError(__('Removed the record, but the stored file could not be deleted: :error', ['error' => $e->getMessage()]));
            $backup->delete();

            return;
        }

        $backup->delete();
        $this->toastSuccess(__('Backup deleted.'));
    }

    public function openRestoreModal(string $backupId): void
    {
        $backup = $this->authorizedRun($backupId);
        if ($backup === null) {
            return;
        }

        if (! app(DatabaseBackupExporter::class)->isDownloadable($backup)) {
            $this->toastError(__('That backup has no artifact to restore from.'));

            return;
        }

        $this->resetErrorBag();
        $this->restoring_backup_id = (string) $backup->id;
        $this->restore_confirm_name = '';
        $this->restore_target_database = '';
        $this->showRestoreModal = true;
    }

    public function closeRestoreModal(): void
    {
        $this->showRestoreModal = false;
        $this->restoring_backup_id = null;
        $this->restore_confirm_name = '';
        $this->restore_target_database = '';
        $this->resetErrorBag();
    }

    /** The run the restore modal is describing, for the confirmation copy. */
    public function restoringBackup(): ?ServerDatabaseBackup
    {
        return $this->restoring_backup_id === null
            ? null
            : ServerDatabaseBackup::with('serverDatabase.server')->find($this->restoring_backup_id);
    }

    public function confirmRestore(): void
    {
        $backup = $this->restoring_backup_id === null ? null : $this->authorizedRun($this->restoring_backup_id);
        if ($backup === null) {
            return;
        }

        $sourceName = (string) $backup->serverDatabase->name;
        $target = trim($this->restore_target_database);
        $intoName = $target !== '' ? $target : $sourceName;

        // Typed confirmation matches the database ABOUT TO BE OVERWRITTEN, which
        // is the target — not the backup's origin. Those differ when forking a
        // dump into another database, and confirming the wrong one is exactly
        // the mistake this guard exists to catch.
        if ($this->restore_confirm_name !== $intoName) {
            $this->addError('restore_confirm_name', __('Type :name exactly to confirm.', ['name' => $intoName]));

            return;
        }

        if ($target !== '' && ! $this->targetDatabaseExists($backup, $target)) {
            $this->addError('restore_target_database', __('No database called :name on that server.', ['name' => $target]));

            return;
        }

        RestoreServerDatabaseBackupJob::dispatch(
            (string) $backup->id,
            $target !== '' ? $target : null,
            (string) Auth::id(),
        );

        $org = $backup->serverDatabase?->server?->organization;
        if ($org !== null) {
            audit_log($org, Auth::user(), 'backup.restore.started', $backup, null, [
                'backup_id' => (string) $backup->id,
                'target_database' => $intoName,
            ]);
        }

        $this->closeRestoreModal();
        $this->toastSuccess(__('Restore queued into :name. It runs in the background — watch the run history.', ['name' => $intoName]));
    }

    private function targetDatabaseExists(ServerDatabaseBackup $backup, string $name): bool
    {
        $serverId = $backup->serverDatabase?->server_id;

        return $serverId !== null
            && ServerDatabase::query()->where('server_id', $serverId)->where('name', $name)->exists();
    }

    /**
     * Load a run the current user may act on. Scoped through the owning server
     * so a guessed id from another organization is refused.
     */
    private function authorizedRun(string $backupId): ?ServerDatabaseBackup
    {
        $backup = ServerDatabaseBackup::with('serverDatabase.server')->find($backupId);
        $server = $backup?->serverDatabase?->server;

        if ($backup === null || $server === null) {
            $this->toastError(__('That backup is no longer available.'));

            return null;
        }

        Gate::authorize('update', $server);

        return $backup;
    }
}
