<?php

namespace App\Services\WorkerPools;

use App\Enums\ServerProvider;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\ProvisionAzureServerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionEquinixMetalServerJob;
use App\Jobs\ProvisionFlyIoServerJob;
use App\Jobs\ProvisionGcpServerJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionOracleServerJob;
use App\Jobs\ProvisionScalewayServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Models\Server;
use App\Models\WorkerPool;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Provisions a fresh replica worker by cloning a source worker's placement and
 * intent (provider, credential, region, size, private network, install profile)
 * and dispatching the provider's normal provisioning job. The provider job
 * generates its own SSH keys and creates the box — we never copy the source's
 * identity.
 *
 * v1 is same-region/same-provider only (the clone joins the source's private
 * network so replicated env resolves). Cross-region (public endpoint + host
 * rewrite + allowlist) is Phase 2.
 */
class WorkerCloneProvisioner
{
    /** Intent keys copied from the source's meta (state/probe keys excluded). */
    private const KEEP_META = [
        'install_profile',
        'server_role',
        'cache_service',
        'webserver',
        'php_version',
        'database',
        'os_image',
        'host_kind',
        'preset_key',
        'runtime_defaults',
        'default_php_version',
        'manage_auto_updates_interval',
        'digitalocean',
        'tags',
    ];

    /**
     * @param  array{region?: string, size?: string, provider?: string, provider_credential_id?: string}  $placement
     *                                                                                                                When `region` (or `provider`) differs from the source's, the clone
     *                                                                                                                is treated as cross-region: it does NOT join the source's private
     *                                                                                                                network, and the replayer rewrites its env to the backends'
     *                                                                                                                public addresses.
     */
    public function provisionReplica(WorkerPool $pool, Server $source, array $placement = []): Server
    {
        $name = $this->nextName($pool);

        $provider = $this->resolveProvider($placement['provider'] ?? null, $source);
        $crossProvider = $provider !== $source->provider;
        $region = trim((string) ($placement['region'] ?? '')) ?: (string) $source->region;
        $size = trim((string) ($placement['size'] ?? '')) ?: (string) $source->size;
        $credentialId = trim((string) ($placement['provider_credential_id'] ?? '')) ?: ($crossProvider ? null : $source->provider_credential_id);
        $crossRegion = $crossProvider || $region !== (string) $source->region;

        if ($crossProvider && $credentialId === null) {
            throw new RuntimeException(__('Choose a provider credential for the new provider.'));
        }

        $meta = $this->cloneableMeta($source);
        // A different provider can't use the source's OS image / DO-specific opts.
        if ($crossProvider) {
            unset($meta['os_image'], $meta['digitalocean']);
        }
        $meta['cloned_from_server_id'] = (string) $source->id;
        $meta['cloned_at'] = now()->toIso8601String();
        $meta['pool'] = ['state' => WorkerPool::MEMBER_PROVISIONING];
        if ($crossRegion) {
            $meta['cross_region'] = true;
            $meta['placement'] = ['region' => $region, 'source_region' => (string) $source->region, 'provider' => $provider->value];
        }

        $clone = Server::query()->create([
            'user_id' => $source->user_id,
            'organization_id' => $source->organization_id,
            'worker_pool_id' => $pool->id,
            'pool_role' => WorkerPool::ROLE_REPLICA,
            'name' => $name,
            'provider' => $provider,
            'hosting_backend' => $crossProvider ? Server::HOSTING_BACKEND_BYO : $source->hosting_backend,
            'provider_credential_id' => $credentialId,
            'region' => $region,
            'size' => $size,
            // Same-region clones join the source's private network so replicated
            // env (private IPs) resolves. Cross-region clones get no private net;
            // the replayer rewrites their env to public backend addresses.
            'hetzner_network_id' => $crossRegion ? null : $source->hetzner_network_id,
            'private_network_id' => $crossRegion ? null : $source->private_network_id,
            'ssh_port' => $source->ssh_port,
            'ssh_user' => $source->ssh_user,
            'setup_script_key' => $source->setup_script_key,
            'meta' => $meta,
            'status' => Server::STATUS_PENDING,
        ]);

        $this->dispatchProvisioning($clone);

        return $clone;
    }

    private function resolveProvider(?string $provider, Server $source): ServerProvider
    {
        $provider = trim((string) $provider);
        if ($provider === '') {
            return $source->provider;
        }

        return ServerProvider::tryFrom($provider)
            ?? throw new RuntimeException(__('Unknown provider :p.', ['p' => $provider]));
    }

    private function dispatchProvisioning(Server $clone): void
    {
        match ($clone->provider) {
            ServerProvider::Hetzner => ProvisionHetznerServerJob::dispatch($clone),
            ServerProvider::DigitalOcean => ProvisionDigitalOceanDropletJob::dispatch($clone),
            ServerProvider::Linode, ServerProvider::Akamai => ProvisionLinodeServerJob::dispatch($clone),
            ServerProvider::Vultr => ProvisionVultrServerJob::dispatch($clone),
            ServerProvider::Scaleway => ProvisionScalewayServerJob::dispatch($clone),
            ServerProvider::UpCloud => ProvisionUpCloudServerJob::dispatch($clone),
            ServerProvider::EquinixMetal => ProvisionEquinixMetalServerJob::dispatch($clone),
            ServerProvider::FlyIo => ProvisionFlyIoServerJob::dispatch($clone),
            ServerProvider::Aws => ProvisionAwsEc2ServerJob::dispatch($clone),
            ServerProvider::Gcp => ProvisionGcpServerJob::dispatch($clone),
            ServerProvider::Azure => ProvisionAzureServerJob::dispatch($clone),
            ServerProvider::Oracle => ProvisionOracleServerJob::dispatch($clone),
            default => throw new RuntimeException(__('Cloning :provider workers is not supported yet.', [
                'provider' => $clone->provider->value,
            ])),
        };
    }

    /** Providers a worker can be cloned onto (have a VM provisioning job). */
    public static function supportedProviders(): array
    {
        return [
            ServerProvider::Hetzner, ServerProvider::DigitalOcean, ServerProvider::Linode,
            ServerProvider::Akamai, ServerProvider::Vultr, ServerProvider::Scaleway,
            ServerProvider::UpCloud, ServerProvider::EquinixMetal, ServerProvider::FlyIo,
            ServerProvider::Aws, ServerProvider::Gcp, ServerProvider::Azure, ServerProvider::Oracle,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cloneableMeta(Server $source): array
    {
        $sourceMeta = is_array($source->meta) ? $source->meta : [];
        $cloned = [];
        foreach (self::KEEP_META as $key) {
            if (array_key_exists($key, $sourceMeta)) {
                $cloned[$key] = $sourceMeta[$key];
            }
        }

        return $cloned;
    }

    private function nextName(WorkerPool $pool): string
    {
        $base = Str::of($pool->name)->slug()->value() ?: 'worker';
        // Find the highest numeric suffix already used in the pool.
        $existing = Server::query()->where('worker_pool_id', $pool->id)->pluck('name');
        $n = $existing->count() + 1;
        $name = $base.'-'.$n;
        while ($existing->contains($name)) {
            $n++;
            $name = $base.'-'.$n;
        }

        return $name;
    }
}
