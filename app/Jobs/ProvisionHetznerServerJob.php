<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\HetznerService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\BootHeadStartScript;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\HetznerCloudFirewallRules;
use App\Support\Servers\ServerHostingPlatformContext;
use App\Support\Servers\ServerImageCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProvisionHetznerServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Server $server
    ) {
        $this->onQueue(config('server_provision.queue', 'dply'));
    }

    public function handle(): void
    {
        $managed = $this->server->usesManagedHosting();

        if (! $managed) {
            $credential = $this->server->providerCredential;
            if (! $credential || $credential->provider !== 'hetzner') {
                $this->markFailed('Missing or wrong-provider credential. Re-link a Hetzner credential to this server.');

                return;
            }
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        // forOrg() routes beta orgs' free boxes to the isolated beta Hetzner
        // project (blast-radius containment); falls back to the primary project.
        $platform = $managed ? ServerHostingPlatformContext::forOrg($this->server->organization) : null;
        if ($managed && ! $platform->configured()) {
            $this->markFailed('dply-managed servers are not configured. Set DPLY_MANAGED_HETZNER_API_TOKEN in the environment.');

            return;
        }

        try {
            $hetzner = $managed
                ? $platform->hetzner()
                : new HetznerService($this->server->providerCredential);

            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

            $keyName = 'dply-'.$this->server->name.'-'.Str::random(6);
            $hetznerKey = $hetzner->addSshKey($keyName, $keys['recovery_public_key']);
            $sshKeyId = $hetznerKey['id'] ?? null;
            if ($sshKeyId === null) {
                $this->markFailed('Hetzner accepted the SSH key request but returned no id — cannot create server.');

                return;
            }

            // Image precedence: a pre-baked snapshot (Hetzner snapshots are global
            // across locations, so a single id applies to every region) is the
            // fast path for BOTH managed (incl. warm-pool members) and BYO; the
            // setup script skip-fasts already-installed steps when launched from
            // it. Managed falls back to the platform default; BYO honours a
            // user-chosen OS image first, then stock Ubuntu.
            $snapshot = ServerImageCatalog::bakedSnapshotForRegion('hetzner', $this->server->region);
            $image = $managed
                ? ($snapshot ?? $platform->defaultImage)
                : (ServerImageCatalog::resolveForServer($this->server, 'hetzner')
                    ?? $snapshot
                    ?? config('services.hetzner.default_image', 'ubuntu-24.04'));

            // Ensure a dply-managed Cloud Firewall that allows SSH (and the
            // server's service ports) BEFORE create, then attach it at boot so
            // the box is reachable atomically. Hetzner projects that carry any
            // Cloud Firewall otherwise drop inbound 22 at the edge — UFW on the
            // box never sees the packets. Best-effort: a firewall hiccup must
            // not fail the whole provision, so we fall back to no managed
            // firewall (same reachability as before this feature).
            $firewallId = null;
            if ((bool) config('services.hetzner.manage_cloud_firewall', true)) {
                try {
                    $firewallName = 'dply-'.$this->server->id;
                    $rules = HetznerCloudFirewallRules::forServer($this->server);
                    $existing = $hetzner->findFirewallByName($firewallName);
                    if ($existing !== null && isset($existing['id'])) {
                        $firewallId = (int) $existing['id'];
                        $hetzner->setFirewallRules($firewallId, $rules);
                    } else {
                        $firewallId = $hetzner->createFirewall($firewallName, $rules);
                    }
                } catch (Throwable $e) {
                    Log::warning('Hetzner cloud firewall ensure failed; provisioning without managed firewall', [
                        'server_id' => $this->server->id,
                        'message' => $e->getMessage(),
                    ]);
                    $firewallId = null;
                }
            }

            $networkId = filled($this->server->hetzner_network_id)
                ? (int) $this->server->hetzner_network_id
                : null;

            $id = $hetzner->createInstance(
                name: $this->server->name,
                location: $this->server->region,
                serverType: $this->server->size,
                image: $image,
                sshKeyIds: [$sshKeyId],
                // Boot head-start (apt warmup at boot) when enabled; '' when off.
                userData: BootHeadStartScript::enabled()
                    ? BootHeadStartScript::cloudInitUserData()
                    : '',
                firewallIds: $firewallId !== null ? [$firewallId] : [],
                networkId: $networkId,
            );
        } catch (Throwable $e) {
            $this->markFailed($this->humanizeApiError($e));

            return;
        }

        $this->server->update([
            'provider_id' => (string) $id,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.hetzner.ssh_user', 'root'),
        ]);

        $metaUpdates = is_array($this->server->meta) ? $this->server->meta : [];
        unset($metaUpdates['provision_error']);
        if ($firewallId !== null) {
            // Recorded so DeleteServerAction can tear the firewall down with the server.
            $metaUpdates['hetzner_firewall_id'] = $firewallId;
        }
        $this->server->update(['meta' => $metaUpdates]);

        PollHetznerIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($this->humanizeApiError($e));
    }

    private function markFailed(string $message): void
    {
        Log::warning('Hetzner server provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'hetzner',
            'message' => $message,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'at' => now()->toIso8601String(),
        ];

        $this->server->forceFill([
            'status' => Server::STATUS_ERROR,
            'meta' => $meta,
        ])->save();
    }

    private function humanizeApiError(Throwable $e): string
    {
        $msg = trim($e->getMessage());

        if ($msg === '') {
            return 'Hetzner returned an unexpected error. Check the configured server type and location.';
        }

        if (stripos($msg, 'server type') !== false && stripos($msg, 'location') !== false) {
            return $msg.' — pick a server type available in the selected location.';
        }

        return $msg;
    }
}
