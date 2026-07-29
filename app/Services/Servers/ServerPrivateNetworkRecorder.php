<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Modules\Deploy\Services\Concerns\ResolvesReachableResources;

/**
 * Records the provider VPC / private network a server sits in as a
 * {@see PrivateNetwork} row and links the server to it via `private_network_id`.
 *
 * This is what makes cross-box private reachability work: two servers in the
 * SAME provider VPC resolve to the SAME PrivateNetwork row (keyed on
 * organization + provider + the provider's VPC id), so
 * {@see ResolvesReachableResources::sharePrivateNetwork()}
 * matches them by FK. The CIDR `ip_range` is a bonus (it powers the
 * range-membership fallback) — the VPC-id identity is the load-bearing part, so
 * recording works even when a provider doesn't hand back a clean CIDR.
 *
 * Idempotent: the poll jobs call it every time a server is (re)observed ready.
 */
final class ServerPrivateNetworkRecorder
{
    public function record(
        Server $server,
        string $provider,
        string $vpcId,
        ?string $ipRange = null,
        ?string $name = null,
        ?string $networkZone = null,
    ): ?PrivateNetwork {
        $vpcId = trim($vpcId);
        if ($vpcId === '' || $server->organization_id === null) {
            return null;
        }

        // Keyed on (org, provider, vpc id). NB: there is no unique DB constraint
        // on this triple yet, so two servers in the same brand-new VPC polled at
        // the exact same instant could create duplicate rows (→ a split FK match).
        // Provisions are effectively sequential per VPC in practice; a unique
        // index on (organization_id, provider, provider_id) is the proper backstop.
        $network = PrivateNetwork::query()->firstOrNew([
            'organization_id' => $server->organization_id,
            'provider' => $provider,
            'provider_id' => $vpcId,
        ]);

        if (! $network->exists) {
            $network->name = ($name !== null && trim($name) !== '') ? trim($name) : $provider.'-'.$vpcId;
            $network->provider_credential_id = $server->provider_credential_id;
        }
        if ($ipRange !== null && trim($ipRange) !== '') {
            $network->ip_range = trim($ipRange);
        }
        if ($networkZone !== null && trim($networkZone) !== '' && (string) $network->network_zone === '') {
            $network->network_zone = trim($networkZone);
        }
        $network->save();

        if ((string) $server->private_network_id !== (string) $network->id) {
            $server->forceFill(['private_network_id' => $network->id])->save();
        }

        return $network;
    }
}
