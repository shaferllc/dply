@php
    $user = auth()->user();
    $displayName = filled($user->name ?? null) ? $user->name : __('there');
    $organizationName = $organization?->name ?? __('Your organization');
    $openFindings = (int) ($fleetInsights['total_open'] ?? 0);
    $avgHealthScore = $fleetInsights['avg_health_score'] ?? null;

    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];

    $platformSurfaces = [
        [
            'title' => __('Servers'),
            'description' => __('Provision infrastructure, review fleet health, and keep your estate ready to ship.'),
            'href' => route('servers.index'),
            'icon' => 'server-stack',
        ],
        [
            'title' => __('Sites'),
            'description' => __('Manage deployed applications, environments, and day-to-day runtime workflows.'),
            'href' => route('sites.index'),
            'icon' => 'globe-alt',
        ],
        ...(\Laravel\Pennant\Feature::active('surface.projects') ? [[
            'title' => __('Projects'),
            'description' => __('Track workspaces and organize app delivery across your infrastructure footprint.'),
            'href' => route('projects.index'),
            'icon' => 'rectangle-stack',
        ]] : []),
        [
            'title' => __('Organizations'),
            'description' => __('Review teams, limits, and the operational context behind your current workspace.'),
            'href' => route('organizations.index'),
            'icon' => 'building-office-2',
        ],
    ];

    $quickActions = [
        [
            'title' => __('Provider credentials'),
            'description' => __('Connect DigitalOcean, Hetzner, and other providers before provisioning infrastructure.'),
            'href' => route('credentials.index'),
            'icon' => 'key',
            'tone' => 'sage',
        ],
        [
            'title' => __('Security settings'),
            'description' => __('Review two-factor, profile security, and access controls for your account.'),
            'href' => route('profile.security'),
            'icon' => 'shield-check',
            'tone' => 'amber',
        ],
        [
            'title' => __('API keys'),
            'description' => __('Issue organization-scoped API tokens with only the permissions you need.'),
            'href' => route('profile.api-keys'),
            'icon' => 'bolt',
            'tone' => 'violet',
        ],
        [
            'title' => __('Setup guide'),
            'description' => __('Follow the guided checklist for connecting a provider and launching your first server.'),
            'href' => route('docs.connect-provider'),
            'icon' => 'book-open',
            'tone' => 'sky',
        ],
    ];

    $primaryHref = multi_surface_active() ? route('launches.create') : route('servers.create');
    $primaryLabel = multi_surface_active() ? __('Open launchpad') : __('Add a server');
@endphp

