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
    'hasAnyBackendCredential' => true,
    'digitalOceanCredentialsUrl' => null,
    'awsCredentialsUrl' => null,
])

@php
    $isProductionSurface = $emptyState === 'production';
    $allTotal = (int) ($totals['all'] ?? 0);
    $showStats = $cloudEnabled && $apiReady && $hasAppsInScope && $allTotal > 0;
    $showShellCreate = $cloudEnabled && $apiReady && $showCreateAction && $hasAppsInScope && $allTotal > 0;
    $showShellDatabases = $cloudEnabled && $apiReady && $showDatabasesAction && $hasAppsInScope && $allTotal > 0;
    $createUrl ??= route('cloud.create');
    $databasesUrl ??= route('cloud.databases.index');
    $digitalOceanCredentialsUrl ??= route('credentials.index', ['provider' => 'digitalocean']);
    $awsCredentialsUrl ??= route('credentials.index', ['provider' => 'aws_app_runner']);
    $showCredentialCtas = ! $isProductionSurface && ! $hasAnyBackendCredential;
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
                @elseif ($isProductionSurface)
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="cloud-empty-heading">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-cloud class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <h2 id="cloud-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">
                            {{ __('No production Cloud apps') }}
                        </h2>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ __('The connected control plane returned no Cloud apps for this organization.') }}
                        </p>
                    </div>
                @else
                    @php
                        $emptyCapabilities = [
                            [
                                'icon' => 'heroicon-o-arrows-pointing-out',
                                'title' => __('Auto-scale'),
                                'body' => __('Scale instances with traffic — no capacity planning or server patching.'),
                            ],
                            [
                                'icon' => 'heroicon-o-lock-closed',
                                'title' => __('HTTPS included'),
                                'body' => __('Every app gets a public HTTPS URL. Custom domains attach when you are ready.'),
                            ],
                            [
                                'icon' => 'heroicon-o-code-bracket-square',
                                'title' => __('Repo or image'),
                                'body' => __('Deploy from Git, or ship a pre-built container image — same Cloud workspace.'),
                            ],
                        ];
                    @endphp

                    {{-- Hero --}}
                    <section class="relative overflow-hidden border-b border-brand-ink/10" aria-labelledby="cloud-empty-heading">
                        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(122,154,122,0.14),_transparent_55%),radial-gradient(ellipse_at_bottom_left,_rgba(212,175,122,0.12),_transparent_50%)]" aria-hidden="true"></div>
                        <div class="relative flex flex-col gap-6 px-5 py-10 sm:px-6 lg:flex-row lg:items-end lg:justify-between lg:py-12">
                            <div class="min-w-0 max-w-2xl">
                                <div class="inline-flex items-center gap-2 rounded-full border border-brand-ink/10 bg-white/70 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-sage shadow-sm backdrop-blur-sm">
                                    <x-heroicon-o-cloud class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Get started') }}
                                </div>
                                <h2 id="cloud-empty-heading" class="mt-3 text-xl font-semibold tracking-tight text-brand-ink sm:text-2xl">
                                    {{ __('Ship your first app in minutes') }}
                                </h2>
                                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                                    {{ __('dply Cloud runs managed containers for PHP, Rails, and long-running web apps on DigitalOcean App Platform or AWS App Runner — HTTPS, scaling, and deploys without owning the box.') }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-center">
                                @if ($showCreateAction)
                                    <a
                                        href="{{ $createUrl }}"
                                        wire:navigate
                                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                                    >
                                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Deploy an app') }}
                                    </a>
                                @endif
                                @if ($showCredentialCtas)
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <a
                                            href="{{ $digitalOceanCredentialsUrl }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2.5 font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Connect DigitalOcean') }}
                                        </a>
                                        <a
                                            href="{{ $awsCredentialsUrl }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1.5 px-2 py-2.5 font-medium text-brand-moss transition hover:text-brand-ink"
                                        >
                                            {{ __('Use AWS') }}
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </section>

                    {{-- Capabilities --}}
                    <section aria-labelledby="cloud-empty-capabilities-heading">
                        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                            <h3 id="cloud-empty-capabilities-heading" class="text-sm font-semibold text-brand-ink">{{ __('What Cloud gives you') }}</h3>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Managed containers for apps that need a process — not static sites or one-shot functions.') }}</p>
                        </div>
                        <ul class="grid gap-0 sm:grid-cols-3">
                            @foreach ($emptyCapabilities as $i => $capability)
                                <li @class([
                                    'flex gap-3 px-5 py-5 sm:px-6',
                                    'border-b border-brand-ink/10 sm:border-b-0 sm:border-e' => $i < count($emptyCapabilities) - 1,
                                ])>
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest shadow-sm ring-1 ring-brand-ink/10">
                                        <x-dynamic-component :component="$capability['icon']" class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $capability['title'] }}</p>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $capability['body'] }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        @if ($showCredentialCtas || $showCreateAction)
                            <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    @if ($showCredentialCtas)
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-brand-ink">{{ __('Connect a cloud account to deploy') }}</p>
                                            <p class="mt-0.5 text-sm text-brand-moss">
                                                {{ __('Link DigitalOcean or AWS once — dply provisions on your account and opens the live URL.') }}
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs">
                                            <a href="{{ $digitalOceanCredentialsUrl }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 font-semibold text-brand-cream hover:bg-brand-ink/90">
                                                {{ __('Connect DigitalOcean') }}
                                            </a>
                                            <a href="{{ $awsCredentialsUrl }}" wire:navigate class="font-medium text-brand-moss hover:text-brand-ink">{{ __('Use AWS') }}</a>
                                        </div>
                                    @elseif ($showCreateAction)
                                        <p class="text-sm text-brand-moss">
                                            {{ __('Ready when you are — pick a repo or image and deploy into a managed container.') }}
                                        </p>
                                        <a
                                            href="{{ $createUrl }}"
                                            wire:navigate
                                            class="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-forest hover:text-brand-ink"
                                        >
                                            {{ __('Open deploy wizard') }}
                                            <x-heroicon-m-arrow-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </section>
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
