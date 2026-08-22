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
                @if (standby_blueprint_active())
                    <a
                        href="{{ route('launches.standby') }}"
                        wire:navigate
                        class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-shield-check class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ __('Standby blueprints') }}
                    </a>
                @endif            </div>
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
                    <h3 class="mt-4 text-base font-semibold text-brand-ink">{{ __('Servers') }}</h3>
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
                </a>            </div>
        </section>

        <section class="mt-12" aria-labelledby="infrastructure-ops-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="infrastructure-ops-heading" class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-moss">
                        {{ __('Operations') }}
                    </h2>
                    <p class="mt-1 text-sm text-brand-moss/85">{{ __('Cross-product, read-only views over every server and site in the org.') }}</p>
                </div>
                <dl class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('In-flight deploys') }}</dt>
                        <dd class="mt-0.5 text-xl font-semibold tabular-nums {{ $runningDeploys > 0 ? 'text-brand-forest' : 'text-brand-ink' }}">{{ $runningDeploys }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('7-day deploy success') }}</dt>
                        <dd class="mt-0.5 text-xl font-semibold tabular-nums {{ $successRate['percent'] === null ? 'text-brand-mist' : ($successRate['percent'] >= 95 ? 'text-emerald-600' : ($successRate['percent'] >= 80 ? 'text-amber-600' : 'text-rose-600')) }}">
                            {{ $successRate['percent'] === null ? '—' : $successRate['percent'].'%' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                @php
                    $opsTiles = [
                        [
                            'route' => 'infrastructure.health',
                            'icon' => 'heroicon-o-heart',
                            'title' => __('Health'),
                            'desc' => __('Drift, in-flight deploys, success rate, most-active sites.'),
                        ],
                        [
                            'route' => 'infrastructure.deploys',
                            'icon' => 'heroicon-o-rocket-launch',
                            'title' => __('Deploys'),
                            'desc' => __('In-flight, failed-latest, and stale deploys across every site.'),
                        ],
                        [
                            'route' => 'infrastructure.domains',
                            'icon' => 'heroicon-o-globe-alt',
                            'title' => __('Domains'),
                            'desc' => __('Org-wide hostname inventory with runtime and primary filters.'),
                        ],
                        [
                            'route' => 'infrastructure.env-search',
                            'icon' => 'heroicon-o-key',
                            'title' => __('Env search'),
                            'desc' => __('Find a key (or AWS_* prefix) across every site in the org.'),
                        ],
                        [
                            'route' => 'infrastructure.env-drift',
                            'icon' => 'heroicon-o-arrows-right-left',
                            'title' => __('Env drift'),
                            'desc' => __('Compare env across BYO + Cloud + Edge sites that share a Git repo.'),
                        ],
                        [
                            'route' => 'infrastructure.intelligence',
                            'icon' => 'heroicon-o-light-bulb',
                            'title' => __('Intelligence'),
                            'desc' => __('Proactive alerts — slow builds, expiring TLS, preview/prod env drift.'),
                        ],
                        [
                            'route' => 'infrastructure.blast-radius',
                            'icon' => 'heroicon-o-share',
                            'title' => __('Blast radius'),
                            'desc' => __('Dependency map — what breaks if a server, site, or database fails.'),
                        ],
                        [
                            'route' => 'infrastructure.previews',
                            'icon' => 'heroicon-o-link',
                            'title' => __('Previews'),
                            'desc' => __('Managed preview hostnames in one place.'),
                        ],
                    ];

                    if (ops_copilot_active()) {
                        $opsTiles[] = [
                            'route' => 'infrastructure.copilot',
                            'icon' => 'heroicon-o-sparkles',
                            'title' => __('Ops Copilot'),
                            'desc' => __('Deploy failure triage — log excerpts, repo config, and fix suggestions.'),
                        ];
                    }

                    $opsTimelineOrg = auth()->user()?->currentOrganization();
                    if ($opsTimelineOrg !== null && $opsTimelineOrg->hasAdminAccess(auth()->user())) {
                        $opsTiles[] = [
                            'route_url' => route('organizations.activity', $opsTimelineOrg),
                            'icon' => 'heroicon-o-clock',
                            'title' => __('Ops timeline'),
                            'desc' => __('Org-wide audit trail — deploys, domains, env, members across every product line.'),
                        ];
                    }
                @endphp
                @foreach ($opsTiles as $tile)
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
    </div>
</div>
