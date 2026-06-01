<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\HetznerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Create a Hetzner private network and attach every Hetzner server in the
 * organisation to it, then poll until each server's private IP is assigned
 * and persist it to the server row.
 *
 * Dispatched from WorkspaceNetworking::createNetwork().
 */
class CreateProviderNetworkJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /** @param  list<string>  $serverIds  dply Server IDs to attach */
    public function __construct(
        public string $credentialId,
        public string $networkName,
        public string $ipRange,
        public array $serverIds,
    ) {}

    public function handle(): void
    {
        $servers = Server::query()
            ->whereIn('id', $this->serverIds)
            ->where('provider', 'hetzner')
            ->get();

        if ($servers->isEmpty()) {
            return;
        }

        $credential = $servers->first()?->providerCredential;
        if (! $credential) {
            Log::warning('CreateProviderNetworkJob: no Hetzner credential found', [
                'server_ids' => $this->serverIds,
            ]);

            return;
        }

        $hetzner = new HetznerService($credential);

        // Create the network.
        $networkId = $hetzner->createNetwork($this->networkName, $this->ipRange);

        // Attach each server and poll for its private IP.
        foreach ($servers as $server) {
            $providerId = (int) $server->provider_id;
            if ($providerId === 0) {
                continue;
            }

            try {
                $hetzner->attachServerToNetwork($providerId, $networkId);
            } catch (\Throwable $e) {
                if (! str_contains($e->getMessage(), '409') && ! str_contains(strtolower($e->getMessage()), 'already')) {
                    Log::warning('CreateProviderNetworkJob: attach failed for server', [
                        'server_id' => $server->id,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            $server->update(['hetzner_network_id' => (string) $networkId]);

            // Poll up to ~30 s for the private IP to be assigned.
            $privateIp = null;
            for ($i = 0; $i < 6; $i++) {
                sleep(5);
                $instance = $hetzner->getInstance($providerId);
                $privateIp = HetznerService::getPrivateIp($instance);
                if ($privateIp) {
                    break;
                }
            }

            if ($privateIp) {
                $server->update(['private_ip_address' => $privateIp]);
            }
        }
    }
}
