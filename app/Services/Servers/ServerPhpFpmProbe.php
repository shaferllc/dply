<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Modules\Insights\Services\Runners\PhpFpmWorkersUndersizedInsightRunner;
use Illuminate\Support\Facades\Log;

/**
 * One-shot SSH probe of PHP-FPM saturation. Reads the configured `pm.max_children`
 * from the default www pool and counts running worker processes via `ps`.
 *
 * Used by {@see PhpFpmWorkersUndersizedInsightRunner}.
 * No FPM status page required — works on stock provisioned servers.
 */
class ServerPhpFpmProbe
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @return array{max_children: int, active_workers: int, php_version: string}|null
     */
    public function probe(Server $server, string $phpVersion): ?array
    {
        $phpVersion = trim($phpVersion);
        if (! preg_match('/^\d+\.\d+$/', $phpVersion)) {
            return null;
        }

        $poolPath = "/etc/php/{$phpVersion}/fpm/pool.d/www.conf";

        $script = <<<BASH
if [ ! -f "{$poolPath}" ]; then
  echo "missing-pool"
  exit 0
fi
max=\$(grep -E '^[[:space:]]*pm\\.max_children[[:space:]]*=' "{$poolPath}" | tail -n 1 | sed -E 's/^[^=]+=[[:space:]]*//' | tr -d '[:space:]')
active=\$(ps -C "php-fpm{$phpVersion}" --no-headers -o cmd 2>/dev/null | grep -c "pool ")
if [ -z "\$active" ] || [ "\$active" = "0" ]; then
  active=\$(ps -ef | grep -E "php-fpm[0-9.]* *: *pool " | grep -v grep | wc -l | tr -d '[:space:]')
fi
echo "max=\${max}"
echo "active=\${active}"
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-fpm-probe', $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.fpm_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return null;
        }

        if (str_contains($buffer, 'missing-pool')) {
            return null;
        }

        $max = $this->parseInt($buffer, 'max=');
        $active = $this->parseInt($buffer, 'active=');
        if ($max === null || $active === null || $max <= 0) {
            return null;
        }

        return [
            'max_children' => $max,
            'active_workers' => $active,
            'php_version' => $phpVersion,
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
