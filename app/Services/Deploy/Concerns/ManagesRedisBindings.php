<?php

declare(strict_types=1);

namespace App\Services\Deploy\Concerns;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\Site;
use App\Models\SiteBinding;
use RuntimeException;

/**
 * Attach the `redis` binding type (Redis-family cache services reachable from
 * the site) and resolve the effective service host.
 */
trait ManagesRedisBindings
{
    /**
     * Redis-family cache services the site can reach: those on its own server
     * (loopback) plus those on private-network peers (private IP). Mirrors
     * {@see attachableDatabases}.
     *
     * @return list<array{id: string, label: string}>
     */
    private function attachableCacheServices(Site $site): array
    {
        $server = $site->server;
        if ($server === null) {
            return [];
        }

        $services = ServerCacheService::query()
            ->whereIn('server_id', $this->reachableServerIds($server))
            ->whereIn('engine', ServerCacheService::FAMILY_REDIS_ENGINES)
            ->with('server:id,name,organization_id,private_ip_address,private_network_id')
            ->orderBy('engine')
            ->get();

        $consumers = $this->bindingConsumerCounts(
            'server_cache_service',
            $services->map(fn (ServerCacheService $s): string => (string) $s->id)->all(),
            (string) $site->id,
        );

        return $services
            ->map(function (ServerCacheService $svc) use ($server, $consumers): array {
                $sameBox = (string) $svc->server_id === (string) $server->id;
                $where = $sameBox ? __('this server') : ($svc->server?->name ?: __('network peer'));
                $state = $svc->status === ServerCacheService::STATUS_RUNNING ? '' : ' — '.$svc->status;
                $used = $consumers[(string) $svc->id] ?? 0;

                return [
                    'id' => (string) $svc->id,
                    'label' => ucfirst((string) $svc->engine).' · '.$where.$state.$this->usageSuffix($used),
                    'engine' => (string) $svc->engine,
                    'group' => $sameBox ? 'local' : 'peer',
                    'consumers' => $used,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachRedis(Site $site, array $params): SiteBinding
    {
        $server = $site->server;
        if ($server === null) {
            throw new RuntimeException(__('This site has no server.'));
        }

        $reachable = $this->reachableServerIds($server);
        $query = ServerCacheService::query()
            ->whereIn('server_id', $reachable)
            ->whereIn('engine', ServerCacheService::FAMILY_REDIS_ENGINES)
            ->with('server:id,name,organization_id,private_ip_address,private_network_id');

        $targetId = (string) ($params['target_id'] ?? '');
        $svc = $targetId !== ''
            ? (clone $query)->whereKey($targetId)->first()
            // No explicit pick (e.g. legacy callers): prefer the local service.
            : (clone $query)->get()->sortBy(fn (ServerCacheService $s) => (string) $s->server_id === (string) $server->id ? 0 : 1)->first();

        if (! $svc instanceof ServerCacheService) {
            throw new RuntimeException(__('No Redis-compatible service is reachable. Install Redis/Valkey from the server Caches workspace, or add one to this private network.'));
        }

        $svcServer = $svc->server ?? $server;
        $crossServer = (string) $svc->server_id !== (string) $server->id;
        $host = $this->effectiveServiceHost($svcServer, $site);
        $port = (string) ($svc->port ?: ServerCacheService::defaultPortFor((string) $svc->engine));

        $env = array_filter([
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => $host,
            'REDIS_PORT' => $port,
            'REDIS_PASSWORD' => filled($svc->auth_password) ? (string) $svc->auth_password : null,
            'REDIS_PREFIX' => filled($svc->cache_prefix) ? (string) $svc->cache_prefix : null,
        ], fn ($v) => $v !== null);

        return $this->persist($site, 'redis', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => (string) $svc->engine.($crossServer ? ' · '.($svcServer->name ?? '') : ''),
            'target_type' => 'server_cache_service',
            'target_id' => (string) $svc->id,
            'injected_env' => $env,
            'config' => array_filter([
                'engine' => (string) $svc->engine,
                'source_server_id' => $crossServer ? (string) $svc->server_id : null,
            ]),
        ]);
    }

    /**
     * Address $site should dial to reach a service on $serviceServer: loopback
     * when it's the site's own box, the server's private IP when it's a peer on
     * the same private network. Used for cache/redis hosts (databases have their
     * own variant that also honours a stored host).
     */
    private function effectiveServiceHost(Server $serviceServer, Site $site): string
    {
        $siteServer = $site->server;

        if ($siteServer !== null
            && (string) $serviceServer->id !== (string) $siteServer->id
            && $this->sharePrivateNetwork($siteServer, $serviceServer)
            && filled($serviceServer->private_ip_address)) {
            return (string) $serviceServer->private_ip_address;
        }

        return '127.0.0.1';
    }
}
