<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;

/**
 * Purge Apache mod_cache disk storage when present.
 */
class ApacheEngineCachePurger
{
    public function purgeAll(Server $server): void
    {
        $diskPath = ApacheEngineCacheConfig::DISK_CACHE_PATH;

        $ssh = new SshConnection($server);
        $script = sprintf(
            <<<'BASH'
set +e
sudo -n rm -rf %s/* /var/cache/apache2/* 2>/dev/null
echo "[dply] Apache disk cache purge complete"
BASH,
            escapeshellarg($diskPath),
        );

        $output = trim($ssh->exec($script, 45));
        if ($ssh->lastExecExitCode() !== 0 && $output === '') {
            throw new \RuntimeException('Apache cache purge command failed on the server.');
        }
    }
}
