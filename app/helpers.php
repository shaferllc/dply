<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Ai\LlmSynthesizer;
use App\Services\OpsCopilot\OpsCopilotContextBuilder;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pennant\Feature;

if (! function_exists('reverb_health_check_url')) {
    /**
     * HTTP URL to the Reverb server's health endpoint (same process as WebSockets).
     * Opens in a new tab from the admin menu; requires Reverb to be running.
     */
    function reverb_health_check_url(): ?string
    {
        $server = config('reverb.servers.'.config('reverb.default', 'reverb'));
        if (! is_array($server)) {
            return null;
        }

        $port = (int) ($server['port'] ?? 8080);
        $host = (string) ($server['hostname'] ?? '');
        if ($host === '' || $host === '0.0.0.0') {
            $host = '127.0.0.1';
        }

        return 'http://'.$host.':'.$port.'/up';
    }
}

if (! function_exists('server_workspace_nav_item_url')) {
    /**
     * URL for a server workspace sidebar item (handles settings default tab segment).
     */
    function server_workspace_nav_item_url(Server $server, array $item): string
    {
        $routeName = $item['route'] ?? '';

        if (! empty($item['preview_only']) && is_string($item['preview_route'] ?? null) && $item['preview_route'] !== '') {
            $routeName = $item['preview_route'];
        }

        if ($routeName === 'servers.settings') {
            return route('servers.settings', ['server' => $server, 'section' => 'connection']);
        }

        return route($routeName, $server);
    }
}

if (! function_exists('server_workspace_nav_feature_names')) {
    /**
     * All Pennant flags referenced by {@see config('server_workspace.nav')} (full + preview).
     *
     * @return list<string>
     */
    function server_workspace_nav_feature_names(): array
    {
        static $names = null;

        if ($names !== null) {
            return $names;
        }

        $names = [];
        foreach ((array) config('server_workspace.nav', []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['feature', 'preview_feature'] as $key) {
                $name = $item[$key] ?? null;
                if (is_string($name) && $name !== '') {
                    $names[$name] = true;
                }
            }
        }

        return $names = array_keys($names);
    }
}

