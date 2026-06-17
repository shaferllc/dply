<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\SiteFileBackup;
use App\Services\ConsoleActions\ConsoleEmitter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Writes a site's file archive to a durable path on the SITE'S OWN server (over
 * root SSH, mirroring DatabaseBackupExporter's KIND_REMOTE_SERVER), so the
 * archive is reachable from any control-plane box and can be curl-uploaded to the
 * download-staging bucket later. Replaces the old stream-tar-to-control-plane
 * approach, which only worked on the box that ran the export.
 */
final class SiteFileBackupExporter
{
    public function __construct(
        private readonly ServerDatabaseRemoteExec $remoteExec,
    ) {}

    /**
     * Tar the site repository to a path on its server, record it on the backup
     * row (status=completed), and prune the per-server archive tree. Throws on
     * any failure (the caller marks the row failed + notifies).
     */
    /**
     * @param  ConsoleEmitter|null  $emit  optional progress sink for on-demand
     *                                     runs; null (scheduled backups) becomes a no-op emitter.
     */
    public function export(SiteFileBackup $backup, ?ConsoleEmitter $emit = null): void
    {
        $emit ??= new ConsoleEmitter(null);

        $backup->loadMissing('site.server');
        $site = $backup->site;
        $server = $site->server;

        if ($site === null || $server === null) {
            throw new \RuntimeException(__('Site or server is missing for this backup.'));
        }

        $rawRoot = $site->effectiveRepositoryPath();
        if ($rawRoot === '' || ! str_starts_with($rawRoot, '/')) {
            throw new \RuntimeException(__('Site repository path must be an absolute path on the server.'));
        }

        $remotePath = $this->remotePath($backup);
        $timeout = (int) config('site_file_backup.timeout_seconds', 7200);
        $maxBytes = (int) config('site_file_backup.max_bytes', 5368709120);

        $emit->step('files', __('Archiving :name files …', ['name' => $site->name]));

        $script = $this->buildTarScript($rawRoot, $remotePath);
        [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $script, $timeout);

        if ($exit !== null && $exit >= 90) {
            throw new \RuntimeException(__('Could not create the archive on the server: :err', ['err' => Str::limit(trim($out), 400)]));
        }

        $bytes = $this->parseMarker($out, 'DPLY_SIZE');
        if ($bytes === null || $bytes <= 0) {
            $this->remoteExec->shellRunWithExit($server, 'rm -f '.escapeshellarg($remotePath), 30);

            throw new \RuntimeException(__('Archive was not written or is empty.'));
        }

        if ($bytes > $maxBytes) {
            $this->remoteExec->shellRunWithExit($server, 'rm -f '.escapeshellarg($remotePath), 30);

            throw new \RuntimeException(__('Archive exceeded the configured maximum size.'));
        }

        $emit->step('files', __('Archived :size — pruning old backups on the server …', ['size' => Number::fileSize($bytes)]));

        $this->remoteExec->pruneRemoteBackupTree(
            $server,
            rtrim((string) config('site_file_backup.remote_backup_root', '/var/lib/dply/site-file-backups'), '/').'/'.$server->id,
            (int) config('site_file_backup.remote_backup_max_bytes_per_server', 21474836480),
        );

        $backup->update([
            'status' => SiteFileBackup::STATUS_COMPLETED,
            'storage_kind' => SiteFileBackup::STORAGE_KIND_REMOTE_SERVER,
            'remote_path' => $remotePath,
            'disk_path' => null,
            'bytes' => $bytes,
        ]);
    }

    /**
     * Best-effort removal of the durable artifact: SSH rm for remote-server rows,
     * local-disk delete for legacy control-plane rows.
     */
    public function deleteArtifact(SiteFileBackup $backup): void
    {
        if ($backup->effectiveStorageKind() === SiteFileBackup::STORAGE_KIND_REMOTE_SERVER && filled($backup->remote_path)) {
            $backup->loadMissing('site.server');
            $server = $backup->site->server;
            if ($server !== null) {
                $this->remoteExec->shellRunWithExit($server, 'rm -f '.escapeshellarg((string) $backup->remote_path), 60);
            }

            return;
        }

        if (filled($backup->disk_path) && Storage::disk('local')->exists((string) $backup->disk_path)) {
            Storage::disk('local')->delete((string) $backup->disk_path);
        }
    }

    public function remotePath(SiteFileBackup $backup): string
    {
        $root = rtrim((string) config('site_file_backup.remote_backup_root', '/var/lib/dply/site-file-backups'), '/');

        return $root.'/'.$backup->site->server->id.'/'.$backup->id.'.tar.gz';
    }

    /**
     * A single bash script that resolves the repo path, tars it to the dest, and
     * prints size + tar exit via markers (tar exit 1 = warnings is tolerated as
     * long as a non-empty file was written). Exit codes >=90 are dply control-flow
     * failures (path resolution / mkdir / cd).
     */
    private function buildTarScript(string $rawRoot, string $remotePath): string
    {
        $excludeFlags = '';
        foreach ((array) config('site_file_backup.tar_excludes', []) as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            $excludeFlags .= ' --exclude='.escapeshellarg($pattern);
        }

        $root = escapeshellarg($rawRoot);
        $dest = escapeshellarg($remotePath);

        return implode("\n", [
            'set -uo pipefail',
            'ROOT=$(readlink -f -- '.$root.') || exit 90',
            '[ -d "$ROOT" ] || exit 91',
            'DEST='.$dest,
            'mkdir -p "$(dirname "$DEST")" || exit 92',
            'cd -- "$ROOT" || exit 93',
            'tar -czf "$DEST"'.$excludeFlags.' .',
            'TAR_EXIT=$?',
            'SIZE=$(stat -c%s "$DEST" 2>/dev/null || echo 0)',
            'echo "DPLY_TAR_EXIT:$TAR_EXIT"',
            'echo "DPLY_SIZE:$SIZE"',
        ]);
    }

    private function parseMarker(string $output, string $marker): ?int
    {
        if (preg_match('/'.preg_quote($marker, '/').':(\d+)/', $output, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
