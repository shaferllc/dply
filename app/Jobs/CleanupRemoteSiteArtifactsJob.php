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
     *     nginx_basename: string,
     *     repository_base: string,
     *     deploy_strategy?: string,
     *     primary_hostname?: string|null,
     *     ssl_was_active?: bool,
     *     supervisor_program_ids?: array<int, int>,
     *     site_id?: int
     * }  $payload
     */
    public function __construct(
        public array $payload
    ) {}

    public function handle(SshConnectionFactory $sshFactory, ServerCronSynchronizer $cronSync, SupervisorProvisioner $supervisorProvisioner): void
    {
        $server = Server::query()->find($this->payload['server_id'] ?? 0);
        if (! $server || ! $server->isReady() || empty($server->ssh_private_key)) {
            return;
        }

        $ssh = $sshFactory->forServer($server);
        $basename = (string) ($this->payload['nginx_basename'] ?? '');
        $base = (string) ($this->payload['repository_base'] ?? '');
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

        if ($basename !== '') {
            $available = rtrim(config('sites.nginx_sites_available'), '/');
            $enabled = rtrim(config('sites.nginx_sites_enabled'), '/');
            $confFile = $available.'/'.$basename.'.conf';
            $linkFile = $enabled.'/'.$basename.'.conf';
            $log .= $ssh->exec(sprintf(
                '(rm -f %1$s %2$s && nginx -t && (systemctl reload nginx 2>/dev/null || service nginx reload 2>/dev/null || nginx -s reload)) 2>&1; printf "\nDPLY_NGINX_CLEAN_EXIT:%%s" "$?"',
                escapeshellarg($confFile),
                escapeshellarg($linkFile)
            ), 120);
        }

        $baseEsc = escapeshellarg($base);
        if ($base !== '' && config('dply.delete_remote_repository_on_site_delete', false)) {
            $log .= $ssh->exec(sprintf('rm -rf %s 2>&1; printf "\nDPLY_RM_BASE_EXIT:%%s" "$?"', $baseEsc), 600);
        } elseif ($base !== '' && $strategy === 'atomic') {
            $log .= $ssh->exec(sprintf(
                'rm -rf %1$s/releases 2>/dev/null; rm -f %1$s/current 2>/dev/null; printf "\nDPLY_ATOMIC_RM_EXIT:%%s" "$?"',
                $baseEsc
            ), 300);
        }

        $siteId = (int) ($this->payload['site_id'] ?? 0);
        if ($siteId > 0) {
            $keyPath = '/root/.ssh/dply_site_'.$siteId.'_deploy';
            $log .= $ssh->exec('rm -f '.escapeshellarg($keyPath).' 2>&1', 30);
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

        if ($basename !== '' && ! preg_match('/DPLY_NGINX_CLEAN_EXIT:0\s*$/', $log)) {
            Log::warning('CleanupRemoteSiteArtifactsJob nginx cleanup incomplete', [
                'server_id' => $server->id,
                'basename' => $basename,
                'output' => Str::limit($log, 2500),
            ]);
        }
    }
}