if (! function_exists('server_workspace_nav_for_server')) {
    /**
     * Server workspace sidebar items filtered to those whose backing service is installed.
     * Items without `requires_any_tags` always show. Items with it only show if at least
     * one tag is in {@see ServerInstalledServices::tagsFor}. Fails open when the provision
     * stack summary is unavailable so freshly-imported servers still see every tab.
     *
     * @return list<array<string, mixed>>
     */
    function server_workspace_nav_for_server(Server $server): array
    {
        static $siteCounts = [];
        static $navCache = [];

        $serverId = (string) $server->getKey();
        $hostKind = (string) ($server->meta['host_kind'] ?? 'vm');
        $supervisorStatus = (string) ($server->supervisor_package_status ?? '');

        $needsSiteCount = false;
        foreach ((array) config('server_workspace.nav', []) as $navItem) {
            if (is_array($navItem) && (int) ($navItem['requires_min_sites'] ?? 0) > 1) {
                $needsSiteCount = true;
                break;
            }
        }

        $siteCountKey = '';
        if ($needsSiteCount) {
            if (! array_key_exists($serverId, $siteCounts)) {
                $siteCounts[$serverId] = $server->sites()->count();
            }
            $siteCountKey = (string) $siteCounts[$serverId];
        }

        $cacheKey = $serverId.'|'.$hostKind.'|'.$supervisorStatus.'|'.$siteCountKey;
        if (isset($navCache[$cacheKey])) {
            return $navCache[$cacheKey];
        }

        $items = config('server_workspace.nav', []);
        if (! is_array($items)) {
            return [];
        }

        $featureNames = server_workspace_nav_feature_names();
        if ($featureNames !== []) {
            Feature::loadMissing($featureNames);
        }

        $installed = ServerInstalledServices::tagsFor($server);
        $unknownStack = array_key_exists('unknown', $installed);
        $hostKind = (string) ($server->meta['host_kind'] ?? 'vm');
        $needsSupervisorSetup = $server->supervisor_package_status !== Server::SUPERVISOR_PACKAGE_INSTALLED;

        // Per-role allow-list: dedicated Redis/Valkey/etc. boxes get a focused
        // sidebar instead of the full app-server nav. Roles not listed in
        // server_workspace.role_nav_keys fall through unchanged.
        // $roleKeyPositions doubles as the membership test AND the within-group
        // sort order — items are emitted in the order their key appears in the
        // role's `keys` list, so the role config alone controls visual order.
        $serverRole = (string) ($server->meta['server_role'] ?? '');
        $roleConfig = (array) (config('server_workspace.role_nav_keys.'.$serverRole, []) ?: []);
        $roleKeyPositions = is_array($roleConfig['keys'] ?? null)
            ? array_flip(array_values(array_filter($roleConfig['keys'], 'is_string')))
            : null;
        $roleOverrides = is_array($roleConfig['overrides'] ?? null) ? $roleConfig['overrides'] : [];

        $filtered = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $item['key'] ?? null;
            if ($roleKeyPositions !== null && (! is_string($key) || ! isset($roleKeyPositions[$key]))) {
                continue;
            }

            $onlyHostKinds = $item['only_host_kinds'] ?? null;
            if (is_array($onlyHostKinds) && $onlyHostKinds !== [] && ! in_array($hostKind, $onlyHostKinds, true)) {
                continue;
            }
            $exceptHostKinds = $item['except_host_kinds'] ?? null;
            if (is_array($exceptHostKinds) && in_array($hostKind, $exceptHostKinds, true)) {
                continue;
            }

            $feature = $item['feature'] ?? null;
            $previewFeature = $item['preview_feature'] ?? null;
            $featureActive = is_string($feature) && $feature !== '' && Feature::active($feature);
            $previewActive = is_string($previewFeature) && $previewFeature !== ''
                && Feature::active($previewFeature)
                && ! $featureActive;

            if (! ($featureActive || $previewActive) && is_string($feature) && $feature !== '') {
                continue;
            }

            $required = $item['requires_any_tags'] ?? null;
            if (is_array($required) && $required !== [] && ! $unknownStack) {
                $hasRequiredTag = false;
                foreach ($required as $tag) {
                    if (is_string($tag) && array_key_exists($tag, $installed)) {
                        $hasRequiredTag = true;
                        break;
                    }
                }
                if (! $hasRequiredTag) {
                    continue;
                }
            }

            if ($previewActive) {
                $item['preview_only'] = true;
            }
            if ($needsSupervisorSetup && ($item['key'] ?? null) === 'daemons') {
                $item['needs_setup'] = true;
            }

            // Apply per-role label / group overrides (e.g. Caches → "Redis"
            // and promoted into the overview group for a redis-role server).
            if (is_string($key) && isset($roleOverrides[$key]) && is_array($roleOverrides[$key])) {
                if (isset($roleOverrides[$key]['label']) && is_string($roleOverrides[$key]['label'])) {
                    $item['label'] = $roleOverrides[$key]['label'];
                }
                if (isset($roleOverrides[$key]['group']) && is_string($roleOverrides[$key]['group'])) {
                    $item['group'] = $roleOverrides[$key]['group'];
                }
            }

            $minSites = (int) ($item['requires_min_sites'] ?? 0);
            if ($minSites > 1) {
                $count = $siteCounts[$serverId] ?? $server->sites()->count();
                $siteCounts[$serverId] = $count;
                if ($count < $minSites) {
                    continue;
                }
            }

            $filtered[] = $item;
        }

        // When role gating is active, sort by the role config's keys order so
        // a Redis-mode sidebar reads "Overview, Redis, Console, Health…" even
        // though the base nav has caches at position 17 (after monitor items).
        // Default-role servers fall through unchanged because $roleKeyPositions
        // is null.
        if ($roleKeyPositions !== null && $filtered !== []) {
            usort($filtered, static function (array $a, array $b) use ($roleKeyPositions): int {
                $posA = $roleKeyPositions[$a['key'] ?? ''] ?? PHP_INT_MAX;
                $posB = $roleKeyPositions[$b['key'] ?? ''] ?? PHP_INT_MAX;

                return $posA <=> $posB;
            });
        }

        return $navCache[$cacheKey] = $filtered;
    }
}

if (! function_exists('workspace_console_active')) {
    /**
     * True when the full in-browser SSH console is enabled for the org.
     */
    function workspace_console_active(?Organization $organization = null): bool
    {
        return $organization === null
            ? Feature::active('workspace.console')
            : Feature::for($organization)->active('workspace.console');
    }
}

if (! function_exists('workspace_console_preview_active')) {
    /**
     * True when console is off but the coming-soon teaser should surface in
     * nav and the global floating affordance.
     */
    function workspace_console_preview_active(?Organization $organization = null): bool
    {
        if (workspace_console_active($organization)) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.console_preview')
            : Feature::for($organization)->active('workspace.console_preview');
    }
}

if (! function_exists('workspace_insights_preview_active')) {
    /**
     * True when insights is off but the coming-soon teaser should surface in
     * nav and the preview workspace page.
     */
    function workspace_insights_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.insights')
            : Feature::for($organization)->active('workspace.insights')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.insights_preview')
            : Feature::for($organization)->active('workspace.insights_preview');
    }
}

