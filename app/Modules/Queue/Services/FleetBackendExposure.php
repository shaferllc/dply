<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Services\Runtimes\FleetHostAllocator;
use App\Services\Servers\ManagedFirewallPort;
use App\Services\WorkerPools\SiteWorkerFleetTrustedSources;
use App\Support\Servers\ServerNetworkPeers;

/**
 * Let fleet hosts through to the app's database and Redis — privately.
 *
 * Two cases are safe to open without asking:
 *  - a backend on another dply server in the fleet hosts' private network gets
 *    a firewall rule per fleet host's private /32, never a public source;
 *  - a managed cluster gets the host as a trusted source.
 *
 * A backend on the app server's own loopback is left alone and reported:
 * reaching it means rebinding that database onto the network, which is a
 * change to the customer's database and theirs to decide.
 */
final class FleetBackendExposure
{
    public function __construct(
        private readonly FleetWorkerEnvironment $environment,
        private readonly FleetHostAllocator $allocator,
        private readonly ManagedFirewallPort $firewall,
        private readonly SiteWorkerFleetTrustedSources $trustedSources,
    ) {}

    /**
     * `action` is one of: firewall (opened), none (no fleet host shares the
     * backend's network), loopback (left for the operator), unmanaged (not a
     * dply server — nothing dply can open).
     *
     * @return list<array{name: string, host: string, port: int, action: string}>
     */
    public function open(ManagedQueueFleet $fleet, Server $host): array
    {
        $site = $fleet->namespace?->site;
        $out = [];

        foreach (FleetReachabilityProbe::targets($this->environment->for($fleet)) as $target) {
            $out[] = $target + ['action' => $this->openOne($target)];
        }

        // Managed clusters are matched by binding, not by hostname, so one
        // grant covers whichever of them the env points at.
        if ($site instanceof Site) {
            $this->trustedSources->grantForMember($site, $host);
        }

        return $out;
    }

    /**
     * @param  array{name: string, host: string, port: int}  $target
     */
    private function openOne(array $target): string
    {
        $address = $target['host'];

        if ($address === 'localhost' || $address === '::1' || str_starts_with($address, '127.')) {
            return 'loopback';
        }

        $backend = Server::query()->where('private_ip_address', $address)->first();
        if (! $backend instanceof Server || blank($backend->private_network_id)) {
            return 'unmanaged';
        }

        // Private network ids are account-scoped, so a match also means the
        // hosts are ours and in the same VPC; anything else would need a
        // public source, which this never opens.
        $sources = $this->allocator->hosts()
            ->filter(fn (Server $h): bool => (string) $h->private_network_id === (string) $backend->private_network_id)
            ->mapWithKeys(fn (Server $h): array => [(string) $h->id => ServerNetworkPeers::hostCidr($h)])
            ->filter()
            ->all();

        if ($sources === []) {
            return 'none';
        }

        $this->firewall->openGroup(
            server: $backend,
            groupTag: 'dply-queue-fleet-'.$target['port'],
            port: $target['port'],
            sourcesByKey: $sources,
            nameFor: fn (string $key, string $cidr): string => 'dply queue fleet host '.$cidr.' → '.$target['name'],
        );

        return 'firewall';
    }
}
