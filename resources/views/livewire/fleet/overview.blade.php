<div>
    <x-fleet-shell
        :title="__('Fleet ops')"
        :description="__('Cross-product, read-only views over every server and site in :org. Spot drift, in-flight deploys, and failure surfaces across BYO, Cloud, and Edge — without leaving a single screen.', ['org' => $org->name])"
    >
        {{-- Headline stats --}}
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Fleet at a glance') }}">
            <x-fleet-stat :label="__('Servers')">
                <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $serverCount }}</p>
            </x-fleet-stat>
            <x-fleet-stat :label="__('Sites')">
                <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $siteCount }}</p>
            </x-fleet-stat>
            <x-fleet-stat :label="__('In-flight deploys')">
                <p class="mt-2 text-3xl font-semibold tabular-nums {{ $runningDeploys > 0 ? 'text-brand-forest' : 'text-brand-ink' }}">{{ $runningDeploys }}</p>
            </x-fleet-stat>
            <x-fleet-stat :label="__('7-day success')">
                @if ($successRate['percent'] === null)
                    <p class="mt-2 text-3xl font-semibold text-brand-mist">—</p>
                @else
                    <p class="mt-2 text-3xl font-semibold tabular-nums {{ $successRate['percent'] >= 95 ? 'text-emerald-600' : ($successRate['percent'] >= 80 ? 'text-amber-600' : 'text-rose-600') }}">{{ $successRate['percent'] }}%</p>
                @endif
            </x-fleet-stat>
        </section>

        {{-- What is the fleet --}}
        <section class="mt-8 overflow-hidden rounded-2xl border border-brand-sage/30 bg-brand-sand/20">
            <div class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:p-7">
                <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-rectangle-stack class="h-6 w-6" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('What is the fleet?') }}</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Your “fleet” is everything dply runs for this organization — SSH-managed servers, managed Cloud containers, Serverless functions, and Edge sites. Most product screens focus on one resource at a time; Fleet ops zooms out so you can answer org-wide questions in seconds.') }}
                    </p>
                    <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                        <li class="flex items-start gap-2.5 text-sm text-brand-moss">
                            <x-heroicon-o-magnifying-glass class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            <span><span class="font-semibold text-brand-ink">{{ __('Find anything') }}</span> — {{ __('locate a domain, an env var, or a preview URL across every site at once.') }}</span>
                        </li>
                        <li class="flex items-start gap-2.5 text-sm text-brand-moss">
                            <x-heroicon-o-shield-exclamation class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            <span><span class="font-semibold text-brand-ink">{{ __('Catch problems early') }}</span> — {{ __('drift, failed deploys, slow builds, and expiring TLS surface before they page you.') }}</span>
                        </li>
                        <li class="flex items-start gap-2.5 text-sm text-brand-moss">
                            <x-heroicon-o-share class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            <span><span class="font-semibold text-brand-ink">{{ __('Understand impact') }}</span> — {{ __('map dependencies to see what breaks if a server or database goes down.') }}</span>
                        </li>
                        <li class="flex items-start gap-2.5 text-sm text-brand-moss">
                            <x-heroicon-o-command-line class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            <span><span class="font-semibold text-brand-ink">{{ __('Mirror the CLI') }}</span> — {{ __('every view maps to a dply:fleet:* command for terminal follow-up.') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- Section directory --}}
        @php
            $sections = [
                ['route' => 'fleet.health', 'icon' => 'heroicon-o-heart', 'title' => __('Health'), 'desc' => __('Drift, in-flight deploys, success rate, and most-active sites at a glance.')],
                ['route' => 'fleet.deploys', 'icon' => 'heroicon-o-rocket-launch', 'title' => __('Deploys'), 'desc' => __('Running, failed-latest, and stale deploys across every site.')],
                ['route' => 'fleet.domains', 'icon' => 'heroicon-o-globe-alt', 'title' => __('Domains'), 'desc' => __('Fleet-wide hostname inventory with runtime and primary filters.')],
                ['route' => 'fleet.env-search', 'icon' => 'heroicon-o-key', 'title' => __('Env search'), 'desc' => __('Find a key — or an AWS_* prefix — across every site in the org.')],
                ['route' => 'fleet.env-drift', 'icon' => 'heroicon-o-arrows-right-left', 'title' => __('Env drift'), 'desc' => __('Compare env across BYO, Cloud, and Edge sites that share a Git repo.')],
                ['route' => 'fleet.intelligence', 'icon' => 'heroicon-o-light-bulb', 'title' => __('Intelligence'), 'desc' => __('Proactive alerts — slow builds, expiring TLS, preview/prod env drift.')],
                ['route' => 'fleet.blast-radius', 'icon' => 'heroicon-o-share', 'title' => __('Blast radius'), 'desc' => __('Dependency map — what breaks if a server, site, or database fails.')],
                ['route' => 'fleet.previews', 'icon' => 'heroicon-o-link', 'title' => __('Previews'), 'desc' => __('Managed preview hostnames across BYO and Edge in one place.')],
            ];

            if (ops_copilot_active()) {
                $sections[] = ['route' => 'fleet.copilot', 'icon' => 'heroicon-o-sparkles', 'title' => __('Copilot'), 'desc' => __('Deploy failure triage — log excerpts, repo config, and fix suggestions.')];
            }

            $timelineOrg = auth()->user()?->currentOrganization();
            if ($timelineOrg !== null && $timelineOrg->hasAdminAccess(auth()->user())) {
                $sections[] = ['route_url' => route('organizations.activity', $timelineOrg), 'icon' => 'heroicon-o-clock', 'title' => __('Timeline'), 'desc' => __('Org-wide audit trail — deploys, domains, env, and members across product lines.')];
            }
        @endphp

        <section class="mt-10" aria-labelledby="fleet-sections-heading">
            <h2 id="fleet-sections-heading" class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Explore') }}</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($sections as $section)
                    <a
                        href="{{ $section['route_url'] ?? route($section['route']) }}"
                        wire:navigate
                        class="group flex flex-col rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm ring-1 ring-brand-ink/[0.04] transition hover:-translate-y-0.5 hover:border-brand-sage/45 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40"
                    >
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10">
                            <x-dynamic-component :component="$section['icon']" class="h-5 w-5 shrink-0" aria-hidden="true" />
                        </span>
                        <h3 class="mt-3 text-sm font-semibold text-brand-ink">{{ $section['title'] }}</h3>
                        <p class="mt-1 flex-1 text-xs leading-5 text-brand-moss">{{ $section['desc'] }}</p>
                        <p class="mt-3 text-xs font-semibold text-brand-sage group-hover:text-brand-ink">{{ __('Open') }} →</p>
                    </a>
                @endforeach
            </div>
        </section>

        <x-cli-snippet class="mt-10" command="dply:fleet:doctor" />
    </x-fleet-shell>
</div>
