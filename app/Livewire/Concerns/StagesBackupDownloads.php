<?php

namespace App\Livewire\Concerns;

use App\Jobs\StageBackupDownloadJob;
use App\Models\BackupDownloadStaging;
use App\Services\Backups\BackupDownloadStager;
use App\Services\Backups\BackupStagingS3ClientFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared "stage a backup to the Hetzner download bucket, then redirect" flow for
 * the backup workspaces. Because the upload runs over SSH (never inline in an
 * HTTP request), the click dispatches a queued job and the view polls
 * {@see pollStaging()} until the staging row is ready, then redirects the browser
 * to a presigned GET. The host component supplies authorization/scoping via
 * {@see resolveDownloadableBackup()}.
 *
 * @phpstan-require-extends \Livewire\Component
 */
trait StagesBackupDownloads
{
    /** The staging row currently being prepared/polled, if any. */
    public ?string $stagingId = null;

    /** The backup id currently being prepared — lets the view show a per-row spinner. */
    public ?string $stagingBackupId = null;

    /** backup id => error message, surfaced inline next to the row. */
    public array $stagingErrors = [];

    /**
     * Resolve + authorize the backup the user may download. Return the Eloquent
     * model (ServerDatabaseBackup | SiteFileBackup) or null when not found/allowed.
     */
    abstract protected function resolveDownloadableBackup(string $type, string $backupId): ?Model;

    public function requestDownload(string $type, string $backupId): mixed
    {
        $backup = $this->resolveDownloadableBackup($type, $backupId);
        if ($backup === null) {
            $this->toastError(__('Backup not found.'));

            return null;
        }

        if (! app(BackupStagingS3ClientFactory::class)->enabled()) {
            $this->toastError(__('Downloads aren’t configured yet — the staging bucket is missing.'));

            return null;
        }

        unset($this->stagingErrors[$backupId]);

        // Reuse a still-valid staged copy (idempotent repeat downloads).
        $existing = $this->stagingQuery($backup)
            ->where('status', BackupDownloadStaging::STATUS_READY)
            ->where('expires_at', '>', now())
            ->latest('expires_at')
            ->first();

        if ($existing) {
            return $this->redirectToStaged($existing);
        }

        // Clear stale rows for this backup, then create a fresh pending one.
        $this->stagingQuery($backup)->delete();

        $row = BackupDownloadStaging::create([
            'backupable_type' => $backup->getMorphClass(),
            'backupable_id' => $backup->getKey(),
            'requested_by_user_id' => auth()->id(),
            'status' => BackupDownloadStaging::STATUS_PENDING,
            'mode' => BackupDownloadStaging::MODE_HETZNER,
        ]);

        $this->stagingId = (string) $row->id;
        $this->stagingBackupId = $backupId;
        StageBackupDownloadJob::dispatch((string) $row->id);

        return null;
    }

    public function pollStaging(): mixed
    {
        if ($this->stagingId === null) {
            return null;
        }

        $row = BackupDownloadStaging::find($this->stagingId);
        if ($row === null) {
            $this->stagingId = null;
            $this->stagingBackupId = null;

            return null;
        }

        if ($row->status === BackupDownloadStaging::STATUS_READY) {
            return $this->redirectToStaged($row);
        }

        if ($row->status === BackupDownloadStaging::STATUS_FAILED) {
            $message = $row->error_message ?: __('The download could not be prepared.');
            $this->stagingErrors[(string) $row->backupable_id] = $message;
            $this->stagingId = null;
            $this->stagingBackupId = null;
            $this->toastError($message);
        }

        return null; // still pending — keep polling
    }

    /**
     * Remove any staging rows (and their staged objects) for a backup that is
     * being deleted, so no orphan object lingers until the sweeper.
     */
    protected function purgeBackupStagings(Model $backup): void
    {
        $stager = app(BackupDownloadStager::class);
        foreach ($this->stagingQuery($backup)->get() as $row) {
            $stager->deleteStaged($row);
            $row->delete();
        }
    }

    private function redirectToStaged(BackupDownloadStaging $row): mixed
    {
        try {
            $url = app(BackupDownloadStager::class)->presignedGet($row);
        } catch (\Throwable $e) {
            // e.g. an org-S3 object still thawing from cold storage.
            $this->stagingId = null;
            $this->stagingBackupId = null;
            $this->toastError($e->getMessage());

            return null;
        }

        $this->stagingId = null;
        $this->stagingBackupId = null;

        return redirect()->away($url);
    }

    private function stagingQuery(Model $backup)
    {
        return BackupDownloadStaging::query()
            ->where('backupable_type', $backup->getMorphClass())
            ->where('backupable_id', $backup->getKey());
    }
}
