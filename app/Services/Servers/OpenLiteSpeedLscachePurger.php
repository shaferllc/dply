<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;

/**
 * Purge server-level LiteSpeed Cache storage. Mirrors managed-panel "purge
 * all" actions (RunCloud-style) without requiring the WordPress LSCache plugin.
 */
class OpenLiteSpeedLscachePurger
{
    public function purgeAll(Server $server): void
    {
        $ssh = new SshConnection($server);
        $script = <<<'BASH'
set +e
sudo -n rm -rf /usr/local/lsws/cachedata/* 2>/dev/null
sudo -n find /tmp -maxdepth 3 -type d -name 'lscache' 2>/dev/null | while read -r d; do
  sudo -n rm -rf "$d"/* 2>/dev/null
done
# Best-effort PURGE against local vhosts (ignored when cache module is off).
for host in $(sudo -n awk '/^[[:space:]]*map[[:space:]]+/ {print $3}' /usr/local/lsws/conf/httpd_config.conf 2>/dev/null | sort -u | head -n 20); do
  curl -sS -o /dev/null -m 3 -X PURGE -H "Host: $host" http://127.0.0.1/ 2>/dev/null || true
done
echo "[dply] LSCache purge complete"
BASH;

        $output = trim($ssh->exec($script, 45));
        if ($ssh->lastExecExitCode() !== 0 && $output === '') {
            throw new \RuntimeException('LSCache purge command failed on the server.');
        }
    }
}
