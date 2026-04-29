@php
    $user = auth()->user();
    $displayName = filled($user->name ?? null) ? $user->name : __('there');
    $organizationName = $organization?->name ?? __('Your organization');
    $openFindings = (int) ($fleetInsights['total_open'] ?? 0);
    $avgHealthScore = $fleetInsights['avg_health_score'] ?? null;
    $platformSurfaces = [
        [
            'title' => __('Servers'),
            'description' => __('Provision infrastructure, review fleet health, and keep your estate ready to ship.'),
            'href' => route('servers.index'),
            'cta' => __('Open servers'),
        ],
        [
            'title' => __('Sites'),
            'description' => __('Manage deployed applications, environments, and day-to-day runtime workflows.'),
            'href' => route('sites.index'),
            'cta' => __('Open sites'),
        ],
        [
            'title' => __('Projects'),
            'description' => __('Track workspaces and organize app delivery across your infrastructure footprint.'),
            'href' => route('projects.index'),
            'cta' => __('Open projects'),
        ],
        [
            'title' => __('Organizations'),
            'description' => __('Review teams, limits, and the operational context behind your current workspace.'),
            'href' => route('organizations.index'),
            'cta' => __('Open organizations'),
        ],
    ];
    $quickActions = [
        [
            'title' => __('Provider credentials'),
            'description' => __('Connect DigitalOcean, Hetzner, and other providers before provisioning infrastructure.'),
            'href' => route('credentials.index'),
            'accent' => 'from-[#3d7a8f]/90 to-brand-sage',
        ],
        [
            'title' => __('Security settings'),
            'description' => __('Review two-factor, profile security, and access controls for your account.'),
            'href' => route('profile.security'),
            'accent' => 'from-brand-rust/90 to-brand-copper',
        ],
        [
            'title' => __('API keys'),
            'description' => __('Issue organization-scoped API tokens with only the permissions you need.'),
            'href' => route('profile.api-keys'),
            'accent' => 'from-brand-olive to-brand-forest',
        ],
        [
            'title' => __('Setup guide'),
            'description' => __('Follow the guided checklist for connecting a provider and launching your first server.'),
            'href' => route('docs.connect-provider'),
            'accent' => 'from-brand-gold to-[#b8922e]',
        ],
    ];
    $surfaceAccents = [
        ['border' => 'border-cyan-700/15', 'bar' => 'bg-cyan-600', 'glow' => 'from-cyan-500/10'],
        ['border' => 'border-brand-sage/25', 'bar' => 'bg-brand-sage', 'glow' => 'from-brand-sage/15'],
        ['border' => 'border-brand-gold/30', 'bar' => 'bg-brand-gold', 'glow' => 'from-brand-gold/15'],
        ['border' => 'border-brand-copper/25', 'bar' => 'bg-brand-copper', 'glow' => 'from-brand-rust/12'],
    ];
@endphp

