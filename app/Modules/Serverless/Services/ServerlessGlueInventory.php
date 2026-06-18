<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\EdgeDeployHook;
use App\Models\FunctionAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;

/**
 * Org-scoped inventory for serverless glue — Edge hooks, DO Functions
 * actions/sequences, Cloud apps, and BYO crons with deep-link hrefs.
 */
final class ServerlessGlueInventory
{
    /**
     * @return array{
     *     functions_hosts: list<array{id: string, name: string, href: string|null, code_action_count: int, sequence_count: int}>,
     *     code_actions: list<array{id: string, name: string, server_id: string, site_id: string, site_name: string, href: string|null}>,
     *     sequences: list<array{id: string, name: string, server_id: string, site_id: string, site_name: string, component_count: int, href: string|null}>,
     *     edge_hooks: list<array{id: string, site_id: string, site_name: string, hook_name: string, token_prefix: string, href: string|null}>,
     *     edge_sites: list<array{id: string, name: string, hook_count: int, href: string|null}>,
     *     cloud_sites: list<array{id: string, name: string, server_id: string|null, live_url: string|null, redeploy_hook: string|null, href: string|null}>,
     *     byo_crons: list<array{id: string, server_id: string, server_name: string, command: string, cron_expression: string, site_name: string|null, href: string|null}>,
     * }
     */
    /** @return array<string, mixed> */
    public function forOrganization(Organization $organization): array
    {
        $servers = Server::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'meta']);

        $sites = Site::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'server_id', 'edge_backend', 'container_backend', 'meta', 'type']);

        $functionsHostIds = $servers
            ->filter(fn (Server $server): bool => $server->isDigitalOceanFunctionsHost())
            ->pluck('id');

        $siteIds = $sites->pluck('id');

        $actions = FunctionAction::query()
            ->with('site:id,name,server_id,organization_id')
            ->whereHas('site', fn ($query) => $query->where('organization_id', $organization->id))
            ->whereIn('site_id', $siteIds)
            ->orderBy('name')
            ->get(['id', 'site_id', 'name', 'kind', 'components']);

        $codeActions = [];
        $sequences = [];

        foreach ($actions as $action) {
            $site = $action->site;
            if ($site === null || $site->server_id === null) {
                continue;
            }

            $serverId = (string) $site->server_id;
            $row = [
                'id' => (string) $action->id,
                'name' => (string) $action->name,
                'server_id' => $serverId,
                'site_id' => (string) $site->id,
                'site_name' => (string) $site->name,
                'href' => $this->siteHref($site, 'platform'),
            ];

            if ($action->kind === FunctionAction::KIND_CODE) {
                $codeActions[] = $row;

                continue;
            }

            if ($action->isSequence()) {
                $components = ($action->components );
                $sequences[] = [
                    ...$row,
                    'component_count' => count($components),
                ];
            }
        }

        $edgeSiteIds = $sites->filter(fn (Site $site): bool => $site->usesEdgeRuntime())->pluck('id');

        $hooks = EdgeDeployHook::query()
            ->with('site:id,name,server_id')
            ->whereIn('site_id', $edgeSiteIds)
            ->orderBy('name')
            ->get(['id', 'site_id', 'name', 'token_prefix']);

        $hookCounts = $hooks->groupBy('site_id')->map->count();

        $edgeHooks = [];
        $edgeSites = [];

        foreach ($sites as $site) {
            if (! $site->usesEdgeRuntime()) {
                continue;
            }

            $count = (int) ($hookCounts[(string) $site->id] ?? 0);
            $edgeSites[] = [
                'id' => (string) $site->id,
                'name' => (string) $site->name,
                'hook_count' => $count,
                'href' => $this->siteHref($site, 'edge-deploy-triggers'),
            ];
        }

        foreach ($hooks as $hook) {
            $site = $hook->site;
            if ($site === null) {
                continue;
            }

            $edgeHooks[] = [
                'id' => (string) $hook->id,
                'site_id' => (string) $site->id,
                'site_name' => (string) $site->name,
                'hook_name' => (string) $hook->name,
                'token_prefix' => (string) $hook->token_prefix,
                'href' => $this->siteHref($site, 'edge-deploy-triggers'),
            ];
        }

        $cloudSites = [];
        foreach ($sites as $site) {
            if (! $site->usesContainerRuntime()) {
                continue;
            }

            $liveUrl = $site->containerLiveUrl();
            $cloudSites[] = [
                'id' => (string) $site->id,
                'name' => (string) $site->name,
                'server_id' => $site->server_id !== null ? (string) $site->server_id : null,
                'live_url' => is_string($liveUrl) && $liveUrl !== '' ? $liveUrl : null,
                'redeploy_hook' => route('hooks.cloud.redeploy', ['site' => $site->id]),
                'href' => $this->siteHref($site, 'deploy'),
            ];
        }

        $byoServerIds = $servers
            ->reject(fn (Server $server): bool => $server->isDigitalOceanFunctionsHost() || $server->isDigitalOceanAppPlatformHost())
            ->pluck('id');

        $crons = ServerCronJob::query()
            ->with(['server:id,name', 'site:id,name'])
            ->whereIn('server_id', $byoServerIds)
            ->orderBy('cron_expression')
            ->get(['id', 'server_id', 'site_id', 'command', 'cron_expression']);

        $byoCrons = [];
        foreach ($crons as $cron) {
            $server = $cron->server;
            if ($server === null) {
                continue;
            }

            $byoCrons[] = [
                'id' => (string) $cron->id,
                'server_id' => (string) $server->id,
                'server_name' => (string) $server->name,
                'command' => (string) $cron->command,
                'cron_expression' => (string) $cron->cron_expression,
                'site_name' => $cron->site !== null ? (string) $cron->site->name : null,
                'href' => $cron->site_id !== null && $cron->site !== null && $cron->site->server_id !== null
                    ? route('servers.cron', ['server' => $cron->site->server_id, 'site' => $cron->site_id])
                    : route('servers.cron', ['server' => $server->id]),
            ];
        }

        $codeByServer = collect($codeActions)->groupBy('server_id')->map->count();
        $seqByServer = collect($sequences)->groupBy('server_id')->map->count();

        $functionsHosts = [];
        foreach ($servers as $server) {
            if (! $server->isDigitalOceanFunctionsHost()) {
                continue;
            }

            $serverId = (string) $server->id;
            $functionsHosts[] = [
                'id' => $serverId,
                'name' => (string) $server->name,
                'href' => route('serverless.index'),
                'code_action_count' => (int) ($codeByServer[$serverId] ?? 0),
                'sequence_count' => (int) ($seqByServer[$serverId] ?? 0),
            ];
        }

        return [
            'functions_hosts' => $functionsHosts,
            'code_actions' => $codeActions,
            'sequences' => $sequences,
            'edge_hooks' => $edgeHooks,
            'edge_sites' => $edgeSites,
            'cloud_sites' => $cloudSites,
            'byo_crons' => $byoCrons,
        ];
    }

    private function siteHref(Site $site, string $section): ?string
    {
        if ($site->server_id === null) {
            return null;
        }

        return route('sites.show', [
            'server' => $site->server_id,
            'site' => $site,
            'section' => $section,
        ]);
    }
}
