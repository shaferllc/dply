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
])

@php
    $serversIndexUrl ??= route('servers.index');
    $summaryStats = [
        ['icon' => 'heroicon-o-globe-alt', 'label' => __('Sites'), 'value' => $summary['total'] ?? 0, 'tone' => 'text-brand-sage'],
        ['icon' => 'heroicon-o-check-circle', 'label' => __('Active'), 'value' => $summary['active'] ?? 0, 'tone' => 'text-brand-sage'],
        ['icon' => 'heroicon-o-arrow-path', 'label' => __('Provisioning'), 'value' => $summary['provisioning'] ?? 0, 'tone' => ($summary['provisioning'] ?? 0) > 0 ? 'text-amber-500' : 'text-brand-mist'],
        ['icon' => 'heroicon-o-exclamation-triangle', 'label' => __('Attention'), 'value' => $summary['attention'] ?? 0, 'tone' => ($summary['attention'] ?? 0) > 0 ? 'text-amber-500' : 'text-brand-mist'],
        ['icon' => 'heroicon-o-lock-closed', 'label' => __('SSL secured'), 'value' => $summary['secured'] ?? 0, 'tone' => 'text-brand-sage'],
        ['icon' => 'heroicon-o-server-stack', 'label' => __('Servers'), 'value' => $summary['servers'] ?? 0, 'tone' => 'text-brand-sage'],
    ];
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <x-breadcrumb-trail :items="$breadcrumbs" />

    @if (session('success'))
        <x-alert tone="success">{{ session('success') }}</x-alert>
    @endif

    {{ $alert ?? '' }}

    <x-hero-card
        :eyebrow="__('Site')"
        :title="__('Sites')"
        :description="__('Every hostname routes through a server—search, filter, and drill into any site from one view.')"
        icon="globe-alt"
    >
        <x-slot:topAction>
            <a
                href="{{ $serversIndexUrl }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                <x-heroicon-o-server class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                {{ __('Servers') }}
                <span aria-hidden="true">→</span>
            </a>
        </x-slot:topAction>
    </x-hero-card>

    @if ($hasSitesInScope)
        <div class="dply-card overflow-hidden">
            <dl class="grid grid-cols-2 divide-y divide-brand-ink/10 sm:grid-cols-3 sm:divide-x lg:grid-cols-6 lg:divide-y-0">
                @foreach ($summaryStats as $stat)
                    <div class="flex items-center justify-between gap-3 px-4 py-3 sm:px-5">
                        <dt class="flex min-w-0 items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                            <x-dynamic-component :component="$stat['icon']" class="h-4 w-4 shrink-0 {{ $stat['tone'] }}" aria-hidden="true" />
                            <span class="truncate">{{ $stat['label'] }}</span>
                        </dt>
                        <dd class="text-xl font-semibold tabular-nums leading-none text-brand-ink">{{ $stat['value'] }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="flex flex-wrap items-center gap-2 border-t border-brand-ink/10 bg-brand-sand/20 px-4 py-3 sm:px-5">
                <div class="min-w-[14rem] flex-1">
                    <label for="sites_search" class="sr-only">{{ __('Search') }}</label>
                    <x-text-input id="sites_search" type="search" wire:model.live.debounce.300ms="search" class="mt-0 w-full" placeholder="{{ __('Search sites, domains, or servers…') }}" autocomplete="off" />
                </div>

                <label for="sites_status" class="sr-only">{{ __('Status') }}</label>
                <x-select id="sites_status" wire:model.live="statusFilter" class="mt-0 w-auto min-w-[10rem]">
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>

                <label for="sites_sort" class="sr-only">{{ __('Order by') }}</label>
                <x-select id="sites_sort" wire:model.live="sort" class="mt-0 w-auto min-w-[10rem]">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>

                <button type="button" wire:click="resetFilters" class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink">
                    {{ __('Reset') }}
                </button>
            </div>
        </div>
    @endif

    @unless ($hasSitesInScope)
        <section class="rounded-[2rem] border-2 border-brand-sage/35 bg-brand-cream shadow-lg shadow-brand-ink/10 ring-1 ring-brand-ink/[0.07]" aria-labelledby="sites-empty-heading">
            <div class="px-6 py-12 text-center sm:px-10 sm:py-14">
                <div class="mx-auto flex max-w-xl flex-col items-center">
                    <span class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-sand/55 text-brand-forest ring-1 ring-brand-ink/10">
                        <x-heroicon-o-globe-alt class="h-9 w-9" aria-hidden="true" />
                    </span>
                    <h2 id="sites-empty-heading" class="mt-6 text-2xl font-semibold tracking-tight text-brand-ink">
                        {{ $emptyState === 'production' ? __('No production sites') : __('No sites yet') }}
                    </h2>
                    <p class="mt-3 text-base leading-relaxed text-brand-moss">
                        {{ $emptyState === 'production'
                            ? __('The connected control plane returned no BYO sites for this organization.')
                            : __('Sites belong to servers. Open a server, then add the hostnames that should route through it.') }}
                    </p>
                    @if ($emptyState !== 'production')
                        <ul class="mt-8 w-full space-y-3 text-left text-sm leading-snug text-brand-moss">
                            <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <x-heroicon-o-server class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span>
                                    <span class="font-semibold text-brand-ink">{{ __('Pick a server') }}</span>
                                    <span class="text-brand-mist"> — </span>
                                    {{ __('Every site lives on a host. Choose where this one belongs.') }}
                                </span>
                            </li>
                            <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <x-heroicon-o-plus-circle class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span>
                                    <span class="font-semibold text-brand-ink">{{ __('Add a site') }}</span>
                                    <span class="text-brand-mist"> — </span>
                                    {{ __('Inside the server workspace, open Sites → New site to wire up a hostname.') }}
                                </span>
                            </li>
                            <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <x-heroicon-o-cog-6-tooth class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span>
                                    <span class="font-semibold text-brand-ink">{{ __('Configure runtime') }}</span>
                                    <span class="text-brand-mist"> — </span>
                                    {{ __('Pick PHP, Node, or static. TLS, deploys, and env vars come with it.') }}
                                </span>
                            </li>
                        </ul>
                        <div class="mt-10 flex w-full flex-wrap items-center justify-center gap-3">
                            <a
                                href="{{ route('servers.index') }}"
                                wire:navigate
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-3 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition hover:bg-brand-forest"
                            >
                                <x-heroicon-o-server class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Go to servers') }}
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @else
        <x-section-card padding="none">
            @if ($rows->isEmpty())
                <div class="px-6 py-14 text-center">
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('No results') }}</p>
                    <h3 class="mt-3 text-xl font-semibold text-brand-ink">{{ __('No sites match your current filters') }}</h3>
                    <p class="mx-auto mt-3 max-w-2xl text-sm leading-6 text-brand-moss">
                        {{ __('Try widening the search, switching the status filter, or resetting to bring every site back into view.') }}
                    </p>
                    <button type="button" wire:click="resetFilters" class="mt-5 inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink transition hover:bg-brand-cream">
                        {{ __('Reset filters') }}
                    </button>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10 overflow-hidden">
                    @foreach ($rows as $site)
                        <li wire:key="site-{{ $site->id }}" class="flex flex-wrap items-start justify-between gap-4 p-4 transition-colors hover:bg-brand-sand/20 sm:p-5">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <a href="{{ $site->href }}" wire:navigate class="truncate text-base font-semibold text-brand-ink hover:text-brand-sage">
                                        {{ $site->name }}
                                    </a>
                                    <span class="inline-flex items-center gap-1 rounded-full border border-brand-ink/10 bg-brand-sand/30 px-2 py-0.5 text-[11px] font-semibold text-brand-moss">
                                        <x-heroicon-o-cpu-chip class="h-3 w-3 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ $site->typeLabel }}
                                    </span>
                                    <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-[11px] font-medium text-brand-moss">
                                        {{ $site->runtimeExecutionModeLabel }}
                                    </span>
                                    @if ($site->phpVersion)
                                        <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-[11px] font-medium text-brand-moss">
                                            {{ __('PHP :v', ['v' => $site->phpVersion]) }}
                                        </span>
                                    @elseif ($site->runtimeVersion)
                                        <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-[11px] font-medium text-brand-moss">
                                            {{ ucfirst((string) ($site->runtimeKey ?? '')) }} {{ $site->runtimeVersion }}
                                        </span>
                                    @endif
                                </div>

                                <div class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-brand-moss">
                                    @if ($site->primaryHostname)
                                        <span class="inline-flex items-center gap-1 font-medium text-brand-ink">
                                            <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                            {{ $site->primaryHostname }}
                                        </span>
                                        @if ($site->extraDomains > 0)
                                            <span class="text-brand-mist">{{ trans_choice('+:count domain|+:count domains', $site->extraDomains, ['count' => $site->extraDomains]) }}</span>
                                        @endif
                                        <span class="text-brand-mist">·</span>
                                    @endif
                                    <span class="inline-flex items-center gap-1">
                                        <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                        @if ($site->serverHref)
                                            <a href="{{ $site->serverHref }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                                                {{ $site->serverName }}
                                            </a>
                                        @else
                                            <span class="font-medium text-brand-ink">{{ $site->serverName }}</span>
                                        @endif
                                    </span>
                                    <span class="text-brand-mist">·</span>
                                    <span class="inline-flex items-center gap-1" title="{{ $site->runtimeProfileLabel }}">
                                        {{ $site->runtimeProfileLabel }}
                                    </span>
                                    @if ($site->workspaceName)
                                        @feature('surface.projects')
                                            <span class="text-brand-mist">·</span>
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-o-folder class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                                @if ($site->workspaceHref)
                                                    <a href="{{ $site->workspaceHref }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                                                        {{ $site->workspaceName }}
                                                    </a>
                                                @else
                                                    <span class="font-medium text-brand-ink">{{ $site->workspaceName }}</span>
                                                @endif
                                            </span>
                                        @endfeature
                                    @endif
                                </div>

                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                                    @if ($site->lastDeployAt)
                                        <span class="inline-flex items-center gap-1" title="{{ $site->lastDeployAt }}">
                                            <x-heroicon-o-rocket-launch class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                            {{ __('Deployed :ago', ['ago' => $site->lastDeployAt->diffForHumans()]) }}
                                        </span>
                                    @endif
                                    @if ($site->createdAt)
                                        <span class="inline-flex items-center gap-1" title="{{ $site->createdAt }}">
                                            <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                            {{ __('Created :ago', ['ago' => $site->createdAt->diffForHumans()]) }}
                                        </span>
                                    @endif
                                </div>

                                @if ($site->isProvisioning)
                                    <p class="mt-1.5 text-xs text-brand-moss">
                                        {{ __('Provisioning step: :step', ['step' => str_replace('_', ' ', $site->provisioningState ?? 'queued')]) }}
                                    </p>
                                @elseif ($site->isFailed)
                                    <p class="mt-1.5 text-xs text-red-700">
                                        {{ $site->provisioningError ?: __('Provisioning failed.') }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 text-sm">
                                <x-badge size="sm" :tone="$site->statusTone">{{ $site->statusLabel }}</x-badge>
                                @if ($site->isProvisioning)
                                    <x-badge size="sm" tone="warning">{{ __('Provisioning') }}</x-badge>
                                @endif
                                @if ($site->sslTone !== null)
                                    <x-badge size="sm" :tone="$site->sslTone">{{ __('SSL: :status', ['status' => $site->sslStatus]) }}</x-badge>
                                @endif
                                @if ($site->visitUrl)
                                    <a href="{{ $site->visitUrl }}" target="_blank" rel="noreferrer" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ __('Visit') }}
                                    </a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-section-card>
    @endunless
</div>
