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
    /** Tighten the page header and the gaps between content sections. Applies
        to the hero card and, for `hideHero` pages, to the content rhythm alone. */
    'pageHeaderCompact' => false,
    /** Suppress the generic hero-card header when the page renders its own identity card (e.g. the Overview identity-hero). */
    'hideHero' => false,
])

@php
    // Server workspace half of the production-data banner that sites/show and
    // sites/settings already carry. Without it these pages look like any other
    // local workspace right up until an SSH-backed section refuses to run — the
    // operator has no standing signal that the host belongs to another control
    // plane. Same derivation as the site pages: mirrored row + a live
    // connection, since an expired one can't reach anything to warn about.
    $productionMirrorServer = data_get($server->meta ?? [], 'production_data_mirror') === true;
    $productionConnection = $productionMirrorServer && production_data_mirror_connected()
        ? app(\App\Services\ProductionData\ProductionDataMirror::class)->connectionFor(auth()->user())
        : null;
@endphp

{{-- Livewire full-page views need a single root; the banner is a sibling of the
     shell so it spans edge-to-edge like it does on the site pages. --}}
<div class="contents">
@if ($productionConnection)
    <x-production-data-banner
        :connection="$productionConnection"
        :writes-unlocked="app(\App\Services\ProductionData\ProductionDataMirror::class)->writesUnlocked()"
    >
        <x-slot:actions>
            <a href="{{ route('live.servers.index') }}" wire:navigate class="rounded-lg bg-amber-950/10 px-3 py-1.5 text-sm font-semibold hover:bg-amber-950/15">
                {{ __('Production servers') }}
            </a>
        </x-slot:actions>
    </x-production-data-banner>
@endif

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
        // The project deliberately isn't a crumb, matching the site trail — see
        // the same note in App\Support\Sites\SiteWorkspaceBreadcrumbs. It isn't a
        // step on this path (you don't reach the server through the project), and
        // including it pushed the bar toward wrapping. The sites trail dropped it
        // and the server trail was simply never brought in line.
        //
        // Reachability is unaffected: the "Open project workspace" link still
        // sits at the end of this same breadcrumb row (see the trailing slot
        // below), plus the server overview and the Projects surface.
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

        $contextualDocSlug = app(\App\Modules\Docs\Support\ContextualDocResolver::class)
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
                            <x-outline-link href="{{ route('projects.resources', $server->workspace) }}" wire:navigate size="sm">
                                {{ __('Open project workspace') }}
                            </x-outline-link>
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
            :compact="$pageHeaderCompact"
            icon="server-stack"
        >
            @isset($headerLeading)
                <x-slot:leading>
                    {{ $headerLeading }}
                </x-slot:leading>
            @endisset
        </x-hero-card>
    @endunless

    @php
        // When the active page belongs to a collapsed cluster (Access, Network,
        // Backups, Scheduled tasks), surface the cluster's member pages as a
        // secondary tab strip. Derived from the same role-aware nav the sidebar
        // uses, so feature/structural gating is already applied.
        $clusterTabs = null;
        if (filled($active)) {
            foreach (server_workspace_nav_for_server($server) as $navItem) {
                if (is_array($navItem)
                    && ! empty($navItem['tabs'])
                    && in_array($active, (array) ($navItem['match_keys'] ?? []), true)
                ) {
                    $clusterTabs = $navItem['tabs'];
                    break;
                }
            }
        }
    @endphp

    <div @class([
        'space-y-8' => ! $pageHeaderCompact,
        'space-y-5' => $pageHeaderCompact,
        'mt-6 sm:mt-8' => ! $hideHero && ! $pageHeaderCompact,
        'mt-4 sm:mt-5' => ! $hideHero && $pageHeaderCompact,
    ])>
        @if ($clusterTabs && count($clusterTabs) > 1)
            <x-server-workspace-tablist :aria-label="__('Section tabs')" scroll>
                @foreach ($clusterTabs as $tab)
                    <x-server-workspace-tab
                        as="a"
                        href="{{ $tab['url'] }}"
                        wire:navigate
                        :icon="$tab['icon'] ?? null"
                        :active="$active === $tab['key']"
                    >
                        {{ $tab['label'] }}
                        @if (! empty($tab['preview_only']) || ! empty($tab['soon_badge']))
                            <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Soon') }}</span>
                        @endif
                        @if (! empty($tab['needs_setup']))
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500" role="img" aria-label="{{ __('Setup required') }}"></span>
                        @endif
                    </x-server-workspace-tab>
                @endforeach
            </x-server-workspace-tablist>
        @endif

        {{ $slot }}
    </div>

    {{ $modals ?? '' }}
</x-server-workspace-shell>
</div>
