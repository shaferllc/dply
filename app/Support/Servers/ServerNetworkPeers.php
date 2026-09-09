<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * "What can THIS server reach over private networking, right now?"
 *
 * Extracted from WorkspaceNetworking::render(), which had the only correct copy
 * of this rule, so the databases workspace can offer the same list when picking
 * which servers to open an engine to. Getting it wrong here is worse than in the
 * map: the map merely displays a bad address, whereas the picker would open a
 * firewall port to a host that cannot reach the box anyway.
 *
 * Deliberately NOT "every server in the org". A DigitalOcean box on
 * 10.136.0.2/nyc3 and a Hetzner box on 10.0.0.x/fsn1 do not share a fabric, and
 * a server with no private address is not reachable at all.
 *
 * Identity matches on EITHER network key: private_network_id is the canonical
 * FK but is only backfilled on some rows, so keying on it alone drops real
 * peers. Compared per-column rather than with a combined whereIn so a numeric
 * Hetzner id can never collide with a ULID private-network id.
 */
final class ServerNetworkPeers
{
    /**
     * Peer servers sharing this server's private network, each with a private IP.
     *
     * @return Collection<int, Server>
     */
    public static function for(Server $server): Collection
    {
        $privateNetworkId = $server->private_network_id;
        $hetznerNetworkId = $server->hetzner_network_id;

        if (blank($privateNetworkId) && blank($hetznerNetworkId)) {
            return collect();
        }

        return Server::query()
            ->where('organization_id', $server->organization_id)
            ->where('id', '!=', $server->id)
            ->where('status', Server::STATUS_READY)
            // Synthetic Edge / Cloud / Serverless hosts are not machines on a
            // network — they exist so a Site has something to hang off. Keying on
            // provider misses them (an Edge host is provider=digitalocean with
            // host_kind=dply_edge_delivery), so key on host_kind.
            ->where(function ($q): void {
                $q->whereNull('meta->host_kind')
                    ->orWhere('meta->host_kind', Server::HOST_KIND_VM);
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (Server $peer): bool => filled($peer->private_ip_address)
                && ((filled($privateNetworkId) && $peer->private_network_id === $privateNetworkId)
                    || (filled($hetznerNetworkId) && (string) $peer->hetzner_network_id === (string) $hetznerNetworkId)))
            ->values();
    }

    /**
     * The /32 source a firewall rule should use to admit `$peer`.
     * Host-scoped on purpose: opening a port to one server should not admit
     * every other host that happens to share its subnet.
     */
    public static function hostCidr(Server $peer): ?string
    {
        $ip = trim((string) ($peer->private_ip_address ?? ''));

        return $ip === '' ? null : $ip.'/32';
    }
}
