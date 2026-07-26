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
])

@php
    $showFleetOps = filter_var($showFleetOps, FILTER_VALIDATE_BOOLEAN);
    $showDeployActions = filter_var($showDeployActions, FILTER_VALIDATE_BOOLEAN);
    $showMutations = filter_var($showMutations, FILTER_VALIDATE_BOOLEAN);
    $showHeroActions = filter_var($showHeroActions, FILTER_VALIDATE_BOOLEAN);
    $heroActionsHtml = isset($actions) ? trim(preg_replace('/<!--.*?-->/s', '', (string) $actions) ?? '') : '';
    $filtersActive = $statusFilter !== ''
        || $sort !== 'created_at'
        || trim((string) $tagFilter) !== '';
    $summaryStats = [
        ['icon' => 'heroicon-o-server-stack', 'label' => __('Servers'), 'value' => $summary['total'] ?? 0, 'tone' => 'text-brand-sage'],
        ['icon' => 'heroicon-o-check-circle', 'label' => __('Ready'), 'value' => $summary['ready'] ?? 0, 'tone' => 'text-brand-sage'],
        ['icon' => 'heroicon-o-exclamation-triangle', 'label' => __('Attention'), 'value' => $summary['attention'] ?? 0, 'tone' => ($summary['attention'] ?? 0) > 0 ? 'text-amber-500' : 'text-brand-mist'],
        ['icon' => 'heroicon-o-globe-alt', 'label' => __('Sites'), 'value' => $summary['sites'] ?? 0, 'tone' => 'text-brand-sage'],
    ];
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <x-breadcrumb-trail :items="$breadcrumbs" />

    {{ $alert ?? '' }}
    {{ $banners ?? '' }}

    <x-hero-card
        icon="server-stack"
        iconSize="md"
        :eyebrow="$eyebrow"
        :title="__('Servers')"
        :description="__('Provision hosts, watch readiness, and drill into each machine from one fleet view.')"
    >
        {{-- Slot must be a direct child of x-hero-card (not wrapped in @if) or Blade drops it. --}}
        <x-slot:top-action>
            @if ($showHeroActions && $heroActionsHtml !== '')
                {{ $actions }}
            @endif
        </x-slot:top-action>

        <x-slot:stats>
            <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($summaryStats as $stat)
                    <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2 shadow-sm sm:min-w-[6.5rem]">
                        <dt class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                            <x-dynamic-component :component="$stat['icon']" class="h-3.5 w-3.5 shrink-0 {{ $stat['tone'] }}" aria-hidden="true" />
                            <span class="truncate">{{ $stat['label'] }}</span>
                        </dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $stat['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-slot:stats>
    </x-hero-card>

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
            <nav class="-mt-2 flex flex-wrap items-center gap-1.5 text-sm" aria-label="{{ __('Fleet ops') }}">
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

    @if ($hasServersInScope)
        <div class="dply-card overflow-hidden">
            <div class="flex items-center gap-2 px-3 py-3 sm:px-5">
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
                                <label for="servers_status" class="block text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</label>
                                <x-select id="servers_status" wire:model.live="statusFilter" class="mt-1.5 w-full">
                                    @foreach ($statusOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-select>
                            </div>

                            @if (count($tagOptions) > 0)
                                <div>
                                    <label for="servers_tag" class="block text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Tag') }}</label>
                                    <x-select id="servers_tag" wire:model.live="tagFilter" class="mt-1.5 w-full">
                                        <option value="">{{ __('All tags') }}</option>
                                        @foreach ($tagOptions as $tag)
                                            <option value="{{ $tag }}">{{ $tag }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                            @endif

                            <div>
                                <label for="servers_sort" class="block text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Order by') }}</label>
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
        </div>
    @endif

    @unless ($hasServersInScope)
        {{ $empty ?? '' }}
        @if (! isset($empty) || $empty->isEmpty())
            <section class="rounded-[2rem] border-2 border-brand-sage/35 bg-brand-cream shadow-lg shadow-brand-ink/10 ring-1 ring-brand-ink/[0.07]">
                <div class="px-6 py-12 text-center sm:px-10 sm:py-14">
                    <p class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('No servers yet') }}</p>
                    <p class="mt-3 text-base text-brand-moss">{{ __('Create a VM once a cloud provider is connected.') }}</p>
                </div>
            </section>
        @endif
    @else
        <div class="dply-card overflow-hidden rounded-[2rem]">
            @if ($groupedRows->flatten()->isEmpty())
                <div class="px-6 py-14 text-center">
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('No results') }}</p>
                    <h3 class="mt-3 text-xl font-semibold text-brand-ink">{{ __('No servers match your current filters') }}</h3>
                    <p class="mx-auto mt-3 max-w-2xl text-sm leading-6 text-brand-moss">
                        {{ __('Try widening the search, switching the status filter, or resetting the command rail to bring the full fleet back into view.') }}
                    </p>
                    <button type="button" wire:click="resetFilters" class="mt-5 inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink transition hover:bg-brand-cream">
                        {{ __('Reset filters') }}
                    </button>
                </div>
            @elseif ($viewMode === 'grid')
                <div class="space-y-10 bg-white p-4 sm:p-6">
                    @foreach ($groupedRows as $groupLabel => $groupServers)
                        <div>
                            <div class="mb-4 flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                                <h2 class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                    <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                    {{ $groupLabel }}
                                </h2>
                                <span class="inline-flex items-center rounded-full bg-brand-sand/30 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $groupServers->count() }}</span>
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
                <div class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($groupedRows as $groupLabel => $groupServers)
                        <div wire:key="group-{{ \Illuminate\Support\Str::slug((string) $groupLabel) }}">
                            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-6">
                                <h2 class="flex min-w-0 items-center gap-2 text-sm font-semibold text-brand-ink">
                                    <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <span class="truncate">{{ $groupLabel }}</span>
                                </h2>
                                <span class="inline-flex items-center rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $groupServers->count() }}</span>
                            </div>
                            <ul>
                                @foreach ($groupServers as $server)
                                    @include('components.partials.server-index-card', ['server' => $server, 'layout' => 'list', 'showDeployActions' => $showDeployActions, 'showMutations' => $showMutations])
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endunless

    {{ $modals ?? '' }}
</div>
