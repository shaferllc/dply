<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SwitchServerWebserverJob;
use App\Models\Server;
use App\Services\Servers\WebserverStatsEndpointTemplates;
use App\Services\SshConnection;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Drop the dply metrics-agent stats endpoint configs on already-running
 * webservers that predate the observability feature.
 *
 * New installs (via {@see SwitchServerWebserverJob}) auto-write
 * these — but a server provisioned before the feature has nginx/apache
 * running without the matching stub_status / mod_status endpoint, so the
 * metrics agent's collectors return None and the engine Overview charts
 * stay empty. This command fixes that in-place.
 *
 * Idempotent on every layer:
 *   - Conf file write overwrites with the canonical body
 *   - apache `a2enmod status` / `a2enconf` are no-ops when already enabled
 *   - Reload is graceful (no dropped connections)
 *
 * Traefik is intentionally NOT in scope: its metrics block lives inside
 * the dply-managed /etc/traefik/traefik.yml, which AddEdgeProxyJob's
 * writeTraefikStaticConfig() rewrites on every Add. Operators who added
 * Traefik before this feature should re-run AddEdgeProxyJob via the
 * Edge Proxy UI (it's idempotent) to get the updated yml.
 *
 * Examples:
 *   php artisan dply:webserver-stats:backfill --dry-run
 *   php artisan dply:webserver-stats:backfill --server=01krev...
 *   php artisan dply:webserver-stats:backfill --engine=nginx
 */
class BackfillWebserverStatsEndpointsCommand extends Command
{
    protected $signature = 'dply:webserver-stats:backfill
                            {--server= : Limit to one server (ULID)}
                            {--engine= : Limit to one engine (nginx|apache)}
                            {--dry-run : Print eligible servers/engines without writing}';

    protected $description = 'Backfill dply stats endpoint configs (nginx stub_status, apache mod_status) on existing servers.';

    public function handle(): int
    {
        $engineFilter = $this->option('engine');
        if ($engineFilter !== null && ! in_array($engineFilter, ['nginx', 'apache'], true)) {
            $this->error('--engine must be one of: nginx, apache');

            return self::INVALID;
        }

        $query = Server::query()->where('status', Server::STATUS_READY);
        if ($serverId = $this->option('server')) {
            $query->where('id', $serverId);
        }
        $servers = $query->orderBy('name')->get();

        if ($servers->isEmpty()) {
            $this->warn('No ready servers match the filters.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run — no SSH writes performed.');
        }

        $totals = ['nginx' => 0, 'apache' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($servers as $server) {
            $engines = [];
            if ($engineFilter === null || $engineFilter === 'nginx') {
                if (ServerInstalledServices::has($server, 'nginx')) {
                    $engines[] = 'nginx';
                }
            }
            if ($engineFilter === null || $engineFilter === 'apache') {
                if (ServerInstalledServices::has($server, 'apache')) {
                    $engines[] = 'apache';
                }
            }

            if ($engines === []) {
                $this->line(sprintf('  [skip] %s — no matching engines installed', $server->name));
                $totals['skipped']++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('  [would patch] %s — %s', $server->name, implode(', ', $engines)));

                continue;
            }

            $ssh = new SshConnection($server);

            foreach ($engines as $engine) {
                try {
                    if ($engine === 'nginx') {
                        $this->patchNginx($server, $ssh);
                    } elseif ($engine === 'apache') {
                        $this->patchApache($server, $ssh);
                    }
                    $this->info(sprintf('  [ok]   %s — %s patched', $server->name, $engine));
                    $totals[$engine]++;
                } catch (\Throwable $e) {
                    $this->error(sprintf('  [fail] %s — %s: %s', $server->name, $engine, $e->getMessage()));
                    $totals['failed']++;
                }
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Done. nginx=%d apache=%d skipped=%d failed=%d eligible=%d',
            $totals['nginx'],
            $totals['apache'],
            $totals['skipped'],
            $totals['failed'],
            $servers->count(),
        ));

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function patchNginx(Server $server, SshConnection $ssh): void
    {
        $path = WebserverStatsEndpointTemplates::NGINX_CONF_PATH;
        $body = WebserverStatsEndpointTemplates::nginxStubStatusConf();

        $this->writeRemoteFile($server, $ssh, $path, $body);

        // Validate before reload — never break an active nginx because of
        // our backfill. `nginx -t` exits non-zero on syntax errors and the
        // exec wrapper raises in that case.
        $this->runRemote($server, $ssh, 'nginx -t 2>&1', 30);
        $this->runRemote($server, $ssh, '(systemctl reload nginx || systemctl restart nginx) 2>&1', 60);
    }

    private function patchApache(Server $server, SshConnection $ssh): void
    {
        $path = WebserverStatsEndpointTemplates::APACHE_CONF_PATH;
        $body = WebserverStatsEndpointTemplates::apacheServerStatusConf();
        $confName = WebserverStatsEndpointTemplates::APACHE_CONF_NAME;

        // Module + conf enable are no-ops when already done.
        $this->runRemote($server, $ssh, 'a2enmod status >/dev/null 2>&1 || true', 15);
        $this->writeRemoteFile($server, $ssh, $path, $body);
        $this->runRemote($server, $ssh, sprintf('a2enconf %s >/dev/null 2>&1 || true', escapeshellarg($confName)), 15);
        $this->runRemote($server, $ssh, 'apachectl configtest 2>&1', 30);
        $this->runRemote($server, $ssh, '(systemctl reload apache2 || systemctl restart apache2) 2>&1', 60);
    }

    private function privilegedCommand(string $command): string
    {
        return 'sudo -n bash -lc '.escapeshellarg($command);
    }

    private function writeRemoteFile(Server $server, SshConnection $ssh, string $remotePath, string $contents): void
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
            throw new \RuntimeException("write {$remotePath} failed (exit {$exit})");
        }
    }

    private function runRemote(Server $server, SshConnection $ssh, string $command, int $timeoutSeconds): string
    {
        $out = $ssh->exec($this->privilegedCommand($command), $timeoutSeconds);
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            $tail = trim(substr((string) $out, -500));
            throw new \RuntimeException("exit {$exit}: {$tail}");
        }

        return (string) $out;
    }
}
