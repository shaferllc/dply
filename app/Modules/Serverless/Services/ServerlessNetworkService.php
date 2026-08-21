<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Services\Servers\ServerPrivateNetworkRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The network a serverless app's resources live in.
 *
 * dply's answer to Laravel Vapor's `network:` directive: a network houses the
 * app's database and the servers (worker hosts, a jump box) that need to reach
 * it over private networking instead of the public internet.
 *
 * The cache is deliberately not in scope: since docs/adr/dply-cache.md M4 a
 * function's cache is an org-owned ManagedCache shared across the org's apps,
 * so no single app's network attachment can govern where it sits.
 *
 * The network itself is the org-level {@see PrivateNetwork} row that BYO
 * servers already use — a DigitalOcean VPC — so a serverless app and the
 * droplets around it resolve to the SAME network and peer by FK, exactly as
 * {@see ServerPrivateNetworkRecorder} sets up for
 * servers. The attachment is stored on `site.meta.serverless.network_id`
 * alongside the other serverless resource state.
 *
 * What a network does NOT do: take the function itself off the public
 * internet. DigitalOcean Functions cannot join a VPC, so a managed cluster
 * keeps its public hostname and the function keeps using it. Attaching a
 * network places the cluster's PRIVATE interface, which is what makes the
 * cluster reachable (free of bandwidth charges) from droplets on the same VPC
 * and lockable to them via trusted sources.
 */
final class ServerlessNetworkService
{
    /**
     * Networks this app can attach to — the org's DigitalOcean VPCs in the
     * host's own region. A managed cluster can only join a VPC in its region,
     * so anything else would be an offer dply could not honour.
     *
     * @return Collection<int, PrivateNetwork>
     */
    public function available(Site $site): Collection
    {
        $region = $this->region($site);

        /** @var Collection<int, PrivateNetwork> $networks */
        $networks = PrivateNetwork::query()
            ->where('organization_id', $site->organization_id)
            ->where('provider', PrivateNetwork::PROVIDER_DO)
            ->when($region !== '', fn ($q) => $q->where(function ($q) use ($region): void {
                // Rows recorded from a droplet poll never got a zone written,
                // so an unknown zone stays selectable rather than disappearing.
                $q->where('network_zone', $region)->orWhereNull('network_zone');
            }))
            ->orderBy('name')
            ->get();

        return $networks;
    }

    /**
     * Pull the account's VPCs from DigitalOcean and record the ones in this
     * app's region as PrivateNetwork rows.
     *
     * An org that has only ever run serverless has no droplet, so nothing has
     * ever recorded a VPC for it — yet DigitalOcean gives every region a
     * default VPC. Without this the picker would be empty on a fresh account.
     *
     * @return int networks now available
     */
    public function sync(Site $site): int
    {
        $credential = $site->server?->providerCredential;
        $region = $this->region($site);

        if ($credential === null || $site->organization_id === null || $region === '') {
            return 0;
        }

        try {
            $vpcs = (new DigitalOceanService($credential))->listVpcs($region);
        } catch (\Throwable $e) {
            Log::warning('serverless.network.sync_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return $this->available($site)->count();
        }

        foreach ($vpcs as $vpc) {
            if ($vpc['id'] === '') {
                continue;
            }

            // Same identity triple as ServerPrivateNetworkRecorder — a VPC a
            // droplet already recorded must resolve to that same row, not a
            // duplicate the FK peering would then fail to match.
            $network = PrivateNetwork::query()->firstOrNew([
                'organization_id' => $site->organization_id,
                'provider' => PrivateNetwork::PROVIDER_DO,
                'provider_id' => $vpc['id'],
            ]);

            if (! $network->exists) {
                $network->name = $vpc['name'] !== '' ? $vpc['name'] : 'digitalocean-'.$vpc['id'];
                $network->provider_credential_id = $credential->id;
            }
            if ($vpc['ip_range'] !== '') {
                $network->ip_range = $vpc['ip_range'];
            }
            $network->network_zone = $vpc['region'];
            $network->save();
        }

        return $this->available($site)->count();
    }

    public function attached(Site $site): ?PrivateNetwork
    {
        $id = trim((string) ($site->serverlessConfig()['network_id'] ?? ''));

        if ($id === '') {
            return null;
        }

        return PrivateNetwork::query()
            ->where('organization_id', $site->organization_id)
            ->find($id);
    }

    /**
     * The VPC uuid to create this app's managed clusters in, or null when the
     * app is not attached to a network (DigitalOcean then picks the region's
     * default VPC, which is the behaviour dply had before networks existed).
     */
    public function vpcUuid(Site $site): ?string
    {
        $providerId = trim((string) $this->attached($site)?->provider_id);

        return $providerId !== '' ? $providerId : null;
    }

    public function attach(Site $site, PrivateNetwork $network): void
    {
        $this->write($site, $network->id);
    }

    public function detach(Site $site): void
    {
        $this->write($site, null);
    }

    /**
     * Servers on the network — the boxes that can reach this app's database
     * privately, and from which an operator can open a psql session.
     * Vapor provisions a dedicated jumpbox for this; dply reuses the servers
     * the org already has on the VPC.
     *
     * @return Collection<int, Server>
     */
    public function members(PrivateNetwork $network): Collection
    {
        /** @var Collection<int, Server> $servers */
        $servers = $network->servers()->orderBy('name')->get();

        return $servers;
    }

    /**
     * Whether the app's database was created before it joined this network.
     * DigitalOcean cannot move an existing cluster between VPCs, so the panel
     * has to say so rather than imply a retro-fit.
     */
    public function hasClustersOutsideNetwork(Site $site): bool
    {
        $config = $site->serverlessConfig();

        if ($this->vpcUuid($site) === null) {
            return false;
        }

        $database = is_array($config['database'] ?? null) ? $config['database'] : [];

        if (($database['status'] ?? '') === '' || empty($database['cluster_id'])) {
            return false;
        }

        return (string) ($database['network_id'] ?? '') !== (string) ($config['network_id'] ?? '');
    }

    private function region(Site $site): string
    {
        return trim((string) $site->server->region);
    }

    private function write(Site $site, ?string $networkId): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];

        if ($networkId === null) {
            unset($serverless['network_id']);
        } else {
            $serverless['network_id'] = $networkId;
        }

        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();
    }
}