<div>
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
        @if ($fleetAlert !== null)
            <div class="mb-6 overflow-hidden rounded-2xl border border-rose-200 bg-rose-50 shadow-sm" role="alert">
                <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-rose-100 text-rose-700 ring-1 ring-rose-200">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Attention') }}</p>
                            <p class="mt-0.5 text-sm font-semibold text-rose-900">{{ __('Fleet needs attention') }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-rose-800">
                                @if ($fleetAlert['failed_latest'] > 0)
                                    {{ trans_choice('{1} 1 site with a failed latest deploy.|[2,*] :count sites with a failed latest deploy.', $fleetAlert['failed_latest'], ['count' => $fleetAlert['failed_latest']]) }}
                                @endif
                                @if ($fleetAlert['long_running'] > 0)
                                    {{ trans_choice('{1} 1 deploy running over 15 minutes.|[2,*] :count deploys running over 15 minutes.', $fleetAlert['long_running'], ['count' => $fleetAlert['long_running']]) }}
                                @endif
                                @if ($fleetAlert['drift_servers'] > 0)
                                    {{ trans_choice('{1} 1 server with engine drift.|[2,*] :count servers with engine drift.', $fleetAlert['drift_servers'], ['count' => $fleetAlert['drift_servers']]) }}
                                @endif
                            </p>
                        </div>
                    </div>
                    @feature('surface.fleet')
                        <a href="{{ route('fleet.health') }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 self-start whitespace-nowrap rounded-xl bg-rose-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-800 sm:self-auto">
                            {{ __('View fleet health') }}
                            <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        </a>
                    @endfeature
                </div>
            </div>
        @endif

        {{-- Hero: greeting + at-a-glance fleet counts. --}}
        <section class="dply-card overflow-hidden">
            <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                <div class="lg:col-span-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-squares-2x2 class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Workspace') }}</p>
                            <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">
                                {{ __('Welcome back, :name', ['name' => $displayName]) }}
                            </h2>
                            <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Run infrastructure, track fleet health, and move from provider setup to production delivery for :organization.', ['organization' => $organizationName]) }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-outline-link href="{{ route('credentials.index') }}" wire:navigate>
                            <x-heroicon-o-key class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Provider credentials') }}
                        </x-outline-link>
                        <x-docs-link doc-route="docs.connect-provider">
                            <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Setup guide') }}
                        </x-docs-link>
                        <a
                            href="{{ $primaryHref }}"
                            wire:navigate
                            class="inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ $primaryLabel }}
                        </a>
                    </div>
                </div>
                <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-brand-sage/30 bg-brand-sage/8' => $serverCount > 0,
                        'border-brand-ink/10 bg-white' => $serverCount === 0,
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Servers') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $serverCount }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('in scope|in scope', $serverCount) }}</span>
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Across your org') }}</p>
                    </div>
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-amber-200 bg-amber-50/60' => $openFindings > 0,
                        'border-brand-ink/10 bg-white' => $openFindings === 0,
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Open findings') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $openFindings }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('issue|issues', $openFindings) }}</span>
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Need triage') }}</p>
                    </div>
                    <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Health') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $avgHealthScore !== null ? (int) $avgHealthScore : '—' }}</span>
                            @if ($avgHealthScore !== null)
                                <span class="text-[11px] text-brand-moss">/ 100</span>
                            @endif
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ $avgHealthScore !== null ? __('Higher is better') : __('Pending insights') }}</p>
                    </div>
                </dl>
            </div>
        </section>

        <div class="mt-6 space-y-6">
            @unless ($hasProviderCredentials)
                {{-- Provider connect: section card with amber tone. --}}
                <section class="dply-card overflow-hidden border-amber-200">
                    <div class="border-b border-brand-ink/10 bg-amber-50/70 px-6 py-5 sm:px-7">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                                    <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add provider credentials before you provision') }}</h3>
                                    <p class="mt-1 max-w-xl text-sm leading-relaxed text-brand-moss">{{ __('Connect a supported infrastructure provider so this workspace can launch and manage real servers instead of stopping at setup.') }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2 sm:items-center">
                                <a
                                    href="{{ route('credentials.index') }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                                >
                                    <x-heroicon-m-key class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Connect provider') }}
                                </a>
                                <a
                                    href="{{ route('docs.connect-provider') }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-m-document-text class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Setup guide') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            @endunless

            {{-- Platform surfaces + Quick actions side-by-side. --}}
            <div class="grid gap-6 xl:grid-cols-[1.7fr_1fr]">
                <section class="dply-card overflow-hidden">
                    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sand'] }}">
                                <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Platform') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Operate from one place') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Jump straight into the surface you need — every workspace lives next to the next.') }}</p>
                            </div>
                            @feature('surface.marketplace')
                                <a
                                    href="{{ route('marketplace.index') }}"
                                    wire:navigate
                                    class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-m-rectangle-stack class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Marketplace') }}
                                </a>
                            @endfeature
                        </div>
                    </div>
                    <div class="p-6 sm:p-7">
                        <div class="grid gap-3 md:grid-cols-2">
                            @foreach ($platformSurfaces as $surface)
                                <a
                                    href="{{ $surface['href'] }}"
                                    wire:navigate
                                    class="group flex items-start gap-3 rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-sage/30 hover:shadow-md"
                                >
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                                        @switch($surface['icon'])
                                            @case('server-stack')
                                                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                                                @break
                                            @case('globe-alt')
                                                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                                                @break
                                            @case('rectangle-stack')
                                                <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                                                @break
                                            @case('building-office-2')
                                                <x-heroicon-o-building-office-2 class="h-5 w-5" aria-hidden="true" />
                                                @break
                                        @endswitch
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-sm font-semibold text-brand-ink">{{ $surface['title'] }}</span>
                                            <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 text-brand-mist transition group-hover:text-brand-sage" aria-hidden="true" />
                                        </div>
                                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $surface['description'] }}</p>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="dply-card overflow-hidden">
                    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                                <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Shortcuts') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Keep the workspace ready') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Setup tasks that unblock provisioning, access, and team ops.') }}</p>
                            </div>
                        </div>
                    </div>
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($quickActions as $action)
                            <li>
                                <a
                                    href="{{ $action['href'] }}"
                                    wire:navigate
                                    class="group flex items-center justify-between gap-3 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7"
                                >
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette[$action['tone']] }}">
                                            @switch($action['icon'])
                                                @case('key')
                                                    <x-heroicon-o-key class="h-4 w-4" aria-hidden="true" />
                                                    @break
                                                @case('shield-check')
                                                    <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                                                    @break
                                                @case('bolt')
                                                    <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                                                    @break
                                                @case('book-open')
                                                    <x-heroicon-o-book-open class="h-4 w-4" aria-hidden="true" />
                                                    @break
                                            @endswitch
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-brand-ink">{{ $action['title'] }}</p>
                                            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $action['description'] }}</p>
                                        </div>
                                    </div>
                                    <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 text-brand-mist transition group-hover:text-brand-sage" aria-hidden="true" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>

            {{-- Fleet insights + Recent servers. Insights is gated behind
                 `workspace.insights`; when off, Recent servers occupies the
                 full row instead of leaving an empty column. --}}
            @php $hasWorkspaceInsights = \Laravel\Pennant\Feature::active('workspace.insights'); @endphp
            <div @class([
                'grid gap-6',
                'lg:grid-cols-[1.35fr_0.95fr]' => $hasWorkspaceInsights,
            ])>
                @if ($hasWorkspaceInsights)
                <section class="dply-card overflow-hidden">
                    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['violet'] }}">
                                <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Insights') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('What needs attention first') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Severity rollup across your fleet plus the noisiest servers.') }}</p>
                            </div>
                            <a
                                href="{{ route('servers.index') }}"
                                wire:navigate
                                class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-m-server-stack class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Open servers') }}
                            </a>
                        </div>
                    </div>

                    @if ($fleetInsights && ($openFindings > 0 || $avgHealthScore !== null))
                        <div class="grid gap-2 px-6 py-5 sm:grid-cols-3 sm:px-7">
                            <div @class([
                                'rounded-2xl border px-4 py-3 shadow-sm',
                                'border-red-200 bg-red-50/80' => $fleetInsights['open_by_severity']['critical'] > 0,
                                'border-brand-ink/10 bg-white' => $fleetInsights['open_by_severity']['critical'] === 0,
                            ])>
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Critical') }}</dt>
                                <dd class="mt-1 flex items-baseline gap-1.5">
                                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $fleetInsights['open_by_severity']['critical'] }}</span>
                                    <span class="text-[11px] text-brand-moss">{{ trans_choice('open|open', $fleetInsights['open_by_severity']['critical']) }}</span>
                                </dd>
                            </div>
                            <div @class([
                                'rounded-2xl border px-4 py-3 shadow-sm',
                                'border-amber-200 bg-amber-50/70' => $fleetInsights['open_by_severity']['warning'] > 0,
                                'border-brand-ink/10 bg-white' => $fleetInsights['open_by_severity']['warning'] === 0,
                            ])>
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Warning') }}</dt>
                                <dd class="mt-1 flex items-baseline gap-1.5">
                                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $fleetInsights['open_by_severity']['warning'] }}</span>
                                    <span class="text-[11px] text-brand-moss">{{ trans_choice('open|open', $fleetInsights['open_by_severity']['warning']) }}</span>
                                </dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Info') }}</dt>
                                <dd class="mt-1 flex items-baseline gap-1.5">
                                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $fleetInsights['open_by_severity']['info'] }}</span>
                                    <span class="text-[11px] text-brand-moss">{{ trans_choice('open|open', $fleetInsights['open_by_severity']['info']) }}</span>
                                </dd>
                            </div>
                        </div>

                        @if (! empty($fleetInsights['worst_servers']))
                            <ul class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
                                @foreach ($fleetInsights['worst_servers'] as $row)
                                    <li>
                                        <a
                                            href="{{ route('servers.insights', $row['id']) }}"
                                            wire:navigate
                                            class="flex items-center justify-between gap-4 px-6 py-3 transition-colors hover:bg-brand-sand/15 sm:px-7"
                                        >
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-semibold text-brand-ink">{{ $row['name'] }}</p>
                                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-moss">
                                                    <span class="inline-flex items-center gap-1">
                                                        <span class="font-mono tabular-nums text-brand-ink">{{ $row['open'] }}</span>
                                                        {{ trans_choice('open finding|open findings', $row['open']) }}
                                                    </span>
                                                    @if ($row['worst'])
                                                        <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                                        <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $row['worst'] }}</span>
                                                    @endif
                                                </p>
                                            </div>
                                            <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-sage">
                                                {{ __('Review') }}
                                                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @else
                        <div class="px-6 py-12 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-sparkles class="h-6 w-6" aria-hidden="true" />
                            </span>
                            <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('A clean slate for new infrastructure') }}</p>
                            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                                {{ __('Connect provider credentials, choose a launch path, and insights will start surfacing here as your infrastructure grows.') }}
                            </p>
                            <div class="mt-5 inline-flex flex-wrap items-center gap-2">
                                <a
                                    href="{{ route('credentials.index') }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-m-key class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Connect providers') }}
                                </a>
                                <x-docs-link doc-route="docs.connect-provider">
                                    <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                    {{ __('Read the guide') }}
                                </x-docs-link>
                            </div>
                        </div>
                    @endif
                </section>
                @endif

                <section class="dply-card overflow-hidden">
                    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Activity') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent servers') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('The five most recently added boxes in your workspace.') }}</p>
                            </div>
                            @if ($serverCount > 0)
                                <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $serverCount }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($servers->isEmpty())
                        <div class="px-6 py-12 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-server-stack class="h-6 w-6" aria-hidden="true" />
                            </span>
                            <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No servers yet') }}</p>
                            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                                @if (multi_surface_active())
                                    {{ __('Start with the launchpad — BYO, Docker, serverless, Kubernetes, edge, or cloud network.') }}
                                @else
                                    {{ __('Spin up your first server — bring your own host or provision a VM from a connected cloud provider.') }}
                                @endif
                            </p>
                            <a
                                href="{{ $primaryHref }}"
                                wire:navigate
                                class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ $primaryLabel }}
                            </a>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($servers as $server)
                                @php
                                    $status = (string) ($server->status ?? '');
                                    $statusTone = match (true) {
                                        in_array($status, ['ready', 'running', 'active'], true) => 'border-brand-sage/30 bg-brand-sage/15 text-brand-forest',
                                        in_array($status, ['provisioning', 'pending', 'queued'], true) => 'border-sky-200 bg-sky-50 text-sky-700',
                                        in_array($status, ['failed', 'error'], true) => 'border-red-200 bg-red-50 text-red-700',
                                        default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
                                    };
                                @endphp
                                <li wire:key="server-{{ $server->id }}">
                                    <a
                                        href="{{ route('servers.show', $server) }}"
                                        wire:navigate
                                        class="flex items-center justify-between gap-4 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                                <span class="truncate text-sm font-semibold text-brand-ink">{{ $server->name }}</span>
                                                @if ($status !== '')
                                                    <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusTone }}">{{ str_replace('_', ' ', $status) }}</span>
                                                @endif
                                            </div>
                                            @if ($server->ip_address)
                                                <p class="mt-0.5 font-mono text-[11px] text-brand-mist">{{ $server->ip_address }}</p>
                                            @endif
                                        </div>
                                        <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-sage">
                                            {{ __('Manage') }}
                                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 text-right sm:px-7">
                            <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                {{ __('View all servers') }}
                                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                            </a>
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </div>
</div>
