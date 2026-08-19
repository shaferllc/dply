<?php

namespace App\Services\WorkerPools;

use App\Enums\ServerProvider;
use App\Jobs\ReconcileWorkerPoolJob;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\ProvisionAzureServerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionOracleServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Models\ProviderCredential;
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

    /**
     * First worker for a site-sourced pool: same provider as the app server,
     * worker install profile, optional smaller size. Same-region workers join
     * the app VPC; another region is for managed Redis/DB over public hostnames.
     */
    public function provisionWorkerFromApp(WorkerPool $pool, Server $app, string $size = '', string $region = ''): Server
    {
        $size = trim($size) !== '' ? trim($size) : (string) $app->size;
        $region = trim($region) !== '' ? trim($region) : (string) $app->region;
        $sameRegion = $region === (string) $app->region;
        $name = $pool->servers()->exists()
            ? $this->nextName($pool)
            : $this->firstSiteWorkerName($app);

        $meta = $this->cloneableMeta($app);
        unset($meta['database'], $meta['cache_service']);
        $meta['server_role'] = 'worker';
        $meta['install_profile'] = 'queue_worker';
        $meta['cloned_from_server_id'] = (string) $app->id;
        $meta['cloned_at'] = now()->toIso8601String();
        $meta['pool'] = ['state' => WorkerPool::MEMBER_PROVISIONING];
        $meta['site_sourced_fleet'] = true;
        if (! $sameRegion) {
            $meta['cross_region'] = true;
            $meta['placement'] = [
                'region' => $region,
                'source_region' => (string) $app->region,
                'provider' => $app->provider->value,
            ];
        }

        $worker = Server::query()->create([
            'user_id' => $app->user_id,
            'organization_id' => $app->organization_id,
            'worker_pool_id' => $pool->id,
            'pool_role' => WorkerPool::ROLE_PRIMARY,
            'name' => $name,
            'provider' => $app->provider,
            'hosting_backend' => $app->hosting_backend,
            'provider_credential_id' => $this->preferredProviderCredentialId($app),
            'region' => $region,
            'size' => $size,
            'hetzner_network_id' => $sameRegion ? $app->hetzner_network_id : null,
            'private_network_id' => $sameRegion ? $app->private_network_id : null,
            'ssh_port' => $app->ssh_port,
            'ssh_user' => $app->ssh_user,
            'setup_script_key' => $app->setup_script_key,
            'meta' => $meta,
            'status' => Server::STATUS_PENDING,
        ]);

        $this->dispatchProvisioning($worker);

        return $worker;
    }

    /**
     * Re-run cloud create for a worker that failed before a provider instance
     * existed (e.g. DigitalOcean rejected the API token while adding the SSH key).
     */
    public function retryCloudProvision(Server $server): void
    {
        if ($server->status !== Server::STATUS_ERROR || filled($server->provider_id)) {
            throw new RuntimeException(__('This worker cannot be retried — it already exists at the provider, or is not in a failed cloud-provision state.'));
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        unset($meta['provision_error'], $meta['auto_retry_at'], $meta['auto_retry_attempt'], $meta['auto_retry_max']);
        $meta['pool'] = array_merge(is_array($meta['pool'] ?? null) ? $meta['pool'] : [], [
            'state' => WorkerPool::MEMBER_PROVISIONING,
            'state_since' => now()->toIso8601String(),
        ]);

        $originId = data_get($meta, 'cloned_from_server_id');
        $origin = filled($originId) ? Server::query()->find($originId) : null;

        $server->forceFill([
            'status' => Server::STATUS_PENDING,
            'provider_credential_id' => $this->preferredProviderCredentialId($origin ?? $server),
            'meta' => $meta,
        ])->save();

        $this->dispatchProvisioning($server->fresh() ?? $server);

        if (filled($server->worker_pool_id)) {
            ReconcileWorkerPoolJob::dispatch((string) $server->worker_pool_id);
        }
    }

    /**
     * Adding a token on Credentials creates a new row. Existing servers keep
     * the credential they were created with, so a worker cloned from the app
     * would otherwise retry with the dead token.
     */
    private function preferredProviderCredentialId(Server $source): ?string
    {
        $provider = $source->provider->value;
        $newest = ProviderCredential::query()
            ->where('organization_id', $source->organization_id)
            ->where('provider', $provider)
            ->orderByDesc('created_at')
            ->value('id');

        return filled($newest) ? (string) $newest : $source->provider_credential_id;
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
            ServerProvider::Linode => ProvisionLinodeServerJob::dispatch($clone),
            ServerProvider::Vultr => ProvisionVultrServerJob::dispatch($clone),
            ServerProvider::UpCloud => ProvisionUpCloudServerJob::dispatch($clone),
            ServerProvider::Aws => ProvisionAwsEc2ServerJob::dispatch($clone),
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
            ServerProvider::Vultr,
            ServerProvider::UpCloud,
            ServerProvider::Aws, ServerProvider::Azure, ServerProvider::Oracle,
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

    private function firstSiteWorkerName(Server $app): string
    {
        $stem = trim((string) preg_replace('/-\d+$/', '', (string) $app->name));
        $base = $stem !== '' ? $stem : 'worker';
        $n = 1;
        $name = $base.'-worker-'.$n;
        while (Server::query()->where('organization_id', $app->organization_id)->where('name', $name)->exists()) {
            $n++;
            $name = $base.'-worker-'.$n;
        }

        return $name;
    }

    private function nextName(WorkerPool $pool): string
    {
        // Name replicas off the PRIMARY server, not the pool name — so a pool
        // led by "worker-1" yields "worker-2", "worker-3", … rather than
        // "worker-1-pool-2". Strip any trailing "-<number>" from the primary's
        // name to get the stem, then take the next free integer suffix across
        // all current member names (so it never collides and continues the
        // primary's numbering, e.g. worker-1 → worker-2).
        $primary = $pool->primaryServer ?? $pool->sourceServer;
        $primaryName = trim((string) ($primary->name ?? ''));
        $base = $primaryName !== ''
            ? (string) preg_replace('/-\d+$/', '', $primaryName)
            : (Str::of($pool->name)->slug()->value() ?: 'worker');
        $base = $base !== '' ? $base : 'worker';

        $existing = Server::query()->where('worker_pool_id', $pool->id)->pluck('name');

        // Start after the primary's own number when it has one (worker-1 → 2),
        // otherwise after the current member count.
        $primarySuffix = ($primaryName !== '' && preg_match('/-(\d+)$/', $primaryName, $m))
            ? (int) $m[1]
            : $existing->count();
        $n = max($primarySuffix + 1, 2);

        $name = $base.'-'.$n;
        while ($existing->contains($name)) {
            $n++;
            $name = $base.'-'.$n;
        }

        return $name;
    }
}
