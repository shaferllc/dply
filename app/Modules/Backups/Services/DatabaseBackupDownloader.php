<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Models\Server;
use App\Models\ServerDatabaseBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DatabaseBackupDownloader
{
    public function __construct(
        private readonly DatabaseBackupExporter $exporter,
        private readonly ServerSshConnectionRunner $sshRunner,
    ) {}

    public function response(ServerDatabaseBackup $backup, string $filename): StreamedResponse|RedirectResponse
    {
        $target = $this->exporter->downloadTarget($backup);

        if ($target['mode'] === 'redirect' && isset($target['url'])) {
            return redirect()->away($target['url']);
        }

        if ($target['mode'] === 'disk' && isset($target['disk_path'])) {
            $disk = Storage::disk((string) config('server_database.backup_disk', 'local'));
            if (! $disk->exists($target['disk_path'])) {
                throw new \RuntimeException(__('Backup file is missing from storage.'));
            }

            return $disk->download($target['disk_path'], $filename);
        }

        $remotePath = $target['remote_path'] ?? $backup->remote_path;
        $server = $backup->serverDatabase?->server;
        if ($remotePath === null || $server === null) {
            throw new \RuntimeException(__('Backup file is missing from storage.'));
        }

        return response()->streamDownload(
            function () use ($server, $remotePath): void {
                $this->streamRemoteFile($server, $remotePath);
            },
            $filename,
        );
    }

    private function streamRemoteFile(Server $server, string $remotePath): void
    {
        $inner = 'cat -- '.escapeshellarg($remotePath);
        $command = 'bash -lc '.escapeshellarg($inner);

        $this->sshRunner->run(
            $server,
            function ($ssh) use ($command): void {
                $ssh->execWithCallback($command, function (string $chunk): void {
                    echo $chunk;
                }, 3600);
            },
            useRoot: (bool) config('server_database.use_root_ssh', true),
            fallbackToDeploy: (bool) config('server_database.fallback_to_deploy_user_ssh', true),
        );
    }
}
