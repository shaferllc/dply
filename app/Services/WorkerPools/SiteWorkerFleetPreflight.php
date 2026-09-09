<?php

declare(strict_types=1);

namespace App\Services\WorkerPools;

use App\Models\CloudDatabase;
use App\Models\PrivateNetwork;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\Providers\ProviderCredentialHealth;
use App\Services\Sites\DotEnvFileParser;
use App\Support\Providers\ProviderAuthFailure;
use App\Support\Sites\SiteWorkerFleetPreflightResult;

/**
 * Site-sourced workers copy env as-is. VM Redis/DB must share the web box VPC.
 * Managed clusters (DigitalOcean, Neon, …) do not — those workers may run in
 * another region and reach the cluster over its public hostname.
 */
class SiteWorkerFleetPreflight
{
    /** @var list<string> */
    private const REDIS_HOST_KEYS = [
        'REDIS_HOST',
        'CACHE_REDIS_HOST',
        'QUEUE_REDIS_HOST',
        'SESSION_REDIS_HOST',
    ];

    /** @var list<string> */
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', '0.0.0.0'];

    /** @var list<string> */
    private const MANAGED_HOST_SUFFIXES = [
        '.ondigitalocean.com',
        '.neon.tech',
        '.supabase.co',
        '.upstash.io',
        '.rds.amazonaws.com',
        '.planetscale.com',
    ];

    public function __construct(
        private readonly DotEnvFileParser $envParser,
        private readonly ProviderCredentialHealth $credentialHealth,
    ) {}

    public function evaluate(Site $site): SiteWorkerFleetPreflightResult
    {
        $app = $site->server;
        if (! $app instanceof Server) {
            return new SiteWorkerFleetPreflightResult(false, __('This site has no server to place workers next to.'));
        }

        $backends = $this->resolveBackends($site, $app);
        if ($backends === []) {
            return new SiteWorkerFleetPreflightResult(
                false,
                __('Couldn’t find Redis or a database. Workers on another VM cannot use localhost services on this web box.'),
            );
        }

        $network = $app->private_network_id
            ? PrivateNetwork::query()->find($app->private_network_id)
            : null;
        $networkName = $network instanceof PrivateNetwork
            ? $network->name
            : (filled($app->hetzner_network_id) ? __('Hetzner network :id', ['id' => $app->hetzner_network_id]) : null);

        $usesManaged = false;
        $hasVmBackend = false;
        $rows = [];
        foreach ($backends as $backend) {
            if ($backend['local']) {
                return new SiteWorkerFleetPreflightResult(
                    false,
                    __(':name is on this web box (localhost). Move Redis and the database onto a managed cluster or a shared private network before adding a worker.', [
                        'name' => $backend['name'],
                    ]),
                    $this->backendRows($backends),
                    $networkName,
                );
            }

            $managed = (bool) ($backend['managed'] ?? false);
            $usesManaged = $usesManaged || $managed;
            $peer = $backend['server'];
            if ($peer instanceof Server && ! $managed) {
                $hasVmBackend = true;
                if (blank($app->private_network_id) && blank($app->hetzner_network_id)) {
                    return new SiteWorkerFleetPreflightResult(
                        false,
                        __('This site’s server is not on a private network. Workers must join the same VPC as Redis and the database.'),
                        $this->backendRows($backends),
                    );
                }
                if (! $this->shareNetwork($app, $peer)) {
                    return new SiteWorkerFleetPreflightResult(
                        false,
                        __(':name is not on the same private network as this site. Workers copy env as-is and will not reach it.', [
                            'name' => $backend['name'],
                        ]),
                        $this->backendRows($backends),
                        $networkName,
                    );
                }
            }

            $rows[] = [
                'id' => $backend['id'],
                'name' => $backend['name'],
                'role' => $backend['role'],
            ];
        }

        $allowsRemoteRegion = $usesManaged && ! $hasVmBackend;

        return $this->withCredentialHealth($app, new SiteWorkerFleetPreflightResult(
            true,
            $allowsRemoteRegion
                ? __('This site uses managed Redis/database. The worker can stay in this region or run in another — we’ll allow its public IP on the cluster.')
                : __('This site, Redis, and the database share :network. The worker will join that network and deploy this release.', [
                    'network' => $networkName ?: __('this private network'),
                ]),
            $rows,
            $networkName,
            $allowsRemoteRegion,
        ));
    }

