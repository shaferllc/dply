<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\HetznerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Attach an already-provisioned server to a Hetzner private network, then
 * poll getInstance() until the private_net IP appears and store it.
 *
 * DigitalOcean does not support adding a droplet to a VPC after creation —
 * this job is Hetzner-only.
 */
class AttachServerToNetworkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $backoff = 8;

    public function __construct(
        public string $serverId,
        public int $networkId,
        public ?string $privateNetworkId = null,
    ) {}

    public function handle(): void
    {
        $server = Server::find($this->serverId);
        if (! $server || $server->provider->value !== 'hetzner') {
            return;
        }

        $credential = $server->providerCredential;
        if (! $credential) {
            return;
        }

        $hetzner = new HetznerService($credential);
        $providerId = (int) $server->provider_id;

        try {
            $hetzner->attachServerToNetwork($providerId, $this->networkId);
        } catch (\Throwable $e) {
            // Already attached — Hetzner returns 409; treat as success.
            if (! str_contains($e->getMessage(), '409') && ! str_contains(strtolower($e->getMessage()), 'already')) {
                Log::warning('AttachServerToNetworkJob: attach failed', [
                    'server_id' => $this->serverId,
                    'network_id' => $this->networkId,
                    'error' => $e->getMessage(),
                ]);
                $this->release($this->backoff);

                return;
            }
        }

        // Save the network ID on the server row.
        $updates = ['hetzner_network_id' => (string) $this->networkId];
        if ($this->privateNetworkId) {
            $updates['private_network_id'] = $this->privateNetworkId;
        }
        $server->update($updates);

        // Poll for the private IP — Hetzner assigns it asynchronously.
        $instance = $hetzner->getInstance($providerId);
        $privateIp = HetznerService::getPrivateIp($instance);

        if ($privateIp) {
            $server->update(['private_ip_address' => $privateIp]);
        } else {
            // Not yet assigned — retry.
            $this->release($this->backoff);
        }
    }
}
