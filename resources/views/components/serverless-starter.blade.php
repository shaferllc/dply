@props([
    /** Deploy wizard URL — the primary destination for every path on this page. */
    'createUrl',
    'glueUrl' => null,
    /** Deploy wizard deep-links that arrive with the demo repo already prefilled. */
    'laravelDemoUrl' => null,
    'phpDemoUrl' => null,
    'showCreateAction' => true,
    'showSecondaryActions' => true,
])

@php
    // Three ways in, ordered by how fast they get someone to a live URL. All
    // three land on the same deploy form — the demos just arrive prefilled.
    $startPaths = array_values(array_filter([
        $laravelDemoUrl === null ? null : [
            'href' => $laravelDemoUrl,
            'icon' => 'heroicon-o-cube',
            'title' => __('Laravel demo'),
            'badge' => __('Fastest'),
            'body' => __('A real Laravel app, prefilled and ready to deploy as-is. Pick a region, hit Create.'),
            'cta' => __('Deploy the demo'),
        ],
        [
            'href' => $createUrl,
            'icon' => 'heroicon-o-code-bracket',
            'title' => __('Your Git repo'),
            'badge' => null,
            'body' => __('Point at any Laravel or PHP repository — dply detects the runtime and builds it.'),
            'cta' => __('Choose a repo'),
        ],
        $phpDemoUrl === null ? null : [
            'href' => $phpDemoUrl,
            'icon' => 'heroicon-o-bolt',
            'title' => __('Plain PHP function'),
            'badge' => null,
            'body' => __('The smallest thing that runs — a single native-PHP web action, no framework.'),
            'cta' => __('Deploy the function'),
        ],
    ]));

    // Literal class strings so Tailwind's scanner sees them — the tile count
    // varies with which demo deep-links the caller passes.
    $startColumns = match (count($startPaths)) {
        1 => '',
        2 => 'sm:grid-cols-2',
        default => 'sm:grid-cols-3',
    };

    $pipeline = [
        ['icon' => 'heroicon-m-arrow-up-tray', 'label' => __('git push')],
        ['icon' => 'heroicon-m-cube', 'label' => __('dply builds')],
        ['icon' => 'heroicon-m-globe-alt', 'label' => __('HTTPS URL')],
    ];

    // Read straight off the biller's own config so the page can never quote a
    // price the invoice contradicts.
    $pricing = app(\App\Modules\Serverless\Services\ServerlessCostEstimator::class)->pricingSummary();
    $money = static fn (int $cents): string => '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);

    $facts = [
        __('Laravel first'),
        __('HTTPS URL included'),
        __('Git-driven deploys'),
        __('No plan upgrade — works on Free'),
    ];

    $steps = [
        [
            'n' => '01',
            'title' => __('Connect a provider'),
            'body' => __('Link DigitalOcean — or use dply-hosted where available — so we can deploy into a managed namespace.'),
        ],
        [
            'n' => '02',
            'title' => __('Point at a repo'),
            'body' => __('dply detects the runtime, builds the package, and injects the Functions adapter at deploy time.'),
        ],
        [
            'n' => '03',
            'title' => __('Open the URL'),
            'body' => __('A stable HTTPS endpoint with logs, rollbacks, and deploy history in the app workspace.'),
        ],
    ];
@endphp

