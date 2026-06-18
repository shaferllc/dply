<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\VultrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-query the provider API for a server's private / internal networking IP and
 * persist it to the `private_ip_address` column. Triggered on demand from the
 * connection settings card's "Refresh" affordance.
 *
 * Provider/SSH calls must never run inline in a Livewire request, so the UI
 * dispatches this and reflects the new value on the next poll. Only providers
 * whose service exposes a private-IP reader are supported
 * ({@see ServerProvider::supportsPrivateIpLookup()}); others no-op.
 */
class RefreshServerPrivateIpJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public string $serverId,
    ) {}

    public function handle(): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        $provider = $server->provider;
        if (! $provider->supportsPrivateIpLookup()) {
            return;
        }

        $credential = $server->providerCredential;
        if ($credential === null || $server->provider_id === null || $server->provider_id === '') {
            return;
        }

        try {
            $privateIp = match ($provider) {
                ServerProvider::DigitalOcean => DigitalOceanService::getDropletPrivateIp(
                    (new DigitalOceanService($credential))->getDroplet((int) $server->provider_id)
                ),
                ServerProvider::Hetzner => HetznerService::getPrivateIp(
                    (new HetznerService($credential))->getInstance((int) $server->provider_id)
                ),
                ServerProvider::Vultr => VultrService::getPrivateIp(
                    (new VultrService($credential))->getInstance((string) $server->provider_id)
                ),
                ServerProvider::Linode => LinodeService::getPrivateIp(
                    (new LinodeService($credential))->getInstance((int) $server->provider_id)
                ),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('RefreshServerPrivateIpJob: provider lookup failed', [
                'server_id' => $this->serverId,
                'provider' => $provider->value,
                'error' => $e->getMessage(),
            ]);

            $this->release($this->backoff);

            return;
        }

        // Provider returned no private IP — leave the existing value untouched
        // (e.g. the server isn't on a VPC / private network).
        if ($privateIp === null || $privateIp === '') {
            return;
        }

        if ($server->private_ip_address !== $privateIp) {
            $server->update(['private_ip_address' => $privateIp]);
        }
    }
}