    private function withCredentialHealth(Server $app, SiteWorkerFleetPreflightResult $result): SiteWorkerFleetPreflightResult
    {
        $credential = ProviderCredential::preferredForServer($app);
        if (! $credential instanceof ProviderCredential) {
            return $result;
        }

        if ($this->credentialHealth->refresh($credential) !== false) {
            return $result;
        }

        return new SiteWorkerFleetPreflightResult(
            false,
            ProviderAuthFailure::message($app->provider->value),
            $result->backends,
            $result->networkName,
            $result->allowsRemoteRegion,
        );
    }

    /**
     * @return list<array{id: string, name: string, role: string, local: bool, server: ?Server, managed?: bool}>
     */
    private function resolveBackends(Site $site, Server $app): array
    {
        $found = [];

        $site->loadMissing(['bindings', 'serverDatabases.server']);

        foreach ($site->bindings as $binding) {
            if ($this->absorbManagedBinding($found, $binding)) {
                continue;
            }

            $resolved = $this->serverFromBinding($binding);
            if ($resolved === null) {
                continue;
            }

            $role = in_array($binding->type, ['database'], true) ? 'database' : 'redis';
            $found[$resolved->id] = $this->fromServer($resolved, $role, $app);
        }

        foreach ($site->serverDatabases as $database) {
            $host = $database->server;
            if (! $host instanceof Server) {
                continue;
            }
            $found[$host->id] = $this->fromServer($host, 'database', $app);
        }

        $orgId = (string) ($site->organization_id ?? $app->organization_id);

        foreach (CloudDatabase::query()->where('organization_id', $orgId)->whereHas('sites', fn ($q) => $q->where('sites.id', $site->id))->get() as $cluster) {
            $this->absorbManagedCluster($found, $cluster);
        }

        $env = $this->envParser->parse((string) $site->env_file_content)['variables'];
        foreach ($site->bindings as $binding) {
            foreach ($binding->connectionEnv() as $key => $value) {
                if (! isset($env[$key]) || trim((string) $env[$key]) === '') {
                    $env[$key] = (string) $value;
                }
            }
        }

        foreach (self::REDIS_HOST_KEYS as $key) {
            $this->absorbEnvHost($found, $env[$key] ?? null, 'redis', $orgId, $app);
        }
        $this->absorbEnvHost($found, $this->hostFromUrl($env['REDIS_URL'] ?? null), 'redis', $orgId, $app);
        $this->absorbEnvHost($found, $env['DB_HOST'] ?? null, 'database', $orgId, $app);
        $this->absorbEnvHost($found, $this->hostFromUrl($env['DATABASE_URL'] ?? null), 'database', $orgId, $app);

        $connection = strtolower(trim((string) ($env['DB_CONNECTION'] ?? '')));
        if ($connection === 'sqlite' && ! $this->hasRole($found, 'database')) {
            $found['local-sqlite'] = [
                'id' => 'local-sqlite',
                'name' => __('SQLite on this web box'),
                'role' => 'database',
                'local' => true,
                'server' => null,
            ];
        }

        return array_values($found);
    }

    /**
     * @param  array<string, array{id: string, name: string, role: string, local: bool, server: ?Server, managed?: bool}>  $found
     */
    private function absorbManagedBinding(array &$found, SiteBinding $binding): bool
    {
        if (! in_array($binding->type, ['redis', 'cache', 'queue', 'session', 'database'], true)) {
            return false;
        }

        if ($binding->target_type === 'cloud_database' && filled($binding->target_id)) {
            $cluster = CloudDatabase::query()->find($binding->target_id);
            if ($cluster instanceof CloudDatabase) {
                $this->absorbManagedCluster($found, $cluster);

                return true;
            }

            $role = $binding->type === 'database' ? 'database' : 'redis';
            $found['managed:'.$binding->id] = $this->fromManaged(
                'managed:'.$binding->id,
                $role === 'redis' ? __('Managed Redis') : __('Managed database'),
                $role,
            );

            return true;
        }

        $config = is_array($binding->config) ? $binding->config : [];
        if (! empty($config['managed']) || ($config['placement'] ?? '') === 'managed') {
            $role = $binding->type === 'database' ? 'database' : 'redis';
            $found['managed:'.$binding->id] = $this->fromManaged(
                'managed:'.$binding->id,
                $role === 'redis' ? __('Managed Redis') : __('Managed database'),
                $role,
            );

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, array{id: string, name: string, role: string, local: bool, server: ?Server, managed?: bool}>  $found
     */
    private function absorbManagedCluster(array &$found, CloudDatabase $cluster): void
    {
        $role = $cluster->engine === CloudDatabase::ENGINE_REDIS ? 'redis' : 'database';
        $found['managed:'.$cluster->id] = $this->fromManaged(
            'managed:'.$cluster->id,
            $cluster->name !== '' ? $cluster->name : ($role === 'redis' ? __('Managed Redis') : __('Managed database')),
            $role,
        );
    }

    /**
     * @return array{id: string, name: string, role: string, local: bool, server: ?Server, managed: bool}
     */
    private function fromManaged(string $id, string $name, string $role): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'role' => $role,
            'local' => false,
            'server' => null,
            'managed' => true,
        ];
    }

