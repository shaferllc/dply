<div>
    <div class="dply-page-shell py-8 sm:py-10">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Infrastructure'), 'icon' => 'rectangle-group'],
        ]" />

        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="max-w-2xl">
                <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Infrastructure') }}</h1>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                    {{ __('Your compute across :org — SSH-managed servers, container apps, and serverless functions.', ['org' => $org->name]) }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a
                    href="{{ route('launches.create') }}"
                    wire:navigate
                    class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                >
                    <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Open launchpad') }}
                </a>
            </div>
        </header>

        <section class="mt-8" aria-labelledby="infrastructure-compute-heading">
            <h2 id="infrastructure-compute-heading" class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-moss">
                {{ __('Compute') }}
            </h2>

            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {{-- Servers --}}
                <a
                    href="{{ route('servers.index') }}"
                    wire:navigate
                    class="group relative flex flex-col rounded-2xl border-2 border-brand-sage/35 bg-white p-6 shadow-sm ring-1 ring-brand-ink/[0.06] transition hover:-translate-y-0.5 hover:border-brand-sage/55 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40"
                >
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10">
                        <x-heroicon-o-server-stack class="h-7 w-7 shrink-0" aria-hidden="true" />
                    </span>
                    <h3 class="mt-4 text-lg font-semibold text-brand-ink">{{ __('Servers') }}</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-brand-moss">
                        {{ __('SSH-managed VMs, droplets, and clusters you operate directly.') }}
                    </p>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">
                        <span class="font-mono">{{ $counts['servers']['ready'] }}</span>
                        <span class="text-brand-moss">/</span>
                        <span class="font-mono text-brand-moss">{{ $counts['servers']['total'] }}</span>
                        <span class="ms-1 font-normal text-brand-moss">{{ __('ready') }}</span>
                    </p>
                    <p class="mt-3 text-sm font-semibold text-brand-sage group-hover:text-brand-ink">{{ __('Open servers') }} →</p>
                </a>

                {{-- Cloud apps --}}
                @if ($cloudEnabled)
                    <a
                        href="{{ route('cloud.index') }}"
                        wire:navigate
                        class="group relative flex flex-col rounded-2xl border-2 border-brand-sage/35 bg-white p-6 shadow-sm ring-1 ring-brand-ink/[0.06] transition hover:-translate-y-0.5 hover:border-brand-sage/55 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40"
                    >
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10">
                            <x-heroicon-o-cube class="h-7 w-7 shrink-0" aria-hidden="true" />
                        </span>
                        <h3 class="mt-4 text-lg font-semibold text-brand-ink">{{ __('Cloud apps') }}</h3>
                        <p class="mt-2 flex-1 text-sm leading-6 text-brand-moss">
                            {{ __('DO App Platform, AWS App Runner, and other managed container backends.') }}
                        </p>
                        <p class="mt-4 text-sm font-semibold text-brand-ink">
                            <span class="font-mono">{{ $counts['cloud']['active'] }}</span>
                            <span class="text-brand-moss">/</span>
                            <span class="font-mono text-brand-moss">{{ $counts['cloud']['total'] }}</span>
                            <span class="ms-1 font-normal text-brand-moss">{{ __('active') }}</span>
                        </p>
                        <p class="mt-3 text-sm font-semibold text-brand-sage group-hover:text-brand-ink">{{ __('Open cloud apps') }} →</p>
                    </a>
                @else
                    <div
                        class="relative flex flex-col rounded-2xl border border-brand-ink/10 bg-white/70 p-6 opacity-[0.88] shadow-sm ring-1 ring-brand-ink/[0.04]"
                        aria-disabled="true"
                        role="group"
                        aria-labelledby="infrastructure-cloud-soon"
                    >
                        <span class="absolute end-4 top-4 inline-flex rounded-full bg-brand-ink/[0.06] px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                            {{ __('Coming soon') }}
                        </span>
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-ink/[0.04] text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-cube class="h-7 w-7 shrink-0 opacity-80" aria-hidden="true" />
                        </span>
                        <h3 id="infrastructure-cloud-soon" class="mt-4 text-lg font-semibold text-brand-ink">{{ __('Cloud apps') }}</h3>
                        <p class="mt-2 flex-1 text-sm leading-6 text-brand-moss">
                            {{ __('DO App Platform, AWS App Runner, and other managed container backends.') }}
                        </p>
                        <p class="mt-5 text-sm font-medium text-brand-mist">{{ __('Not available yet') }}</p>
                    </div>
                @endif

                {{-- Serverless --}}
                <a
                    href="{{ route('serverless.index') }}"
                    wire:navigate
                    class="group relative flex flex-col rounded-2xl border-2 border-brand-sage/35 bg-white p-6 shadow-sm ring-1 ring-brand-ink/[0.06] transition hover:-translate-y-0.5 hover:border-brand-sage/55 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40"
                >
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10">
                        <x-heroicon-o-bolt class="h-7 w-7 shrink-0" aria-hidden="true" />
                    </span>
                    <h3 class="mt-4 text-lg font-semibold text-brand-ink">{{ __('Serverless') }}</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-brand-moss">
                        {{ __('HTTP-triggered functions on DigitalOcean Functions and AWS Lambda.') }}
                    </p>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">
                        <span class="font-mono">{{ $counts['serverless']['total'] }}</span>
                        <span class="ms-1 font-normal text-brand-moss">{{ trans_choice('function|functions', $counts['serverless']['total']) }}</span>
                    </p>
                    <p class="mt-3 text-sm font-semibold text-brand-sage group-hover:text-brand-ink">{{ __('Open serverless') }} →</p>
                </a>

                {{-- Edge --}}
                @if ($edgeEnabled)
                    <a
                        href="{{ route('edge.index') }}"
                        wire:navigate
                        class="group relative flex flex-col rounded-2xl border-2 border-brand-sage/35 bg-white p-6 shadow-sm ring-1 ring-brand-ink/[0.06] transition hover:-translate-y-0.5 hover:border-brand-sage/55 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40"
                    >
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10">
                            <x-heroicon-o-globe-alt class="h-7 w-7 shrink-0" aria-hidden="true" />
                        </span>
                        <h3 class="mt-4 text-lg font-semibold text-brand-ink">{{ __('Edge') }}</h3>
                        <p class="mt-2 flex-1 text-sm leading-6 text-brand-moss">
                            {{ __('JavaScript frameworks, static sites, previews, and CDN-style delivery.') }}
                        </p>
                        <p class="mt-4 text-sm font-semibold text-brand-ink">
                            <span class="font-mono">{{ $counts['edge']['active'] }}</span>
                            <span class="text-brand-moss">/</span>
                            <span class="font-mono text-brand-moss">{{ $counts['edge']['total'] }}</span>
                            <span class="ms-1 font-normal text-brand-moss">{{ __('active') }}</span>
                        </p>
                        <p class="mt-3 text-sm font-semibold text-brand-sage group-hover:text-brand-ink">{{ __('Open edge apps') }} →</p>
                    </a>
                @else
                    <a
                        href="{{ route('edge.index') }}"
                        wire:navigate
                        class="group relative flex flex-col rounded-2xl border border-brand-ink/10 bg-white/70 p-6 opacity-[0.88] shadow-sm ring-1 ring-brand-ink/[0.04] transition hover:-translate-y-0.5 hover:border-brand-ink/20 hover:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40"
                    >
                        <span class="absolute end-4 top-4 inline-flex rounded-full bg-brand-ink/[0.06] px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                            {{ __('Coming soon') }}
                        </span>
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-ink/[0.04] text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-globe-alt class="h-7 w-7 shrink-0 opacity-80" aria-hidden="true" />
                        </span>
                        <h3 id="infrastructure-edge-soon" class="mt-4 text-lg font-semibold text-brand-ink">{{ __('Edge') }}</h3>
                        <p class="mt-2 flex-1 text-sm leading-6 text-brand-moss">
                            {{ __('JavaScript frameworks, static sites, previews, and CDN-style delivery.') }}
                        </p>
                        <p class="mt-5 text-sm font-semibold text-brand-sage group-hover:text-brand-ink">{{ __('Learn more') }} →</p>
                    </a>
                @endif
            </div>
        </section>

        @if ($fleetEnabled)
            <section class="mt-12" aria-labelledby="infrastructure-fleet-heading">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 id="infrastructure-fleet-heading" class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-moss">
                            {{ __('Fleet ops') }}
                        </h2>
                        <p class="mt-1 text-sm text-brand-moss/85">{{ __('Cross-product views over every server and site in the org.') }}</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    @php
                        $fleetTiles = [
                            [
                                'route' => 'fleet.health',
                                'icon' => 'heroicon-o-heart',
                                'title' => __('Fleet health'),
                                'desc' => __('Drift, in-flight deploys, success rate, most-active sites.'),
                            ],
                            [
                                'route' => 'fleet.deploys',
                                'icon' => 'heroicon-o-rocket-launch',
                                'title' => __('Deploys'),
                                'desc' => __('In-flight, failed-latest, and stale deploys across every site.'),
                            ],
                            [
                                'route' => 'fleet.domains',
                                'icon' => 'heroicon-o-globe-alt',
                                'title' => __('Domains'),
                                'desc' => __('Fleet-wide hostname inventory with runtime and primary filters.'),
                            ],
                            [
                                'route' => 'fleet.env-search',
                                'icon' => 'heroicon-o-key',
                                'title' => __('Env search'),
                                'desc' => __('Find a key (or AWS_* prefix) across every site in the org.'),
                            ],
                            [
                                'route' => 'fleet.env-drift',
                                'icon' => 'heroicon-o-arrows-right-left',
                                'title' => __('Env drift'),
                                'desc' => __('Compare env across BYO + Cloud + Edge sites that share a Git repo.'),
                            ],
                            [
                                'route' => 'fleet.intelligence',
                                'icon' => 'heroicon-o-light-bulb',
                                'title' => __('Intelligence'),
                                'desc' => __('Proactive alerts — slow builds, expiring TLS, preview/prod env drift.'),
                            ],
                        ];

                        $opsTimelineOrg = auth()->user()?->currentOrganization();
                        if ($opsTimelineOrg !== null && $opsTimelineOrg->hasAdminAccess(auth()->user())) {
                            $fleetTiles[] = [
                                'route_url' => route('organizations.activity', $opsTimelineOrg),
                                'icon' => 'heroicon-o-clock',
                                'title' => __('Ops timeline'),
                                'desc' => __('Org-wide audit trail — deploys, domains, env, members across every product line.'),
                            ];
                        }
                    @endphp
                    @foreach ($fleetTiles as $tile)
                        <a
                            href="{{ $tile['route_url'] ?? route($tile['route']) }}"
                            wire:navigate
                            class="group flex flex-col rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm ring-1 ring-brand-ink/[0.04] transition hover:-translate-y-0.5 hover:border-brand-sage/45 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40"
                        >
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10">
                                <x-dynamic-component :component="$tile['icon']" class="h-5 w-5 shrink-0" aria-hidden="true" />
                            </span>
                            <h3 class="mt-3 text-sm font-semibold text-brand-ink">{{ $tile['title'] }}</h3>
                            <p class="mt-1 flex-1 text-xs leading-5 text-brand-moss">{{ $tile['desc'] }}</p>
                            <p class="mt-3 text-xs font-semibold text-brand-sage group-hover:text-brand-ink">{{ __('Open') }} →</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
