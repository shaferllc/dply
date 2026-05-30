<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;

/**
 * Purge nginx FastCGI / proxy engine cache storage (RunCloud-style purge all).
 */
class NginxEngineCachePurger
{
    public function purgeAll(Server $server): void
    {
        $fcgiPath = (string) config('sites.nginx_engine_fcgi_cache_path');
        $proxyPath = (string) config('sites.nginx_engine_proxy_cache_path');

        $ssh = new SshConnection($server);
        $script = sprintf(
            "set +e\nsudo -n rm -rf %s/* %s/* 2>/dev/null\nfor host in \$(sudo -n awk '/server_name/ {for (i=2;i<=NF;i++) if (\$i !~ /;/) print \$i}' /etc/nginx/sites-enabled/* 2>/dev/null | tr -d ';' | sort -u | head -n 30); do\n  curl -sS -o /dev/null -m 3 -X PURGE -H \"Host: \$host\" http://127.0.0.1/ 2>/dev/null || true\ndone\necho \"[dply] nginx engine cache purge complete\"\n",
            escapeshellarg($fcgiPath),
            escapeshellarg($proxyPath),
        );

        $output = trim($ssh->exec($script, 60));
        if ($ssh->lastExecExitCode() !== 0 && $output === '') {
            throw new \RuntimeException('nginx cache purge command failed on the server.');
        }
    }
}
