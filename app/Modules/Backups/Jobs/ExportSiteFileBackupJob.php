<?php

namespace App\Modules\Backups\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Models\User;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Modules\Notifications\Services\ServerBackupNotificationDispatcher;
use App\Modules\Backups\Services\SiteFileBackupExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Number;

class ExportSiteFileBackupJob implements ShouldQueue
{
    use Queueable, WritesConsoleAction;

    public int $timeout = 7200;

    private ?Model $consoleSubjectCache = null;

    /**
     * @param  string|null  $seededConsoleRunId  a ConsoleAction the dispatcher
     *                                           (an on-demand run) pre-seeded so progress streams into the Backups-tab
     *                                           banner. Null for scheduled runs — they stay silent.
     */
    public function __construct(
        public string $backupId,
        public ?string $seededConsoleRunId = null,
    ) {
        $q = config('site_file_backup.export_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
        $this->timeout = (int) config('site_file_backup.timeout_seconds', 7200);
    }

    /**
     * Subject = the seeded row's subject (the workspace server the operator is
     * viewing), falling back to the site's own server only if the row vanished.
     */
    protected function consoleSubject(): Model
    {
        if ($this->consoleSubjectCache !== null) {
            return $this->consoleSubjectCache;
        }

        if ($this->seededConsoleRunId !== null) {
            $subject = ConsoleAction::query()->whereKey($this->seededConsoleRunId)->first()?->subject;
            if ($subject instanceof Model) {
                return $this->consoleSubjectCache = $subject;
            }
        }

        $server = SiteFileBackup::query()->with('site.server')->find($this->backupId)?->site?->server;
        if (! $server instanceof Server) {
            throw new \RuntimeException('Console subject server not found for site files backup.');
        }

        return $this->consoleSubjectCache = $server;
    }

    protected function consoleKind(): string
    {
        return 'backup_site_files';
    }

    public function handle(SiteFileBackupExporter $exporter, ServerBackupNotificationDispatcher $notifications): void
    {
        $backup = SiteFileBackup::query()->with(['site.server'])->find($this->backupId);
        if (! $backup) {
            return;
        }

        $site = $backup->site;

        $emit = new ConsoleEmitter(null);
        if ($this->seededConsoleRunId !== null) {
            $this->bindConsoleRunId($this->seededConsoleRunId);
            $emit = $this->beginConsoleAction();
        }

        $server = $site->server;
        if (! $server instanceof Server || ! $site->supportsSshFileArchive()) {
            $message = __('This site cannot export files over SSH (runtime or server not ready).');
            $backup->update([
                'status' => SiteFileBackup::STATUS_FAILED,
                'error_message' => $message,
            ]);

            if ($server instanceof Server) {
                $actor = $backup->user instanceof User ? $backup->user : null;
                $notifications->notify($server, 'failed', [__('Site files — :name', ['name' => $site->name])], $actor, [
                    'backup_type' => 'site_files',
                    'backup_id' => (string) $backup->id,
                    'site_id' => (string) $site->id,
                    'error' => 'unsupported',
                ]);
            }

            $emit->error($message, 'files');
            $this->failConsoleAction($message);

            return;
        }

        try {
            // Writes the tar to a durable path on the site's own server, records
            // remote_path + bytes on the row, and prunes the per-server tree.
            $exporter->export($backup, $emit);
            $backup->refresh();

            $actor = $backup->user instanceof User ? $backup->user : null;
            $notifications->notify($server, 'completed', [__('Site files — :name', ['name' => $site->name])], $actor, [
                'backup_type' => 'site_files',
                'backup_id' => (string) $backup->id,
                'site_id' => (string) $site->id,
                'bytes' => $backup->bytes,
            ]);

            $emit->success(__('Site files backup complete — :size', ['size' => Number::fileSize((int) ($backup->bytes ?? 0))]), 'files');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $backup->update([
                'status' => SiteFileBackup::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $actor = $backup->user instanceof User ? $backup->user : null;
            $notifications->notify($server, 'failed', [__('Site files — :name', ['name' => $site->name])], $actor, [
                'backup_type' => 'site_files',
                'backup_id' => (string) $backup->id,
                'site_id' => (string) $site->id,
                'error' => $e->getMessage(),
            ]);

            // No-retry: surface the failure, don't re-throw.
            $emit->error($e->getMessage(), 'files');
            $this->failConsoleAction($e->getMessage());
        }
    }
}
