@php
    $user = auth()->user();
    $displayName = filled($user->name ?? null) ? $user->name : __('there');
    $organizationName = $organization?->name ?? __('Your organization');
    $openFindings = (int) ($orgInsights['total_open'] ?? 0);
    $avgHealthScore = $orgInsights['avg_health_score'] ?? null;

    // Card click-through targets. Servers go to the servers list; findings and
    // health land on the org-wide Infrastructure health view.
    $serversCardHref = route('servers.index');
    $insightsCardHref = route('infrastructure.health');

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
            'description' => __('Provision infrastructure, review health, and keep your estate ready to ship.'),
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
            'title' => __('Credentials'),
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
    $hasWorkspaceInsights = \Laravel\Pennant\Feature::active('workspace.insights');
    $shellDescription = __('Run infrastructure, track health, and move from provider setup to production delivery for :organization.', ['organization' => $organizationName]);
    // Sized for the dense one-line panel head, and matching the Marketplace /
    // Open servers buttons further down so every control on the page is one size.
    $headerBtn = 'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm transition-colors';

    $serversStatClass = $serverCount > 0
        ? 'group relative rounded-xl border border-brand-sage/30 bg-brand-sage/8 px-3 py-2 transition hover:border-brand-sage/50 focus-within:ring-2 focus-within:ring-brand-sage/40'
        : 'group relative rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2 transition hover:border-brand-ink/20 focus-within:ring-2 focus-within:ring-brand-sage/40';
    $findingsStatClass = $openFindings > 0
        ? 'group relative rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-2 transition hover:border-amber-300 focus-within:ring-2 focus-within:ring-brand-sage/40'
        : 'group relative rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2 transition hover:border-brand-ink/20 focus-within:ring-2 focus-within:ring-brand-sage/40';
    $insightsGridClass = $hasWorkspaceInsights
        ? 'grid gap-0 lg:grid-cols-[1.35fr_0.95fr] lg:divide-x lg:divide-brand-ink/10'
        : 'grid gap-0';
@endphp

