<?php

namespace App\Jobs;

use App\Models\SiteFileBackup;
use App\Services\Servers\ServerSshConnectionRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ExportSiteFileBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public function __construct(
        public string $backupId
    ) {
        $q = config('site_file_backup.export_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
        $this->timeout = (int) config('site_file_backup.timeout_seconds', 7200);
    }

    public function handle(ServerSshConnectionRunner $sshRunner): void
    {
        $backup = SiteFileBackup::query()->with(['site.server'])->find($this->backupId);
        if (! $backup) {
            return;
        }

        $site = $backup->site;
        if (! $site) {
            return;
        }

        $server = $site->server;
        if (! $server || ! $site->supportsSshFileArchive()) {
            $backup->update([
                'status' => SiteFileBackup::STATUS_FAILED,
                'error_message' => __('This site cannot export files over SSH (runtime or server not ready).'),
            ]);

            return;
        }

        $maxBytes = (int) config('site_file_backup.max_bytes', 5368709120);
        $timeout = (int) config('site_file_backup.timeout_seconds', 7200);

        try {
            $relative = 'site-file-backups/'.$server->id.'/'.$backup->id.'.tar.gz';
            $fullPath = Storage::disk('local')->path($relative);
            $dir = dirname($fullPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $written = 0;
            $sshRunner->run($server, function ($ssh, $_loginUser) use ($site, $fullPath, $maxBytes, $timeout, &$written): void {
                $rawRoot = $site->effectiveRepositoryPath();
                if ($rawRoot === '' || ! str_starts_with($rawRoot, '/')) {
                    throw new \RuntimeException(__('Site repository path must be an absolute path on the server.'));
                }

                $canonical = trim($ssh->exec(
                    'bash -lc '.escapeshellarg('readlink -f -- '.escapeshellarg($rawRoot)),
                    120
                ));
                if ($canonical === '' || ! str_starts_with($canonical, '/')) {
                    throw new \RuntimeException(__('Could not resolve the site path on the server.'));
                }

                $probe = trim($ssh->exec(
                    'bash -lc '.escapeshellarg('test -d '.escapeshellarg($canonical).' && echo OK'),
                    60
                ));
                if ($probe !== 'OK') {
                    throw new \RuntimeException(__('Site directory does not exist on the server.'));
                }

                $excludeFlags = '';
                foreach (config('site_file_backup.tar_excludes', []) as $pattern) {
                    if (! is_string($pattern) || $pattern === '') {
                        continue;
                    }
                    $excludeFlags .= ' --exclude='.escapeshellarg($pattern);
                }

                $inner = 'cd -- '.escapeshellarg($canonical).' && tar -czf -'.$excludeFlags.' .';
                $command = 'bash -lc '.escapeshellarg($inner);

                $handle = fopen($fullPath, 'wb');
                if ($handle === false) {
                    throw new \RuntimeException(__('Could not open local path for writing.'));
                }

                try {
                    $ssh->execWithCallback($command, function (string $chunk) use ($handle, $maxBytes, &$written): void {
                        $len = strlen($chunk);
                        $written += $len;
                        if ($written > $maxBytes) {
                            throw new \RuntimeException(__('Archive exceeded the configured maximum size.'));
                        }
                        fwrite($handle, $chunk);
                    }, $timeout);
                } finally {
                    fclose($handle);
                }

                $exit = $ssh->lastExecExitCode();
                if ($exit !== null && $exit !== 0) {
                    throw new \RuntimeException(__('tar exited with status :code.', ['code' => $exit]));
                }
            }, useRoot: false);

            if (! is_file($fullPath)) {
                throw new \RuntimeException(__('Archive was not written.'));
            }

            $bytes = filesize($fullPath);
            if ($bytes === false) {
                throw new \RuntimeException(__('Could not read archive size.'));
            }

            $backup->update([
                'status' => SiteFileBackup::STATUS_COMPLETED,
                'disk_path' => $relative,
                'bytes' => $bytes,
            ]);
        } catch (\Throwable $e) {
            if (isset($relative)) {
                Storage::disk('local')->delete($relative);
            }

            $backup->update([
                'status' => SiteFileBackup::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
