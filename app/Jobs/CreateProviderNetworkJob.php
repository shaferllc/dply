<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Services\HetznerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Create a Hetzner private network and attach the selected servers.
 *
 * Subnets are created for every unique network zone across the selected servers
 * so multi-region networks work without a second create-network pass (#7).
 *
 * IP polling is handled by {@see AttachServerToNetworkJob} (tries=10, backoff=8s)
 * dispatched per server, replacing the old inline sleep-loop (#6).
 */
class CreateProviderNetworkJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    /** @param  list<string>  $serverIds  dply Server IDs to attach */
    public function __construct(
        public string $credentialId,
        public string $networkName,
        public string $ipRange,
        public array $serverIds,
        public ?string $privateNetworkId = null,
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

        // Derive subnets from the unique zones of the selected servers (#7).
        $zones = $servers
            ->map(fn (Server $s) => HetznerService::networkZoneForRegion((string) ($s->region ?? '')))
            ->unique()
            ->values()
            ->all();

        $hetzner = new HetznerService($credential);
        $hetznerNetworkId = $hetzner->createNetwork($this->networkName, $this->ipRange, $zones);

        // Store the Hetzner provider ID on the PrivateNetwork row if one was pre-created.
        if ($this->privateNetworkId) {
            PrivateNetwork::query()
                ->where('id', $this->privateNetworkId)
                ->update(['provider_id' => (string) $hetznerNetworkId]);
        }

        // Dispatch one attach job per server — each polls independently with backoff (#6).
        foreach ($servers as $server) {
            if ((int) $server->provider_id === 0) {
                continue;
            }
            AttachServerToNetworkJob::dispatch($server->id, $hetznerNetworkId, $this->privateNetworkId);
        }
    }
}
