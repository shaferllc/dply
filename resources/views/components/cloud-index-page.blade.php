@props([
    /** @var \Illuminate\Support\Collection<int, \App\Support\Cloud\CloudIndexRow> */
    'rows',
    /** @var array{all: int, active: int, provisioning: int, source: int, image: int, previews: int, failed: int} */
    'totals' => ['all' => 0, 'active' => 0, 'provisioning' => 0, 'source' => 0, 'image' => 0, 'previews' => 0, 'failed' => 0],
    'hasAppsInScope' => true,
    'cloudEnabled' => true,
    'apiReady' => true,
    'filter' => 'all',
    'showFilters' => true,
    'showCreateAction' => false,
    'showDatabasesAction' => false,
    /** @var list<array{label: string, href?: string, icon?: string}> */
    'breadcrumbs' => [],
    'emptyState' => 'local',
    'createUrl' => null,
    'databasesUrl' => null,
])

@php
    $isProductionSurface = $emptyState === 'production';
    $allTotal = (int) ($totals['all'] ?? 0);
    $showStats = $cloudEnabled && $apiReady && $hasAppsInScope && $allTotal > 0;
    $showShellCreate = $cloudEnabled && $apiReady && $showCreateAction && $hasAppsInScope && $allTotal > 0;
    $showShellDatabases = $cloudEnabled && $apiReady && $showDatabasesAction && $hasAppsInScope && $allTotal > 0;
    $createUrl ??= route('cloud.create');
    $databasesUrl ??= route('cloud.databases.index');
    $summaryStats = [
        [
            'icon' => 'heroicon-o-cloud',
            'label' => __('All apps'),
            'value' => $allTotal,
            'tone' => 'text-brand-sage',
        ],
        [
            'icon' => 'heroicon-o-check-badge',
            'label' => __('Live'),
            'value' => (int) ($totals['active'] ?? 0),
            'tone' => 'text-brand-forest',
        ],
        [
            'icon' => 'heroicon-o-arrow-path',
            'label' => __('Provisioning'),
            'value' => (int) ($totals['provisioning'] ?? 0),
            'tone' => ((int) ($totals['provisioning'] ?? 0)) > 0 ? 'text-brand-sage' : 'text-brand-mist',
        ],
        [
            'icon' => 'heroicon-o-sparkles',
            'label' => __('Previews'),
            'value' => (int) ($totals['previews'] ?? 0),
            'tone' => 'text-brand-sage',
        ],
        [
            'icon' => 'heroicon-o-exclamation-triangle',
            'label' => __('Failed'),
            'value' => (int) ($totals['failed'] ?? 0),
            'tone' => ((int) ($totals['failed'] ?? 0)) > 0 ? 'text-rose-600' : 'text-brand-mist',
        ],
    ];
    $filterTabs = [
        ['key' => 'all', 'label' => __('All'), 'count' => (int) ($totals['all'] ?? 0)],
        ['key' => 'source', 'label' => __('Repository'), 'count' => (int) ($totals['source'] ?? 0)],
        ['key' => 'image', 'label' => __('Image'), 'count' => (int) ($totals['image'] ?? 0)],
        ['key' => 'previews', 'label' => __('Previews'), 'count' => (int) ($totals['previews'] ?? 0)],
        ['key' => 'provisioning', 'label' => __('Provisioning'), 'count' => (int) ($totals['provisioning'] ?? 0)],
        ['key' => 'failed', 'label' => __('Failed'), 'count' => (int) ($totals['failed'] ?? 0)],
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="$breadcrumbs" />

    @unless ($cloudEnabled)
        <div class="dply-card relative p-8 text-center">
            <span class="absolute end-6 top-6 inline-flex rounded-full bg-brand-sand/60 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                {{ __('Coming soon') }}
            </span>
            <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-xl border border-brand-ink/10 bg-white text-brand-ink shadow-sm">
                <x-heroicon-o-cloud class="h-8 w-8 shrink-0" aria-hidden="true" />
            </span>
            <p class="mt-5 text-lg font-semibold text-brand-ink">{{ __('Cloud apps') }}</p>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-brand-moss">
                {{ __('Managed container apps — deploy from a repo or image without wiring infrastructure.') }}
            </p>
            <p class="mt-5 text-sm font-medium text-brand-mist">{{ __('Not available yet') }}</p>
        </div>
    @else
        <x-profile-shell
            :title="__('Cloud apps')"
            :description="$isProductionSurface
                ? __('Live Cloud apps from the connected control plane — Open materializes into the real workspace with Production data.')
                : __('Managed container apps on dply Cloud — deploy from a repository or image, with HTTPS, scaling, and previews.')"
            icon="heroicon-o-cloud"
        >
            @if ($showShellCreate || $showShellDatabases || isset($actions))
                <x-slot:actions>
                    @if ($showShellDatabases)
                        <a href="{{ $databasesUrl }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-o-circle-stack class="h-4 w-4" aria-hidden="true" />
                            {{ __('Databases') }}
                        </a>
                    @endif
                    @if ($showShellCreate)
                        <a
                            href="{{ $createUrl }}"
                            wire:navigate
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Deploy an app') }}
                        </a>
                    @endif
                    @isset($actions)
                        {{ $actions }}
                    @endisset
                </x-slot:actions>
            @endif

            @if ($showStats)
                <x-slot:stats>
                    <dl class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($summaryStats as $stat)
                            <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                                <dt class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                                    <x-dynamic-component :component="$stat['icon']" class="h-3.5 w-3.5 shrink-0 {{ $stat['tone'] }}" aria-hidden="true" />
                                    <span class="truncate">{{ $stat['label'] }}</span>
                                </dt>
                                <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $stat['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-slot:stats>
            @endif

            @if (isset($alert) && filled(trim((string) $alert)))
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    {{ $alert }}
                </div>
            @endif

            @if (! $apiReady)
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="cloud-api-heading">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-cloud class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <h2 id="cloud-api-heading" class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No list API for this product line yet') }}</h2>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Nav is wired. When the control-plane API exposes Cloud inventory, it will load here.') }}
                    </p>
                </div>
            @elseif (! $hasAppsInScope)
                @if (isset($empty) && ! $empty->isEmpty())
                    {{ $empty }}
                @else
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="cloud-empty-heading">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-sparkles class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <h2 id="cloud-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">
                            {{ $isProductionSurface ? __('No production Cloud apps') : __('Ship your first app in minutes') }}
                        </h2>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ $isProductionSurface
                                ? __('The connected control plane returned no Cloud apps for this organization.')
                                : __('Point dply at a repository or pre-built image and we handle HTTPS, scaling, and previews.') }}
                        </p>
                        @if ($showCreateAction && ! $isProductionSurface)
                            <a
                                href="{{ $createUrl }}"
                                wire:navigate
                                class="mt-5 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Deploy an app') }}
                            </a>
                        @endif
                    </div>
                @endif
            @else
                @if ($showFilters)
                    <nav class="flex flex-wrap gap-2 border-b border-brand-ink/10 px-3 py-3 sm:px-5" aria-label="{{ __('Cloud filters') }}">
                        @foreach ($filterTabs as $tab)
                            <button
                                type="button"
                                wire:click="$set('filter', '{{ $tab['key'] }}')"
                                class="rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $filter === $tab['key'] ? 'border-brand-ink bg-brand-ink text-brand-cream' : 'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' }}"
                            >
                                {{ $tab['label'] }}
                                <span class="ml-1 font-mono opacity-80">{{ $tab['count'] }}</span>
                            </button>
                        @endforeach
                    </nav>
                @endif

                @if ($rows->isEmpty())
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No apps match this filter') }}</p>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ __('Try another status tab, or switch back to All to see every Cloud app.') }}
                        </p>
                        @if ($showFilters)
                            <button type="button" wire:click="$set('filter', 'all')" class="mt-4 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                {{ __('Clear filter') }}
                            </button>
                        @endif
                    </div>
                @else
                    <ul>
                        @foreach ($rows as $app)
                            @include('components.partials.cloud-index-card', ['app' => $app])
                        @endforeach
                    </ul>
                @endif
            @endif
        </x-profile-shell>
    @endunless

    {{ $modals ?? '' }}
</div>
