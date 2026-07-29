<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LoadBalancer;
use App\Models\LoadBalancerTarget;
use App\Models\Server;
use App\Modules\Cloud\Services\HetznerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Create the Hetzner load balancer, wait for a public IP, then persist
 * all targets and services to the dply DB.
 */
class ProvisionHetznerLoadBalancerJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $loadBalancerId) {}

    public function handle(): void
    {
        $lb = LoadBalancer::query()
            ->with(['providerCredential', 'targets.server', 'services'])
            ->find($this->loadBalancerId);

        if (! $lb) {
            return;
        }

        if (! $lb->providerCredential) {
            $lb->update(['status' => LoadBalancer::STATUS_ERROR, 'error_message' => 'No provider credential found.']);

            return;
        }

        $hetzner = new HetznerService($lb->providerCredential);

        $targetProviderIds = $lb->targets
            ->map(fn ($t) => $t->server)
            ->filter()
            ->map(fn (Server $s) => (int) $s->provider_id)
            ->filter()
            ->values()
            ->all();

        $services = $lb->services->map(fn ($svc) => [
            'protocol' => $svc->protocol,
            'listen_port' => $svc->listen_port,
            'destination_port' => $svc->destination_port,
            'sticky_sessions' => $svc->sticky_sessions,
        ])->all();

        try {
            $data = $hetzner->createLoadBalancer(
                name: $lb->name,
                loadBalancerType: $lb->load_balancer_type,
                location: $lb->region,
                algorithm: $lb->algorithm,
                networkId: $lb->hetzner_network_id ? (int) $lb->hetzner_network_id : null,
                targetServerProviderIds: $targetProviderIds,
                services: $services,
            );
        } catch (\Throwable $e) {
            $lb->update([
                'status' => LoadBalancer::STATUS_ERROR,
                'error_message' => $e->getMessage(),
            ]);
            Log::warning('ProvisionHetznerLoadBalancerJob: create failed', [
                'lb_id' => $lb->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $providerId = (int) ($data['id'] ?? 0);
        if ($providerId === 0) {
            $lb->update(['status' => LoadBalancer::STATUS_ERROR, 'error_message' => 'No provider ID returned.']);

            return;
        }

        $lb->update(['provider_id' => (string) $providerId]);

        // Poll up to ~60 s for the public IP.
        $publicIp = null;
        $privateIp = null;
        for ($i = 0; $i < 12; $i++) {
            sleep(5);
            try {
                $fresh = $hetzner->getLoadBalancer($providerId);
                $publicIp = HetznerService::getLbPublicIpv4($fresh);
                $privateIp = HetznerService::getLbPrivateIp($fresh);
                if ($publicIp) {
                    break;
                }
            } catch (\Throwable) {
                // Retry.
            }
        }

        $lb->update([
            'status' => LoadBalancer::STATUS_RUNNING,
            'public_ipv4' => $publicIp,
            'private_ip' => $privateIp,
        ]);

        // Persist the Hetzner target statuses back to each target row.
        foreach (($fresh ?? [])['targets'] ?? [] as $t) {
            $hetznerServerId = (int) ($t['server']['id'] ?? 0);
            if ($hetznerServerId === 0) {
                continue;
            }

            $server = Server::query()->where('provider_id', (string) $hetznerServerId)->first();
            if (! $server) {
                continue;
            }

            LoadBalancerTarget::query()
                ->where('load_balancer_id', $lb->id)
                ->where('server_id', $server->id)
                ->update([
                    'provider_server_id' => (string) $hetznerServerId,
                    'status' => $t['health_status'][0]['status'] ?? 'healthy',
                ]);
        }
    }
}
