<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteProvisioner;
use App\Services\SshConnectionFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Retrofit a webserver onto a server that was provisioned without one
 * (typically a worker host whose install profile set `webserver=none`
 * before workers got the `caddy` default).
 *
 * SSHes in as root, runs the same apt install lines the provisioner uses
 * during fresh-server setup, updates `server.meta.webserver` and
 * `server.meta.installed_stack.webserver`, and re-queues provisioning
 * for any headless sites on the box so they pick up vhosts + testing
 * hostnames now that there's actually an HTTP front to attach them to.
 */
class InstallServerWebserverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $serverId,
        public string $webserver = 'caddy',
    ) {}

    public function handle(SshConnectionFactory $sshFactory, SiteProvisioner $siteProvisioner): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }

        $current = (string) ($server->meta['webserver'] ?? 'none');
        if ($current !== 'none' && $current !== '') {
            Log::info('InstallServerWebserverJob skipped — webserver already installed.', [
                'server_id' => $server->id,
                'webserver' => $current,
            ]);

            return;
        }

        if ($this->webserver !== 'caddy') {
            // We only support caddy retrofits today. Adding nginx etc. would
            // duplicate large chunks of the provisioner; defer to a fresh
            // provision when those are needed.
            throw new \InvalidArgumentException("Unsupported webserver retrofit: {$this->webserver}");
        }

        try {
            $shell = $sshFactory->forServer($server);

            $script = implode(" && \\\n", [
                'set -e',
                'export DEBIAN_FRONTEND=noninteractive',
                'install -d /usr/share/keyrings',
                "curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg",
                "curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt | tee /etc/apt/sources.list.d/caddy-stable.list",
                'apt-get update -y',
                'apt-get install -y --no-install-recommends caddy',
                'ufw allow 80/tcp || true',
                'ufw allow 443/tcp || true',
                'systemctl enable --now caddy',
            ]);

            $log = $shell->exec("sudo bash -lc '{$script}' 2>&1", 540);

            $check = trim($shell->exec("systemctl is-active caddy 2>&1 || true", 30));
            if ($check !== 'active') {
                Log::warning('InstallServerWebserverJob: caddy not active after install.', [
                    'server_id' => $server->id,
                    'check' => $check,
                    'tail' => mb_substr($log, -2000),
                ]);
                throw new \RuntimeException('Caddy install completed but systemd reports the service is not active. See worker logs for the apt output.');
            }
        } catch (\Throwable $e) {
            // Clear the UI pending flag so the operator can retry.
            $meta = is_array($server->meta) ? $server->meta : [];
            unset($meta['webserver_install_pending']);
            $meta['webserver_install_error'] = $e->getMessage();
            $server->forceFill(['meta' => $meta])->save();
            throw $e;
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta['webserver'] = 'caddy';
        $installedStack = is_array($meta['installed_stack'] ?? null) ? $meta['installed_stack'] : [];
        $installedStack['webserver'] = 'caddy';
        $meta['installed_stack'] = $installedStack;
        $meta['webserver_installed_at'] = now()->toIso8601String();
        unset($meta['webserver_install_pending']);

        $server->forceFill(['meta' => $meta])->save();

        Log::info('InstallServerWebserverJob: caddy installed.', ['server_id' => $server->id]);

        // Re-queue provisioning for headless sites on this server so they
        // get vhosts + testing hostnames now that caddy is up. Skip sites
        // that already failed or are mid-flight.
        $headlessSites = $server->sites()
            ->whereIn('status', [Site::STATUS_CUSTOM_ACTIVE, Site::STATUS_PENDING])
            ->get();
        foreach ($headlessSites as $site) {
            $site->forceFill(['status' => Site::STATUS_PENDING])->save();
            $siteProvisioner->markQueued($site);
            ProvisionSiteJob::dispatch($site->id);
        }
    }
}
