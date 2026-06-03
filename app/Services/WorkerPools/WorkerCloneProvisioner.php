<?php

namespace App\Services\WorkerPools;

use App\Enums\ServerProvider;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
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

    public function provisionReplica(WorkerPool $pool, Server $source): Server
    {
        $name = $this->nextName($pool);

        $meta = $this->cloneableMeta($source);
        $meta['cloned_from_server_id'] = (string) $source->id;
        $meta['cloned_at'] = now()->toIso8601String();
        $meta['pool'] = ['state' => WorkerPool::MEMBER_PROVISIONING];

        $clone = Server::query()->create([
            'user_id' => $source->user_id,
            'organization_id' => $source->organization_id,
            'worker_pool_id' => $pool->id,
            'pool_role' => WorkerPool::ROLE_REPLICA,
            'name' => $name,
            'provider' => $source->provider,
            'hosting_backend' => $source->hosting_backend,
            'provider_credential_id' => $source->provider_credential_id,
            'region' => $source->region,
            'size' => $source->size,
            // Same private network so replicated env (private IPs) resolves.
            'hetzner_network_id' => $source->hetzner_network_id,
            'private_network_id' => $source->private_network_id,
            'ssh_port' => $source->ssh_port,
            'ssh_user' => $source->ssh_user,
            'setup_script_key' => $source->setup_script_key,
            'meta' => $meta,
            'status' => Server::STATUS_PENDING,
        ]);

        $this->dispatchProvisioning($clone);

        return $clone;
    }

    private function dispatchProvisioning(Server $clone): void
    {
        match ($clone->provider) {
            ServerProvider::Hetzner => ProvisionHetznerServerJob::dispatch($clone),
            ServerProvider::DigitalOcean => ProvisionDigitalOceanDropletJob::dispatch($clone),
            default => throw new RuntimeException(__('Cloning :provider workers is not supported yet.', [
                'provider' => $clone->provider->value,
            ])),
        };
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