if (! function_exists('workspace_server_blueprint_preview_active')) {
    /**
     * True when server blueprint is off but the coming-soon teaser should
     * surface in nav and the preview workspace page.
     */
    function workspace_server_blueprint_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.server_blueprint')
            : Feature::for($organization)->active('workspace.server_blueprint')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.server_blueprint_preview')
            : Feature::for($organization)->active('workspace.server_blueprint_preview');
    }
}

if (! function_exists('workspace_files_preview_active')) {
    /**
     * True when remote files is off but the coming-soon teaser should
     * surface in nav and the preview workspace page.
     */
    function workspace_files_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.files')
            : Feature::for($organization)->active('workspace.files')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.files_preview')
            : Feature::for($organization)->active('workspace.files_preview');
    }
}

if (! function_exists('workspace_ssh_access_graph_preview_active')) {
    /**
     * True when the SSH access graph workspace is off but the coming-soon
     * teaser should surface in nav and the preview workspace page.
     */
    function workspace_ssh_access_graph_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.ssh_access_graph')
            : Feature::for($organization)->active('workspace.ssh_access_graph')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.ssh_access_graph_preview')
            : Feature::for($organization)->active('workspace.ssh_access_graph_preview');
    }
}

if (! function_exists('workspace_backups_preview_active')) {
    /**
     * True when the Backups workspace is off but the coming-soon teaser should
     * surface in nav and the preview workspace page.
     */
    function workspace_backups_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.backups')
            : Feature::for($organization)->active('workspace.backups')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.backups_preview')
            : Feature::for($organization)->active('workspace.backups_preview');
    }
}

if (! function_exists('workspace_site_cdn_preview_active')) {
    /**
     * True when the site CDN workspace is off but the coming-soon teaser should
     * surface in nav and the preview page.
     */
    function workspace_site_cdn_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.site_cdn')
            : Feature::for($organization)->active('workspace.site_cdn')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.site_cdn_preview')
            : Feature::for($organization)->active('workspace.site_cdn_preview');
    }
}

if (! function_exists('workspace_site_caching_preview_active')) {
    /**
     * True when the site caching workspace is off but the coming-soon teaser should
     * surface in nav and the preview page.
     */
    function workspace_site_caching_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.site_caching')
            : Feature::for($organization)->active('workspace.site_caching')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.site_caching_preview')
            : Feature::for($organization)->active('workspace.site_caching_preview');
    }
}

if (! function_exists('workspace_docker_preview_active')) {
    /**
     * True when the Docker workspace is off but the coming-soon teaser should
     * surface in nav and the preview workspace page.
     */
    function workspace_docker_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.docker')
            : Feature::for($organization)->active('workspace.docker')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.docker_preview')
            : Feature::for($organization)->active('workspace.docker_preview');
    }
}

if (! function_exists('workspace_server_maintenance_preview_active')) {
    /**
     * True when the server maintenance surface is off but the coming-soon
     * teaser should surface in nav and the preview workspace page.
     */
    function workspace_server_maintenance_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.server_maintenance')
            : Feature::for($organization)->active('workspace.server_maintenance')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.server_maintenance_preview')
            : Feature::for($organization)->active('workspace.server_maintenance_preview');
    }
}

if (! function_exists('workspace_deploy_windows_preview_active')) {
    /**
     * True when the deploy windows surface is off but the coming-soon teaser
     * should surface in nav and the preview workspace page.
     */
    function workspace_deploy_windows_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.deploy_windows')
            : Feature::for($organization)->active('workspace.deploy_windows')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.deploy_windows_preview')
            : Feature::for($organization)->active('workspace.deploy_windows_preview');
    }
}

if (! function_exists('workspace_security_digest_preview_active')) {
    /**
     * True when the security digest surface is off but the coming-soon teaser
     * should surface in nav and the preview workspace page.
     */
    function workspace_security_digest_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.security_digest')
            : Feature::for($organization)->active('workspace.security_digest')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.security_digest_preview')
            : Feature::for($organization)->active('workspace.security_digest_preview');
    }
}

if (! function_exists('workspace_release_hygiene_preview_active')) {
    /**
     * True when the release hygiene surface is off but the coming-soon teaser
     * should surface in nav and the preview workspace page.
     */
    function workspace_release_hygiene_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.release_hygiene')
            : Feature::for($organization)->active('workspace.release_hygiene')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.release_hygiene_preview')
            : Feature::for($organization)->active('workspace.release_hygiene_preview');
    }
}

if (! function_exists('workspace_run_preview_active')) {
    /**
     * True when the Run workspace surface is off but the coming-soon teaser
     * should surface in nav and the preview workspace page.
     */
    function workspace_run_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.run')
            : Feature::for($organization)->active('workspace.run')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.run_preview')
            : Feature::for($organization)->active('workspace.run_preview');
    }
}

