<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Models\Server;
use App\Models\ServerLogAgent;
use App\Models\ServerLogAggregator;
use App\Services\Servers\ManagedFirewallPort;
use Illuminate\Support\Facades\Log;

/**
 * Keep the aggregator's mTLS listener reachable by exactly the edges that ship
 * to it — and nobody else.
 *
 * The first cut of this opened port 6000 to the aggregator's own VPC range,
 * which is wrong for the product: dply Logs is a CUSTOMER-facing store, and a
 * customer's app server is routinely on a different VPC, a different provider,
 * or a different continent. VectorLogAgentInstallScripts already anticipates
 * that — resolveAggregatorTarget() hands the edge BOTH endpoints and the edge
 * probes the private one before falling back to the public. A VPC-scoped
 * firewall rule silently broke that fallback, so a cross-VPC edge shipped into
 * a closed port.
 *
 * So scope per edge instead of per network:
 *
 *   same VPC as the aggregator → its private IP /32   (traffic stays internal)
 *   anywhere else              → its public IP /32    (the documented fallback)
 *
 * Never 0.0.0.0/0. mTLS is the authentication boundary, but an unauthenticated
 * TCP listener open to the internet is still a surface worth not having, and
 * "allow the whole world" is not something an operator should get by default.
 *
 * Rules are a tagged group, so adding an edge adds one /32, removing one
 * revokes it, and nothing is orphaned. Re-run this after any change to the
 * agent fleet.
 */
final class LogAggregatorAccess
{
    /** Group tag for the per-edge rules on the aggregator's box. */
    public const FIREWALL_TAG = 'dply-logs-aggregator';

    public static function sync(ServerLogAggregator $aggregator, ManagedFirewallPort $ports): int
    {
        $server = $aggregator->server;

        if (! $server instanceof Server) {
            return 0;
        }

        $port = $aggregator->listen_port > 0 ? $aggregator->listen_port : 6000;

        $edges = ServerLogAgent::query()
            ->with('server')
            ->where('status', ServerLogAgent::STATUS_RUNNING)
            ->where('server_id', '!=', $server->id)
            ->get();

        $sources = [];
        $names = [];

        foreach ($edges as $edge) {
            $edgeServer = $edge->server;

            if (! $edgeServer instanceof Server) {
                continue;
            }

            $cidr = self::sourceFor($server, $edgeServer);

            if ($cidr === null) {
                Log::warning('LogAggregatorAccess: edge has no usable address; not opening the aggregator to it', [
                    'aggregator_id' => $aggregator->id,
                    'edge_server_id' => $edgeServer->id,
                ]);

                continue;
            }

            $sources[$edgeServer->id] = $cidr;
            $names[$edgeServer->id] = (string) $edgeServer->name;
        }

        if ($sources === []) {
            // No edges yet — leave the port closed rather than open it to a guess.
            $ports->closeAll($server, self::FIREWALL_TAG);
            $ports->close($server, self::FIREWALL_TAG);

            return 0;
        }

        // Drop the pre-per-edge broad rule if this box still carries one.
        $ports->closeUngrouped($server, self::FIREWALL_TAG);

        $ports->openGroup(
            server: $server,
            groupTag: self::FIREWALL_TAG,
            port: $port,
            sourcesByKey: $sources,
            nameFor: fn (string $key, string $cidr): string => sprintf(
                'dply Logs · edge · %s',
                $names[$key] ?? $cidr,
            ),
            extraTags: ['dply-logs'],
        );

        return count($sources);
    }

    /**
     * Which address the aggregator should admit for this edge.
     *
     * Private only when both boxes are demonstrably on the same network — the
     * same test the rest of the app uses (either network key, since
     * private_network_id is not backfilled everywhere). Otherwise the edge dials
     * from its public address, so that is what has to be allowed.
     */
    private static function sourceFor(Server $aggregatorServer, Server $edge): ?string
    {
        $sameNetwork = filled($edge->private_ip_address) && (
            (filled($aggregatorServer->private_network_id)
                && $edge->private_network_id === $aggregatorServer->private_network_id)
            || (filled($aggregatorServer->hetzner_network_id)
                && (string) $edge->hetzner_network_id === (string) $aggregatorServer->hetzner_network_id)
        );

        if ($sameNetwork) {
            return trim((string) $edge->private_ip_address).'/32';
        }

        $public = trim((string) ($edge->ip_address ?? ''));

        return $public === '' ? null : $public.'/32';
    }
}
