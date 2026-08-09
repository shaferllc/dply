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
            'label' => __('All apps'),
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
            <span class="absolute end-6 top-6 inline-flex rounded-full bg-brand-sand/60 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                {{ __('Coming soon') }}
            </span>
            <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-xl border border-brand-ink/10 bg-white text-brand-ink shadow-sm">
                <x-heroicon-o-bolt class="h-8 w-8 shrink-0" aria-hidden="true" />
            </span>
            <p class="mt-5 text-lg font-semibold text-brand-ink">{{ __('Serverless') }}</p>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-brand-moss">
                {{ __('Deploy full web apps from Git — Laravel first — without managing servers.') }}
            </p>
            <p class="mt-5 text-sm font-medium text-brand-mist">{{ __('Not available yet') }}</p>
        </div>
    @else
        <x-profile-shell
            :title="__('Serverless apps')"
            :description="$isProductionSurface
                ? __('Live serverless apps from the connected control plane — Open materializes into the real workspace with Production data.')
                : __('Deploy full sites from Git — Laravel first. No servers to manage; dply handles build, runtime, and the public URL.')"
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
                            {{ __('New app') }}
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
                                <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
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
                @elseif ($isProductionSurface)
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="serverless-empty-heading">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-bolt class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <h2 id="serverless-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">
                            {{ __('No production apps') }}
                        </h2>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ __('The connected control plane returned no serverless apps for this organization.') }}
                        </p>
                    </div>
                @else
                    @php
                        $serverlessFlatCents = (int) config('subscription.standard.serverless_cents', 200);
                        $serverlessFlatLabel = '$'.number_format($serverlessFlatCents / 100, $serverlessFlatCents % 100 === 0 ? 0 : 2);
                        $emptySteps = [
                            [
                                'n' => '01',
                                'icon' => 'heroicon-o-key',
                                'title' => __('Connect a provider'),
                                'body' => __('Link DigitalOcean (or use dply-hosted when available) so we can deploy your app into a managed namespace.'),
                            ],
                            [
                                'n' => '02',
                                'icon' => 'heroicon-o-code-bracket',
                                'title' => __('Point at a Git repo'),
                                'body' => __('Bring a Laravel app — or PHP — and dply detects the runtime, builds the package, and ships the site.'),
                            ],
                            [
                                'n' => '03',
                                'icon' => 'heroicon-o-globe-alt',
                                'title' => __('Open the public URL'),
                                'body' => __('Get a stable HTTPS endpoint, logs, and rollbacks in the app workspace — no servers to provision or patch.'),
                            ],
                        ];
                        $emptyHighlights = [
                            [
                                'icon' => 'heroicon-o-cube',
                                'title' => __('Laravel first'),
                                'body' => __('Deploy a real Laravel app as-is. The OpenWhisk adapter is applied at deploy time — your repo stays plain Laravel.'),
                            ],
                            [
                                'icon' => 'heroicon-o-globe-alt',
                                'title' => __('Full web apps'),
                                'body' => __('HTTP sites with a public URL — not just one-off handlers. Great for APIs, webhooks, and full Laravel apps.'),
                            ],
                            [
                                'icon' => 'heroicon-o-arrow-path',
                                'title' => __('Git-driven deploys'),
                                'body' => __('Push to your branch and redeploy from the dashboard — build output and journey status stay in one place.'),
                            ],
                            [
                                'icon' => 'heroicon-o-banknotes',
                                'title' => __(':price / mo per app', ['price' => $serverlessFlatLabel]),
                                'body' => __('Flat platform fee from unit one, plus provider usage. Works on Free — no plan upgrade required.'),
                            ],
                        ];
                    @endphp

                    {{-- Hero --}}
                    <section class="border-b border-brand-ink/10 px-5 py-8 sm:px-6" aria-labelledby="serverless-empty-heading">
                        <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                            <div class="min-w-0 max-w-2xl">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Get started') }}</p>
                                <h2 id="serverless-empty-heading" class="mt-1.5 text-xl font-semibold tracking-tight text-brand-ink sm:text-2xl">
                                    {{ __('No apps yet') }}
                                </h2>
                                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                                    {{ __('Ship a full Laravel site from Git in a few minutes. dply owns the build, runtime, and public URL — you keep the app.') }}
                                </p>
                            </div>
                            @if ($showCreateAction)
                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <a
                                        href="{{ $createUrl }}"
                                        wire:navigate
                                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                                    >
                                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Create an app') }}
                                    </a>
                                    @if ($showSecondaryActions)
                                        <a
                                            href="{{ $glueUrl }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Explore Glue') }}
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </section>

                    {{-- How it works --}}
                    <section class="border-b border-brand-ink/10" aria-labelledby="serverless-empty-steps-heading">
                        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                            <h3 id="serverless-empty-steps-heading" class="text-sm font-semibold text-brand-ink">{{ __('How it works') }}</h3>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Three steps from empty workspace to a live Laravel app.') }}</p>
                        </div>
                        <ol class="grid gap-0 sm:grid-cols-3">
                            @foreach ($emptySteps as $i => $step)
                                <li @class([
                                    'flex gap-3 px-5 py-5 sm:px-6',
                                    'border-b border-brand-ink/10 sm:border-b-0 sm:border-e' => $i < count($emptySteps) - 1,
                                    'border-b-0' => $i === count($emptySteps) - 1,
                                ])>
                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-xs font-bold tabular-nums text-brand-forest ring-1 ring-brand-sage/25" aria-hidden="true">
                                        {{ $step['n'] }}
                                    </span>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <x-dynamic-component :component="$step['icon']" class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                            <p class="text-sm font-semibold text-brand-ink">{{ $step['title'] }}</p>
                                        </div>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $step['body'] }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>

                    {{-- Highlights --}}
                    <section aria-labelledby="serverless-empty-highlights-heading">
                        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                            <h3 id="serverless-empty-highlights-heading" class="text-sm font-semibold text-brand-ink">{{ __('What you get') }}</h3>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Built for full sites first — Laravel apps, APIs, and glue between Edge, Cloud, and BYO.') }}</p>
                        </div>
                        <ul class="grid gap-3 p-5 sm:grid-cols-2 sm:p-6">
                            @foreach ($emptyHighlights as $highlight)
                                <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-brand-cream/30 px-4 py-3.5">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest shadow-sm ring-1 ring-brand-ink/10">
                                        <x-dynamic-component :component="$highlight['icon']" class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $highlight['title'] }}</p>
                                        <p class="mt-0.5 text-sm leading-relaxed text-brand-moss">{{ $highlight['body'] }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        @if ($showCreateAction)
                            <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="text-sm text-brand-moss">
                                        {{ __('New to serverless? Start from the Laravel demo — a real app you can deploy as-is — or a minimal PHP demo.') }}
                                    </p>
                                    <a
                                        href="{{ $createUrl }}"
                                        wire:navigate
                                        class="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-forest hover:text-brand-ink"
                                    >
                                        {{ __('Start from Laravel') }}
                                        <x-heroicon-m-arrow-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    </a>
                                </div>
                            </div>
                        @endif
                    </section>
                @endif
            @else
                @if ($rows->isEmpty())
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No apps match') }}</p>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ __('Try adjusting filters to bring apps back into view.') }}
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