if (! function_exists('workspace_shared_host_preview_active')) {
    /**
     * True when the Shared Host Radar surface is off but the coming-soon teaser
     * should surface in nav and the preview workspace page.
     */
    function workspace_shared_host_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.shared_host')
            : Feature::for($organization)->active('workspace.shared_host')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.shared_host_preview')
            : Feature::for($organization)->active('workspace.shared_host_preview');
    }
}

if (! function_exists('workspace_shared_host_active')) {
    function workspace_shared_host_active(?Organization $organization = null): bool
    {
        return $organization === null
            ? Feature::active('workspace.shared_host')
            : Feature::for($organization)->active('workspace.shared_host');
    }
}

if (! function_exists('multi_surface_active')) {
    /**
     * True when the current org has at least one non-VM product surface
     * enabled (Cloud / Edge / Serverless). Used to gate the Infrastructure
     * dashboard and the Launchpad — those screens are designed to triage
     * across multiple surfaces and become noise when only Servers exist.
     *
     * Optional $organization scopes the check to a specific org (admin
     * tooling); omit to use Pennant's default scope (current org).
     */
    function multi_surface_active(?Organization $organization = null): bool
    {
        foreach (['surface.cloud', 'surface.edge', 'surface.serverless'] as $flag) {
            $active = $organization === null
                ? Feature::active($flag)
                : Feature::for($organization)->active($flag);
            if ($active) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('full_stack_wizard_active')) {
    /**
     * True when the Tier B full-stack launch wizard should be available:
     * flag on, launchpad surfaces active, and both Cloud + Edge enabled.
     */
    function full_stack_wizard_active(?Organization $organization = null): bool
    {
        $flagActive = $organization === null
            ? Feature::active('launch.full_stack_wizard')
            : Feature::for($organization)->active('launch.full_stack_wizard');

        if (! $flagActive || ! multi_surface_active($organization)) {
            return false;
        }

        foreach (['surface.cloud', 'surface.edge'] as $required) {
            $active = $organization === null
                ? Feature::active($required)
                : Feature::for($organization)->active($required);
            if (! $active) {
                return false;
            }
        }

        return true;
    }
}

if (! function_exists('standby_blueprint_active')) {
    /**
     * True when the Tier C standby failover blueprint wizard is available.
     */
    function standby_blueprint_active(?Organization $organization = null): bool
    {
        return $organization === null
            ? Feature::active('launch.standby_blueprint')
            : Feature::for($organization)->active('launch.standby_blueprint');
    }
}

if (! function_exists('ephemeral_deploy_credentials_active')) {
    /**
     * True when per-deploy ephemeral SSH credentials are enabled for the org.
     */
    function ephemeral_deploy_credentials_active(?Organization $organization = null): bool
    {
        return $organization === null
            ? Feature::active('workspace.ephemeral_credentials')
            : Feature::for($organization)->active('workspace.ephemeral_credentials');
    }
}

if (! function_exists('cost_observatory_active')) {
    /**
     * True when the transparent cost observatory panel should render on
     * billing analytics (global billing flag — same gate as pricing CTAs).
     */
    function cost_observatory_active(?Organization $organization = null): bool
    {
        return $organization === null
            ? Feature::active('global.billing_enabled')
            : Feature::for($organization)->active('global.billing_enabled');
    }
}

if (! function_exists('ops_copilot_active')) {
    /**
     * True when Fleet Ops Copilot deploy triage should be available.
     */
    function ops_copilot_active(?Organization $organization = null): bool
    {
        return $organization === null
            ? Feature::active('global.ops_copilot')
            : Feature::for($organization)->active('global.ops_copilot');
    }
}

if (! function_exists('ops_copilot_site_has_failure')) {
    /**
     * True when the site has a recent failed BYO or Edge deploy Copilot can triage.
     */
    function ops_copilot_site_has_failure(Site $site): bool
    {
        return app(OpsCopilotContextBuilder::class)->siteHasRecentFailure($site);
    }
}

if (! function_exists('ai_llm_active')) {
    /**
     * True when platform LLM synthesis is enabled for the org.
     */
    function ai_llm_active(?Organization $organization = null): bool
    {
        if (! app(LlmSynthesizer::class)->isConfigured()) {
            return false;
        }

        return $organization === null
            ? Feature::active('global.ai_llm')
            : Feature::for($organization)->active('global.ai_llm');
    }
}

if (! function_exists('audit_log')) {
    /**
     * Log an action to the organization audit log.
     */
    function audit_log(
        Organization $organization,
        ?User $user,
        string $action,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        return AuditLog::log($organization, $user, $action, $subject, $oldValues, $newValues);
    }
}
