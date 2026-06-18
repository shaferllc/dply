<?php

declare(strict_types=1);

namespace App\Services\Sites\Backends;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBackend;
use App\Services\Servers\ServerProvisioningDispatcher;
use App\Services\WorkerPools\WorkerCloneProvisioner;
use RuntimeException;

/**
 * Stands up a fresh app server to serve as an additional backend for a
 * multi-backend site, by cloning the site's primary server's placement and
 * intent (provider, credential, region, size, private network, install profile)
 * and dispatching the provider's normal provisioning job. Mirrors
 * {@see WorkerCloneProvisioner} but tags the box as a
 * WEB backend (meta['site_backend']) rather than a worker-pool member, and
 * records a {@see SiteBackend} row instead of a pool membership.
 *
 * v1 is same-region/same-provider (the backend joins the source's private
 * network so replicated env resolves). See docs/MULTI_BACKEND_SITES.md.
 */
class SiteBackendProvisioner
{
    public function __construct(
        private readonly ServerProvisioningDispatcher $provisioning,
    ) {}

    /** Intent keys copied from the source server's meta (state/probe keys excluded). */
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
     */
    public function provision(Site $site, array $placement = []): SiteBackend
    {
        $source = $site->server;
        if ($source === null) {
            throw new RuntimeException(__('This site has no server to clone a backend from.'));
        }

        $provider = $this->resolveProvider($placement['provider'] ?? null, $source);
        $crossProvider = $provider !== $source->provider;
        $region = trim((string) ($placement['region'] ?? '')) ?: (string) $source->region;
        $size = trim((string) ($placement['size'] ?? '')) ?: (string) $source->size;
        $credentialId = trim((string) ($placement['provider_credential_id'] ?? ''))
            ?: ($crossProvider ? null : $source->provider_credential_id);
        $crossRegion = $crossProvider || $region !== (string) $source->region;

        if ($crossProvider && $credentialId === null) {
            throw new RuntimeException(__('Choose a provider credential for the new provider.'));
        }

        $meta = $this->cloneableMeta($source);
        if ($crossProvider) {
            unset($meta['os_image'], $meta['digitalocean']);
        }
        $meta['cloned_from_server_id'] = (string) $source->id;
        $meta['cloned_at'] = now()->toIso8601String();
        // Tag the box so it's discoverable as a web backend host (not a pool member).
        $meta['site_backend'] = ['site_id' => (string) $site->id];
        if ($crossRegion) {
            $meta['cross_region'] = true;
            $meta['placement'] = [
                'region' => $region,
                'source_region' => (string) $source->region,
                'provider' => $provider->value,
            ];
        }

        $server = Server::query()->create([
            'user_id' => $source->user_id,
            'organization_id' => $source->organization_id,
            'name' => $this->nextName($site, $source),
            'provider' => $provider,
            'hosting_backend' => $crossProvider ? Server::HOSTING_BACKEND_BYO : $source->hosting_backend,
            'provider_credential_id' => $credentialId,
            'region' => $region,
            'size' => $size,
            'hetzner_network_id' => $crossRegion ? null : $source->hetzner_network_id,
            'private_network_id' => $crossRegion ? null : $source->private_network_id,
            'ssh_port' => $source->ssh_port,
            'ssh_user' => $source->ssh_user,
            'setup_script_key' => $source->setup_script_key,
            'meta' => $meta,
            'status' => Server::STATUS_PENDING,
        ]);

        $backend = SiteBackend::query()->create([
            'site_id' => $site->id,
            'server_id' => $server->id,
            'role' => SiteBackend::ROLE_REPLICA,
            'state' => SiteBackend::STATE_PROVISIONING,
            'weight' => 100,
        ]);

        $this->provisioning->dispatch($server);

        return $backend;
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

    /**
     * Name backends off the primary server's stem: "app-1" → "app-1-be-2",
     * "app-1-be-3", … deduped against the site's existing backend server names.
     */
    private function nextName(Site $site, Server $source): string
    {
        $stem = (string) preg_replace('/-be-\d+$/', '', (string) $source->name);
        $stem = $stem !== '' ? $stem : 'backend';

        $existing = $site->backends()
            ->with('server:id,name')
            ->get()
            ->map(fn (SiteBackend $b): string => (string) ($b->server->name ?? ''))
            ->filter()
            ->values();

        $n = max(2, $existing->count() + 1);
        $name = $stem.'-be-'.$n;
        while ($existing->contains($name)) {
            $n++;
            $name = $stem.'-be-'.$n;
        }

        return $name;
    }
}