    private function serverFromBinding(SiteBinding $binding): ?Server
    {
        if (! in_array($binding->type, ['redis', 'cache', 'queue', 'session', 'database'], true)) {
            return null;
        }

        $provisionId = $binding->provisionServerId();
        if ($provisionId !== null) {
            return Server::query()->find($provisionId);
        }

        return match ($binding->target_type) {
            'server' => Server::query()->find($binding->target_id),
            'server_cache_service' => ServerCacheService::query()->find($binding->target_id)?->server,
            'server_database' => ServerDatabase::query()->find($binding->target_id)?->server,
            default => null,
        };
    }

    /**
     * @param  array<string, array{id: string, name: string, role: string, local: bool, server: ?Server}>  $found
     */
    private function absorbEnvHost(array &$found, ?string $host, string $role, string $orgId, Server $app): void
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return;
        }

        if ($this->isLocalHost($host, $app)) {
            $key = 'local-'.$role;
            $found[$key] = [
                'id' => $key,
                'name' => $role === 'database' ? __('Database on this web box') : __('Redis on this web box'),
                'role' => $role,
                'local' => true,
                'server' => null,
            ];

            return;
        }

        $peer = Server::query()
            ->where('organization_id', $orgId)
            ->where(function ($q) use ($host): void {
                $q->whereRaw('lower(private_ip_address) = ?', [$host])
                    ->orWhereRaw('lower(ip_address) = ?', [$host]);
            })
            ->first();

        if ($peer instanceof Server) {
            $found[$peer->id] = $this->fromServer($peer, $role, $app);

            return;
        }

        if ($this->isManagedHost($host)) {
            $key = 'managed-host:'.$role.':'.$host;
            $found[$key] = $this->fromManaged(
                $key,
                $role === 'database' ? __('Managed database') : __('Managed Redis'),
                $role,
            );
        }
    }

    private function isManagedHost(string $host): bool
    {
        foreach (self::MANAGED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: string, name: string, role: string, local: bool, server: ?Server}
     */
    private function fromServer(Server $server, string $role, Server $app): array
    {
        return [
            'id' => (string) $server->id,
            'name' => $server->name !== '' ? $server->name : $role,
            'role' => $role,
            'local' => (string) $server->id === (string) $app->id,
            'server' => $server,
            'managed' => false,
        ];
    }

    /**
     * @param  list<array{id: string, name: string, role: string, local: bool, server: ?Server}>  $backends
     * @return list<array{id: string, name: string, role: string}>
     */
    private function backendRows(array $backends): array
    {
        return array_map(
            fn (array $backend): array => [
                'id' => $backend['id'],
                'name' => $backend['name'],
                'role' => $backend['role'],
            ],
            $backends,
        );
    }

    /**
     * @param  array<string, array{id: string, name: string, role: string, local: bool, server: ?Server}>  $found
     */
    private function hasRole(array $found, string $role): bool
    {
        foreach ($found as $backend) {
            if ($backend['role'] === $role) {
                return true;
            }
        }

        return false;
    }

    private function isLocalHost(string $host, Server $app): bool
    {
        if (in_array($host, self::LOCAL_HOSTS, true)) {
            return true;
        }

        $own = array_filter([
            strtolower(trim((string) $app->private_ip_address)),
            strtolower(trim((string) $app->ip_address)),
        ]);

        return in_array($host, $own, true);
    }

    private function shareNetwork(Server $a, Server $b): bool
    {
        if ((string) $a->organization_id !== (string) $b->organization_id) {
            return false;
        }

        if (filled($a->private_network_id) && (string) $a->private_network_id === (string) $b->private_network_id) {
            return true;
        }

        return filled($a->hetzner_network_id)
            && (string) $a->hetzner_network_id === (string) $b->hetzner_network_id;
    }

    private function hostFromUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
