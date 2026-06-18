<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Services\Servers\ServerDatabaseRemoteExec;

use App\Models\Server;
use App\Models\ServerDatabaseBackup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Restore a {@see ServerDatabaseBackup} into a target database — the missing
 * other half of the backup feature (W3). Resolves the dump per storage kind
 * (S3 presigned GET / on-server path / control-plane disk) into a string, then
 * imports it via the existing {@see ServerDatabaseRemoteExec} psql path.
 *
 * DESTRUCTIVE: imports overwrite the target DB. Callers must gate on explicit
 * intent. Postgres-only for now (the control-plane engine); other engines error.
 */
final class ServerDatabaseBackupRestorer
{
    public function __construct(
        private readonly DatabaseBackupExporter $exporter,
        private readonly ServerDatabaseRemoteExec $remoteExec,
    ) {}

    /**
     * @return string import output
     */
    public function restore(ServerDatabaseBackup $backup, ?string $targetDatabase = null): string
    {
        $db = $backup->serverDatabase;
        if ($db === null) {
            throw new RuntimeException('Backup has no associated database.');
        }
        if ($db->engine !== 'postgres') {
            throw new RuntimeException("Restore currently supports postgres only (got {$db->engine}).");
        }
        if ($backup->status !== ServerDatabaseBackup::STATUS_COMPLETED) {
            throw new RuntimeException('Refusing to restore a non-completed backup.');
        }

        $server = $db->server;
        $sql = $this->fetchDump($backup, $server);
        if (trim($sql) === '') {
            throw new RuntimeException('Fetched dump was empty.');
        }

        $database = $targetDatabase ?? $db->name;

        // The default import cap is ~10MB (fine for customer tweaks); a full
        // control-plane restore is far larger, so raise it explicitly.
        $maxBytes = (int) config('self_manage.restore_max_bytes', 2_000_000_000);

        return $this->remoteExec->postgresImportFromString(
            $server,
            $database,
            $db->username,
            $db->password,
            $sql,
            1800,
            $maxBytes,
        );
    }

    private function fetchDump(ServerDatabaseBackup $backup, Server $server): string
    {
        $target = $this->exporter->downloadTarget($backup);

        return match ($target['mode']) {
            'redirect' => $this->fetchHttp((string) ($target['url'] ?? '')),
            'remote' => $this->fetchRemoteFile($server, (string) ($target['remote_path'] ?? '')),
            'disk' => $this->fetchDisk((string) ($target['disk_path'] ?? '')),
            default => throw new RuntimeException("Unknown download mode: {$target['mode']}"),
        };
    }

    private function fetchHttp(string $url): string
    {
        if ($url === '') {
            throw new RuntimeException('No presigned URL for the backup.');
        }
        $resp = Http::timeout(600)->get($url);
        if (! $resp->successful()) {
            throw new RuntimeException('Failed to download backup object: HTTP '.$resp->status());
        }

        return $resp->body();
    }

    private function fetchRemoteFile(Server $server, string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('No remote path for the backup.');
        }
        [$out, $exit] = $this->remoteExec->shellRunWithExit($server, 'cat '.escapeshellarg($path), 600);
        if ($exit !== 0) {
            throw new RuntimeException("Failed to read remote dump {$path} (exit {$exit}).");
        }

        return $out;
    }

    private function fetchDisk(string $diskPath): string
    {
        if ($diskPath === '' || ! Storage::disk('local')->exists($diskPath)) {
            throw new RuntimeException("Control-plane dump not found: {$diskPath}");
        }

        return (string) Storage::disk('local')->get($diskPath);
    }
}
