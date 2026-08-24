@props([
    /** @var \Illuminate\Support\Collection<int, \App\Support\Sites\SiteIndexRow> */
    'rows',
    /** @var array<string, int> */
    'summary',
    'hasSitesInScope' => true,
    /** @var array<string, string> */
    'statusOptions' => [],
    /** @var array<string, string> */
    'sortOptions' => [],
    /** @var list<array{label: string, href?: string, icon?: string}> */
    'breadcrumbs' => [],
    'serversIndexUrl' => null,
    'emptyState' => 'local',
    'statusFilter' => '',
    'sort' => 'created_at',
    'eyebrow' => null,
])

@php
    $serversIndexUrl ??= route('servers.index');
    $filtersActive = $statusFilter !== '' || $sort !== 'created_at';
    // One count line instead of six stat tiles — the cards carry per-site status.
    // Search/filter chrome earns its space only once the list stops fitting on
    // screen; below that the table itself is the filter.
    $showToolbar = ($summary['total'] ?? 0) > 8;
    // Livewire wraps every @if/@foreach in `<!--[if BLOCK]>` markers, so a slot
    // whose conditions all fail is still a non-empty string — strip them first.
    $alertHtml = isset($alert) ? trim(preg_replace('/<!--.*?-->/s', '', (string) $alert) ?? '') : '';
    $summaryLine = collect([
        trans_choice(':count site|:count sites', $summary['total'] ?? 0, ['count' => $summary['total'] ?? 0]),
        ($summary['active'] ?? 0) > 0 ? __(':count active', ['count' => $summary['active']]) : null,
        ($summary['provisioning'] ?? 0) > 0 ? __(':count provisioning', ['count' => $summary['provisioning']]) : null,
        ($summary['attention'] ?? 0) > 0 ? __(':count need attention', ['count' => $summary['attention']]) : null,
    ])->filter()->implode(' · ');
@endphp

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="$breadcrumbs" />

    <x-profile-shell
        dense
        :title="__('Sites')"
        :description="$hasSitesInScope ? $summaryLine : null"
        icon="heroicon-o-globe-alt"
    >
        <x-slot:actions>
            <a
                href="{{ $serversIndexUrl }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                <x-heroicon-o-server class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                {{ __('Servers') }}
                <span aria-hidden="true">→</span>
            </a>
        </x-slot:actions>

        @if (session('success'))
            <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                <x-alert tone="success">{{ session('success') }}</x-alert>
            </div>
        @endif

        @if ($alertHtml !== '')
            <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                {{ $alert }}
            </div>
        @endif

        @unless ($hasSitesInScope)
            <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="sites-empty-heading">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-globe-alt class="h-6 w-6" aria-hidden="true" />
                </span>
                <h2 id="sites-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">
                    {{ $emptyState === 'production' ? __('No production sites') : __('No sites yet') }}
                </h2>
                <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                    {{ $emptyState === 'production'
                        ? __('The connected control plane returned no BYO sites for this organization.')
                        : __('Sites belong to servers. Open a server, then add the hostnames that should route through it.') }}
                </p>
                @if ($emptyState !== 'production')
                    <a
                        href="{{ route('servers.index') }}"
                        wire:navigate
                        class="mt-5 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-server class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Go to servers') }}
                    </a>
                @endif
            </div>
        @else
            @if ($showToolbar)
            <div class="flex items-center gap-2 border-b border-brand-ink/10 px-3 py-3 sm:px-5">
                <div class="min-w-0 flex-1">
                    <label for="sites_search" class="sr-only">{{ __('Search') }}</label>
                    <x-text-input id="sites_search" type="search" wire:model.live.debounce.300ms="search" class="mt-0 w-full" placeholder="{{ __('Search sites, domains, or servers…') }}" autocomplete="off" />
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
                                <label for="sites_status" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</label>
                                <x-select id="sites_status" wire:model.live="statusFilter" class="mt-1.5 w-full">
                                    @foreach ($statusOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                            <div>
                                <label for="sites_sort" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Order by') }}</label>
                                <x-select id="sites_sort" wire:model.live="sort" class="mt-1.5 w-full">
                                    @foreach ($sortOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
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
            </div>
            @endif

            @if ($rows->isEmpty())
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No sites match your current filters') }}</p>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Try widening the search, switching the status filter, or resetting to bring every site back into view.') }}
                    </p>
                    <button type="button" wire:click="resetFilters" class="mt-4 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                        {{ __('Reset filters') }}
                    </button>
                </div>
            @else
                @php
                    $th = 'px-3 py-2.5 text-start text-2xs font-semibold uppercase tracking-wide text-brand-mist sm:px-5';
                    $sortCols = [
                        ['key' => 'name', 'label' => __('Site'), 'class' => ''],
                        ['key' => null, 'label' => __('Hostname'), 'class' => ''],
                        ['key' => 'status', 'label' => __('Status'), 'class' => ''],
                        ['key' => null, 'label' => __('Server'), 'class' => 'hidden lg:table-cell'],
                        ['key' => 'deployed', 'label' => __('Deployed'), 'class' => 'hidden sm:table-cell'],
                    ];
                @endphp
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10">
                                @foreach ($sortCols as $col)
                                    <th scope="col" class="{{ $th }} {{ $col['class'] }}">
                                        @if ($col['key'])
                                            <button
                                                type="button"
                                                wire:click="$set('sort', '{{ $sort === $col['key'] ? 'created_at' : $col['key'] }}')"
                                                class="inline-flex items-center gap-1 uppercase tracking-wide transition-colors hover:text-brand-ink"
                                            >
                                                {{ $col['label'] }}
                                                @if ($sort === $col['key'])
                                                    <x-heroicon-m-chevron-down class="h-3 w-3 shrink-0 text-brand-sage" aria-hidden="true" />
                                                @endif
                                            </button>
                                        @else
                                            {{ $col['label'] }}
                                        @endif
                                    </th>
                                @endforeach
                                <th scope="col" class="{{ $th }}"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $site)
                                @include('components.partials.site-index-card', ['site' => $site])
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endunless
    </x-profile-shell>

    {{ $modals ?? '' }}
</div>
