@props([
    /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Support\Servers\ServerIndexRow>> */
    'groupedRows',
    /** @var array{total: int, ready: int, attention: int, sites: int} */
    'summary',
    'hasServersInScope' => true,
    /** @var array<string, string> */
    'statusOptions' => [],
    /** @var array<string, string> */
    'sortOptions' => [],
    /** @var list<string> */
    'tagOptions' => [],
    /** @var list<array{label: string, href?: string, icon?: string}> */
    'breadcrumbs' => [],
    'viewMode' => 'list',
    /** Local fleet only — Production mirror must leave this false. */
    'showFleetOps' => false,
    /** Deploy / Sync servers — local Livewire or Production API (same buttons). */
    'showDeployActions' => false,
    /** Destructive local-only actions (delete / schedule removal). */
    'showMutations' => false,
    'showHeroActions' => false,
    'eyebrow' => null,
    'statusFilter' => '',
    'sort' => 'created_at',
    'tagFilter' => '',
    'sitesIndexUrl' => null,
    'emptyState' => 'local',
])

@php
    $showFleetOps = filter_var($showFleetOps, FILTER_VALIDATE_BOOLEAN);
    $showDeployActions = filter_var($showDeployActions, FILTER_VALIDATE_BOOLEAN);
    $showMutations = filter_var($showMutations, FILTER_VALIDATE_BOOLEAN);
    $showHeroActions = filter_var($showHeroActions, FILTER_VALIDATE_BOOLEAN);
    $heroActionsHtml = isset($actions) ? trim(preg_replace('/<!--.*?-->/s', '', (string) $actions) ?? '') : '';
    $sitesIndexUrl ??= route('sites.index');
    $filtersActive = $statusFilter !== ''
        || $sort !== 'created_at'
        || trim((string) $tagFilter) !== '';
    $isProductionSurface = $emptyState === 'production';
    $summaryStats = [
        ['icon' => 'heroicon-o-server-stack', 'label' => __('Servers'), 'value' => $summary['total'] ?? 0, 'tone' => 'text-brand-sage'],
        ['icon' => 'heroicon-o-check-circle', 'label' => __('Ready'), 'value' => $summary['ready'] ?? 0, 'tone' => 'text-brand-sage'],
        ['icon' => 'heroicon-o-exclamation-triangle', 'label' => __('Attention'), 'value' => $summary['attention'] ?? 0, 'tone' => ($summary['attention'] ?? 0) > 0 ? 'text-amber-500' : 'text-brand-mist'],
        ['icon' => 'heroicon-o-globe-alt', 'label' => __('Sites'), 'value' => $summary['sites'] ?? 0, 'tone' => 'text-brand-sage'],
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="$breadcrumbs" />

    <x-profile-shell
        :title="__('Servers')"
        :description="$isProductionSurface
            ? __('Live servers from the connected control plane — Manage opens the real workspace with Production data.')
            : __('Provision hosts, watch readiness, and drill into each machine from one fleet view.')"
        icon="heroicon-o-server-stack"
    >
        <x-slot:actions>
            <a
                href="{{ $sitesIndexUrl }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                {{ __('Sites') }}
                <span aria-hidden="true">→</span>
            </a>
            @if ($showHeroActions && $heroActionsHtml !== '')
                {{ $actions }}
            @endif
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($summaryStats as $stat)
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                        <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <x-dynamic-component :component="$stat['icon']" class="h-3.5 w-3.5 shrink-0 {{ $stat['tone'] }}" aria-hidden="true" />
                            <span class="truncate">{{ $stat['label'] }}</span>
                        </dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $stat['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-slot:stats>

        @if (isset($alert) && filled(trim((string) $alert)))
            <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                {{ $alert }}
            </div>
        @endif

        @if (isset($banners) && filled(trim((string) $banners)))
            <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6 space-y-4">
                {{ $banners }}
            </div>
        @endif

        @if ($showFleetOps)
            @feature('surface.fleet')
                @php
                    $serversPillOrg = auth()->user()?->currentOrganization();
                    $serversPillCanTimeline = $serversPillOrg !== null && $serversPillOrg->hasAdminAccess(auth()->user());
                    $serversPillTiles = [
                        ['url' => route('fleet.health'), 'label' => __('Health'), 'icon' => 'heroicon-o-heart'],
                        ['url' => route('fleet.deploys'), 'label' => __('Deploys'), 'icon' => 'heroicon-o-rocket-launch'],
                        ['url' => route('fleet.domains'), 'label' => __('Domains'), 'icon' => 'heroicon-o-globe-alt'],
                        ['url' => route('fleet.env-search'), 'label' => __('Env search'), 'icon' => 'heroicon-o-key'],
                        ['url' => route('fleet.env-drift'), 'label' => __('Env drift'), 'icon' => 'heroicon-o-arrows-right-left'],
                        ['url' => route('fleet.intelligence'), 'label' => __('Intelligence'), 'icon' => 'heroicon-o-light-bulb'],
                    ];
                    if ($serversPillCanTimeline) {
                        $serversPillTiles[] = [
                            'url' => route('organizations.activity', $serversPillOrg),
                            'label' => __('Timeline'),
                            'icon' => 'heroicon-o-clock',
                        ];
                    }
                @endphp
                <nav class="flex flex-wrap items-center gap-1.5 border-b border-brand-ink/10 px-5 py-3 text-sm sm:px-6" aria-label="{{ __('Fleet ops') }}">
                    <span class="me-1 text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Fleet ops') }}</span>
                    @foreach ($serversPillTiles as $fleetTile)
                        <a
                            href="{{ $fleetTile['url'] }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-3 py-1 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-brand-sage/45 hover:text-brand-ink"
                        >
                            <x-dynamic-component :component="$fleetTile['icon']" class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ $fleetTile['label'] }}
                        </a>
                    @endforeach
                </nav>
            @endfeature
        @endif

        @unless ($hasServersInScope)
            @if (isset($empty) && ! $empty->isEmpty())
                {{ $empty }}
            @else
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="servers-empty-heading">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-server-stack class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <h2 id="servers-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">
                        {{ $isProductionSurface ? __('No production servers') : __('No servers yet') }}
                    </h2>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ $isProductionSurface
                            ? __('The connected control plane returned no servers for this organization.')
                            : __('Create a VM once a cloud provider is connected.') }}
                    </p>
                </div>
            @endif
        @else
            <div class="flex items-center gap-2 border-b border-brand-ink/10 px-3 py-3 sm:px-5">
                <div class="min-w-0 flex-1">
                    <label for="servers_search" class="sr-only">{{ __('Search') }}</label>
                    <x-text-input id="servers_search" type="search" wire:model.live.debounce.300ms="search" class="mt-0 w-full" placeholder="{{ __('Search servers, IPs, or providers…') }}" autocomplete="off" />
                </div>

                <x-dropdown align="right" width="w-72" content-classes="p-3" :close-on-content-click="false">
                    <x-slot name="trigger">
                        <button
                            type="button"
                            class="relative inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-brand-ink/15 bg-white text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink"
                            title="{{ __('Filters') }}"
                        >
                            <span class="sr-only">{{ __('Filters') }}</span>
                            <x-heroicon-o-funnel class="h-4 w-4" aria-hidden="true" />
                            @if ($filtersActive)
                                <span class="absolute end-1.5 top-1.5 h-1.5 w-1.5 rounded-full bg-brand-forest ring-2 ring-white" aria-hidden="true"></span>
                            @endif
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <div class="space-y-3">
                            <div>
                                <label for="servers_status" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</label>
                                <x-select id="servers_status" wire:model.live="statusFilter" class="mt-1.5 w-full">
                                    @foreach ($statusOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-select>
                            </div>

                            @if (count($tagOptions) > 0)
                                <div>
                                    <label for="servers_tag" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Tag') }}</label>
                                    <x-select id="servers_tag" wire:model.live="tagFilter" class="mt-1.5 w-full">
                                        <option value="">{{ __('All tags') }}</option>
                                        @foreach ($tagOptions as $tag)
                                            <option value="{{ $tag }}">{{ $tag }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                            @endif

                            <div>
                                <label for="servers_sort" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Order by') }}</label>
                                <x-select id="servers_sort" wire:model.live="sort" class="mt-1.5 w-full">
                                    @foreach ($sortOptions as $value => $label)
                                        <option value="{{ $value }}">{{ __($label) }}</option>
                                    @endforeach
                                </x-select>
                            </div>

                            <div class="flex justify-end border-t border-brand-ink/10 pt-3">
                                <button
                                    type="button"
                                    wire:click="resetFilters"
                                    @@click="$dispatch('close')"
                                    class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink"
                                >
                                    {{ __('Reset') }}
                                </button>
                            </div>
                        </div>
                    </x-slot>
                </x-dropdown>

                <div class="inline-flex shrink-0 rounded-xl border border-brand-ink/15 bg-white p-0.5" role="group" aria-label="{{ __('View') }}">
                    <button
                        type="button"
                        wire:click="$set('viewMode', 'list')"
                        class="rounded-lg px-2.5 py-1.5 text-sm font-medium transition-colors {{ $viewMode === 'list' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:bg-brand-sand/40' }}"
                        aria-pressed="{{ $viewMode === 'list' ? 'true' : 'false' }}"
                        title="{{ __('List') }}"
                    >
                        <span class="sr-only">{{ __('List') }}</span>
                        <x-heroicon-o-list-bullet class="h-5 w-5" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        wire:click="$set('viewMode', 'grid')"
                        class="rounded-lg px-2.5 py-1.5 text-sm font-medium transition-colors {{ $viewMode === 'grid' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:bg-brand-sand/40' }}"
                        aria-pressed="{{ $viewMode === 'grid' ? 'true' : 'false' }}"
                        title="{{ __('Grid') }}"
                    >
                        <span class="sr-only">{{ __('Grid') }}</span>
                        <x-heroicon-o-squares-2x2 class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
            </div>

            @if ($groupedRows->flatten()->isEmpty())
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No servers match your current filters') }}</p>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Try widening the search, switching the status filter, or resetting to bring every server back into view.') }}
                    </p>
                    <button type="button" wire:click="resetFilters" class="mt-4 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                        {{ __('Reset filters') }}
                    </button>
                </div>
            @elseif ($viewMode === 'grid')
                <div class="space-y-8 px-4 py-4 sm:px-5 sm:py-5">
                    @foreach ($groupedRows as $groupLabel => $groupServers)
                        <div wire:key="group-grid-{{ \Illuminate\Support\Str::slug((string) $groupLabel) }}">
                            <div class="mb-3 flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                                <h2 class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                    <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                    {{ $groupLabel }}
                                </h2>
                                <span class="inline-flex items-center rounded-full bg-brand-sand/30 px-2 py-0.5 text-xs font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $groupServers->count() }}</span>
                            </div>
                            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($groupServers as $server)
                                    @include('components.partials.server-index-card', ['server' => $server, 'layout' => 'grid', 'showDeployActions' => $showDeployActions, 'showMutations' => $showMutations])
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @else
                @foreach ($groupedRows as $groupLabel => $groupServers)
                    <div wire:key="group-{{ \Illuminate\Support\Str::slug((string) $groupLabel) }}">
                        <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-6">
                            <h2 class="flex min-w-0 items-center gap-2 text-sm font-semibold text-brand-ink">
                                <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate">{{ $groupLabel }}</span>
                            </h2>
                            <span class="inline-flex items-center rounded-full bg-white px-2 py-0.5 text-xs font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $groupServers->count() }}</span>
                        </div>
                        <ul>
                            @foreach ($groupServers as $server)
                                @include('components.partials.server-index-card', ['server' => $server, 'layout' => 'list', 'showDeployActions' => $showDeployActions, 'showMutations' => $showMutations])
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            @endif
        @endunless
    </x-profile-shell>

    {{ $modals ?? '' }}
</div>
