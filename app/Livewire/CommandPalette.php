<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\RealtimeApp;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Livewire\Component;

/**
 * Global Cmd/Ctrl+K command palette.
 *
 * Mounted once on every authenticated page (see layouts/app.blade.php). The
 * palette is *nestable*: a server-side {@see $stack} of contexts lets the
 * operator drill from a category (Sites, Servers, Settings…) into its members,
 * and from a single site/server into that resource's own sub-pages. Leaf rows
 * carry a `url` and navigate; nestable rows carry an `into` and push a context.
 *
 * The Alpine view owns open/close, keyboard navigation and the final click;
 * Livewire owns the stack, the query and the org-scoped lookups.
 */
class CommandPalette extends Component
{
    /** The live search query, bound from the palette input. */
    public string $query = '';

    /**
     * The drill-down context stack. Empty = root. Each entry is the context
     * the operator navigated into.
     *
     * @var list<array{type: string, id: ?string, label: string}>
     */
    public array $stack = [];

    /** Per-list result cap so a broad context can't balloon the payload. */
    private const LIST_LIMIT = 50;

    /** Smaller cap for the root direct-hit search across every resource. */
    private const SEARCH_LIMIT = 6;

    /** Category contexts whose label is static (not derived from a record). */
    private function categoryLabels(): array
    {
        return [
            'sites' => 'Sites',
            'servers' => 'Servers',
            'projects' => 'Projects',
            'realtime' => 'Realtime',
            'organizations' => 'Organizations',
            'settings' => 'Settings',
            'admin' => 'Admin',
        ];
    }

    /**
     * Drill into a context. Category labels come from the static map; a single
     * site/server resolves its label (and is org-scoped) from the record.
     */
    public function push(string $type, ?string $id = null): void
    {
        $org = auth()->user()?->currentOrganization();
        $label = $this->categoryLabels()[$type] ?? null;

        if ($type === 'site' && $id !== null) {
            $label = $this->scopedSite($org, $id)?->name;
        } elseif ($type === 'server' && $id !== null) {
            $label = $this->scopedServer($org, $id)?->name;
        }

        if ($label === null) {
            return; // unknown context or a record outside the current org
        }

        $this->stack[] = ['type' => $type, 'id' => $id, 'label' => $label];
        $this->query = '';
        $this->dispatch('cmdk-changed');
    }

    /** Pop one level back up the stack. */
    public function pop(): void
    {
        array_pop($this->stack);
        $this->query = '';
        $this->dispatch('cmdk-changed');
    }

    /** Trim the stack to a given depth (breadcrumb click). */
    public function popTo(int $depth): void
    {
        $this->stack = array_slice($this->stack, 0, max(0, $depth));
        $this->query = '';
        $this->dispatch('cmdk-changed');
    }

    /** Return to root — used on breadcrumb "Home" and on close. */
    public function resetStack(): void
    {
        $this->stack = [];
        $this->query = '';
    }

    public function render(): View
    {
        $context = $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
        $placeholder = $context !== null
            ? __('Search :context…', ['context' => $context['label']])
            : __('Search sites, servers, projects…');

        return view('livewire.command-palette', [
            'groups' => $this->groups($context),
            'stack' => $this->stack,
            'placeholder' => $placeholder,
        ]);
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
            'servers' => $this->serversList($org, $query),
            'server' => $this->serverSubPages($org, $context['id'] ?? null, $query),
            'projects' => $this->workspaceList($org, $query),
            'realtime' => $this->realtimeList($org, $query),
            'organizations' => $this->organizationList($query),
            'settings' => $this->commandContext('settings', $org, $query),
            'admin' => $this->commandContext('admin', $org, $query),
            default => $this->rootGroups($org, $query),
        };

