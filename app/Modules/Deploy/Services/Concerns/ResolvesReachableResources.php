<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\SiteBinding;
use Illuminate\Support\Collection;

/**
 * Private-network reachability helpers shared by the resource bindings that
 * resolve a service host relative to where a site runs (databases + caches).
 */
trait ResolvesReachableResources
{
    /**
     * Per-instance memo of each org's PrivateNetwork rows + each server's
     * reachable-server-id set, so resolving reachability across many bindings /
     * peers in one request doesn't re-run the same `private_networks` /
     * peer-server queries (sharePrivateNetwork is called once per peer).
     *
     * @var array<string, Collection<int, PrivateNetwork>>
     */
    private array $privateNetworksByOrg = [];

    /** @var array<string, list<string>> */
    private array $reachableServerIdsByServer = [];

    /** @var array<string, list<string>> */
    private array $attachableCacheServerIdsByServer = [];

    /** @var array<string, list<string>> */
    private array $attachableDatabaseServerIdsByServer = [];

    /** @return Collection<int, PrivateNetwork> */
    private function orgPrivateNetworks(string $organizationId)
    {
        return $this->privateNetworksByOrg[$organizationId] ??= PrivateNetwork::query()
            ->where('organization_id', $organizationId)
            ->get();
    }

    /**
     * How many OTHER sites already bind each of the given resources, keyed by
     * target_id. Lets the attach pickers warn that a Redis/database/realtime app
     * is shared so the operator sets a prefix / separate DB to avoid collisions.
     *
     * @param  list<string>  $targetIds
     * @return array<string, int> target_id => distinct other-site count
     */
    private function bindingConsumerCounts(string $targetType, array $targetIds, ?string $exceptSiteId): array
    {
        if ($targetIds === []) {
            return [];
        }

        return SiteBinding::query()
            ->where('target_type', $targetType)
            ->whereIn('target_id', $targetIds)
            ->when($exceptSiteId !== null, fn ($q) => $q->where('site_id', '!=', $exceptSiteId))
            ->selectRaw('target_id, COUNT(DISTINCT site_id) as c')
            ->groupBy('target_id')
            ->pluck('c', 'target_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** Human " · used by N app(s)" / " · unused" suffix for an attach-option label. */
    private function usageSuffix(int $consumers): string
    {
        return ' · '.($consumers > 0
            ? trans_choice('used by :count app|used by :count apps', $consumers, ['count' => $consumers])
            : __('unused'));
    }

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
        if (isset($this->reachableServerIdsByServer[(string) $server->id])) {
            return $this->reachableServerIdsByServer[(string) $server->id];
        }

        $ids = [(string) $server->id];

        if (blank($server->private_ip_address)) {
            return $this->reachableServerIdsByServer[(string) $server->id] = $ids; // No private interface → only its own (loopback) DBs.
        }

        $peers = Server::query()
            ->where('organization_id', $server->organization_id)
            ->whereKeyNot($server->id)
            ->whereNotNull('private_ip_address')
            ->get()
            ->filter(fn (Server $peer): bool => $this->sharePrivateNetwork($server, $peer))
            ->map(fn (Server $peer): string => (string) $peer->id)
            ->all();

        return $this->reachableServerIdsByServer[(string) $server->id] = array_values(array_unique([...$ids, ...$peers]));
    }

    /**
     * Servers whose databases this site may attach: every server in the org.
     *
     * Reachability and *offerability* are different questions, and conflating
     * them is what made "attach a database from another server" impossible
     * unless a PrivateNetwork row happened to exist. {@see reachableServerIds()}
     * answers "can these two talk over private IPs", which is the right input
     * to {@see effectiveDatabaseHost()} — it is not the right input to a
     * picker, because a database on an unlinked org server is perfectly
     * attachable over its public endpoint once remote access is open.
     *
     * Same posture as {@see attachableCacheServerIds()}, which has always
     * offered dedicated cache hosts org-wide and fallen back to a public IP.
     * The label on each option says which case it is, and host resolution
     * still prefers loopback, then a shared private IP, then the stored host —
     * widening the picker changes nothing about how a binding actually dials.
     *
     * @return list<string>
     */
    private function attachableDatabaseServerIds(Server $server): array
    {
        $key = (string) $server->id;
        if (isset($this->attachableDatabaseServerIdsByServer[$key])) {
            return $this->attachableDatabaseServerIdsByServer[$key];
        }

        $orgServerIds = Server::query()
            ->where('organization_id', $server->organization_id)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return $this->attachableDatabaseServerIdsByServer[$key] = array_values(array_unique([
            ...$this->reachableServerIds($server),
            ...$orgServerIds,
        ]));
    }

    /**
     * Servers whose Redis-family cache services this site can attach: the
     * private-network set, plus same-org dedicated cache hosts (redis/valkey
     * role or redis_server profile). Dedicated boxes exist to be shared even
     * when they aren't on a recorded VPC — the attach path uses their public
     * IP in that case.
     *
     * @return list<string>
     */
    private function attachableCacheServerIds(Server $server): array
    {
        $key = (string) $server->id;
        if (isset($this->attachableCacheServerIdsByServer[$key])) {
            return $this->attachableCacheServerIdsByServer[$key];
        }

        $dedicated = Server::query()
            ->where('organization_id', $server->organization_id)
            ->whereKeyNot($server->id)
            ->where(function ($q): void {
                $q->where('meta->server_role', 'redis')
                    ->orWhere('meta->server_role', 'valkey')
                    ->orWhere('meta->install_profile', 'redis_server');
            })
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return $this->attachableCacheServerIdsByServer[$key] = array_values(array_unique([
            ...$this->reachableServerIds($server),
            ...$dedicated,
        ]));
    }

    /**
     * Whether two servers sit on the same EXPLICIT private network and can reach
     * each other over their private IPs. True only when they're linked to the same
     * PrivateNetwork row, OR a PrivateNetwork in the org has a CIDR range covering
     * both private IPs.
     *
     * Deliberately does NOT fall back to a "same /24" heuristic: two servers
     * coincidentally sharing a /24 (e.g. unrelated boxes a provider happened to
     * place in 10.x) are NOT a private network the operator declared, and silently
     * treating them as reachable would let services that aren't really networked
     * together see each other. A resource is reachable privately only across a
     * modeled shared network; otherwise it must be reached publicly.
     *
     * Caveat: a provider VPC that dply hasn't recorded as a PrivateNetwork row
     * (e.g. a DigitalOcean VPC with no row + no private_network_id on the servers)
     * will NOT count here — record the network so its CIDR/link is known.
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

        foreach ($this->orgPrivateNetworks((string) $a->organization_id) as $net) {
            $cidr = (string) $net->ip_range;
            if ($cidr !== '' && $this->ipInCidr($aIp, $cidr) && $this->ipInCidr($bIp, $cidr)) {
                return true;
            }
        }

        return false;
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
}
