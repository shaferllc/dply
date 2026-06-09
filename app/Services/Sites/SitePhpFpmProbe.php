<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerPhpFpmProbe;
use Illuminate\Support\Facades\Log;

/**
 * One-shot SSH probe of a site's DEDICATED PHP-FPM pool (the per-site pool every
 * nginx/caddy PHP site gets — see {@see Site::usesDedicatedPhpFpmPool()}).
 *
 * Mirrors {@see ServerPhpFpmProbe}, but scoped to one
 * pool: it reads the configured `pm.max_children` from the pool's own conf,
 * confirms the listen socket is present (master up), and counts the worker
 * processes currently spawned for the pool. No FPM status page required — works
 * on stock provisioned boxes. Run deferred (wire:init), never on the render path.
 */
final class SitePhpFpmProbe
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @return array{running: bool, socket_present: bool, conf_present: bool, workers: int, max_children: int|null, php_version: string, pool: string}|null
     */
    public function probe(Site $site): ?array
    {
        $server = $site->server;
        if ($server === null || ! $site->usesDedicatedPhpFpmPool()) {
            return null;
        }

        $version = $site->resolvedPhpFpmVersion();
        if (! preg_match('/^\d+\.\d+$/', $version)) {
            return null;
        }

        $name = $site->phpFpmPoolName();
        $socket = $site->phpFpmListenSocketPath();
        $conf = "/etc/php/{$version}/fpm/pool.d/{$name}.conf";

        $nameArg = escapeshellarg($name);
        $sockArg = escapeshellarg($socket);
        $confArg = escapeshellarg($conf);

        // grep -F (fixed string) so dots/dashes in the pool name aren't read as
        // regex. The worker process title is exactly "php-fpm: pool <name>".
        $script = <<<BASH
NAME={$nameArg}
SOCK={$sockArg}
CONF={$confArg}

if [ -f "\$CONF" ]; then echo "conf=1"; else echo "conf=0"; fi
if [ -S "\$SOCK" ]; then echo "sock=1"; else echo "sock=0"; fi

if [ -f "\$CONF" ]; then
  max=\$(grep -E '^[[:space:]]*pm\\.max_children[[:space:]]*=' "\$CONF" | tail -n 1 | sed -E 's/^[^=]+=[[:space:]]*//' | tr -d '[:space:]')
  echo "max=\${max}"
fi

workers=\$(ps -eo args 2>/dev/null | grep -F "php-fpm: pool \${NAME}" | grep -v grep | wc -l | tr -d '[:space:]')
echo "workers=\${workers}"
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'site-fpm-probe', $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('sites.fpm_probe_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return null;
        }

        $socketPresent = $this->parseInt($buffer, 'sock=') === 1;
        $confPresent = $this->parseInt($buffer, 'conf=') === 1;
        $maxChildren = $this->parseInt($buffer, 'max=');
        $workers = $this->parseInt($buffer, 'workers=') ?? 0;

        return [
            // The socket only exists while the fpm master is listening on it, so
            // its presence is our "pool is up" signal — an idle ondemand pool with
            // zero workers is still running.
            'running' => $socketPresent,
            'socket_present' => $socketPresent,
            'conf_present' => $confPresent,
            'workers' => max(0, $workers),
            'max_children' => $maxChildren !== null && $maxChildren > 0 ? $maxChildren : null,
            'php_version' => $version,
            'pool' => $name,
        ];
    }

    private function parseInt(string $buffer, string $prefix): ?int
    {
        foreach (explode("\n", $buffer) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, $prefix)) {
                continue;
            }
            $value = trim(substr($line, strlen($prefix)));
            if ($value === '' || ! is_numeric($value)) {
                return null;
            }

            return (int) $value;
        }

        return null;
    }
}
