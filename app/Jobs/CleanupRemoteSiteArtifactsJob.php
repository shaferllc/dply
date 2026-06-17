<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerCronSynchronizer;
use App\Services\Servers\SupervisorProvisioner;
use App\Services\SshConnectionFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CleanupRemoteSiteArtifactsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array{
     *     server_id: int,
     *     webserver?: string,
     *     nginx_basename: string,
     *     repository_base: string,
     *     deploy_strategy?: string,
     *     primary_hostname?: string|null,
     *     ssl_was_active?: bool,
     *     supervisor_program_ids?: array<int, int>,
     *     php_fpm_pool_name?: string|null,
     *     site_id?: int,
     *     systemd_unit_names?: list<string>
     * }  $payload
     */
    public function __construct(
        public array $payload
    ) {}

    public function handle(SshConnectionFactory $sshFactory, ServerCronSynchronizer $cronSync, SupervisorProvisioner $supervisorProvisioner): void
    {
        $server = Server::find($this->payload['server_id']);
        if (! $server || ! $server->isReady() || empty($server->ssh_private_key)) {
            return;
        }

        $ssh = $sshFactory->forServer($server);
        $basename = $this->payload['nginx_basename'];
        $webserver = (string) ($this->payload['webserver'] ?? 'nginx');
        $base = $this->payload['repository_base'];
        $strategy = (string) ($this->payload['deploy_strategy'] ?? 'simple');
        /** @var array<int, int> $svIds */
        $svIds = $this->payload['supervisor_program_ids'] ?? [];

        $log = '';

        $dir = rtrim(config('sites.supervisor_conf_d'), '/');
        foreach ($svIds as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $path = $dir.'/dply-sv-'.$pid.'.conf';
            $log .= $ssh->exec('rm -f '.escapeshellarg($path).' 2>&1', 30);
        }
        if ($svIds !== []) {
            $log .= $ssh->exec($supervisorProvisioner->supervisorRereadUpdateExecLine($server, 'DPLY_SV_CLEAN_EXIT'), 180);
        }

        // Worker / headless hosts provision with webserver=none and have no
        // nginx/apache binary — skip vhost teardown entirely so we don't run
        // `nginx -t` on a box where it doesn't exist (exit 127).
        if ($basename !== '' && $webserver !== '' && $webserver !== 'none') {
            $log .= match ($webserver) {
                'apache' => $ssh->exec(sprintf(
                    '(sudo a2dissite %1$s >/dev/null 2>&1 || true; sudo rm -f %2$s; sudo apachectl configtest && (sudo systemctl reload apache2 2>/dev/null || sudo service apache2 reload 2>/dev/null)) 2>&1; printf "\nDPLY_WEBSERVER_CLEAN_EXIT:%%s" "$?"',
                    escapeshellarg($basename.'.conf'),
                    escapeshellarg(rtrim(config('sites.apache_sites_available'), '/').'/'.$basename.'.conf')
                ), 120),
                'caddy' => $ssh->exec(sprintf(
                    '(sudo rm -f %1$s && sudo caddy validate --config /etc/caddy/Caddyfile && (sudo systemctl reload caddy 2>/dev/null || sudo service caddy reload 2>/dev/null || sudo systemctl restart caddy)) 2>&1; printf "\nDPLY_WEBSERVER_CLEAN_EXIT:%%s" "$?"',
                    escapeshellarg(rtrim(config('sites.caddy_sites_enabled'), '/').'/'.$basename.'.caddy')
                ), 120),
                'openlitespeed' => $ssh->exec(sprintf(
                    '(sudo rm -rf %1$s && sudo /usr/local/lsws/bin/lswsctrl restart) 2>&1; printf "\nDPLY_WEBSERVER_CLEAN_EXIT:%%s" "$?"',
                    escapeshellarg(rtrim(config('sites.openlitespeed_vhosts_path'), '/').'/'.$basename)
                ), 180),
                'traefik' => $ssh->exec(sprintf(
                    '(sudo rm -f %1$s %2$s && sudo caddy validate --config /etc/caddy/Caddyfile && (sudo systemctl reload caddy 2>/dev/null || sudo service caddy reload 2>/dev/null || sudo systemctl restart caddy) && (sudo systemctl reload traefik 2>/dev/null || sudo systemctl restart traefik 2>/dev/null || true)) 2>&1; printf "\nDPLY_WEBSERVER_CLEAN_EXIT:%%s" "$?"',
                    escapeshellarg(rtrim(config('sites.traefik_dynamic_config_path'), '/').'/'.$basename.'.yml'),
                    escapeshellarg(rtrim(config('sites.caddy_sites_enabled'), '/').'/'.$basename.'-backend.caddy')
                ), 180),
                default => (function () use ($ssh, $basename): string {
                    $available = rtrim(config('sites.nginx_sites_available'), '/');
                    $enabled = rtrim(config('sites.nginx_sites_enabled'), '/');
                    $confFile = $available.'/'.$basename.'.conf';
                    $linkFile = $enabled.'/'.$basename.'.conf';

                    // The vhost files live under /etc/nginx (root-owned), and
                    // nginx -t / reload need root — run privileged or the rm
                    // fails with "Permission denied" and leaves orphaned vhosts
                    // (→ "conflicting server name" on the next site).
                    return $ssh->exec(sprintf(
                        '(sudo rm -f %1$s %2$s && sudo nginx -t && (sudo systemctl reload nginx 2>/dev/null || sudo service nginx reload 2>/dev/null || sudo nginx -s reload)) 2>&1; printf "\nDPLY_WEBSERVER_CLEAN_EXIT:%%s" "$?"',
                        escapeshellarg($confFile),
                        escapeshellarg($linkFile)
                    ), 120);
                })(),
            };
        }

        // Remove this site's dedicated PHP-FPM pool from every version's pool.d
        // (a version switch can leave the conf in more than one) and reload each
        // affected master. Runs after the vhost teardown above, so nothing still
        // points at the pool's socket when it goes away.
        $poolName = trim((string) ($this->payload['php_fpm_pool_name'] ?? '')); // optional key
        if ($poolName !== '') {
            $log .= $ssh->exec(sprintf(
                '(NAME=%1$s; RELOAD=""; for d in /etc/php/*/fpm/pool.d; do [ -d "$d" ] || continue; f="${d}/${NAME}.conf"; if [ -f "$f" ]; then sudo rm -f "$f"; v="$(basename "$(dirname "$(dirname "$d")")")"; RELOAD="${RELOAD} ${v}"; fi; done; for v in $(echo "$RELOAD" | tr " " "\n" | sort -u); do [ -z "$v" ] && continue; sudo systemctl reload "php${v}-fpm" 2>/dev/null || sudo systemctl restart "php${v}-fpm" 2>/dev/null || true; done) 2>&1; printf "\nDPLY_FPM_POOL_CLEAN_EXIT:%%s" "$?"',
                escapeshellarg($poolName)
            ), 120);
        }

        // Release artifacts are written by privileged provision/deploy steps, so
        // the deploy user can't always rm them — use sudo or the tree survives
        // and a re-created same-slug site inherits stale code (wrong PHP socket,
        // old index.php) and 502s.
        $baseEsc = escapeshellarg($base);
        if ($base !== '' && config('dply.delete_remote_repository_on_site_delete', false)) {
            $log .= $ssh->exec(sprintf('sudo rm -rf %s 2>&1; printf "\nDPLY_RM_BASE_EXIT:%%s" "$?"', $baseEsc), 600);
        } elseif ($base !== '' && $strategy === 'atomic') {
            $log .= $ssh->exec(sprintf(
                'sudo rm -rf %1$s/releases 2>/dev/null; sudo rm -f %1$s/current 2>/dev/null; printf "\nDPLY_ATOMIC_RM_EXIT:%%s" "$?"',
                $baseEsc
            ), 300);
        }

        $siteId = (int) ($this->payload['site_id'] ?? 0);
        if ($siteId > 0) {
            // The deploy key lives under root's home; the SSH connection runs
            // as the (non-root) deploy user, so remove it via sudo — mirroring
            // the systemd teardown below.
            $keyPath = '/root/.ssh/dply_site_'.$siteId.'_deploy';
            $log .= $ssh->exec('sudo rm -f '.escapeshellarg($keyPath).' 2>&1', 30);
        }

        $host = trim((string) ($this->payload['primary_hostname'] ?? ''));
        if (
            config('dply.delete_remote_certbot_certificate_on_site_delete', false)
            && ($this->payload['ssl_was_active'] ?? false)
            && $host !== ''
        ) {
            $log .= $ssh->exec(
                sprintf(
                    'command -v certbot >/dev/null 2>&1 && certbot delete --cert-name %s --non-interactive 2>&1 || echo "certbot_skip"',
                    escapeshellarg($host)
                ),
                300
            );
        }

        try {
            $cronSync->sync($server);
        } catch (\Throwable $e) {
            Log::warning('CleanupRemoteSiteArtifactsJob crontab resync failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($basename !== '' && ! preg_match('/DPLY_WEBSERVER_CLEAN_EXIT:0\s*$/', $log)) {
            Log::warning('CleanupRemoteSiteArtifactsJob webserver cleanup incomplete', [
                'server_id' => $server->id,
                'webserver' => $webserver,
                'basename' => $basename,
                'output' => Str::limit($log, 2500),
            ]);
        }

        // Tear down any systemd units that the SiteSystemdProvisioner
        // installed for non-PHP/static runtimes. The unit names are passed
        // through from the deleting hook because the Site row is gone by
        // the time this job runs.
        /** @var list<string> $unitNames */
        $unitNames = $this->payload['systemd_unit_names'] ?? [];
        $unitNames = array_values(array_filter($unitNames, 'is_string'));
        if ($unitNames !== []) {
            $disableLines = [];
            foreach ($unitNames as $name) {
                // Allow systemctl to fail (unit may never have been
                // activated) without aborting the rm; combine into one
                // SSH round-trip per unit.
                $disableLines[] = sprintf(
                    'sudo systemctl disable --now %1$s 2>/dev/null || true; sudo rm -f /etc/systemd/system/%1$s',
                    escapeshellarg($name),
                );
            }
            $disableLines[] = 'sudo systemctl daemon-reload';
            try {
                $ssh->exec(implode(' && ', $disableLines), 90);
            } catch (\Throwable $e) {
                Log::info('CleanupRemoteSiteArtifactsJob systemd teardown skipped', [
                    'server_id' => $server->id,
                    'units' => $unitNames,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
