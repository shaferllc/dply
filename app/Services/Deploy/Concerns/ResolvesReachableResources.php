<?php

declare(strict_types=1);

namespace App\Services\Deploy\Concerns;

use App\Models\PrivateNetwork;
use App\Models\Server;

/**
 * Private-network reachability helpers shared by the resource bindings that
 * resolve a service host relative to where a site runs (databases + caches).
 */
trait ResolvesReachableResources
{
    /**
     * Server IDs whose databases $server can reach: itself, plus every same-org
     * peer that shares a private network with it (see {@see sharePrivateNetwork}).
     * Membership is derived from the actual private IPs, not just the
     * private_network_id column — servers often have a private IP on the subnet
     * without that link being recorded.
     *
     * @return list<string>
     */
    private function reachableServerIds(Server $server): array
    {
        $ids = [(string) $server->id];

        if (blank($server->private_ip_address)) {
            return $ids; // No private interface → only its own (loopback) DBs.
        }

        $peers = Server::query()
            ->where('organization_id', $server->organization_id)
            ->whereKeyNot($server->id)
            ->whereNotNull('private_ip_address')
            ->get()
            ->filter(fn (Server $peer): bool => $this->sharePrivateNetwork($server, $peer))
            ->map(fn (Server $peer): string => (string) $peer->id)
            ->all();

        return array_values(array_unique([...$ids, ...$peers]));
    }

    /**
     * Whether two servers sit on the same private network and can reach each
     * other over their private IPs. True when they're linked to the same
     * PrivateNetwork row, OR a PrivateNetwork in the org has a CIDR covering
     * both private IPs, OR (no network row links them) the IPs share a /24.
     */
    private function sharePrivateNetwork(Server $a, Server $b): bool
    {
        if ((string) $a->organization_id !== (string) $b->organization_id) {
            return false;
        }

        $aIp = trim((string) $a->private_ip_address);
        $bIp = trim((string) $b->private_ip_address);
        if ($aIp === '' || $bIp === '') {
            return false;
        }

        if ($a->private_network_id !== null && (string) $a->private_network_id === (string) $b->private_network_id) {
            return true;
        }

        foreach (PrivateNetwork::query()->where('organization_id', $a->organization_id)->get() as $net) {
            $cidr = (string) $net->ip_range;
            if ($cidr !== '' && $this->ipInCidr($aIp, $cidr) && $this->ipInCidr($bIp, $cidr)) {
                return true;
            }
        }

        return $this->sameSubnet24($aIp, $bIp);
    }

    /** IPv4 CIDR-membership test. Non-IPv4 / unparseable inputs return false. */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : ((~0 << (32 - $bits)) & 0xFFFFFFFF);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /** Whether two IPv4 addresses share the same /24 subnet. */
    private function sameSubnet24(string $a, string $b): bool
    {
        $al = ip2long($a);
        $bl = ip2long($b);
        if ($al === false || $bl === false) {
            return false;
        }

        $mask = (~0 << 8) & 0xFFFFFFFF;

        return ($al & $mask) === ($bl & $mask);
    }
}