<div class="contents">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <x-profile-shell
            dense
            :title="__('Welcome back, :name', ['name' => $displayName])"
            :description="$shellDescription"
            icon="heroicon-o-squares-2x2"
        >
            <x-slot:actions>
                <a
                    href="{{ route('credentials.index') }}"
                    wire:navigate
                    class="{{ $headerBtn }} border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Credentials') }}
                </a>
                <button
                    type="button"
                    class="{{ $headerBtn }} border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40"
                    x-on:click="window.dispatchEvent(new CustomEvent('dply-docs-open', { detail: { docRoute: 'docs.connect-provider' } }))"
                >
                    <x-heroicon-o-document-text class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Setup guide') }}
                </button>
                <a
                    href="{{ $primaryHref }}"
                    wire:navigate
                    class="{{ $headerBtn }} bg-brand-ink text-brand-cream shadow-md hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ $primaryLabel }}
                </a>
            </x-slot:actions>

            <x-slot:stats>
                {{-- Dense profile-shell hands the stats slot a bare bordered strip,
                     so the padding lives here and matches the panel head above. --}}
                <dl class="grid grid-cols-1 gap-2 px-3 py-2 sm:grid-cols-3 sm:px-4">
                    <div class="{{ $serversStatClass }}">
                        <a href="{{ $serversCardHref }}" wire:navigate class="absolute inset-0 rounded-xl" aria-label="{{ __('View servers') }}"></a>
                        <dt class="flex items-center justify-between gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <span class="flex min-w-0 items-center gap-1.5">
                                <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate">{{ __('Servers') }}</span>
                            </span>
                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0 text-brand-mist opacity-0 transition group-hover:opacity-100" aria-hidden="true" />
                        </dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $serverCount }}</span>
                            <span class="text-xs text-brand-moss">{{ __('in scope') }}</span>
                        </dd>
                    </div>
                    <div class="{{ $findingsStatClass }}">
                        <a href="{{ $insightsCardHref }}" wire:navigate class="absolute inset-0 rounded-xl" aria-label="{{ __('Review open findings') }}"></a>
                        <dt class="flex items-center justify-between gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <span class="flex min-w-0 items-center gap-1.5">
                                <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate">{{ __('Open findings') }}</span>
                            </span>
                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0 text-brand-mist opacity-0 transition group-hover:opacity-100" aria-hidden="true" />
                        </dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $openFindings }}</span>
                            <span class="text-xs text-brand-moss">{{ trans_choice('issue|issues', $openFindings) }}</span>
                        </dd>
                    </div>
                    <div class="group relative rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2 transition hover:border-brand-ink/20 focus-within:ring-2 focus-within:ring-brand-sage/40">
                        <a href="{{ $insightsCardHref }}" wire:navigate class="absolute inset-0 rounded-xl" aria-label="{{ __('View infrastructure health') }}"></a>
                        <dt class="flex items-center justify-between gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <span class="flex min-w-0 items-center gap-1.5">
                                <x-heroicon-o-heart class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate">{{ __('Health') }}</span>
                            </span>
                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0 text-brand-mist opacity-0 transition group-hover:opacity-100" aria-hidden="true" />
                        </dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $avgHealthScore !== null ? (int) $avgHealthScore : '—' }}</span>
                            @if ($avgHealthScore !== null)
                                <span class="text-xs text-brand-moss">/ 100</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-slot:stats>

            @if ($healthAlert !== null)
                <div class="border-b border-brand-ink/10 bg-rose-50/80 px-3 py-2 sm:px-4" role="alert">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-2">
                            <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
                            <div class="min-w-0 sm:flex sm:flex-wrap sm:items-center sm:gap-x-2">
                                <h2 class="text-sm font-semibold text-rose-900">{{ __('Infrastructure needs attention') }}</h2>
                                <span class="hidden h-4 w-px shrink-0 bg-rose-900/15 sm:block" aria-hidden="true"></span>
                                <p class="text-xs leading-relaxed text-rose-800">
                                    @if ($healthAlert['failed_latest'] > 0)
                                        {{ trans_choice('{1} 1 site with a failed latest deploy.|[2,*] :count sites with a failed latest deploy.', $healthAlert['failed_latest'], ['count' => $healthAlert['failed_latest']]) }}
                                    @endif
                                    @if ($healthAlert['long_running'] > 0)
                                        {{ trans_choice('{1} 1 deploy running over 15 minutes.|[2,*] :count deploys running over 15 minutes.', $healthAlert['long_running'], ['count' => $healthAlert['long_running']]) }}
                                    @endif
                                    @if ($healthAlert['drift_servers'] > 0)
                                        {{ trans_choice('{1} 1 server with engine drift.|[2,*] :count servers with engine drift.', $healthAlert['drift_servers'], ['count' => $healthAlert['drift_servers']]) }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('infrastructure.health') }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 self-start whitespace-nowrap rounded-lg bg-rose-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-800 sm:self-auto">
                            {{ __('View infrastructure health') }}
                            <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        </a>
                    </div>
                </div>
            @endif

            @unless ($hasProviderCredentials)
                <div class="border-b border-brand-ink/10 bg-amber-50/60 px-3 py-2 sm:px-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-2">
                            <x-heroicon-o-shield-exclamation class="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                            <div class="min-w-0 sm:flex sm:flex-wrap sm:items-center sm:gap-x-2">
                                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Add provider credentials before you provision') }}</h2>
                                <span class="hidden h-4 w-px shrink-0 bg-amber-900/15 sm:block" aria-hidden="true"></span>
                                <p class="text-xs leading-relaxed text-brand-moss">{{ __('Connect a supported infrastructure provider so this workspace can launch and manage real servers instead of stopping at setup.') }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-1.5 sm:items-center">
                            <a
                                href="{{ route('credentials.index') }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                            >
                                <x-heroicon-m-key class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Connect provider') }}
                            </a>
                            <a
                                href="{{ route('docs.connect-provider') }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-m-document-text class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Setup guide') }}
                            </a>
                        </div>
                    </div>
                </div>
            @endunless

            {{-- Platform surfaces + Quick actions --}}
            <div class="grid gap-0 border-b border-brand-ink/10 xl:grid-cols-[1.7fr_1fr] xl:divide-x xl:divide-brand-ink/10">
                <section class="min-w-0" aria-labelledby="dashboard-platform-heading">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-rectangle-stack"
                        title-id="dashboard-platform-heading"
                        :title="__('Platform')"
                        :note="__('Jump straight into the surface you need — every workspace lives next to the next.')"
                    >
                        @feature('surface.marketplace')
                            <x-slot:actions>
                                <a
                                    href="{{ route('marketplace.index') }}"
                                    wire:navigate
                                    class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-m-rectangle-stack class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Marketplace') }}
                                </a>
                            </x-slot:actions>
                        @endfeature
                    </x-workspace-panel-head>
                    <div class="grid gap-2 px-3 py-3 sm:grid-cols-2 sm:px-4">
                        @foreach ($platformSurfaces as $surface)
                            <a
                                href="{{ $surface['href'] }}"
                                wire:navigate
                                class="group flex items-start gap-2.5 rounded-xl border border-brand-ink/10 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-sage/30 hover:shadow-md"
                            >
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $tonePalette['sage'] }}">
                                    @switch($surface['icon'])
                                        @case('server-stack')
                                            <x-heroicon-o-server-stack class="h-4 w-4" aria-hidden="true" />
                                            @break
                                        @case('globe-alt')
                                            <x-heroicon-o-globe-alt class="h-4 w-4" aria-hidden="true" />
                                            @break
                                        @case('rectangle-stack')
                                            <x-heroicon-o-rectangle-stack class="h-4 w-4" aria-hidden="true" />
                                            @break
                                        @case('building-office-2')
                                            <x-heroicon-o-building-office-2 class="h-4 w-4" aria-hidden="true" />
                                            @break
                                    @endswitch
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-brand-ink">{{ $surface['title'] }}</span>
                                        <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 text-brand-mist transition group-hover:text-brand-sage" aria-hidden="true" />
                                    </div>
                                    <p class="mt-0.5 text-xs leading-snug text-brand-moss">{{ $surface['description'] }}</p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>

                <section class="min-w-0" aria-labelledby="dashboard-shortcuts-heading">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-bolt"
                        title-id="dashboard-shortcuts-heading"
                        :title="__('Shortcuts')"
                        :note="__('Setup tasks that unblock provisioning, access, and team ops.')"
                    />
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($quickActions as $action)
                            <li>
                                <a
                                    href="{{ $action['href'] }}"
                                    wire:navigate
                                    class="group flex items-center justify-between gap-3 px-3 py-2.5 transition-colors hover:bg-brand-sand/15 sm:px-4"
                                >
                                    <div class="flex min-w-0 items-start gap-2.5">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $tonePalette[$action['tone']] }}">
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
                                            <p class="mt-0.5 text-xs leading-snug text-brand-moss">{{ $action['description'] }}</p>
                                        </div>
                                    </div>
                                    <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 text-brand-mist transition group-hover:text-brand-sage" aria-hidden="true" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>

            {{-- Insights rollup + Recent servers --}}
            <div class="{{ $insightsGridClass }}">
                @if ($hasWorkspaceInsights)
                    <section class="min-w-0 border-b border-brand-ink/10 lg:border-b-0" aria-labelledby="dashboard-insights-heading">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-chart-bar"
                            title-id="dashboard-insights-heading"
                            :title="__('What needs attention first')"
                            :note="__('Severity rollup across every server plus the noisiest ones.')"
                        >
                            <x-slot:actions>
                                <a
                                    href="{{ route('servers.index') }}"
                                    wire:navigate
                                    class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-m-server-stack class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Open servers') }}
                                </a>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        @if ($orgInsights && ($openFindings > 0 || $avgHealthScore !== null))
                            @php
                                $criticalCount = (int) ($orgInsights['open_by_severity']['critical'] ?? 0);
                                $warningCount = (int) ($orgInsights['open_by_severity']['warning'] ?? 0);
                                $infoCount = (int) ($orgInsights['open_by_severity']['info'] ?? 0);
                                $criticalClass = $criticalCount > 0
                                    ? 'rounded-xl border border-red-200 bg-red-50/80 px-3 py-2'
                                    : 'rounded-xl border border-brand-ink/10 bg-white px-3 py-2';
                                $warningClass = $warningCount > 0
                                    ? 'rounded-xl border border-amber-200 bg-amber-50/70 px-3 py-2'
                                    : 'rounded-xl border border-brand-ink/10 bg-white px-3 py-2';
                            @endphp
                            <div class="grid gap-2 px-3 py-3 sm:grid-cols-3 sm:px-4">
                                <div class="{{ $criticalClass }}">
                                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Critical') }}</dt>
                                    <dd class="mt-1 flex items-baseline gap-1.5">
                                        <span class="font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $criticalCount }}</span>
                                        <span class="text-xs text-brand-moss">{{ __('open') }}</span>
                                    </dd>
                                </div>
                                <div class="{{ $warningClass }}">
                                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Warning') }}</dt>
                                    <dd class="mt-1 flex items-baseline gap-1.5">
                                        <span class="font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $warningCount }}</span>
                                        <span class="text-xs text-brand-moss">{{ __('open') }}</span>
                                    </dd>
                                </div>
                                <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Info') }}</dt>
                                    <dd class="mt-1 flex items-baseline gap-1.5">
                                        <span class="font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $infoCount }}</span>
                                        <span class="text-xs text-brand-moss">{{ __('open') }}</span>
                                    </dd>
                                </div>
                            </div>

                            @if (! empty($orgInsights['worst_servers']))
                                <ul class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
                                    @foreach ($orgInsights['worst_servers'] as $row)
                                        <li>
                                            <a
                                                href="{{ route('servers.insights', $row['id']) }}"
                                                wire:navigate
                                                class="flex items-center justify-between gap-4 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4"
                                            >
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate text-sm font-semibold text-brand-ink">{{ $row['name'] }}</p>
                                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-moss">
                                                        <span class="inline-flex items-center gap-1">
                                                            <span class="font-mono tabular-nums text-brand-ink">{{ $row['open'] }}</span>
                                                            {{ trans_choice('open finding|open findings', $row['open']) }}
                                                        </span>
                                                        @if ($row['worst'])
                                                            <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                                            <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ $row['worst'] }}</span>
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
                            <div class="flex flex-col items-center justify-center px-3 py-8 text-center sm:px-4">
                                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                    <x-heroicon-o-sparkles class="h-6 w-6" aria-hidden="true" />
                                </span>
                                <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('A clean slate for new infrastructure') }}</p>
                                <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                                    {{ __('Connect provider credentials, choose a launch path, and insights will start surfacing here as your infrastructure grows.') }}
                                </p>
                                <div class="mt-5 inline-flex flex-wrap items-center justify-center gap-2">
                                    <a
                                        href="{{ route('credentials.index') }}"
                                        wire:navigate
                                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-m-key class="h-4 w-4 shrink-0" aria-hidden="true" />
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

                <section class="min-w-0" aria-labelledby="dashboard-recent-servers-heading">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-server-stack"
                        title-id="dashboard-recent-servers-heading"
                        :title="__('Recent servers')"
                        :note="__('The five most recently added boxes in your workspace.')"
                        :count="$serverCount > 0 ? $serverCount : null"
                    />

                    @if ($servers->isEmpty())
                        <div class="flex flex-col items-center justify-center px-3 py-8 text-center sm:px-4">
                            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
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
                                        class="flex items-center justify-between gap-4 px-3 py-2.5 transition-colors hover:bg-brand-sand/15 sm:px-4"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                                <span class="truncate text-sm font-semibold text-brand-ink">{{ $server->name }}</span>
                                                @if ($status !== '')
                                                    <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $statusTone }}">{{ str_replace('_', ' ', $status) }}</span>
                                                @endif
                                            </div>
                                            {{-- One meta line, not two: host/provider facts and the
                                                 site/age facts wrap together, which costs a row of
                                                 height only when the column is genuinely too narrow. --}}
                                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-mist">
                                                @if ($server->ip_address)
                                                    <span class="font-mono text-brand-moss">{{ $server->ip_address }}</span>
                                                @endif
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-cloud class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                    {{ $server->providerDisplayLabel() }}
                                                </span>
                                                @if ($server->region)
                                                    <span aria-hidden="true" class="text-brand-mist/50">·</span>
                                                    <span>{{ $server->region }}</span>
                                                @endif
                                                @if ($server->size)
                                                    <span aria-hidden="true" class="text-brand-mist/50">·</span>
                                                    <span class="font-mono">{{ $server->size }}</span>
                                                @endif
                                                <span aria-hidden="true" class="text-brand-mist/50">·</span>
                                                <span class="inline-flex items-center gap-1 text-brand-moss">
                                                    <x-heroicon-m-globe-alt class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    {{ $server->sites_count }} {{ trans_choice('site|sites', $server->sites_count) }}
                                                </span>
                                                @if ($server->created_at)
                                                    <span aria-hidden="true" class="text-brand-mist/50">·</span>
                                                    <span class="inline-flex items-center gap-1 text-brand-moss">
                                                        <x-heroicon-m-clock class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                                        {{ __('added :time', ['time' => $server->created_at->diffForHumans()]) }}
                                                    </span>
                                                @endif
                                            </p>
                                        </div>
                                        <span class="inline-flex shrink-0 items-center gap-1 self-start text-xs font-semibold text-brand-sage">
                                            {{ __('Manage') }}
                                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2 text-right sm:px-4">
                            <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                {{ __('View all servers') }}
                                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                            </a>
                        </div>
                    @endif
                </section>
            </div>
        </x-profile-shell>
    </div>
</div>
