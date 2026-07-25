<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\WorkerPool;
use Illuminate\Support\Collection;

/**
 * Canonical JSON shape for a fleet server card — used by GET /api/v1/servers
 * and by {@see ServerIndexRow} so local Eloquent and Production API stay aligned.
 */
final class ServerIndexAssembler
{
    /**
     * @param  list<array{server: Server, reason: string}>  $related
     * @return array<string, mixed>
     */
    public static function toArray(
        Server $server,
        ?ServerMetricSnapshot $snapshot = null,
        int $insightsOpen = 0,
        ?string $insightsWorst = null,
        array $related = [],
        bool $deployable = false,
        ?string $manageBaseUrl = null,
    ): array {
        $digest = ProvisioningDigest::forServer($server);
        $payload = is_array($snapshot?->payload) ? $snapshot->payload : null;
        $metrics = $payload !== null ? [
            'cpu' => isset($payload['cpu_pct']) && is_numeric($payload['cpu_pct']) ? (float) $payload['cpu_pct'] : null,
            'ram' => isset($payload['mem_pct']) && is_numeric($payload['mem_pct']) ? (float) $payload['mem_pct'] : null,
            'disk' => isset($payload['disk_pct']) && is_numeric($payload['disk_pct']) ? (float) $payload['disk_pct'] : null,
            'captured_at' => $snapshot?->captured_at?->toIso8601String(),
        ] : null;

        $groupLabel = __('Personal');
        if ($server->team_id !== null && $server->relationLoaded('team') && $server->team !== null) {
            $groupLabel = (string) $server->team->name;
        } elseif ($server->organization_id !== null && $server->relationLoaded('organization') && $server->organization !== null) {
            $groupLabel = (string) $server->organization->name;
        }

        $sites = [];
        if ($server->relationLoaded('sites')) {
            foreach ($server->sites as $site) {
                $sites[] = self::siteSummary($site, $server, $manageBaseUrl);
            }
        }

        $services = [];
        if ($server->relationLoaded('databaseEngines')) {
            foreach ($server->databaseEngines as $engine) {
                $services[] = [
                    'kind' => 'database',
                    'engine' => (string) $engine->engine,
                    'version' => $engine->version !== null ? (string) $engine->version : null,
                    'status' => $engine->status !== null ? (string) $engine->status : null,
                    'is_default' => (bool) $engine->is_default,
                ];
            }
        }
        if ($server->relationLoaded('cacheServices')) {
            foreach ($server->cacheServices as $cache) {
                $services[] = [
                    'kind' => 'cache',
                    'engine' => (string) $cache->engine,
                    'version' => $cache->version !== null ? (string) $cache->version : null,
                    'status' => $cache->status !== null ? (string) $cache->status : null,
                    'is_default' => false,
                ];
            }
        }

        $relatedPayload = [];
        foreach ($related as $peer) {
            $peerServer = $peer['server'];
            $relatedPayload[] = [
                'id' => (string) $peerServer->id,
                'name' => (string) $peerServer->name,
                'ip_address' => $peerServer->ip_address,
                'reason' => (string) $peer['reason'],
                'href' => $manageBaseUrl !== null
                    ? rtrim($manageBaseUrl, '/').'/servers/'.$peerServer->id
                    : route('servers.show', $peerServer),
            ];
        }

        $workerLabel = null;
        if ($server->isWorkerServer()) {
            $workerLabel = $server->isPoolPrimary()
                ? __('Worker · primary')
                : ($server->pool_role === WorkerPool::ROLE_REPLICA ? __('Worker · replica') : __('Worker'));
        }

        return [
            'id' => (string) $server->id,
            'name' => (string) $server->name,
            'status' => (string) $server->status,
            'setup_status' => (string) $server->setup_status,
            'health_status' => $server->health_status !== null ? (string) $server->health_status : null,
            'ip_address' => $server->ip_address,
            'provider' => $server->provider->value,
            'provider_label' => $server->provider->label(),
            'logo_url' => $server->logoUrl(),
            'sites_count' => (int) ($server->sites_count ?? count($sites)),
            'workspace_id' => $server->workspace_id !== null ? (string) $server->workspace_id : null,
            'workspace_name' => $server->workspace?->name,
            'group_label' => $groupLabel,
            'tags' => ServerTags::forServer($server),
            'scheduled_deletion_at' => $server->scheduled_deletion_at?->toIso8601String(),
            'created_at' => $server->created_at?->toIso8601String(),
            'uptime_days' => $server->created_at !== null
                ? max(0, (int) $server->created_at->diffInDays(now()))
                : null,
            'worker_label' => $workerLabel,
            'metrics' => $metrics,
            'insights_open' => $insightsOpen,
            'insights_worst' => $insightsWorst,
            'deployable' => $deployable,
            'sites' => $sites,
            'services' => $services,
            'related' => $relatedPayload,
            'provisioning' => $digest !== null ? [
                'phase_label' => $digest->phaseLabel,
                'step_label' => $digest->stepLabel,
                'step_index' => $digest->stepIndex,
                'step_total' => $digest->stepTotal,
                'elapsed_human' => $digest->elapsedHuman(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function siteSummary(Site $site, Server $server, ?string $manageBaseUrl): array
    {
        $site->setRelation('server', $server);
        $php = $site->phpVersion();
        $runtimeVersion = $site->runtimeVersion();
        $runtimeChip = $php
            ? __('PHP :v', ['v' => $php])
            : ($runtimeVersion ? trim(ucfirst((string) ($site->runtimeKey() ?? '')).' '.$runtimeVersion) : null);

        return [
            'id' => (string) $site->id,
            'name' => (string) $site->name,
            'status' => (string) $site->status,
            'status_label' => $site->statusLabel(),
            'ssl_status' => $site->ssl_status !== null ? (string) $site->ssl_status : null,
            'type_label' => $site->type?->label(),
            'runtime_chip' => $runtimeChip,
            'logo_url' => $site->logoUrl(),
            'last_deploy_at' => $site->last_deploy_at?->toIso8601String(),
            'last_deploy_human' => $site->last_deploy_at?->diffForHumans(),
            'is_provisioning' => $site->isProvisioning(),
            'is_failed' => $site->provisioningState() === 'failed'
                || in_array($site->status, [
                    Site::STATUS_ERROR,
                    Site::STATUS_CONTAINER_FAILED,
                    Site::STATUS_EDGE_FAILED,
                    Site::STATUS_SCAFFOLD_FAILED,
                ], true),
            'is_ready' => $site->isReadyForTraffic(),
            'href' => $manageBaseUrl !== null
                ? rtrim($manageBaseUrl, '/').'/servers/'.$server->id.'/sites/'.$site->id
                : route('sites.show', [$server, $site]),
        ];
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @param  Collection<int, Server>  $candidates
     * @return array<string, list<array{server: Server, reason: string}>>
     */
    public static function relatedServersMap(Collection $servers, Collection $candidates): array
    {
        $byPool = $candidates->filter(fn (Server $s) => $s->worker_pool_id !== null)->groupBy('worker_pool_id');
        $byNetwork = $candidates->filter(fn (Server $s) => $s->private_network_id !== null)->groupBy('private_network_id');
        $byProject = $candidates->filter(fn (Server $s) => $s->workspace_id !== null)->groupBy('workspace_id');

        $map = [];
        foreach ($servers as $server) {
            /** @var array<string, array{server: Server, reason: string}> $peers */
            $peers = [];

            $collect = function (?Collection $group, string $reason) use (&$peers, $server): void {
                foreach ($group ?? collect() as $candidate) {
                    if ($candidate->id === $server->id || isset($peers[$candidate->id])) {
                        continue;
                    }
                    $peers[$candidate->id] = ['server' => $candidate, 'reason' => $reason];
                }
            };

            if ($server->worker_pool_id !== null) {
                $collect($byPool->get($server->worker_pool_id), __('same pool'));
            }
            if ($server->private_network_id !== null) {
                $collect($byNetwork->get($server->private_network_id), __('same VPC'));
            }
            if ($server->workspace_id !== null) {
                $collect($byProject->get($server->workspace_id), __('same project'));
            }

            if ($peers !== []) {
                $map[$server->id] = array_values($peers);
            }
        }

        return $map;
    }
}
