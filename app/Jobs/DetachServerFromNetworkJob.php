<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Modules\Cloud\Services\HetznerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DetachServerFromNetworkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 8;

    public function __construct(
        public string $serverId,
        public int $networkId,
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
            $hetzner->detachServerFromNetwork($providerId, $this->networkId);
        } catch (\Throwable $e) {
            // 404/409 = already detached; treat as success.
            if (! str_contains($e->getMessage(), '404') && ! str_contains($e->getMessage(), '409')) {
                Log::warning('DetachServerFromNetworkJob: detach failed', [
                    'server_id' => $this->serverId,
                    'network_id' => $this->networkId,
                    'error' => $e->getMessage(),
                ]);
                $this->release($this->backoff);

                return;
            }
        }

        $server->update([
            'private_ip_address' => null,
            'hetzner_network_id' => null,
        ]);
    }
}
