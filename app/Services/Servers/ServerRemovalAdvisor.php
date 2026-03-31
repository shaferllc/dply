<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Models\SiteDeployment;

final class ServerRemovalAdvisor
{
    /**
     * @return array{
     *   sites: int,
     *   databases: int,
     *   cron_jobs: int,
     *   supervisor_programs: int,
     *   firewall_rules: int,
     *   authorized_keys: int,
     *   recipes: int,
     *   running_deployments: int,
     *   provider_label: string,
     *   provider_value: string,
     *   will_destroy_cloud: bool,
     *   organization_name: ?string,
     * }
     */
    public static function summary(Server $server): array
    {
        $server->loadCount([
            'sites',
            'serverDatabases',
            'cronJobs',
            'supervisorPrograms',
            'firewallRules',
            'authorizedKeys',
            'recipes',
        ]);

        $runningDeployments = SiteDeployment::query()
            ->whereHas('site', fn ($q) => $q->where('server_id', $server->id))
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->count();

        $provider = $server->provider;

        return [
            'sites' => (int) $server->sites_count,
            'databases' => (int) $server->server_databases_count,
            'cron_jobs' => (int) $server->cron_jobs_count,
            'supervisor_programs' => (int) $server->supervisor_programs_count,
            'firewall_rules' => (int) $server->firewall_rules_count,
            'authorized_keys' => (int) $server->authorized_keys_count,
            'recipes' => (int) $server->recipes_count,
            'running_deployments' => $runningDeployments,
            'provider_label' => $provider instanceof \BackedEnum ? $provider->label() : (string) $provider,
            'provider_value' => $provider instanceof \BackedEnum ? $provider->value : (string) $provider,
            'will_destroy_cloud' => self::willDestroyCloudResource($server),
            'organization_name' => $server->organization?->name,
        ];
    }

    public static function willDestroyCloudResource(Server $server): bool
    {
        return filled($server->provider_id)
            && $server->provider instanceof ServerProvider
            && $server->provider !== ServerProvider::Custom;
    }

    public static function hasRunningDeployments(Server $server): bool
    {
        return SiteDeployment::query()
            ->whereHas('site', fn ($q) => $q->where('server_id', $server->id))
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->exists();
    }
}
