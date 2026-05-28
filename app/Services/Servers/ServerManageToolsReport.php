<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Support\Carbon;

/**
 * Manage → Tools overview: catalog visibility, probe freshness, and per-tool
 * status rows for the expanded Tools workspace tab.
 */
final class ServerManageToolsReport
{
    /**
     * @param  array<string, array<string, mixed>>  $serviceActions
     * @return array{
     *     overall: string,
     *     ops_ready: bool,
     *     php_available: bool,
     *     redis_relevant: bool,
     *     tools_probed: bool,
     *     mise_runtimes_probed: bool,
     *     system_runtimes_probed: bool,
     *     probe_stale: bool,
     *     checked_at: ?Carbon,
     *     summary: array{
     *         catalog_count: int,
     *         installed_count: int,
     *         mise_present: bool,
     *         runtime_versions: int,
     *         php_available: bool,
     *     },
     *     catalog_rows: list<array<string, mixed>>,
     *     generic_tools: list<array<string, mixed>>,
     *     hero_tool: ?array<string, mixed>,
     * }
     */
    public function build(Server $server, array $serviceActions = []): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $manageTools = is_array($meta['manage_tools'] ?? null) ? $meta['manage_tools'] : [];
        $manageMiseRuntimes = is_array($meta['manage_mise_runtimes'] ?? null) ? $meta['manage_mise_runtimes'] : [];
        $manageRedis = is_array($meta['manage_redis'] ?? null) ? $meta['manage_redis'] : [];

        $toolsProbed = array_key_exists('manage_tools', $meta);
        $miseRuntimesProbed = array_key_exists('manage_mise_runtimes', $meta);
        $systemRuntimesProbed = array_key_exists('manage_system_runtimes', $meta);
        $checkedAt = $this->parseTimestamp($meta['inventory_checked_at'] ?? null);

        $opsReady = $server->isReady() && filled($server->ssh_private_key);
        $installedTags = ServerInstalledServices::tagsFor($server);
        $phpAvailable = array_key_exists('php', $installedTags);
        $redisRelevant = $this->redisRelevant($server, $manageRedis);

        $runtimeVersions = 0;
        foreach ($manageMiseRuntimes as $entry) {
            if (is_array($entry) && is_array($entry['versions'] ?? null)) {
                $runtimeVersions += count($entry['versions']);
            }
        }

        $catalog = config('server_manage.tool_catalog', []);
        $catalogRows = [];
        $genericTools = [];
        $heroTool = null;
        $installedCount = 0;
        $visibleCount = 0;

        foreach ($catalog as $key => $def) {
            if (! is_array($def)) {
                continue;
            }

            if (! $this->toolVisible($def, $phpAvailable, $redisRelevant)) {
                continue;
            }

            $slug = (string) ($def['slug'] ?? $key);
            $state = is_array($manageTools[$slug] ?? null)
                ? $manageTools[$slug]
                : ['present' => false, 'version' => null];
            $present = ! empty($state['present']);
            $version = is_string($state['version'] ?? null) && $state['version'] !== ''
                ? $state['version']
                : null;

            if ($present) {
                $installedCount++;
            }
            $visibleCount++;

            $actionKey = is_string($def['action_key'] ?? null) ? $def['action_key'] : null;
            $action = $actionKey !== null ? ($serviceActions[$actionKey] ?? null) : null;
            $actionWhen = (string) ($def['action_when'] ?? 'always');
            $showAction = $action !== null
                && ($actionWhen !== 'missing' || ! $present);

            $row = [
                'slug' => $slug,
                'label' => (string) ($def['label'] ?? $slug),
                'description' => (string) ($def['description'] ?? ''),
                'docs_url' => is_string($def['docs_url'] ?? null) ? $def['docs_url'] : null,
                'icon' => (string) ($def['icon'] ?? 'heroicon-o-wrench-screwdriver'),
                'present' => $present,
                'version' => $version,
                'preinstalled' => (bool) ($def['preinstalled'] ?? false),
                'action_key' => $actionKey,
                'action' => is_array($action) ? $action : null,
                'show_action' => $showAction,
                'run_suggestion' => is_string($def['run_suggestion'] ?? null) ? $def['run_suggestion'] : null,
                'run_url' => route('servers.run', $server),
                'caches_url' => ! empty($def['show_when_redis_relevant'])
                    ? route('servers.caches', $server)
                    : null,
                'status_label' => $this->statusLabel($present, (bool) ($def['preinstalled'] ?? false)),
                'status_tone' => $present ? 'emerald' : 'mist',
            ];

            $catalogRows[] = $row;

            $card = (string) ($def['card'] ?? 'generic');
            if ($card === 'hero') {
                $heroTool = $row;
            } elseif ($card === 'generic') {
                $genericTools[] = $row;
            }
        }

        $probeStale = $checkedAt !== null
            && (! $toolsProbed || ! $miseRuntimesProbed || ! $systemRuntimesProbed);

        $overall = 'ready';
        if ($probeStale) {
            $overall = 'stale';
        } elseif (! $opsReady) {
            $overall = 'blocked';
        }

        return [
            'overall' => $overall,
            'ops_ready' => $opsReady,
            'php_available' => $phpAvailable,
            'redis_relevant' => $redisRelevant,
            'tools_probed' => $toolsProbed,
            'mise_runtimes_probed' => $miseRuntimesProbed,
            'system_runtimes_probed' => $systemRuntimesProbed,
            'probe_stale' => $probeStale,
            'checked_at' => $checkedAt,
            'summary' => [
                'catalog_count' => $visibleCount,
                'installed_count' => $installedCount,
                'mise_present' => ! empty($manageTools['mise']['present']),
                'runtime_versions' => $runtimeVersions,
                'php_available' => $phpAvailable,
            ],
            'catalog_rows' => $catalogRows,
            'generic_tools' => $genericTools,
            'hero_tool' => $heroTool,
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private function toolVisible(array $def, bool $phpAvailable, bool $redisRelevant): bool
    {
        if (! empty($def['requires_php']) && ! $phpAvailable) {
            return false;
        }

        if (! empty($def['show_when_redis_relevant']) && ! $redisRelevant) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $manageRedis
     */
    private function redisRelevant(Server $server, array $manageRedis): bool
    {
        if (! empty($manageRedis['present']) || filled($manageRedis['info_raw'] ?? null)) {
            return true;
        }

        return ServerCacheService::query()
            ->where('server_id', $server->id)
            ->exists();
    }

    private function statusLabel(bool $present, bool $preinstalled): string
    {
        if ($present && $preinstalled) {
            return __('Preinstalled');
        }

        if ($present) {
            return __('Installed');
        }

        return __('Not detected');
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
