<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Jobs\RedeployCloudSiteJob;
use App\Jobs\RunServerInsightsJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\RealtimeApp;
use App\Models\RecentResource;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Support\Docs\ContextualDocResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
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

    /**
     * The resource the current page is *about*, captured at mount from the
     * route. The palette opens drilled into it (so "Deploy" is one keystroke
     * from any site page) and returns to it on close. Null on pages that aren't
     * a single site/server.
     *
     * @var array{type: string, id: string, label: string}|null
     */
    public ?array $contextSeed = null;

    /**
     * The documentation slug most relevant to the page the palette opened on,
     * resolved at mount from the route + section. Lets the palette offer "the
     * guide for *this* page" no matter how deep you've drilled. Null off any
     * documented page.
     */
    public ?string $contextDocSlug = null;

    /** Per-list result cap so a broad context can't balloon the payload. */
    private const LIST_LIMIT = 50;

    /** Smaller cap for the root direct-hit search across every resource. */
    private const SEARCH_LIMIT = 6;

    /** Tighter cap for per-result quick actions in root search (e.g. deploy). */
    private const SEARCH_ACTION_LIMIT = 3;

    /**
     * Capture the current page's resource so the palette opens in context.
     * Runs once per page render (the palette re-mounts on each wire:navigate, so
     * this stays in step with navigation). Route-model binding means the `site`
     * / `server` route parameters are already-resolved models. The seed is a
     * starting point only — every action re-resolves org-scoped and re-authorizes.
     */
    public function mount(): void
    {
        $route = request()->route();
        if ($route === null) {
            return;
        }

        $site = $route->parameter('site');
        $server = $route->parameter('server');

        if ($site instanceof Site) {
            $this->contextSeed = ['type' => 'site', 'id' => (string) $site->getKey(), 'label' => (string) $site->name];
        } elseif ($server instanceof Server) {
            $this->contextSeed = ['type' => 'server', 'id' => (string) $server->getKey(), 'label' => (string) $server->name];
        }

        if ($this->contextSeed !== null) {
            $this->stack = [$this->contextSeed];

            // The resolver reads the live route (including the site section /
            // server workspace tab), so the captured slug is page-specific.
            try {
                $this->contextDocSlug = app(ContextualDocResolver::class)->resolve();
            } catch (\Throwable) {
                $this->contextDocSlug = null;
            }
        }
    }

    /** Category contexts whose label is static (not derived from a record). */
    private function categoryLabels(): array
    {
        return [
            'sites' => 'Sites',
            'servers' => 'Servers',
            'projects' => 'Projects',
            'realtime' => 'Realtime',
            'organizations' => 'Organizations',
            'switch-org' => 'Switch organization',
            'docs' => 'Documentation',
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

        // Record the drill-in so the empty-query root can offer "Recently
        // visited". Only the per-record contexts (site/server) are worth it.
        if ($id !== null && in_array($type, ['site', 'server'], true)) {
            RecentResource::record(auth()->id(), $type, $id);
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

    /** Return to true root — used by the breadcrumb "Home" button. */
    public function resetStack(): void
    {
        $this->stack = [];
        $this->query = '';
    }

    /**
     * Reset to the page's context (or true root if none) — used on close, so the
     * next open starts where the current page expects rather than at bare root.
     */
    public function resetToContext(): void
    {
        $this->stack = $this->contextSeed !== null ? [$this->contextSeed] : [];
        $this->query = '';
    }

    /**
     * Run an action row. Actions *do* something (dispatch a job, switch org)
     * rather than navigate; the view routes here via wire:click. Every action
     * re-resolves and re-authorizes its target server-side — the rendered row
     * is a convenience, never the authority.
     */
    public function run(string $key, ?string $id = null): mixed
    {
        $org = auth()->user()?->currentOrganization();

        return match ($key) {
            'site.deploy' => $this->runSiteDeploy($org, $id),
            'site.redeploy' => $this->runSiteRedeploy($org, $id),
            'server.insights' => $this->runServerInsights($org, $id),
            'org.switch' => $this->runOrgSwitch($id),
            default => null,
        };
    }

    /** Queue a manual deployment for a VM-hosted, org-scoped site. */
    private function runSiteDeploy(?Organization $org, ?string $id): void
    {
        $site = $this->scopedSite($org, $id);
        if ($site === null) {
            return;
        }
        Gate::authorize('update', $site);

        RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
        $this->dispatch('notify', message: __('Deployment queued for :site.', ['site' => $site->name]));
    }

    /** Roll a new container deployment for an org-scoped cloud/container site. */
    private function runSiteRedeploy(?Organization $org, ?string $id): void
    {
        $site = $this->scopedSite($org, $id);
        if ($site === null || ! $site->usesContainerRuntime()) {
            return;
        }
        Gate::authorize('update', $site);

        RedeployCloudSiteJob::dispatch($site->id);
        $this->dispatch('notify', message: __('Redeploy queued for :site.', ['site' => $site->name]));
    }

    /** Queue a full server insights scan; findings land on the Insights tab. */
    private function runServerInsights(?Organization $org, ?string $id): void
    {
        $server = $this->scopedServer($org, $id);
        if ($server === null) {
            return;
        }
        Gate::authorize('view', $server);

        RunServerInsightsJob::dispatch($server->id, null, (string) Str::ulid());
        $this->dispatch('notify', message: __('Insights scan queued for :server.', ['server' => $server->name]));
    }

    /**
     * The deploy action row for a site — "Redeploy" for container/cloud sites
     * (their roll uses a different job) or "Deploy now" for VM sites. Returns
     * null when the user can't deploy it. `$withName` labels it with the site
     * name for the shared root search; the in-context row stays terse.
     *
     * @return array<string, mixed>|null
     */
    private function siteDeployAction(Site $site, bool $withName): ?array
    {
        if (! Gate::allows('update', $site)) {
            return null;
        }
        $container = $site->usesContainerRuntime();

        return [
            'label' => $withName
                ? ($container ? __('Redeploy :site', ['site' => $site->name]) : __('Deploy :site', ['site' => $site->name]))
                : ($container ? __('Redeploy') : __('Deploy now')),
            'sublabel' => $withName
                ? $site->server?->name
                : ($container ? __('Roll a new container deployment') : __('Queue a manual deployment')),
            'action' => ['key' => $container ? 'site.redeploy' : 'site.deploy', 'id' => $site->id],
            'icon' => 'arrows-right-left',
            'confirm' => true,
        ];
    }

    /** Switch the active organization (membership-checked) and reload. */
    private function runOrgSwitch(?string $id): mixed
    {
        $user = auth()->user();
        if ($id === null || $user === null) {
            return null;
        }
        if (! $user->organizations()->where('organizations.id', $id)->exists()) {
            return null; // not a member — render is stale or tampered
        }

        Session::put('current_organization_id', $id);
        Session::forget('current_team_id');
        Session::flash('success', __('Organization switched.'));

        return $this->redirect(route('dashboard'), navigate: true);
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
            'switch-org' => $this->switchOrgList($query),
            'docs' => $this->docsList($query),
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

    /** How many recents the empty-query root surfaces. */
    private const RECENT_LIMIT = 5;

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
        $serverIds = $org->servers()->pluck('id');
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

        if (Feature::active('surface.realtime')) {
            $realtime = $org->realtimeApps()
                ->where('name', 'like', $like)
                ->orderByDesc('created_at')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->map(fn (RealtimeApp $app): array => [
                    'label' => $app->name,
                    'sublabel' => null,
                    'url' => route('realtime.show', $app),
                    'icon' => 'signal',
                ])
                ->all();
            $groups[] = ['label' => __('Realtime'), 'items' => $realtime];
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

        // Actions lead the context — operators reach for "Deploy" far more than
        // any sub-page. The builder gates on permission and picks deploy vs.
        // redeploy by runtime; we just apply the text filter.
        $needle = mb_strtolower(trim($query));
        $actions = [];
        $deploy = $this->siteDeployAction($site, false);
        if ($deploy !== null && ($needle === '' || str_contains('deploy now redeploy release ship', $needle))) {
            $actions[] = $deploy;
        }

        $docGroup = $this->docGroupFor($this->siteFallbackDocSlug($site), $query);

        return array_values(array_filter([
            ['label' => __('Actions'), 'items' => $actions],
            ['label' => $site->name, 'items' => $this->resolveResourcePages($pages, [$server, $site], $query)],
            $docGroup ?? ['label' => '', 'items' => []],
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

    /** Best doc slug for a site when the palette wasn't opened on its page. */
    private function siteFallbackDocSlug(Site $site): ?string
    {
        try {
            return app(ContextualDocResolver::class)->resolveForSiteSection($site, 'general');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Best doc slug for a server when the palette wasn't opened on its page. */
    private function serverFallbackDocSlug(): ?string
    {
        try {
            return app(ContextualDocResolver::class)->resolveForServerWorkspace(null);
        } catch (\Throwable) {
            return null;
        }
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
