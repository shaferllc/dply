<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServerProvider;
use App\Models\PrivateNetwork;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Services\Servers\ServerPrivateNetworkRecorder;
use App\Modules\Cloud\Services\VultrService;
use Illuminate\Console\Command;

/**
 * Records the provider VPC of EXISTING DigitalOcean / Vultr servers as a
 * {@see PrivateNetwork} and links them via `private_network_id`. New servers get
 * this at poll time (PollDropletIpJob / PollVultrIpJob); this backfills the ones
 * that were created before that wiring existed, so same-VPC peers reach each
 * other again after private-network reachability was tightened to explicit
 * networks only.
 *
 * Hetzner already records its networks through its own flow; Linode is skipped on
 * purpose — its legacy private range is datacenter-shared, not an isolated VPC.
 */
class BackfillPrivateNetworksCommand extends Command
{
    protected $signature = 'dply:backfill-private-networks
        {--provider= : Limit to a single provider (digitalocean|vultr)}
        {--force : Re-resolve even servers that already have a private_network_id}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Record existing DigitalOcean/Vultr servers\' VPCs as PrivateNetworks and link them.';

    public function handle(ServerPrivateNetworkRecorder $recorder): int
    {
        $providerOpt = (string) ($this->option('provider') ?? '');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $supported = [ServerProvider::DigitalOcean->value, ServerProvider::Vultr->value];
        if ($providerOpt !== '' && ! in_array($providerOpt, $supported, true)) {
            $this->error('Unsupported provider. Use one of: '.implode(', ', $supported));

            return self::FAILURE;
        }

        $servers = Server::query()
            ->whereIn('provider', $providerOpt !== '' ? [$providerOpt] : $supported)
            ->whereNotNull('provider_id')
            ->where('provider_id', '!=', '')
            ->when(! $force, fn ($q) => $q->whereNull('private_network_id'))
            ->with('providerCredential')
            ->get();

        if ($servers->isEmpty()) {
            $this->info('No matching servers to backfill.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%s%d server(s) to inspect.', $dryRun ? '[dry-run] ' : '', $servers->count()));
        $recorded = 0;
        $skipped = 0;

        foreach ($servers as $server) {
            $label = sprintf('%s (%s)', $server->name, $server->provider->value);

            $credential = $server->providerCredential;
            if ($credential === null) {
                $this->warn("  · $label — no provider credential, skipped.");
                $skipped++;

                continue;
            }

            try {
                $resolved = match ($server->provider) {
                    ServerProvider::DigitalOcean => $this->resolveDigitalOcean($credential, (int) $server->provider_id),
                    ServerProvider::Vultr => $this->resolveVultr($credential, (string) $server->provider_id),
                    default => null,
                };
            } catch (\Throwable $e) {
                $this->warn("  · $label — API error: ".$e->getMessage());
                $skipped++;

                continue;
            }

            if ($resolved === null) {
                $this->line("  · $label — no VPC attached, skipped.");
                $skipped++;

                continue;
            }

            [$provider, $vpcId, $ipRange, $name] = $resolved;

            if ($dryRun) {
                $this->line("  · $label → would record $provider VPC $vpcId".($ipRange ? " ($ipRange)" : ''));
                $recorded++;

                continue;
            }

            $network = $recorder->record($server, $provider, $vpcId, $ipRange, $name);
            if ($network !== null) {
                $this->line("  · $label → ".$network->name.' ['.$network->id.']');
                $recorded++;
            } else {
                $skipped++;
            }
        }

        $this->info(sprintf('%sDone — %d recorded, %d skipped.', $dryRun ? '[dry-run] ' : '', $recorded, $skipped));

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string, 3: ?string}|null [provider, vpcId, ipRange, name]
     */
    private function resolveDigitalOcean(ProviderCredential $credential, int $dropletId): ?array
    {
        $do = new DigitalOceanService($credential);
        $droplet = $do->getDroplet($dropletId);
        $vpcUuid = DigitalOceanService::getDropletVpcUuid($droplet);
        if ($vpcUuid === null) {
            return null;
        }

        $ipRange = null;
        $name = null;
        try {
            foreach ($do->listVpcs() as $vpc) {
                if ($vpc['id'] === $vpcUuid) {
                    $ipRange = $vpc['ip_range'] !== '' ? $vpc['ip_range'] : null;
                    $name = $vpc['name'] !== '' ? $vpc['name'] : null;
                    break;
                }
            }
        } catch (\Throwable) {
            // best-effort CIDR; recording by UUID still enables FK-match peering
        }

        return [PrivateNetwork::PROVIDER_DO, $vpcUuid, $ipRange, $name];
    }

    /**
     * @return array{0: string, 1: string, 2: ?string, 3: ?string}|null
     */
    private function resolveVultr(ProviderCredential $credential, string $instanceId): ?array
    {
        $vultr = new VultrService($credential);
        $instance = $vultr->getInstance($instanceId);
        $vpcId = VultrService::getInstanceVpcId($instance);
        if ($vpcId === null) {
            return null;
        }

        return [PrivateNetwork::PROVIDER_VULTR, $vpcId, VultrService::getInstanceVpcRange($instance), null];
    }
}
