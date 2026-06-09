<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Server;
use App\Services\SshConnection;
use Illuminate\Support\Str;

/**
 * Shared SSH-as-root helpers used by jobs that manage on-server config
 * files (webserver switch, edge-proxy add/remove). The same patterns are
 * used by AbstractSiteWebserverProvisioner — we duplicate here rather
 * than reaching into a Sites/* class because the queue-job context is
 * server-level, not site-level.
 */
trait PrivilegedRemoteFileWrites
{
    /**
     * Wrap a shell command so it runs as root via the deploy user's
     * passwordless sudo. dply provisions exactly this capability — the
     * command goes through `sudo -n bash -lc` so a login-shell profile
     * loads (apt's PATH, locale, etc.).
     */
    protected function privilegedCommand(Server $server, string $command): string
    {
        if (str_contains($command, "\0")) {
            $command = str_replace("\0", '', $command);
        }

        return 'sudo -n bash -lc '.escapeshellarg($command);
    }

    /**
     * Write `$contents` to `$remotePath` atomically: putFile → /tmp →
     * sudo-mv → chown root:root → chmod 644. Throws RuntimeException on
     * any non-zero exit from the mv/chown/chmod pipeline.
     */
    protected function writeRemoteFile(Server $server, SshConnection $ssh, string $remotePath, string $contents): void
    {
        $tmp = '/tmp/'.basename($remotePath).'.'.Str::random(8);
        $ssh->putFile($tmp, $contents);
        $cmd = sprintf(
            'sudo -n mkdir -p %1$s && sudo -n mv %2$s %3$s && sudo -n chown root:root %3$s && sudo -n chmod 644 %3$s',
            escapeshellarg(dirname($remotePath)),
            escapeshellarg($tmp),
            escapeshellarg($remotePath),
        );
        $ssh->exec($cmd.' 2>&1', 30);
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(sprintf(
                'Failed to write %s on remote host (exit %d).',
                $remotePath,
                $exit,
            ));
        }
    }
}
