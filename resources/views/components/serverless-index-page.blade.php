@props([
    /** @var \Illuminate\Support\Collection<int, \App\Support\Serverless\ServerlessIndexRow> */
    'rows',
    /** @var array{all: int, live: int, deploying: int} */
    'totals' => ['all' => 0, 'live' => 0, 'deploying' => 0],
    'hasFunctionsInScope' => true,
    'serverlessEnabled' => true,
    'apiReady' => true,
    'showCreateAction' => false,
    'showSecondaryActions' => false,
    /** @var list<array{label: string, href?: string, icon?: string}> */
    'breadcrumbs' => [],
    'emptyState' => 'local',
    'createUrl' => null,
    'glueUrl' => null,
])

@php
    $isProductionSurface = $emptyState === 'production';
    $allTotal = (int) ($totals['all'] ?? 0);
    $showStats = $serverlessEnabled && $apiReady && $hasFunctionsInScope && $allTotal > 0;
    $showShellCreate = $serverlessEnabled && $showCreateAction && $hasFunctionsInScope && $allTotal > 0;
    $showShellSecondary = $serverlessEnabled && $showSecondaryActions && $hasFunctionsInScope && $allTotal > 0;
    $createUrl ??= route('serverless.create');
    $glueUrl ??= route('serverless.glue');
    $summaryStats = [
        [
            'icon' => 'heroicon-o-bolt',
            'label' => __('All functions'),
            'value' => $allTotal,
            'tone' => 'text-brand-sage',
        ],
        [
            'icon' => 'heroicon-o-check-badge',
            'label' => __('Live'),
            'value' => (int) ($totals['live'] ?? 0),
            'tone' => 'text-brand-forest',
        ],
        [
            'icon' => 'heroicon-o-arrow-path',
            'label' => __('Deploying'),
            'value' => (int) ($totals['deploying'] ?? 0),
            'tone' => ((int) ($totals['deploying'] ?? 0)) > 0 ? 'text-brand-sage' : 'text-brand-mist',
        ],
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="$breadcrumbs" />

    @unless ($serverlessEnabled)
        <div class="dply-card relative p-8 text-center">
            <span class="absolute end-6 top-6 inline-flex rounded-full bg-brand-sand/60 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                {{ __('Coming soon') }}
            </span>
            <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-xl border border-brand-ink/10 bg-white text-brand-ink shadow-sm">
                <x-heroicon-o-bolt class="h-8 w-8 shrink-0" aria-hidden="true" />
            </span>
            <p class="mt-5 text-lg font-semibold text-brand-ink">{{ __('Serverless') }}</p>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-brand-moss">
                {{ __('HTTP-triggered functions — deploy from Git without managing servers.') }}
            </p>
            <p class="mt-5 text-sm font-medium text-brand-mist">{{ __('Not available yet') }}</p>
        </div>
    @else
        <x-profile-shell
            :title="__('Serverless functions')"
            :description="$isProductionSurface
                ? __('Live serverless functions from the connected control plane — Open materializes into the real workspace with Production data.')
                : __('HTTP-triggered functions deployed from Git — DigitalOcean Functions, AWS Lambda, and more.')"
            icon="heroicon-o-bolt"
        >
            @if ($showShellCreate || $showShellSecondary || isset($actions))
                <x-slot:actions>
                    @if ($showShellSecondary)
                        <a href="{{ $glueUrl }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-o-link class="h-4 w-4" aria-hidden="true" />
                            {{ __('Glue') }}
                        </a>
                    @endif
                    @if ($showShellCreate)
                        <a
                            href="{{ $createUrl }}"
                            wire:navigate
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('New function') }}
                        </a>
                    @endif
                    @isset($actions)
                        {{ $actions }}
                    @endisset
                </x-slot:actions>
            @endif

            @if ($showStats)
                <x-slot:stats>
                    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-3">
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

            @unless ($apiReady)
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="serverless-api-heading">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-bolt class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <h2 id="serverless-api-heading" class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No list API for Serverless yet') }}</h2>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Nav is wired. When the control-plane API exposes serverless inventory, it will load here.') }}
                    </p>
                </div>
            @elseif (! $hasFunctionsInScope)
                @if (isset($empty) && ! $empty->isEmpty())
                    {{ $empty }}
                @else
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="serverless-empty-heading">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-bolt class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <h2 id="serverless-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">
                            {{ $isProductionSurface ? __('No production functions') : __('No functions yet') }}
                        </h2>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ $isProductionSurface
                                ? __('The connected control plane returned no serverless functions for this organization.')
                                : __('Deploy an HTTP-triggered function from a Git repository — point dply at a repo and it handles the build, runtime, and invocation URL.') }}
                        </p>
                        @if ($showCreateAction && ! $isProductionSurface)
                            <a
                                href="{{ $createUrl }}"
                                wire:navigate
                                class="mt-5 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create a function') }}
                            </a>
                        @endif
                    </div>
                @endif
            @else
                @if ($rows->isEmpty())
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No functions match') }}</p>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ __('Try adjusting filters to bring functions back into view.') }}
                        </p>
                    </div>
                @else
                    <ul>
                        @foreach ($rows as $function)
                            @include('components.partials.serverless-index-card', ['function' => $function])
                        @endforeach
                    </ul>
                @endif
            @endunless
        </x-profile-shell>
    @endunless

    {{ $modals ?? '' }}
</div>
