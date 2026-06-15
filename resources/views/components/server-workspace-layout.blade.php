@props([
    'server',
    'active',
    'title',
    'description' => null,
    'showNavigation' => null,
    /** @var \App\Models\Site|null Optional site context (site-scoped cron/daemons routes). */
    'contextSite' => null,
    'docRoute' => null,
    'docSlug' => null,
    'docLabel' => null,
    /** Match fleet-style headers (Servers / Sites): icon + title left, docs + actions right on large screens. */
    'pageHeaderToolbar' => false,
    'pageHeaderCompact' => false,
    /** Suppress the generic hero-card header when the page renders its own identity card (e.g. the Overview identity-hero). */
    'hideHero' => false,
])

<x-server-workspace-shell :server="$server" :active="$active" :show-navigation="$showNavigation">
    @if (($showNavigation ?? ($server->status === \App\Models\Server::STATUS_READY && $server->setup_status === \App\Models\Server::SETUP_STATUS_DONE)) === true)
        @include('livewire.servers.partials.workspace-mobile-nav', ['server' => $server, 'active' => $active])
    @endif

    @php
        // Read the active item label from the *role-aware* nav so role overrides
        // (e.g. `caches` labelled "Redis" on a redis-role server) surface in the
        // breadcrumb too. Falls back to the base nav for active keys the role
        // filter would hide — defence in case of a stale deep link bookmark.
        $activePageItem = null;
        if (filled($active) && $active !== 'overview') {
            $roleAwareNav = server_workspace_nav_for_server($server);
            foreach ($roleAwareNav as $navItem) {
                if (is_array($navItem) && ($navItem['key'] ?? null) === $active) {
                    $activePageItem = [
                        'label' => __($navItem['label'] ?? ucfirst((string) $active)),
                        'icon' => $navItem['icon'] ?? null,
                    ];
                    break;
                }
            }
            if ($activePageItem === null) {
                foreach (config('server_workspace.nav', []) as $navItem) {
                    if (is_array($navItem) && ($navItem['key'] ?? null) === $active) {
                        $activePageItem = [
                            'label' => __($navItem['label'] ?? ucfirst((string) $active)),
                            'icon' => $navItem['icon'] ?? null,
                        ];
                        break;
                    }
                }
            }
        }

        $workspaceBreadcrumbs = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server-stack'],
        ];
        if ($server->workspace && \Laravel\Pennant\Feature::active('surface.projects')) {
            $workspaceBreadcrumbs[] = [
                'label' => $server->workspace->name,
                'href' => route('projects.resources', $server->workspace),
                'icon' => 'rectangle-group',
            ];
        }
        if ($contextSite) {
            $workspaceBreadcrumbs[] = [
                'label' => $server->name,
                'href' => route('servers.overview', $server),
                'icon' => 'server-stack',
                'avatar' => $server->name ?: (string) $server->id,
                'avatar_image' => $server->logoUrl(),
            ];
            $workspaceBreadcrumbs[] = [
                'label' => $contextSite->name,
                'href' => $activePageItem ? route('sites.show', ['server' => $server, 'site' => $contextSite]) : null,
                'icon' => 'globe-alt',
                'avatar' => $contextSite->name ?: (string) $contextSite->id,
                'avatar_image' => $contextSite->logoUrl(),
            ];
        } else {
            $workspaceBreadcrumbs[] = [
                'label' => $server->name,
                'href' => $activePageItem ? route('servers.overview', $server) : null,
                'icon' => 'server-stack',
                'avatar' => $server->name ?: (string) $server->id,
                'avatar_image' => $server->logoUrl(),
            ];
        }

        if ($activePageItem) {
            $workspaceBreadcrumbs[] = $activePageItem;
        }

        $contextualDocSlug = app(\App\Support\Docs\ContextualDocResolver::class)
            ->resolveForServerWorkspace(is_string($active) ? $active : null);
    @endphp
    {{-- Full-width breadcrumb at the very top of the workspace (above the sidebar
         + content grid), matching the site pages. --}}
    <x-slot:breadcrumb>
        <x-breadcrumb-trail
            :items="$workspaceBreadcrumbs"
            doc-contextual
            :contextual-doc-slug="$contextualDocSlug"
        >
            @if ($server->workspace || isset($headerActions))
                <x-slot name="trailing">
                    @isset($headerActions)
                        {{ $headerActions }}
                    @endisset
                    @if ($server->workspace)
                        @feature('surface.projects')
                            <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                                {{ __('Open project workspace') }}
                            </a>
                        @endfeature
                    @endif
                </x-slot>
            @endif
        </x-breadcrumb-trail>
    </x-slot:breadcrumb>

    @unless ($hideHero)
        <x-hero-card
            :title="$contextSite ? $title.' — '.$contextSite->name : $title"
            :description="$description"
            icon="server-stack"
        >
            @isset($headerLeading)
                <x-slot:leading>
                    {{ $headerLeading }}
                </x-slot:leading>
            @endisset
        </x-hero-card>
    @endunless

    <div @class(['space-y-8', 'mt-6 sm:mt-8' => ! $hideHero])>
        {{ $slot }}
    </div>

    {{ $modals ?? '' }}
</x-server-workspace-shell>
