<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Services\HetznerService;
use App\Services\Sites\SiteProvisioner;
use App\Services\SshConnectionFactory;
use App\Support\Servers\HetznerCloudFirewallRules;
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
                'curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg',
                'curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt | tee /etc/apt/sources.list.d/caddy-stable.list',
                'apt-get update -y',
                'apt-get install -y --no-install-recommends caddy',
                'ufw allow 80/tcp || true',
                'ufw allow 443/tcp || true',
                'systemctl enable --now caddy',
            ]);

            $log = $shell->exec("sudo bash -lc '{$script}' 2>&1", 540);

            $check = trim($shell->exec('systemctl is-active caddy 2>&1 || true', 30));
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

        // Re-sync the provider cloud firewall (Hetzner) so 80/443 open at
        // the edge — the on-box `ufw allow 80/443` from the install script
        // is necessary but not sufficient; Hetzner Cloud Firewall sits in
        // front of UFW and was provisioned with the server's original
        // webserver=none rule set, so 80/443 are still blocked until we
        // ask Hetzner to add them. HetznerCloudFirewallRules::forServer()
        // already includes them whenever meta.webserver is non-"none", so
        // a single setFirewallRules call against the existing firewall
        // does it. Failures are logged but don't fail the install — the
        // operator can re-run `dply:hetzner:ensure-firewall <id>` to retry.
        $this->syncHetznerCloudFirewall($server->fresh());

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

    /**
     * Push the new rule set (which now includes 80/443 because meta.webserver
     * is no longer "none") to the server's existing Hetzner Cloud Firewall.
     * Only acts on Hetzner-provisioned hosts that already have a managed
     * firewall id stamped in meta — other providers rely on default-open
     * cloud networking and only need the on-box UFW lines.
     */
    private function syncHetznerCloudFirewall(Server $server): void
    {
        if ($server->provider->value !== 'hetzner') {
            return;
        }

        $firewallId = (int) data_get($server->meta, 'hetzner_firewall_id', 0);
        if ($firewallId === 0) {
            Log::info('InstallServerWebserverJob: no hetzner_firewall_id on server, skipping cloud-firewall sync.', [
                'server_id' => $server->id,
            ]);

            return;
        }

        $credential = $server->providerCredential;
        if ($credential === null) {
            Log::warning('InstallServerWebserverJob: server has no provider credential, cannot sync cloud firewall.', [
                'server_id' => $server->id,
            ]);

            return;
        }

        try {
            $hetzner = new HetznerService($credential);
            $rules = HetznerCloudFirewallRules::forServer($server);
            $hetzner->setFirewallRules($firewallId, $rules);
            Log::info('InstallServerWebserverJob: Hetzner cloud firewall re-synced.', [
                'server_id' => $server->id,
                'firewall_id' => $firewallId,
                'rule_count' => count($rules),
            ]);
        } catch (\Throwable $e) {
            Log::warning('InstallServerWebserverJob: Hetzner cloud firewall sync failed (run dply:hetzner:ensure-firewall to retry).', [
                'server_id' => $server->id,
                'firewall_id' => $firewallId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
