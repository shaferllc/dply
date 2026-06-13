<?php

declare(strict_types=1);

namespace App\Services\Concerns;



/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesHetznerLoadBalancers
{


    /**
     * Create a load balancer and return its ID.
     *
     * @param  array<int>  $targetServerProviderIds  Hetzner server IDs to add as targets immediately
     * @param  list<array{protocol:string,listen_port:int,destination_port:int}>  $services
     */
    public function createLoadBalancer(
        string $name,
        string $loadBalancerType,
        string $location,
        string $algorithm = 'round_robin',
        ?int $networkId = null,
        array $targetServerProviderIds = [],
        array $services = [],
    ): array {
        $body = [
            'name' => $name,
            'load_balancer_type' => $loadBalancerType,
            'location' => $location,
            'algorithm' => ['type' => $algorithm],
            'public_interface' => true,
        ];

        if ($networkId !== null) {
            $body['network'] = $networkId;
        }

        if ($targetServerProviderIds !== []) {
            $body['targets'] = array_map(static fn ($id) => [
                'type' => 'server',
                'server' => ['id' => (int) $id],
                'use_private_ip' => $networkId !== null,
            ], array_values($targetServerProviderIds));
        }

        if ($services !== []) {
            $body['services'] = array_map(static fn ($svc) => [
                'protocol' => $svc['protocol'],
                'listen_port' => (int) $svc['listen_port'],
                'destination_port' => (int) $svc['destination_port'],
                'health_check' => [
                    'protocol' => in_array($svc['protocol'], ['http', 'https'], true) ? 'http' : 'tcp',
                    'port' => (int) $svc['destination_port'],
                    'interval' => 15,
                    'timeout' => 10,
                    'retries' => 3,
                    'http' => in_array($svc['protocol'], ['http', 'https'], true)
                        ? ['path' => '/', 'status_codes' => ['2??', '3??'], 'tls' => false]
                        : null,
                ],
                'sticky_sessions' => ['enabled' => (bool) ($svc['sticky_sessions'] ?? false)],
            ], $services);
        }

        $response = $this->request('post', '/load_balancers', $body);
        $this->assertSuccess($response, 'create load balancer');

        $data = $response->json();
        $lb = $data['load_balancer'] ?? null;
        if (! $lb || ! isset($lb['id'])) {
            throw new \RuntimeException('Hetzner API did not return load balancer id.');
        }

        return $lb;
    }

    public function getLoadBalancer(int $id): array
    {
        $response = $this->request('get', "/load_balancers/{$id}");
        $this->assertSuccess($response, 'get load balancer');

        return $response->json()['load_balancer'] ?? throw new \RuntimeException('Hetzner API did not return load balancer.');
    }

    /** @return list<array<string, mixed>> */
    public function listLoadBalancers(): array
    {
        $response = $this->request('get', '/load_balancers');
        $this->assertSuccess($response, 'list load balancers');

        return $response->json()['load_balancers'] ?? [];
    }

    public function deleteLoadBalancer(int $id): void
    {
        $response = $this->request('delete', "/load_balancers/{$id}");
        if ($response->status() === 404) {
            return; // Already gone — idempotent.
        }
        $this->assertSuccess($response, 'delete load balancer');
    }

    public function addLoadBalancerTarget(int $lbId, int $serverProviderId, bool $usePrivateIp = false): void
    {
        $response = $this->request('post', "/load_balancers/{$lbId}/actions/add_target", [
            'type' => 'server',
            'server' => ['id' => $serverProviderId],
            'use_private_ip' => $usePrivateIp,
        ]);
        $this->assertSuccess($response, 'add load balancer target');
    }

    public function removeLoadBalancerTarget(int $lbId, int $serverProviderId): void
    {
        $response = $this->request('post', "/load_balancers/{$lbId}/actions/remove_target", [
            'type' => 'server',
            'server' => ['id' => $serverProviderId],
        ]);
        if ($response->status() === 404) {
            return;
        }
        $this->assertSuccess($response, 'remove load balancer target');
    }

    public static function getLbPublicIpv4(array $lb): ?string
    {
        foreach ($lb['public_net']['ipv4'] ?? [] as $entry) {
            if (isset($entry['ip'])) {
                return $entry['ip'];
            }
        }

        return $lb['public_net']['ipv4']['ip'] ?? null;
    }

    public static function getLbPrivateIp(array $lb): ?string
    {
        foreach ($lb['private_net'] ?? [] as $net) {
            if (isset($net['ip'])) {
                return $net['ip'];
            }
        }

        return null;
    }
}