        // Drop empty groups so the palette never shows a bare header.
        return array_values(array_filter($raw, fn (array $group): bool => $group['items'] !== []));
    }

    // ── Root ────────────────────────────────────────────────────────────────

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

        // Nestable resource categories.
        $resourceNav = [
            ['Sites', 'sites', 'globe-alt', 'sites apps websites'],
            ['Servers', 'servers', 'server', 'servers vms hosts machines'],
            ['Projects', 'projects', 'rectangle-stack', 'projects workspaces', 'surface.projects'],
            ['Realtime', 'realtime', 'signal', 'realtime websockets pusher', 'surface.realtime'],
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
        if ($isAdmin && ($needle === '' || str_contains('admin platform', $needle))) {
            $manage[] = ['label' => __('Admin'), 'sublabel' => __('Browse…'), 'into' => ['type' => 'admin'], 'icon' => 'wrench-screwdriver'];
        }
        $groups[] = ['label' => __('Manage'), 'items' => $manage];

        return $groups;
    }

    /**
     * Org-scoped direct-hit search across every resource (root level only).
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function searchGroups(Organization $org, string $query): array
    {
        $like = $this->like($query);
        $serverIds = $org->servers()->pluck('id');
        $groups = [];

        $sites = Site::query()
            ->whereIn('server_id', $serverIds)
            ->where('name', 'like', $like)
            ->with('server')
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get()
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
            $groups[] = ['label' => __('Databases'), 'items' => $databases];
        }

        return array_values(array_filter($groups, fn (array $g): bool => $g['items'] !== []));
    }

    // ── Resource lists (one level down) ──────────────────────────────────────

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function sitesList(?Organization $org, string $query): array
    {
        if ($org === null) {
            return [];
        }
        $items = $this->maybeIndexLink($query, 'all sites index', __('All sites'), __('Open the sites index'), 'sites.index', 'globe-alt');

        $serverIds = $org->servers()->pluck('id');
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
    private function realtimeList(?Organization $org, string $query): array
    {
        if ($org === null || ! Feature::active('surface.realtime')) {
            return [];
        }
        $items = $this->maybeIndexLink($query, 'all realtime apps index', __('All realtime apps'), __('Open the realtime index'), 'realtime.index', 'signal');

        foreach (
            RealtimeApp::query()
                ->where('organization_id', $org->id)
                ->where('name', 'like', $this->like($query))
                ->orderByDesc('created_at')
                ->limit(self::LIST_LIMIT)
                ->get() as $app
        ) {
            $items[] = [
                'label' => $app->name,
                'sublabel' => null,
                'url' => route('realtime.show', $app),
                'icon' => 'signal',
            ];
        }

        return [['label' => __('Realtime'), 'items' => $items]];
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

    // ── Single-resource sub-pages (two levels down) ──────────────────────────

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
            ['Scheduler', 'sites.cron', 'command-line', 'cron schedule tasks'],
            ['Queue workers', 'sites.workers', 'bolt', 'workers queue horizon'],
            ['Daemons', 'sites.daemons', 'command-line', 'daemons processes'],
            ['Backups', 'sites.backups', 'circle-stack', 'backups restore'],
            ['Settings', 'sites.settings', 'wrench-screwdriver', 'settings config'],
        ];

        return [[
            'label' => $site->name,
            'items' => $this->resolveResourcePages($pages, [$server, $site], $query),
        ]];
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

        return [[
            'label' => $server->name,
            'items' => $this->resolveResourcePages($pages, [$server], $query),
        ]];
    }

    // ── Command contexts (settings / admin) ──────────────────────────────────

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function commandContext(string $key, ?Organization $org, string $query): array
    {
        $specs = $this->commandSpecs();
        $items = $this->resolveCommandItems($specs[$key] ?? [], $org, mb_strtolower(trim($query)), Gate::check('viewPlatformAdmin'));

        return [['label' => __(ucfirst($key)), 'items' => $items]];
    }

    // ── Command specs ────────────────────────────────────────────────────────

    /**
     * @return array<string, list<array{0: string, 1: string, 2: string, 3: string, 4?: array<string, mixed>, 5?: ?string, 6?: bool}>>
     */
    private function commandSpecs(): array
    {
        return [
            'create' => [
                ['New server', 'create add provision vm droplet host', 'servers.create', 'plus-circle'],
                ['New launch', 'create launch wizard stack', 'launches.create', 'plus-circle'],
                ['New cloud app', 'create cloud paas deploy', 'cloud.create', 'cube', [], 'surface.cloud'],
                ['New cloud database', 'create database postgres mysql redis', 'cloud.databases.create', 'circle-stack', [], 'surface.cloud'],
                ['New serverless function', 'create function faas', 'serverless.create', 'bolt', [], 'surface.serverless'],
                ['New edge app', 'create edge worker', 'edge.create', 'globe-alt', [], 'surface.edge'],
                ['New realtime app', 'create realtime websockets pusher', 'realtime.create', 'signal', [], 'surface.realtime'],
                ['New project', 'create project workspace', 'projects.index', 'rectangle-stack', [], 'surface.projects'],
                ['New organization', 'create organization team', 'organizations.create', 'building-office-2'],
                ['New script', 'create script automation', 'scripts.create', 'code-bracket', [], 'surface.scripts'],
                ['Import from DigitalOcean', 'import digitalocean do droplet', 'servers.import.digitalocean', 'cloud-arrow-down'],
                ['Import from Forge', 'import forge migrate', 'imports.forge.inventory', 'cloud-arrow-down'],
                ['Import from Ploi', 'import ploi migrate', 'imports.ploi.inventory', 'cloud-arrow-down'],
            ],
            'go' => [
                ['Dashboard', 'home overview', 'dashboard', 'squares-2x2'],
                ['Networking', 'firewall dns network load balancer', 'networking.index', 'share'],
                ['Infrastructure', 'infrastructure fleet overview', 'infrastructure.index', 'rectangle-group'],
                ['Fleet health', 'fleet health monitoring', 'fleet.health', 'heart', [], 'surface.fleet'],
                ['Cloud apps', 'cloud paas managed', 'cloud.index', 'cube', [], 'surface.cloud'],
                ['Cloud databases', 'database postgres mysql redis', 'cloud.databases.index', 'circle-stack', [], 'surface.cloud'],
                ['Serverless', 'functions faas', 'serverless.index', 'bolt', [], 'surface.serverless'],
                ['Edge', 'workers cdn edge', 'edge.index', 'globe-alt', [], 'surface.edge'],
                ['Deploy sync', 'deploy groups sync', 'deploy-sync.index', 'arrows-right-left'],
                ['Scripts', 'scripts automation', 'scripts.index', 'code-bracket', [], 'surface.scripts'],
                ['Script marketplace', 'scripts marketplace presets', 'scripts.marketplace', 'rectangle-group', [], 'surface.scripts'],
                ['Marketplace', 'marketplace apps', 'marketplace.index', 'rectangle-group', [], 'surface.marketplace'],
                ['Backups — databases', 'backup database restore', 'backups.databases', 'circle-stack'],
                ['Backups — files', 'backup files restore', 'backups.files', 'document-text'],
                ['Status pages', 'status incident uptime', 'status-pages.index', 'document-text', [], 'surface.status_pages'],
                ['Notifications', 'inbox alerts', 'notifications.index', 'bell'],
            ],
            'settings' => [
                ['Profile', 'account preferences profile', 'settings.profile', 'user'],
                ['Security', 'password security', 'profile.security', 'shield-check'],
                ['Two-factor auth', '2fa mfa two factor authentication', 'two-factor.setup', 'shield-check'],
                ['SSH keys', 'ssh keys access', 'profile.ssh-keys', 'key'],
                ['API keys', 'api tokens keys', 'profile.api-keys', 'key'],
                ['CLI tokens', 'cli command line tokens', 'profile.cli', 'command-line'],
                ['Source control', 'github gitlab source control git', 'profile.source-control', 'code-bracket'],
                ['Notification channels', 'slack email webhook channels', 'profile.notification-channels', 'bell'],
                ['Backup configurations', 'backup config s3 storage', 'profile.backup-configurations', 'circle-stack'],
                ['Referrals', 'referral invite friends', 'profile.referrals', 'user'],
                ['Billing', 'billing invoices payment plan', 'billing.show', 'credit-card', ['org' => true]],
                ['Invoices', 'invoices billing receipts', 'billing.invoices', 'credit-card', ['org' => true]],
                ['Org members', 'organization members invite', 'organizations.members', 'building-office-2', ['org' => true]],
                ['Org teams', 'organization teams', 'organizations.teams', 'building-office-2', ['org' => true]],
            ],
            'admin' => [
                ['Admin overview', 'admin platform overview', 'admin.overview', 'wrench-screwdriver', [], null, true],
                ['Admin operations', 'admin operations ops', 'admin.operations', 'wrench-screwdriver', [], null, true],
                ['Audit log', 'admin audit log', 'admin.audit', 'document-text', [], null, true],
                ['Global feature flags', 'admin flags features', 'admin.flags.global', 'wrench-screwdriver', [], null, true],
                ['Beta invites', 'admin beta invites', 'admin.beta-invites', 'wrench-screwdriver', [], null, true],
                ['Platform organizations', 'admin organizations', 'admin.organizations.index', 'building-office-2', [], null, true],
            ],
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Filter + resolve a list of command specs into leaf items.
     *
     * @param  list<array<int, mixed>>  $commands
     * @return list<array{label: string, sublabel: ?string, url: string, icon: string}>
     */
    private function resolveCommandItems(array $commands, ?Organization $org, string $needle, bool $isAdmin): array
    {
        $items = [];
        foreach ($commands as $command) {
            [$label, $keywords, $routeName, $icon] = $command;
            $params = $command[4] ?? [];
            $feature = $command[5] ?? null;
            $adminOnly = $command[6] ?? false;

            if ($adminOnly && ! $isAdmin) {
                continue;
            }
            if ($feature !== null && ! Feature::active($feature)) {
                continue;
            }
            if ($needle !== '' && ! str_contains(mb_strtolower($label.' '.$keywords), $needle)) {
                continue;
            }

            $url = $this->resolveUrl($routeName, $params, $org);
            if ($url === null) {
                continue;
            }

            $items[] = ['label' => __($label), 'sublabel' => null, 'url' => $url, 'icon' => $icon];
        }

        return $items;
    }

    /**
     * Resolve a single resource's sub-pages into leaf items, scoped + filtered.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $pages
     * @param  array<int, mixed>  $routeParams
     * @return list<array{label: string, sublabel: ?string, url: string, icon: string}>
     */
    private function resolveResourcePages(array $pages, array $routeParams, string $query): array
    {
        $needle = mb_strtolower(trim($query));
        $items = [];
        foreach ($pages as [$label, $routeName, $icon, $keywords]) {
            if ($needle !== '' && ! str_contains(mb_strtolower($label.' '.$keywords), $needle)) {
                continue;
            }
            try {
                $url = route($routeName, $routeParams);
            } catch (\Throwable) {
                continue;
            }
            $items[] = ['label' => __($label), 'sublabel' => null, 'url' => $url, 'icon' => $icon];
        }

        return $items;
    }

    /**
     * A single "open the index" leaf, shown when the query is empty or matches.
     *
     * @return list<array{label: string, sublabel: ?string, url: string, icon: string}>
     */
    private function maybeIndexLink(string $query, string $haystack, string $label, string $sublabel, string $routeName, string $icon): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle !== '' && ! str_contains($haystack, $needle)) {
            return [];
        }
        $url = $this->resolveUrl($routeName, [], auth()->user()?->currentOrganization());

        return $url === null ? [] : [['label' => $label, 'sublabel' => $sublabel, 'url' => $url, 'icon' => $icon]];
    }

    /**
     * Resolve a command's URL, returning null when it can't be built — an
     * org-scoped route with no current org, or an unregistered route name.
     *
     * @param  array<string, mixed>  $params
     */
    private function resolveUrl(string $routeName, array $params, ?Organization $org): ?string
    {
        $routeParams = [];
        if (($params['org'] ?? false) === true) {
            if ($org === null) {
                return null;
            }
            $routeParams['organization'] = $org;
        }

        try {
            return route($routeName, $routeParams);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Org-scoped site lookup (eager-loads the server for deep links). */
    private function scopedSite(?Organization $org, ?string $id): ?Site
    {
        if ($org === null || $id === null) {
            return null;
        }

        return Site::query()
            ->whereIn('server_id', $org->servers()->pluck('id'))
            ->with('server')
            ->find($id);
    }

    /** Org-scoped server lookup. */
    private function scopedServer(?Organization $org, ?string $id): ?Server
    {
        if ($org === null || $id === null) {
            return null;
        }

        return $org->servers()->find($id);
    }

    /** Build an escaped LIKE pattern for a free-text query. */
    private function like(string $query): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], trim($query)).'%';
    }
}
