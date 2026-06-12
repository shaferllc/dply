<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\RecentResource;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Support\Docs\ContextualDocResolver;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsCommandPaletteGroups
{


    /** Category contexts whose label is static (not derived from a record). */
    private function categoryLabels(): array
    {
        return [
            'sites' => 'Sites',
            'servers' => 'Servers',
            'projects' => 'Projects',
            'organizations' => 'Organizations',
            'switch-org' => 'Switch organization',
            'docs' => 'Documentation',
            'settings' => 'Settings',
            'admin' => 'Admin',
        ];
    }

    /**
     * Build the result groups for the current context + query.
     *
     * @param  array{type: string, id: ?string, label: string}|null  $context
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function groups(?array $context): array
    {
        $org = auth()->user()?->currentOrganization();
        $query = trim($this->query);

        $raw = match ($context['type'] ?? 'root') {
            'sites' => $this->sitesList($org, $query),
            'site' => $this->siteSubPages($org, $context['id'] ?? null, $query),
            'deploy-sync' => $this->deploySyncContext($org, $context['id'] ?? null, $query),
            'servers' => $this->serversList($org, $query),
            'server' => $this->serverSubPages($org, $context['id'] ?? null, $query),
            'projects' => $this->workspaceList($org, $query),
            'organizations' => $this->organizationList($query),
            'switch-org' => $this->switchOrgList($query),
            'docs' => $this->docsList($query),
            'settings' => $this->commandContext('settings', $org, $query),
            'admin' => $this->commandContext('admin', $org, $query),
            default => $this->rootGroups($org, $query),
        };

        // Drop empty groups so the palette never shows a bare header.
        return array_values(array_filter($raw, fn (array $group): bool => $group['items'] !== []));
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function rootGroups(?Organization $org, string $query): array
    {
        $needle = mb_strtolower($query);
        $isAdmin = Gate::check('viewPlatformAdmin');
        $groups = [];

        // Direct resource hits lead when searching — the most specific meaning
        // of the query.
        if ($org !== null && $query !== '') {
            foreach ($this->searchGroups($org, $query) as $group) {
                $groups[] = $group;
            }
        }

        // Empty query → lead with where the operator has recently been. Skipped
        // entirely while searching (the direct hits above are more relevant).
        if ($org !== null && $query === '') {
            $recent = $this->recentGroup($org);
            if ($recent['items'] !== []) {
                $groups[] = $recent;
            }
        }

        // Nestable resource categories.
        $resourceNav = [
            ['Sites', 'sites', 'globe-alt', 'sites apps websites'],
            ['Servers', 'servers', 'server', 'servers vms hosts machines'],
            ['Projects', 'projects', 'rectangle-stack', 'projects workspaces', 'surface.projects'],
            ['Organizations', 'organizations', 'building-office-2', 'organizations teams orgs'],
        ];
        $resourceItems = [];
        foreach ($resourceNav as $entry) {
            [$label, $type, $icon, $keywords] = $entry;
            $feature = $entry[4] ?? null;
            if ($feature !== null && ! Feature::active($feature)) {
                continue;
            }
            if ($needle !== '' && ! str_contains(mb_strtolower($label.' '.$keywords), $needle)) {
                continue;
            }
            $resourceItems[] = [
                'label' => __($label),
                'sublabel' => __('Browse…'),
                'into' => ['type' => $type],
                'icon' => $icon,
            ];
        }
        $groups[] = ['label' => __('Browse'), 'items' => $resourceItems];

        $specs = $this->commandSpecs();
        $groups[] = ['label' => __('Create'), 'items' => $this->resolveCommandItems($specs['create'], $org, $needle, $isAdmin)];
        $groups[] = ['label' => __('Go to'), 'items' => $this->resolveCommandItems($specs['go'], $org, $needle, $isAdmin)];

        // Nestable management areas.
        $manage = [];
        if ($needle === '' || str_contains('settings preferences account profile', $needle)) {
            $manage[] = ['label' => __('Settings'), 'sublabel' => __('Browse…'), 'into' => ['type' => 'settings'], 'icon' => 'cog-6-tooth'];
        }
        // Switching is only meaningful with more than one org to switch between.
        if (auth()->user()?->organizations()->count() > 1 && ($needle === '' || str_contains('switch organization team', $needle))) {
            $manage[] = ['label' => __('Switch organization'), 'sublabel' => __('Browse…'), 'into' => ['type' => 'switch-org'], 'icon' => 'building-office-2'];
        }
        if ($needle === '' || str_contains('documentation docs help guides reference', $needle)) {
            $manage[] = ['label' => __('Documentation'), 'sublabel' => __('Browse…'), 'into' => ['type' => 'docs'], 'icon' => 'document-text'];
        }
        if ($isAdmin && ($needle === '' || str_contains('admin platform', $needle))) {
            $manage[] = ['label' => __('Admin'), 'sublabel' => __('Browse…'), 'into' => ['type' => 'admin'], 'icon' => 'wrench-screwdriver'];
        }
        $groups[] = ['label' => __('Manage'), 'items' => $manage];

        return $groups;
    }

    /**
     * The "Recently visited" group for the empty-query root. Reads the user's
     * recents (most-recent first) and re-resolves each against the live,
     * org-scoped record so renamed resources show fresh and ones that were
     * deleted or now sit outside the current org silently drop out.
     *
     * @return array{label: string, items: list<array<string, mixed>>}
     */
    private function recentGroup(Organization $org): array
    {
        $recents = RecentResource::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('visited_at')
            ->get();

        $items = [];
        foreach ($recents as $recent) {
            if (count($items) >= self::RECENT_LIMIT) {
                break;
            }
            if ($recent->resource_type === 'site') {
                $site = $this->scopedSite($org, $recent->resource_id);
                if ($site !== null) {
                    $items[] = [
                        'label' => $site->name,
                        'sublabel' => $site->server?->name,
                        'into' => ['type' => 'site', 'id' => $site->id],
                        'icon' => 'globe-alt',
                    ];
                }
            } elseif ($recent->resource_type === 'server') {
                $server = $this->scopedServer($org, $recent->resource_id);
                if ($server !== null) {
                    $items[] = [
                        'label' => $server->name,
                        'sublabel' => $server->ip_address ?? $server->region ?? null,
                        'into' => ['type' => 'server', 'id' => $server->id],
                        'icon' => 'server',
                    ];
                }
            }
        }

        return ['label' => __('Recently visited'), 'items' => $items];
    }

    /**
     * Org-scoped direct-hit search across every resource (root level only).
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function searchGroups(Organization $org, string $query): array
    {
        $like = $this->like($query);
        $serverIds = $org->serverIds();
        $groups = [];

        $siteModels = Site::query()
            ->whereIn('server_id', $serverIds)
            ->where('name', 'like', $like)
            ->with('server')
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get();
        $sites = $siteModels
            ->map(fn (Site $site): array => [
                'label' => $site->name,
                'sublabel' => $site->server?->name,
                'into' => ['type' => 'site', 'id' => $site->id],
                'icon' => 'globe-alt',
            ])
            ->all();
        $groups[] = ['label' => __('Sites'), 'items' => $sites];

        $servers = $org->servers()
            ->where('name', 'like', $like)
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(fn (Server $server): array => [
                'label' => $server->name,
                'sublabel' => $server->ip_address ?? $server->region ?? null,
                'into' => ['type' => 'server', 'id' => $server->id],
                'icon' => 'server',
            ])
            ->all();
        $groups[] = ['label' => __('Servers'), 'items' => $servers];

        // Server databases (on-box engines) link to their server's database
        // tab — there's no per-database page, but the tab is the destination.
        $serverDatabases = ServerDatabase::query()
            ->whereIn('server_id', $serverIds)
            ->where('name', 'like', $like)
            ->with('server')
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->filter(fn (ServerDatabase $database): bool => $database->server !== null)
            ->map(fn (ServerDatabase $database): array => [
                'label' => $database->name,
                'sublabel' => $database->server?->name,
                'url' => route('servers.databases', $database->server),
                'icon' => 'circle-stack',
            ])
            ->all();
        $groups[] = ['label' => __('Server databases'), 'items' => $serverDatabases];

        if (Feature::active('surface.projects')) {
            $projects = $org->workspaces()
                ->where('name', 'like', $like)
                ->orderByDesc('id')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->map(fn ($workspace): array => [
                    'label' => $workspace->name,
                    'sublabel' => $workspace->slug ?? null,
                    'url' => route('projects.show', $workspace),
                    'icon' => 'rectangle-stack',
                ])
                ->all();
            $groups[] = ['label' => __('Projects'), 'items' => $projects];
        }

        if (Feature::active('surface.cloud')) {
            $databases = CloudDatabase::query()
                ->where('organization_id', $org->id)
                ->where('name', 'like', $like)
                ->orderByDesc('created_at')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->map(fn (CloudDatabase $database): array => [
                    'label' => $database->name,
                    'sublabel' => $database->engine ?? null,
                    'url' => route('cloud.databases.index'),
                    'icon' => 'circle-stack',
                ])
                ->all();
            $groups[] = ['label' => __('Cloud databases'), 'items' => $databases];
        }

        // Quick actions on the matched sites — deploy without drilling in. Capped
        // tighter than the nav hits so a broad query doesn't bury everything
        // under deploy rows; the builder gates on permission and runtime.
        $deployActions = $siteModels
            ->map(fn (Site $site): ?array => $this->siteDeployAction($site, true))
            ->filter()
            ->take(self::SEARCH_ACTION_LIMIT)
            ->values()
            ->all();
        $groups[] = ['label' => __('Quick actions'), 'items' => $deployActions];

        // Documentation hits — searching for a concept ("firewall", "deploy")
        // should reach the guide, not just resources. Capped like other groups.
        $docHits = [];
        $needle = mb_strtolower($query);
        foreach (app(ContextualDocResolver::class)->indexEntries() as $entry) {
            if (count($docHits) >= self::SEARCH_LIMIT) {
                break;
            }
            if (! str_contains(mb_strtolower($entry['title']), $needle)) {
                continue;
            }
            $docHits[] = [
                'label' => $entry['title'],
                'sublabel' => null,
                'docSlug' => $entry['slug'],
                'icon' => 'document-text',
            ];
        }
        $groups[] = ['label' => __('Documentation'), 'items' => $docHits];

        return array_values(array_filter($groups, fn (array $g): bool => $g['items'] !== []));
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function sitesList(?Organization $org, string $query): array
    {
        if ($org === null) {
            return [];
        }
        $items = $this->maybeIndexLink($query, 'all sites index', __('All sites'), __('Open the sites index'), 'sites.index', 'globe-alt');

        $serverIds = $org->serverIds();
        foreach (
            Site::query()
                ->whereIn('server_id', $serverIds)
                ->where('name', 'like', $this->like($query))
                ->with('server')
                ->orderByDesc('id')
                ->limit(self::LIST_LIMIT)
                ->get() as $site
        ) {
            $items[] = [
                'label' => $site->name,
                'sublabel' => $site->server?->name,
                'into' => ['type' => 'site', 'id' => $site->id],
                'icon' => 'globe-alt',
            ];
        }

        return [['label' => __('Sites'), 'items' => $items]];
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function serversList(?Organization $org, string $query): array
    {
        if ($org === null) {
            return [];
        }
        $items = $this->maybeIndexLink($query, 'all servers index', __('All servers'), __('Open the servers index'), 'servers.index', 'server');

        foreach (
            $org->servers()
                ->where('name', 'like', $this->like($query))
                ->orderByDesc('id')
                ->limit(self::LIST_LIMIT)
                ->get() as $server
        ) {
            $items[] = [
                'label' => $server->name,
                'sublabel' => $server->ip_address ?? $server->region ?? null,
                'into' => ['type' => 'server', 'id' => $server->id],
                'icon' => 'server',
            ];
        }

        return [['label' => __('Servers'), 'items' => $items]];
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function workspaceList(?Organization $org, string $query): array
    {
        if ($org === null || ! Feature::active('surface.projects')) {
            return [];
        }
        $items = $this->maybeIndexLink($query, 'all projects index', __('All projects'), __('Open the projects index'), 'projects.index', 'rectangle-stack');

        foreach (
            $org->workspaces()
                ->where('name', 'like', $this->like($query))
                ->orderByDesc('id')
                ->limit(self::LIST_LIMIT)
                ->get() as $workspace
        ) {
            $items[] = [
                'label' => $workspace->name,
                'sublabel' => $workspace->slug ?? null,
                'url' => route('projects.show', $workspace),
                'icon' => 'rectangle-stack',
            ];
        }

        return [['label' => __('Projects'), 'items' => $items]];
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function organizationList(string $query): array
    {
        $items = $this->maybeIndexLink($query, 'all organizations index', __('All organizations'), __('Open the organizations index'), 'organizations.index', 'building-office-2');

        foreach (
            auth()->user()->organizations()
                ->where('name', 'like', $this->like($query))
                ->orderBy('name')
                ->limit(self::LIST_LIMIT)
                ->get() as $organization
        ) {
            $items[] = [
                'label' => $organization->name,
                'sublabel' => null,
                'url' => route('organizations.show', $organization),
                'icon' => 'building-office-2',
            ];
        }

        return [['label' => __('Organizations'), 'items' => $items]];
    }

    /**
     * The other organizations the user belongs to, as switch *actions* (the
     * current org is omitted — you can't switch to where you are).
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function switchOrgList(string $query): array
    {
        $current = auth()->user()?->currentOrganization();

        $items = [];
        foreach (
            auth()->user()->organizations()
                ->where('name', 'like', $this->like($query))
                ->orderBy('name')
                ->limit(self::LIST_LIMIT)
                ->get() as $organization
        ) {
            if ($current !== null && $organization->id === $current->id) {
                continue;
            }
            $items[] = [
                'label' => $organization->name,
                'sublabel' => null,
                'action' => ['key' => 'org.switch', 'id' => $organization->id],
                'icon' => 'building-office-2',
            ];
        }

        return [['label' => __('Switch organization'), 'items' => $items]];
    }

    /**
     * Every documentation page, title-filtered. Rows open the in-app docs panel
     * (no navigation away); a "home" leaf opens the full index.
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function docsList(string $query): array
    {
        $needle = mb_strtolower(trim($query));
        $items = $this->maybeIndexLink($query, 'documentation home index all docs', __('Documentation home'), __('Open the docs index'), 'docs.index', 'document-text');

        foreach (app(ContextualDocResolver::class)->indexEntries() as $entry) {
            if ($needle !== '' && ! str_contains(mb_strtolower($entry['title']), $needle)) {
                continue;
            }
            $items[] = [
                'label' => $entry['title'],
                'sublabel' => null,
                'docSlug' => $entry['slug'],
                'icon' => 'document-text',
            ];
        }

        return [['label' => __('Documentation'), 'items' => $items]];
    }

    /**
     * The "guide for this page" group: a single doc row that opens the panel.
     * Falls back to the resolver's own slug for the resource when the palette
     * wasn't opened directly on a documented page (e.g. drilled in from search).
     *
     * @return array{label: string, items: list<array<string, mixed>>}|null
     */
    private function docGroupFor(?string $fallbackSlug, string $query): ?array
    {
        $slug = $this->contextDocSlug ?? $fallbackSlug;
        if ($slug === null) {
            return null;
        }

        $resolver = app(ContextualDocResolver::class);
        $title = $resolver->titleForSlug($slug);
        if ($title === null) {
            return null;
        }

        $needle = mb_strtolower(trim($query));
        if ($needle !== '' && ! str_contains(mb_strtolower($title.' docs help guide documentation'), $needle)) {
            return null;
        }

        return ['label' => __('Documentation'), 'items' => [[
            'label' => __('Docs: :title', ['title' => $title]),
            'sublabel' => __('Open the guide for this page'),
            'docSlug' => $slug,
            'icon' => 'document-text',
        ]]];
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function siteSubPages(?Organization $org, ?string $id, string $query): array
    {
        $site = $org !== null ? $this->scopedSite($org, $id) : null;
        if ($site === null) {
            return [];
        }
        $server = $site->server;

        // [label, route, icon, keywords]
        $pages = [
            ['Overview', 'sites.show', 'globe-alt', 'overview summary'],
            ['Routing & domains', 'sites.routing', 'share', 'domains dns routing ssl'],
            ['Deployments', 'sites.deployments.index', 'arrows-right-left', 'deploy releases'],
            ['Repository', 'sites.repository', 'code-bracket', 'git repo source branch'],
            ['Monitoring', 'sites.monitor', 'heart', 'monitor uptime metrics'],
            ['Errors', 'sites.errors', 'document-text', 'errors logs exceptions'],
            ['Scheduler', 'sites.schedule', 'command-line', 'cron schedule tasks'],
            ['Queue workers', 'sites.workers', 'bolt', 'workers queue horizon'],
            ['Daemons', 'sites.daemons', 'command-line', 'daemons processes'],
            ['Backups', 'sites.backups', 'circle-stack', 'backups restore'],
            ['Settings', 'sites.settings', 'wrench-screwdriver', 'settings config'],
        ];

        // Actions lead the context — operators reach for "Deploy" far more than
        // any sub-page. The builder gates on permission and picks deploy vs.
        // redeploy by runtime; we just apply the text filter.
        $needle = mb_strtolower(trim($query));
        $actions = [];
        $deploy = $this->siteDeployAction($site, false);
        if ($deploy !== null && ($needle === '' || str_contains('deploy now redeploy release ship', $needle))) {
            $actions[] = $deploy;
        }

        // When this site shares a repo with others (typically its worker), offer
        // a multi-select drill-in so the whole group ships from one place — no
        // bouncing back to each site's deploy page. Only when there's more than
        // one deployable peer (a lone site already has "Deploy now" above).
        $peerCount = $this->deploySyncPeers($site)->count();
        if ($peerCount > 1 && ($needle === '' || str_contains('deploy together sync linked sites workers all group', $needle))) {
            $actions[] = [
                'label' => __('Deploy together…'),
                'sublabel' => __('Pick from :count linked sites and ship at once', ['count' => $peerCount]),
                'into' => ['type' => 'deploy-sync', 'id' => $site->id],
                'icon' => 'arrows-right-left',
            ];
        }

        $docGroup = $this->docGroupFor($this->siteFallbackDocSlug($site), $query);

        return array_values(array_filter([
            ['label' => __('Actions'), 'items' => $actions],
            ['label' => $site->name, 'items' => $this->resolveResourcePages($pages, [$server, $site], $query)],
            $docGroup ?? ['label' => '', 'items' => []],
        ], fn (array $group): bool => $group['items'] !== []));
    }

    /**
     * The "Deploy together" multi-select: a tickable row per repo-sharing peer
     * (seeded all-ticked in {@see push()}) plus a "Deploy N sites" action that
     * fires one deploy job per ticked site. Toggle rows fire {@see toggleDeploySync()}
     * and keep the palette open; the action runs and closes like any other.
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function deploySyncContext(?Organization $org, ?string $id, string $query): array
    {
        $site = $org !== null ? $this->scopedSite($org, $id) : null;
        if ($site === null) {
            return [];
        }

        $peers = $this->deploySyncPeers($site);
        if ($peers->isEmpty()) {
            return [];
        }

        // Keep the live selection honest against the peers actually shown (a peer
        // could have dropped out of the group or the operator's reach mid-session).
        $selected = array_values(array_intersect(
            array_map('strval', $this->deploySyncSelected),
            $peers->pluck('id')->map(fn ($peerId): string => (string) $peerId)->all(),
        ));

        $actions = [];
        if ($selected !== []) {
            $actions[] = [
                'label' => trans_choice('{1}Deploy :count site|[2,*]Deploy :count sites', count($selected), ['count' => count($selected)]),
                'sublabel' => __('Queue a deployment for every ticked site'),
                'action' => ['key' => 'site.deploy-sync', 'id' => $site->id],
                'icon' => 'arrows-right-left',
                'confirm' => true,
            ];
        }

        $needle = mb_strtolower(trim($query));
        $rows = [];
        foreach ($peers as $peer) {
            if ($needle !== '' && ! str_contains(mb_strtolower($peer->name.' '.($peer->server?->name ?? '')), $needle)) {
                continue;
            }
            $isSelf = (string) $peer->id === (string) $site->id;
            $rows[] = [
                'label' => $peer->name,
                'sublabel' => $isSelf
                    ? trim(($peer->server?->name ? $peer->server->name.' · ' : '').__('This site'))
                    : $peer->server?->name,
                'toggle' => ['id' => (string) $peer->id],
                'selected' => in_array((string) $peer->id, $selected, true),
                'icon' => 'globe-alt',
            ];
        }

        return array_values(array_filter([
            ['label' => __('Deploy together'), 'items' => $actions],
            ['label' => __('Linked sites'), 'items' => $rows],
        ], fn (array $group): bool => $group['items'] !== []));
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function serverSubPages(?Organization $org, ?string $id, string $query): array
    {
        $server = $org !== null && $id !== null ? $this->scopedServer($org, $id) : null;
        if ($server === null) {
            return [];
        }

        $pages = [
            ['Overview', 'servers.show', 'server', 'overview summary'],
            ['Sites', 'servers.sites', 'globe-alt', 'sites apps'],
            ['Monitoring', 'servers.monitor', 'heart', 'monitor metrics load'],
            ['Databases', 'servers.databases', 'circle-stack', 'database mysql postgres'],
            ['Firewall', 'servers.firewall', 'shield-check', 'firewall ufw security'],
            ['Networking', 'servers.networking', 'share', 'network ip dns'],
            ['Scheduler', 'servers.cron', 'command-line', 'cron schedule'],
            ['Services', 'servers.services', 'wrench-screwdriver', 'services systemd'],
            ['Backups', 'servers.backups', 'circle-stack', 'backups restore'],
            ['Logs', 'servers.logs', 'document-text', 'logs output'],
            ['SSH keys', 'servers.ssh-keys', 'key', 'ssh keys access'],
            ['Settings', 'servers.settings', 'wrench-screwdriver', 'settings config'],
        ];

        // A safe, fire-and-forget scan — findings update on the server's Insights
        // tab; gated on the same permission that page uses to trigger it.
        $needle = mb_strtolower(trim($query));
        $actions = [];
        if (Gate::allows('view', $server) && ($needle === '' || str_contains('run insights scan checks health audit', $needle))) {
            $actions[] = [
                'label' => __('Run insights scan'),
                'sublabel' => __('Queue server health checks'),
                'action' => ['key' => 'server.insights', 'id' => $server->id],
                'icon' => 'heart',
                'confirm' => true,
            ];
        }

        $docGroup = $this->docGroupFor($this->serverFallbackDocSlug(), $query);

        return array_values(array_filter([
            ['label' => __('Actions'), 'items' => $actions],
            ['label' => $server->name, 'items' => $this->resolveResourcePages($pages, [$server], $query)],
            $docGroup ?? ['label' => '', 'items' => []],
        ], fn (array $group): bool => $group['items'] !== []));
    }
}