<section class="dply-card relative overflow-hidden p-0" aria-labelledby="serverless-starter-heading">
    {{-- Brand wash behind the hero only — the strips below stay flat so the page
         reads as one card rather than a gradient poster. --}}
    <div class="pointer-events-none absolute inset-x-0 top-0 h-80 bg-mesh-brand opacity-90" aria-hidden="true"></div>

    {{-- Hero: the pitch on the left, a picture of the outcome on the right. --}}
    <div class="relative grid gap-8 px-6 py-8 sm:px-8 sm:py-10 lg:grid-cols-[minmax(0,1fr)_19rem] lg:items-center lg:gap-12">
        <div class="min-w-0">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white/70 px-3 py-1 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-forest shadow-sm backdrop-blur-sm">
                <x-heroicon-m-bolt class="h-3.5 w-3.5 shrink-0 text-brand-gold" aria-hidden="true" />
                {{ __('Serverless') }}
                <span class="text-brand-mist" aria-hidden="true">·</span>
                {{ __(':price / mo per app', ['price' => $money($pricing['flat_cents'])]) }}
            </span>

            <h1 id="serverless-starter-heading" class="mt-4 text-2xl font-semibold leading-tight tracking-tight text-brand-ink sm:text-[2rem]">
                {{ __('Ship a Laravel app.') }}
                <span class="block text-brand-sage">{{ __('Skip the server.') }}</span>
            </h1>

            <p class="mt-3 max-w-xl text-sm leading-relaxed text-brand-moss">
                {{ __('Point dply at a Git repo. We build it, run it on demand, and hand back an HTTPS URL — no box to provision, patch, or pay for while it idles.') }}
            </p>

            @if ($showCreateAction || $showSecondaryActions)
                <div class="mt-5 flex flex-wrap items-center gap-2.5">
                    @if ($showCreateAction)
                        <a
                            href="{{ $createUrl }}"
                            wire:navigate
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Deploy from Git') }}
                        </a>
                    @endif
                    @if ($showSecondaryActions && $glueUrl)
                        <a
                            href="{{ $glueUrl }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white/80 px-3.5 py-2.5 text-sm font-semibold text-brand-ink shadow-sm backdrop-blur-sm transition hover:bg-white"
                        >
                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Explore Glue') }}
                        </a>
                    @endif
                </div>
            @endif

            {{-- The whole product in one line: push, build, live. --}}
            <div class="mt-6 flex flex-wrap items-center gap-x-1.5 gap-y-2">
                @foreach ($pipeline as $i => $node)
                    @if ($i > 0)
                        <x-heroicon-m-arrow-long-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                    @endif
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-2xs font-semibold uppercase tracking-wide backdrop-blur-sm',
                        'border-brand-ink/10 bg-white/70 text-brand-moss' => $i < count($pipeline) - 1,
                        'border-brand-sage/30 bg-brand-sage/15 text-brand-forest' => $i === count($pipeline) - 1,
                    ])>
                        <x-dynamic-component :component="$node['icon']" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ $node['label'] }}
                    </span>
                @endforeach
            </div>
        </div>

        {{-- Illustrative only: what a deployed app looks like once it lands. --}}
        <div class="hidden lg:block" aria-hidden="true">
            <div class="rounded-2xl border border-brand-ink/10 bg-white/75 p-3 shadow-lg shadow-brand-ink/[0.06] backdrop-blur-sm">
                <div class="flex items-center justify-between px-1 pb-2">
                    <span class="text-3xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Preview') }}</span>
                    <span class="inline-flex items-center gap-1 text-3xs font-semibold uppercase tracking-wide text-brand-forest">
                        <span class="h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                        {{ __('Live') }}
                    </span>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/70 px-3 py-3">
                    <p class="truncate text-sm font-semibold text-brand-ink">laravel-demo</p>
                    <p class="mt-1 truncate font-mono text-2xs text-brand-moss">https://laravel-demo.dply.app</p>
                    <dl class="mt-3 grid grid-cols-3 gap-2 border-t border-brand-ink/10 pt-2.5">
                        <div class="min-w-0">
                            <dt class="text-3xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Runtime') }}</dt>
                            <dd class="truncate font-mono text-2xs text-brand-ink">php:8.4</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-3xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Region') }}</dt>
                            <dd class="truncate font-mono text-2xs text-brand-ink">nyc1</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-3xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Build') }}</dt>
                            <dd class="truncate font-mono text-2xs text-brand-ink">41s</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    {{-- Start from — the page's real job: pick a path in. --}}
    <div class="relative border-t border-brand-ink/10">
        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-2.5 sm:px-8">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-brand-ink">{{ __('Start from') }}</h2>
            <p class="text-xs text-brand-moss">{{ __('Every path lands on the same deploy form — the demos arrive prefilled.') }}</p>
        </div>
        <ul class="grid {{ $startColumns }}">
            @foreach ($startPaths as $i => $path)
                <li @class([
                    'min-w-0',
                    'border-b border-brand-ink/10 sm:border-b-0 sm:border-e' => $i < count($startPaths) - 1,
                ])>
                    <a
                        href="{{ $path['href'] }}"
                        wire:navigate
                        class="group flex h-full flex-col gap-1.5 px-6 py-4 transition-colors hover:bg-brand-sand/20 sm:px-6"
                    >
                        <div class="flex items-center gap-2">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest shadow-sm ring-1 ring-brand-ink/10 transition group-hover:ring-brand-sage/40">
                                <x-dynamic-component :component="$path['icon']" class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <p class="min-w-0 text-sm font-semibold text-brand-ink">{{ $path['title'] }}</p>
                            @if ($path['badge'])
                                <span class="inline-flex shrink-0 items-center rounded-full bg-brand-gold/20 px-2 py-0.5 text-3xs font-bold uppercase tracking-wide text-brand-rust">
                                    {{ $path['badge'] }}
                                </span>
                            @endif
                        </div>
                        <p class="text-xs leading-relaxed text-brand-moss">{{ $path['body'] }}</p>
                        <span class="mt-auto inline-flex items-center gap-1 pt-1 text-xs font-semibold text-brand-forest">
                            {{ $path['cta'] }}
                            <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- What it costs — spelled out, because "$2/mo" alone stops being the
         whole story the moment metered overage is switched on. --}}
    <div class="relative border-t border-brand-ink/10">
        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-2.5 sm:px-8">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-brand-ink">{{ __('What it costs') }}</h2>
            <p class="text-xs text-brand-moss">
                {{ $pricing['metered']
                    ? __('A flat fee per app, a monthly usage allowance, then metered by what you actually run.')
                    : __('A flat fee per app. Nothing metered on top.') }}
            </p>
        </div>

        <dl @class([
            'grid',
            'sm:grid-cols-3' => $pricing['metered'],
            'sm:grid-cols-1' => ! $pricing['metered'],
        ])>
            <div class="min-w-0 border-b border-brand-ink/10 px-6 py-4 sm:border-b-0 sm:border-e sm:px-6">
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Platform fee') }}</dt>
                <dd class="mt-1 flex items-baseline gap-1">
                    <span class="text-xl font-semibold tracking-tight text-brand-ink">{{ $money($pricing['flat_cents']) }}</span>
                    <span class="text-xs text-brand-moss">{{ __('/ mo per app') }}</span>
                </dd>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                    {{ __('Charged once the app is live. Works on the Free plan — no upgrade required.') }}
                </p>
            </div>

            @if ($pricing['metered'])
                <div class="min-w-0 border-b border-brand-ink/10 px-6 py-4 sm:border-b-0 sm:border-e sm:px-6">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Included every month') }}</dt>
                    <dd class="mt-1 font-mono text-sm font-semibold text-brand-ink">
                        {{ __(':gib GiB-seconds', ['gib' => number_format($pricing['included_gib_seconds'])]) }}
                    </dd>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                        {{ __('Roughly :hours hours of a :memory MB app, plus :requests requests — per app, every month.', [
                            'hours' => $pricing['included_hours_at_default_memory'],
                            'memory' => \App\Models\Site::SERVERLESS_DEFAULT_MEMORY_MB,
                            'requests' => number_format($pricing['included_invocations']),
                        ]) }}
                    </p>
                </div>

                <div class="min-w-0 px-6 py-4 sm:px-6">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('After the allowance') }}</dt>
                    <dd class="mt-1 font-mono text-sm font-semibold text-brand-ink">
                        {{ __(':price / 100k GiB-seconds', ['price' => $money($pricing['gib_seconds_cents_per_100k'])]) }}
                    </dd>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                        {{ __('Plus :price per million requests. Metered hourly — you only pay for compute your app actually used.', [
                            'price' => $money($pricing['invocations_cents_per_million']),
                        ]) }}
                    </p>
                </div>
            @endif
        </dl>

        <p class="border-t border-brand-ink/10 bg-brand-cream/40 px-6 py-2.5 text-xs leading-relaxed text-brand-moss sm:px-8">
            <x-heroicon-m-information-circle class="me-1 inline h-3.5 w-3.5 -translate-y-px text-brand-sage" aria-hidden="true" />
            {{ __('Deploying to your own DigitalOcean account? Then DigitalOcean bills you for compute directly and dply charges only the flat fee — the allowance and rates above apply to dply-hosted apps.') }}
        </p>
    </div>

    {{-- Facts on one line; the teaching copy stays folded away until asked for. --}}
    <div class="relative border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-2.5 sm:px-8">
        <details class="group/details">
            <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-x-4 gap-y-1.5">
                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                    @foreach ($facts as $i => $fact)
                        @if ($i > 0)
                            <span class="text-brand-mist/60" aria-hidden="true">·</span>
                        @endif
                        <span>{{ $fact }}</span>
                    @endforeach
                </div>
                <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-forest hover:text-brand-ink">
                    {{ __('How it works') }}
                    <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 transition-transform group-open/details:rotate-180" aria-hidden="true" />
                </span>
            </summary>
            <ol class="mt-3 grid gap-3 border-t border-brand-ink/10 pt-3 sm:grid-cols-3">
                @foreach ($steps as $step)
                    <li class="flex min-w-0 gap-2.5">
                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-white text-2xs font-bold tabular-nums text-brand-forest ring-1 ring-brand-ink/10" aria-hidden="true">
                            {{ $step['n'] }}
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold leading-6 text-brand-ink">{{ $step['title'] }}</p>
                            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $step['body'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </details>
    </div>
</section>