<div class="relative py-8 sm:py-10 lg:py-12">
    {{-- Atmospheric wash behind dashboard content --}}
    <div class="pointer-events-none absolute inset-x-0 top-0 h-[min(42rem,65vh)] bg-[radial-gradient(ellipse_85%_70%_at_50%_-10%,rgb(104_132_121/0.22),transparent_55%),radial-gradient(ellipse_60%_45%_at_100%_15%,rgb(205_169_66/0.14),transparent_50%),radial-gradient(ellipse_50%_40%_at_0%_30%,rgb(50_72_44/0.12),transparent_45%)]" aria-hidden="true"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <section class="relative overflow-hidden rounded-[2rem] border border-brand-ink/10 bg-brand-ink text-brand-cream shadow-xl shadow-brand-ink/15 ring-1 ring-white/10">
            <div class="absolute inset-0 bg-mesh-brand opacity-95"></div>
            <div class="absolute -left-24 top-1/2 h-72 w-72 -translate-y-1/2 rounded-full bg-brand-sage/25 blur-3xl"></div>
            <div class="absolute -right-16 top-0 h-56 w-56 rounded-full bg-brand-gold/20 blur-3xl"></div>
            <div class="absolute inset-y-0 right-0 w-1/2 bg-gradient-to-l from-brand-gold/[0.22] via-brand-sage/[0.08] to-transparent"></div>
            <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-brand-gold/35 to-transparent"></div>
            <div class="relative px-6 py-8 sm:px-8 sm:py-10 lg:px-10 lg:py-12">
                <div class="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="inline-flex items-center rounded-full border border-white/15 bg-white/8 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-brand-sand">
                                {{ __('Workspace command deck') }}
                            </span>
                            <span class="inline-flex items-center rounded-full border border-emerald-300/20 bg-emerald-400/10 px-3 py-1 text-xs font-medium text-emerald-100">
                                {{ __('Signed in') }}
                            </span>
                            <span class="inline-flex items-center rounded-full border border-white/15 bg-white/8 px-3 py-1 text-xs font-medium text-brand-cream/85">
                                {{ $organizationName }}
                            </span>
                        </div>

                        <h1 class="mt-6 text-3xl font-semibold tracking-tight text-white sm:text-4xl lg:text-[2.9rem] lg:leading-[1.05]">
                            {{ __('Welcome back, :name.', ['name' => $displayName]) }}
                        </h1>
                        <p class="mt-4 max-w-2xl text-base leading-7 text-brand-cream/78 sm:text-lg">
                            {{ __('Run infrastructure, track fleet health, and move from provider setup to production delivery from one premium workspace for :organization.', ['organization' => $organizationName]) }}
                        </p>

                        <div class="mt-8 flex flex-wrap gap-3">
                            <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-brand-gold px-5 py-3 text-sm font-semibold text-brand-ink shadow-lg shadow-brand-gold/20 transition hover:bg-[#d4b24d]">
                                {{ __('Open launchpad') }}
                            </a>
                            <a href="{{ route('credentials.index') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-white/15 bg-white/8 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/12">
                                {{ __('Provider credentials') }}
                            </a>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3 lg:w-[28rem]">
                        <div class="rounded-2xl border border-emerald-400/25 bg-gradient-to-br from-emerald-500/20 to-white/[0.06] p-4 shadow-inner shadow-emerald-900/20 backdrop-blur-sm ring-1 ring-white/10">
                            <p class="text-xs font-medium uppercase tracking-wide text-emerald-100/90">{{ __('Servers in scope') }}</p>
                            <p class="mt-3 text-3xl font-semibold tabular-nums text-white">{{ $serverCount }}</p>
                            <p class="mt-1 text-sm text-brand-cream/75">{{ $serverCount === 1 ? __('1 server') : __(':count servers', ['count' => $serverCount]) }}</p>
                        </div>
                        <div class="rounded-2xl border border-amber-400/30 bg-gradient-to-br from-amber-500/18 to-white/[0.05] p-4 shadow-inner shadow-amber-950/25 backdrop-blur-sm ring-1 ring-white/10">
                            <p class="text-xs font-medium uppercase tracking-wide text-amber-100/90">{{ __('Open findings') }}</p>
                            <p class="mt-3 text-3xl font-semibold tabular-nums text-white">{{ $openFindings }}</p>
                            <p class="mt-1 text-sm text-brand-cream/75">{{ __('Across your organization') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-gold/35 bg-gradient-to-br from-brand-gold/25 to-brand-sage/15 p-4 shadow-inner shadow-brand-ink/30 backdrop-blur-sm ring-1 ring-white/10">
                            <p class="text-xs font-medium uppercase tracking-wide text-brand-sand">{{ __('Avg health score') }}</p>
                            <p class="mt-3 text-3xl font-semibold tabular-nums text-white">{{ $avgHealthScore !== null ? (int) $avgHealthScore : '—' }}</p>
                            <p class="mt-1 text-sm text-brand-cream/75">{{ $avgHealthScore !== null ? __('0–100, higher is better') : __('Available when insights are ready') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        @unless ($hasProviderCredentials)
            <section class="mt-6 rounded-[1.75rem] border border-amber-200 bg-amber-50/90 p-5 shadow-sm shadow-amber-100/40 sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-800">{{ __('Set up a provider') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-amber-950">{{ __('Add provider credentials before you provision infrastructure.') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-amber-900/80">
                            {{ __('Connect a supported infrastructure provider so this workspace can launch and manage real servers instead of stopping at setup.') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('credentials.index') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-amber-950 transition hover:bg-amber-400">
                            {{ __('Provider credentials') }}
                        </a>
                        <a href="{{ route('docs.connect-provider') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-amber-300 bg-white px-4 py-2.5 text-sm font-semibold text-amber-900 transition hover:bg-amber-50">
                            {{ __('Setup guide') }}
                        </a>
                    </div>
                </div>
            </section>
        @endunless

        <div class="mt-8 grid gap-6 xl:grid-cols-[1.7fr_1fr]">
            <section class="rounded-[1.75rem] border border-brand-ink/10 bg-gradient-to-br from-white via-white to-brand-sand/40 p-6 shadow-md shadow-brand-ink/[0.06] ring-1 ring-brand-sage/10 sm:p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Platform surfaces') }}</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Everything you need to operate from one place') }}</h2>
                    </div>
                    <a href="{{ route('marketplace.index') }}" wire:navigate class="inline-flex items-center gap-1 rounded-full bg-brand-sage/10 px-3 py-1.5 text-sm font-semibold text-brand-forest transition hover:bg-brand-sage/20">
                        {{ __('Browse marketplace') }} →
                    </a>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    @foreach ($platformSurfaces as $surface)
                        @php $acc = $surfaceAccents[$loop->index % count($surfaceAccents)]; @endphp
                        <a href="{{ $surface['href'] }}" wire:navigate class="group relative overflow-hidden rounded-2xl border {{ $acc['border'] }} bg-gradient-to-br from-white to-brand-cream/90 p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-sage/35 hover:shadow-lg hover:shadow-brand-sage/10">
                            <div class="pointer-events-none absolute -right-8 -top-10 h-28 w-28 rounded-full bg-gradient-to-br {{ $acc['glow'] }} to-transparent blur-2xl"></div>
                            <span class="absolute left-0 top-3 bottom-3 w-1 rounded-full {{ $acc['bar'] }} opacity-90"></span>
                            <div class="relative flex items-start justify-between gap-3 ps-2">
                                <div>
                                    <h3 class="text-lg font-semibold text-brand-ink">{{ $surface['title'] }}</h3>
                                    <p class="mt-2 text-sm leading-6 text-brand-moss">{{ $surface['description'] }}</p>
                                </div>
                                <span class="rounded-full border border-brand-ink/10 bg-white/90 px-2.5 py-1 text-xs font-medium text-brand-moss shadow-sm">
                                    {{ __('Open') }}
                                </span>
                            </div>
                            <p class="relative mt-5 ps-2 text-sm font-semibold text-brand-sage transition group-hover:text-brand-forest">{{ $surface['cta'] }} →</p>
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="rounded-[1.75rem] border border-brand-ink/10 bg-gradient-to-b from-white to-brand-sand/35 p-6 shadow-md shadow-brand-ink/[0.06] ring-1 ring-brand-gold/15 sm:p-7">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Quick actions') }}</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Keep the workspace ready') }}</h2>
                <p class="mt-3 text-sm leading-6 text-brand-moss">
                    {{ __('Handle the setup tasks that unblock provisioning, access, and team operations without leaving the dashboard.') }}
                </p>

                <div class="mt-6 space-y-3">
                    @foreach ($quickActions as $action)
                        <a href="{{ $action['href'] }}" wire:navigate class="group flex items-start gap-4 rounded-2xl border border-brand-ink/10 bg-white/80 px-4 py-4 shadow-sm transition hover:border-brand-gold/25 hover:bg-white hover:shadow-md">
                            <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br {{ $action['accent'] }} text-white shadow-md ring-1 ring-white/20">
                                <x-heroicon-o-arrow-up-right class="h-5 w-5 opacity-95" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-brand-ink">{{ $action['title'] }}</span>
                                <span class="mt-1 block text-sm leading-6 text-brand-moss">{{ $action['description'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[1.35fr_0.95fr]">
            <section class="rounded-[1.75rem] border border-brand-ink/10 bg-gradient-to-br from-white via-brand-cream/50 to-brand-sage/[0.08] p-6 shadow-md shadow-brand-ink/[0.05] ring-1 ring-brand-sage/10 sm:p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Fleet insights') }}</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-brand-ink">{{ __('See what needs attention first') }}</h2>
                    </div>
                    <a href="{{ route('servers.index') }}" wire:navigate class="text-sm font-semibold text-brand-sage transition hover:text-brand-forest">
                        {{ __('Open servers list') }} →
                    </a>
                </div>

                @if ($fleetInsights && ($openFindings > 0 || $avgHealthScore !== null))
                    <div class="mt-6 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-red-200 bg-red-50/90 px-4 py-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-red-800">{{ __('Critical') }}</p>
                            <p class="mt-2 text-2xl font-semibold text-red-950">{{ $fleetInsights['open_by_severity']['critical'] }}</p>
                        </div>
                        <div class="rounded-2xl border border-amber-200 bg-amber-50/90 px-4 py-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">{{ __('Warning') }}</p>
                            <p class="mt-2 text-2xl font-semibold text-amber-950">{{ $fleetInsights['open_by_severity']['warning'] }}</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ __('Info') }}</p>
                            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $fleetInsights['open_by_severity']['info'] }}</p>
                        </div>
                    </div>

                    @if (! empty($fleetInsights['worst_servers']))
                        <div class="mt-6 space-y-3">
                            @foreach ($fleetInsights['worst_servers'] as $row)
                                <a href="{{ route('servers.insights', $row['id']) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand-ink/10 bg-brand-cream/60 px-4 py-4 transition hover:border-brand-sage/30 hover:bg-white">
                                    <span>
                                        <span class="block text-sm font-semibold text-brand-ink">{{ $row['name'] }}</span>
                                        <span class="mt-1 block text-sm text-brand-moss">
                                            {{ trans_choice(':count open|:count open', $row['open'], ['count' => $row['open']]) }}
                                            @if ($row['worst'])
                                                <span class="text-brand-mist">·</span>
                                                <span class="uppercase tracking-wide text-brand-ink">{{ $row['worst'] }}</span>
                                            @endif
                                        </span>
                                    </span>
                                    <span class="text-sm font-semibold text-brand-sage">{{ __('Review') }} →</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @else
                    <div class="mt-6 rounded-[1.5rem] border border-dashed border-brand-ink/15 bg-brand-cream/50 p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('A clean slate for new infrastructure') }}</h3>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-brand-moss">
                            {{ __('Connect provider credentials, choose a launch path, and insights will start surfacing here as your infrastructure grows.') }}
                        </p>
                        <div class="mt-5 flex flex-wrap gap-3">
                            <a href="{{ route('credentials.index') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink transition hover:bg-brand-cream">
                                {{ __('Connect providers') }}
                            </a>
                            <a href="{{ route('docs.connect-provider') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl text-sm font-semibold text-brand-sage transition hover:text-brand-forest">
                                {{ __('Read the guide') }} →
                            </a>
                        </div>
                    </div>
                @endif
            </section>

            <section class="rounded-[1.75rem] border border-brand-ink/10 bg-gradient-to-br from-white to-brand-sand/30 p-6 shadow-md shadow-brand-ink/[0.05] ring-1 ring-brand-ink/5 sm:p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Your servers') }}</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Recent infrastructure activity') }}</h2>
                    </div>
                    @if ($serverCount > 0)
                        <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-cream/70 px-3 py-1 text-xs font-medium text-brand-moss">
                            {{ $serverCount === 1 ? __('1 server') : __(':count servers', ['count' => $serverCount]) }}
                        </span>
                    @endif
                </div>

                @if ($servers->isEmpty())
                    <div class="mt-6 rounded-[1.5rem] border border-brand-ink/10 bg-brand-cream/65 p-6">
                        <p class="text-base font-medium text-brand-ink">{{ __('No servers yet. Choose a launch path to get started.') }}</p>
                        <p class="mt-2 text-sm leading-6 text-brand-moss">
                            {{ __('Start with the launchpad, then continue into BYO, local Docker, remote Docker, serverless, Kubernetes, edge, or cloud network setup as the workspace grows.') }}
                        </p>
                        <div class="mt-5 flex flex-wrap gap-3 text-sm">
                            <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-4 py-2.5 font-semibold text-brand-cream transition hover:bg-brand-forest">
                                {{ __('Open launchpad') }}
                            </a>
                            <a href="{{ route('docs.connect-provider') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 font-semibold text-brand-ink transition hover:bg-brand-cream">
                                {{ __('New? Read the guide') }}
                            </a>
                        </div>
                        <p class="mt-4 text-sm text-brand-moss">
                            {{ __('Connect DigitalOcean or Hetzner first') }}
                        </p>
                    </div>
                @else
                    <div class="mt-6 space-y-3">
                        @foreach ($servers as $server)
                            <a href="{{ route('servers.show', $server) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand-ink/10 bg-brand-cream/60 px-4 py-4 transition hover:border-brand-sage/30 hover:bg-white">
                                <span>
                                    <span class="block text-sm font-semibold text-brand-ink">{{ $server->name }}</span>
                                    <span class="mt-1 block text-sm text-brand-moss">
                                        {{ $server->ip_address ?? $server->status }}
                                        @if ($server->status)
                                            <span class="text-brand-mist">·</span>
                                            <span class="capitalize">{{ str_replace('_', ' ', $server->status) }}</span>
                                        @endif
                                    </span>
                                </span>
                                <span class="text-sm font-semibold text-brand-sage">{{ __('Manage') }} →</span>
                            </a>
                        @endforeach
                    </div>

                    <a href="{{ route('servers.index') }}" wire:navigate class="mt-5 inline-flex items-center text-sm font-semibold text-brand-sage transition hover:text-brand-forest">
                        {{ __('View all servers') }} →
                    </a>
                @endif
            </section>
        </div>
    </div>
</div>
