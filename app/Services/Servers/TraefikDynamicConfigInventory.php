<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;

/**
 * Lists file-provider dynamic config under /etc/traefik/dynamic/.
 * Traefik hot-reloads these on change (SIGHUP / file watch).
 */
class TraefikDynamicConfigInventory
{
    private const REMOTE_DIR = '/etc/traefik/dynamic';

    /**
     * @return list<array{path: string, basename: string, size: int, modified_at: ?string}>
     */
    public function list(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $script = sprintf(
                'if [ ! -d %1$s ]; then exit 0; fi; '
                .'find %1$s -maxdepth 1 -type f \\( -name "*.yml" -o -name "*.yaml" \\) -printf "%%f\\t%%s\\t%%T@\\n" 2>/dev/null | sort',
                escapeshellarg(self::REMOTE_DIR),
            );
            $output = trim($ssh->exec('sudo -n bash -c '.escapeshellarg($script), 15));
            if ($output === '' || $ssh->lastExecExitCode() !== 0) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) < 2) {
                continue;
            }
            $basename = (string) $parts[0];
            $size = (int) ($parts[1] ?? 0);
            $mtime = isset($parts[2]) && is_numeric($parts[2])
                ? date('c', (int) floor((float) $parts[2]))
                : null;
            $rows[] = [
                'path' => self::REMOTE_DIR.'/'.$basename,
                'basename' => $basename,
                'size' => $size,
                'modified_at' => $mtime,
            ];
        }

        return $rows;
    }
}
