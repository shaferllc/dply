@php
    // A server with status=READY but setup_status!=DONE is still being
    // provisioned (the bash script is on the droplet, the UI shouldn't
    // paint it green or claim it's "ready" yet). Treat it as
    // "provisioning" everywhere on this card so the listing reflects
    // reality until the journey hands over.
    $isFullyReady = function (\App\Models\Server $server): bool {
        return $server->status === \App\Models\Server::STATUS_READY
            && $server->setup_status === \App\Models\Server::SETUP_STATUS_DONE;
    };
    $isSetupFailed = function (\App\Models\Server $server): bool {
        return $server->setup_status === \App\Models\Server::SETUP_STATUS_FAILED;
    };
    $displayStatus = function (\App\Models\Server $server) use ($isFullyReady, $isSetupFailed): string {
        if ($isSetupFailed($server)) {
            return __('setup failed');
        }
        if ($server->status === \App\Models\Server::STATUS_READY && ! $isFullyReady($server)) {
            return 'provisioning';
        }

        return (string) $server->status;
    };
    $stripe = function (\App\Models\Server $server) use ($isFullyReady, $isSetupFailed): string {
        if ($server->scheduled_deletion_at) {
            return 'bg-orange-500';
        }
        if ($isSetupFailed($server)) {
            return 'bg-red-500';
        }
        if ($isFullyReady($server)) {
            if ($server->health_status === \App\Models\Server::HEALTH_REACHABLE) {
                return 'bg-emerald-500';
            }
            if ($server->health_status === \App\Models\Server::HEALTH_UNREACHABLE) {
                return 'bg-red-500';
            }

            return 'bg-amber-400';
        }
        if ($server->status === \App\Models\Server::STATUS_ERROR) {
            return 'bg-red-500';
        }
        if (
            $server->status === \App\Models\Server::STATUS_PROVISIONING
            || $server->status === \App\Models\Server::STATUS_PENDING
            || $server->status === \App\Models\Server::STATUS_READY  // setup still in flight
        ) {
            return 'bg-amber-400';
        }

        return 'bg-brand-mist';
    };
    $insightBadgeClass = function (string $serverId) use ($insightRollup): string {
        $worst = $insightRollup[$serverId]['worst'] ?? null;

        return match ($worst) {
            'critical' => 'bg-red-600 text-white',
            'warning' => 'bg-amber-500 text-white',
            'info' => 'bg-slate-500 text-white',
            default => 'bg-brand-ink text-brand-cream',
        };
    };
@endphp

<div
    wire:key="servers-epoch-{{ $serverListEpoch }}"
    @if ($provisioningDigests->isNotEmpty())
        {{-- Refresh the fleet list every 10s while any server is mid-build
             so the per-row step label + elapsed counter tick live. Polling
             is conditional so a fleet of all-ready servers doesn't keep
             hitting the DB for no visual change. The conditional re-rendering
             also auto-stops polling once the last in-flight server finishes
             (provisioningDigests goes empty on the next render). --}}
        wire:poll.10s
    @endif
>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <x-breadcrumb-trail :items="array_values(array_filter([
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            multi_surface_active()
                ? ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group']
                : null,
            ['label' => __('Servers'), 'icon' => 'server-stack'],
        ]))" />

        @if (session('success'))
            <x-alert tone="success">{{ session('success') }}</x-alert>
        @endif

        @if ($failedSetups->isNotEmpty())
            <div class="rounded-2xl border border-red-200 bg-red-50/70 px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 text-sm font-semibold text-red-900">
                            <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
                            {{ trans_choice(
                                '{1} :count server failed to finish setting up.|[2,*] :count servers failed to finish setting up.',
                                $failedSetups->count(),
                                ['count' => $failedSetups->count()],
                            ) }}
                        </p>
                        <ul class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm text-red-800">
                            @foreach ($failedSetups as $failed)
                                <li>
                                    <a href="{{ route('servers.journey', $failed) }}" wire:navigate class="font-medium underline-offset-2 hover:underline">
                                        {{ $failed->name }}
                                    </a>
                                    @if ($failed->ip_address)
                                        <span class="text-red-700/70">· {{ $failed->ip_address }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-red-800/80">{{ __('Open the journey to see the failing step and retry the provision, or remove the server.') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if ($serverCreateDraft)
            @php
                $stepLabels = [
                    1 => __('Type & name'),
                    2 => __('Where it runs'),
                    3 => __('What it runs'),
                    4 => __('Review'),
                ];
                $stepLabel = $stepLabels[$serverCreateDraft->step] ?? __('In progress');
            @endphp
            <div class="rounded-2xl border border-sky-200 bg-sky-50/70 px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-sky-900">{{ __('You have an in-progress server draft.') }}</p>
                        <p class="mt-0.5 text-sm text-sky-800">{{ __('Step :n of :total · :label · last touched :ago', ['n' => $serverCreateDraft->step, 'total' => \App\Models\ServerCreateDraft::TOTAL_STEPS, 'label' => $stepLabel, 'ago' => $serverCreateDraft->updated_at?->diffForHumans()]) }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('servers.create') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-700">
                            {{ __('Continue') }}
                            <x-heroicon-o-arrow-right class="h-4 w-4" />
                        </a>
                        <button type="button" wire:click="openDiscardServerCreateDraftModal" class="inline-flex items-center gap-2 rounded-xl border border-sky-200 bg-white px-4 py-2 text-sm font-semibold text-sky-900 hover:bg-sky-100">
                            {{ __('Discard') }}
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <x-page-header
            :title="__('Servers')"
            :description="__('Provision hosts, watch readiness, and drill into each machine from one fleet view.')"
            doc-route="docs.index"
            flush
            compact
            toolbar
        >
            <x-slot name="leading">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    <x-heroicon-o-server-stack class="h-7 w-7 text-brand-ink" aria-hidden="true" />
                </span>
            </x-slot>
            <x-slot name="actions">
                @can('create', App\Models\Server::class)
                    @if (multi_surface_active())
                        <a
                            href="{{ route('launches.create') }}"
                            wire:navigate
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Open launchpad') }}
                        </a>
                    @else
                        <a
                            href="{{ route('servers.create') }}"
                            wire:navigate
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Create a server') }}
                        </a>
                    @endif
                    <a
                        href="{{ route('docs.create-first-server') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-document-text class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ __('First server guide') }}
                    </a>
                    <a
                        href="{{ route('servers.import.digitalocean') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-cloud-arrow-down class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ __('Import from DigitalOcean') }}
                    </a>
                @endcan
            </x-slot>
        </x-page-header>

        @feature('surface.fleet')
            <nav class="-mt-2 flex flex-wrap items-center gap-1.5 text-sm" aria-label="{{ __('Fleet ops') }}">
                <span class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss me-1">{{ __('Fleet ops') }}</span>
                @foreach ([
                    ['route' => 'fleet.health', 'label' => __('Health'), 'icon' => 'heroicon-o-heart'],
                    ['route' => 'fleet.deploys', 'label' => __('Deploys'), 'icon' => 'heroicon-o-rocket-launch'],
                    ['route' => 'fleet.domains', 'label' => __('Domains'), 'icon' => 'heroicon-o-globe-alt'],
                    ['route' => 'fleet.env-search', 'label' => __('Env search'), 'icon' => 'heroicon-o-key'],
                    ['route' => 'fleet.env-drift', 'label' => __('Env drift'), 'icon' => 'heroicon-o-arrows-right-left'],
                ] as $fleetTile)
                    <a
                        href="{{ route($fleetTile['route']) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-3 py-1 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-brand-sage/45 hover:text-brand-ink"
                    >
                        <x-dynamic-component :component="$fleetTile['icon']" class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        {{ $fleetTile['label'] }}
                    </a>
                @endforeach
            </nav>
        @endfeature

        @php
            $summaryStats = [
                ['icon' => 'heroicon-o-server-stack', 'label' => __('Servers'), 'value' => $summary['total'], 'tone' => 'text-brand-sage'],
                ['icon' => 'heroicon-o-check-circle', 'label' => __('Ready'), 'value' => $summary['ready'], 'tone' => 'text-brand-sage'],
                ['icon' => 'heroicon-o-exclamation-triangle', 'label' => __('Attention'), 'value' => $summary['attention'], 'tone' => $summary['attention'] > 0 ? 'text-amber-500' : 'text-brand-mist'],
                ['icon' => 'heroicon-o-globe-alt', 'label' => __('Sites'), 'value' => $summary['sites'], 'tone' => 'text-brand-sage'],
            ];
        @endphp
        <div class="dply-card overflow-hidden">
            <dl class="grid grid-cols-2 divide-y divide-brand-ink/10 sm:grid-cols-4 sm:divide-x sm:divide-y-0">
                @foreach ($summaryStats as $stat)
                    <div class="flex items-center justify-between gap-3 px-4 py-3 sm:px-5">
                        <dt class="flex min-w-0 items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                            <x-dynamic-component :component="$stat['icon']" class="h-4 w-4 shrink-0 {{ $stat['tone'] }}" aria-hidden="true" />
                            <span class="truncate">{{ $stat['label'] }}</span>
                        </dt>
                        <dd class="text-xl font-semibold tabular-nums leading-none text-brand-ink">{{ $stat['value'] }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($hasServersInScope)
                <div class="flex flex-wrap items-center gap-2 border-t border-brand-ink/10 bg-brand-sand/20 px-4 py-3 sm:px-5">
                    <div class="min-w-[14rem] flex-1">
                        <label for="servers_search" class="sr-only">{{ __('Search') }}</label>
                        <x-text-input id="servers_search" type="search" wire:model.live.debounce.300ms="search" class="mt-0 w-full" placeholder="{{ __('Search servers, IPs, or providers…') }}" autocomplete="off" />
                    </div>

                    <label for="servers_status" class="sr-only">{{ __('Status') }}</label>
                    <x-select id="servers_status" wire:model.live="statusFilter" class="mt-0 w-auto min-w-[10rem]">
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-select>

                    <label for="servers_sort" class="sr-only">{{ __('Order by') }}</label>
                    <x-select id="servers_sort" wire:model.live="sort" class="mt-0 w-auto min-w-[10rem]">
                        @foreach ($sortOptions as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </x-select>

                    <div class="inline-flex rounded-xl border border-brand-ink/15 bg-white p-0.5" role="group" aria-label="{{ __('View') }}">
                        <button
                            type="button"
                            wire:click="$set('viewMode', 'list')"
                            class="rounded-lg px-2.5 py-1.5 text-sm font-medium transition-colors {{ $viewMode === 'list' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:bg-brand-sand/40' }}"
                            aria-pressed="{{ $viewMode === 'list' ? 'true' : 'false' }}"
                            title="{{ __('List') }}"
                        >
                            <span class="sr-only">{{ __('List') }}</span>
                            <x-heroicon-o-list-bullet class="h-5 w-5" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            wire:click="$set('viewMode', 'grid')"
                            class="rounded-lg px-2.5 py-1.5 text-sm font-medium transition-colors {{ $viewMode === 'grid' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:bg-brand-sand/40' }}"
                            aria-pressed="{{ $viewMode === 'grid' ? 'true' : 'false' }}"
                            title="{{ __('Grid') }}"
                        >
                            <span class="sr-only">{{ __('Grid') }}</span>
                            <x-heroicon-o-squares-2x2 class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </div>

                    <button type="button" wire:click="resetFilters" class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink">
                        {{ __('Reset') }}
                    </button>
                </div>
            @endif
        </div>

        @unless ($hasProviderCredentials)
            <section class="mb-8 rounded-[1.75rem] border border-amber-200 bg-amber-50/90 p-5 shadow-sm shadow-amber-100/40 sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-800">{{ __('Set up a provider') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-amber-950">{{ __('Add provider credentials before you provision infrastructure.') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-amber-900/80">
                            {{ __('This fleet can show guidance and empty states, but you will need a connected provider before you can provision cloud infrastructure from the workspace.') }}
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

        @if (! $hasServersInScope)
            <section class="rounded-[2rem] border-2 border-brand-sage/35 bg-brand-cream shadow-lg shadow-brand-ink/10 ring-1 ring-brand-ink/[0.07]" aria-labelledby="servers-empty-heading">
                <div class="px-6 py-12 text-center sm:px-10 sm:py-14">
                    <div class="mx-auto flex max-w-xl flex-col items-center">
                        <span class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-sand/55 text-brand-forest ring-1 ring-brand-ink/10">
                            <x-heroicon-o-server-stack class="h-9 w-9" aria-hidden="true" />
                        </span>
                        <h2 id="servers-empty-heading" class="mt-6 text-2xl font-semibold tracking-tight text-brand-ink">
                            {{ __('No servers yet') }}
                        </h2>
                        <p class="mt-3 text-base leading-relaxed text-brand-moss">
                            {{ __('Create a VM from here once a cloud provider is connected—or pick a guided path first.') }}
                        </p>
                        <ul class="mt-8 w-full space-y-3 text-left text-sm leading-snug text-brand-moss">
                            <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <x-heroicon-o-plus-circle class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span>
                                    <span class="font-semibold text-brand-ink">{{ __('Create a server') }}</span>
                                    <span class="text-brand-mist"> — </span>
                                    {{ __('Fast path to provision when credentials are ready.') }}
                                </span>
                            </li>
                            @if (multi_surface_active())
                                <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                    <x-heroicon-o-rectangle-group class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <span>
                                        <span class="font-semibold text-brand-ink">{{ __('Browse infrastructure') }}</span>
                                        <span class="text-brand-mist"> — </span>
                                        {{ __('See servers, cloud apps, and serverless in one place.') }}
                                    </span>
                                </li>
                                <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                    <x-heroicon-o-squares-2x2 class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <span>
                                        <span class="font-semibold text-brand-ink">{{ __('Open launchpad') }}</span>
                                        <span class="text-brand-mist"> — </span>
                                        {{ __('Explore BYO, Docker, serverless, Kubernetes, and more.') }}
                                    </span>
                                </li>
                            @endif
                            <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <x-heroicon-o-link class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span>
                                    <span class="font-semibold text-brand-ink">{{ __('Connect a provider') }}</span>
                                    <span class="text-brand-mist"> — </span>
                                    {{ __('Add API tokens so Dply can reach your cloud account.') }}
                                </span>
                            </li>
                        </ul>
                        <div class="mt-10 flex w-full flex-wrap items-center justify-center gap-3">
                            @can('create', App\Models\Server::class)
                                <a
                                    href="{{ route('servers.create') }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-3 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition hover:bg-brand-forest"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Create a server') }}
                                </a>
                                <a
                                    href="{{ route('infrastructure.index') }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-5 py-3 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-rectangle-group class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                    {{ __('Browse infrastructure') }}
                                </a>
                                <a
                                    href="{{ route('launches.create') }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-5 py-3 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                    {{ __('Open launchpad') }}
                                </a>
                                @foreach ($importSources as $importSource)
                                    @php
                                        $importRoute = match ($importSource) {
                                            'ploi' => route('imports.ploi.inventory'),
                                            'forge' => route('imports.forge.inventory'),
                                            default => null,
                                        };
                                        $importLabel = match ($importSource) {
                                            'ploi' => __('Migrate from Ploi'),
                                            'forge' => __('Migrate from Forge'),
                                            default => __('Migrate'),
                                        };
                                    @endphp
                                    @if ($importRoute !== null)
                                        <a
                                            href="{{ $importRoute }}"
                                            wire:navigate
                                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-amber-300 bg-amber-50 px-5 py-3 text-sm font-semibold text-amber-950 shadow-sm transition hover:bg-amber-100"
                                        >
                                            <x-heroicon-o-arrow-down-tray class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                            {{ $importLabel }}
                                        </a>
                                    @endif
                                @endforeach
                            @endcan
                            <a
                                href="{{ route('credentials.index') }}"
                                wire:navigate
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-sage/40 bg-brand-sand/30 px-5 py-3 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/50"
                            >
                                <x-heroicon-o-key class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Connect a provider') }}
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        @else
            <div class="dply-card overflow-hidden rounded-[2rem]">
                @if ($groupedServers->flatten()->isEmpty())
                    <div class="px-6 py-14 text-center">
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('No results') }}</p>
                        <h3 class="mt-3 text-xl font-semibold text-brand-ink">{{ __('No servers match your current filters') }}</h3>
                        <p class="mx-auto mt-3 max-w-2xl text-sm leading-6 text-brand-moss">
                            {{ __('Try widening the search, switching the status filter, or resetting the command rail to bring the full fleet back into view.') }}
                        </p>
                        <button type="button" wire:click="resetFilters" class="mt-5 inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink transition hover:bg-brand-cream">
                            {{ __('Reset filters') }}
                        </button>
                    </div>
                @elseif ($viewMode === 'grid')
                    <div class="p-4 sm:p-6 space-y-10 bg-white">
                        @foreach ($groupedServers as $groupLabel => $groupServers)
                            <div>
                                <div class="mb-4 flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                                    <h2 class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                        <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ $groupLabel }}
                                    </h2>
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/30 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $groupServers->count() }}</span>
                                </div>
                                <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                    @foreach ($groupServers as $server)
                                        <li wire:key="server-grid-{{ $server->id }}" class="flex rounded-xl border border-brand-ink/10 bg-white overflow-hidden shadow-sm hover:border-brand-ink/20 transition-colors">
                                            <div class="w-1 shrink-0 {{ $stripe($server) }}" aria-hidden="true"></div>
                                            <div class="flex flex-1 flex-col gap-3 p-4 min-w-0">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2 min-w-0">
                                                        <a href="{{ route('servers.show', $server) }}" wire:navigate class="font-semibold text-brand-ink hover:text-brand-sage truncate block">{{ $server->name }}</a>
                                                        @php $insOpen = (int) ($insightRollup[$server->id]['open'] ?? 0); @endphp
                                                        @if ($insOpen > 0)
                                                            <a href="{{ route('servers.insights', $server) }}" wire:navigate title="{{ __('Open insights') }}" class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold leading-none {{ $insightBadgeClass($server->id) }}">{{ trans_choice(':count insight|:count insights', $insOpen, ['count' => $insOpen]) }}</a>
                                                        @endif
                                                    </div>
                                                    <p class="mt-1 font-mono text-sm text-brand-moss truncate">{{ $server->ip_address ?? __('Provisioning…') }}</p>
                                                    <div class="mt-2">
                                                        <x-server-metric-pulse :snapshot="$latestSnapshots[$server->id] ?? null" />
                                                    </div>
                                                    @if ($server->workspace)
                                                        @feature('surface.projects')
                                                            <p class="mt-1 text-xs text-brand-moss">
                                                                {{ __('Project:') }}
                                                                <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                                                                    {{ $server->workspace->name }}
                                                                </a>
                                                            </p>
                                                        @endfeature
                                                    @endif
                                                    @if ($server->scheduled_deletion_at)
                                                        <p class="mt-1 text-xs font-medium text-amber-800">
                                                            {{ __('Removal scheduled :date', ['date' => $server->scheduled_deletion_at->timezone(config('app.timezone'))->toFormattedDateString()]) }}
                                                            <button type="button" wire:click="cancelScheduledServerRemoval(@js($server->id))" class="ml-1 font-semibold underline hover:no-underline">{{ __('Cancel') }}</button>
                                                        </p>
                                                    @endif
                                                </div>
                                                <p class="text-xs text-brand-moss leading-relaxed">
                                                    {{ trans_choice(':count site|:count sites', $server->sites_count, ['count' => $server->sites_count]) }}
                                                    @if ($isFullyReady($server))
                                                        <span class="text-brand-mist"> · </span>
                                                        {{ __('Online for :days days', ['days' => max(0, (int) $server->created_at->diffInDays(now()))]) }}
                                                    @endif
                                                </p>
                                                <div class="flex items-center justify-end gap-2 pt-1 mt-auto">
                                                    <a href="{{ route('servers.show', $server) }}" wire:navigate class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/50" title="{{ __('Manage') }}">
                                                        <x-heroicon-o-bars-3 class="h-4 w-4" aria-hidden="true" />
                                                    </a>
                                                    @can('delete', $server)
                                                        <button type="button" wire:click="openRemoveServerModal(@js($server->id))" class="text-xs font-semibold text-red-600 hover:text-red-800">
                                                            {{ __('Remove') }}
                                                        </button>
                                                    @endcan
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="divide-y divide-brand-ink/10 bg-white">
                        @foreach ($groupedServers as $groupLabel => $groupServers)
                            <div wire:key="group-{{ \Illuminate\Support\Str::slug($groupLabel) }}">
                                <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-4 sm:px-6 py-2.5">
                                    <h2 class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                        <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ $groupLabel }}
                                    </h2>
                                    <span class="inline-flex items-center rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $groupServers->count() }}</span>
                                </div>
                                <ul>
                                    @foreach ($groupServers as $server)
                                        <li wire:key="server-list-{{ $server->id }}" class="flex items-stretch border-b border-brand-ink/10 last:border-b-0 hover:bg-brand-sand/15 transition-colors">
                                            <div class="w-1 shrink-0 {{ $stripe($server) }}" aria-hidden="true"></div>
                                            <div class="flex flex-1 flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-4 py-4 sm:px-6 min-w-0">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                        <a href="{{ route('servers.show', $server) }}" wire:navigate class="font-semibold text-brand-ink hover:text-brand-sage">
                                                            {{ $server->name }}
                                                            <span class="text-brand-mist font-normal">·</span>
                                                            <span class="font-mono text-sm font-normal text-brand-moss">{{ $server->ip_address ?? __('Provisioning…') }}</span>
                                                        </a>
                                                        @php $insOpenList = (int) ($insightRollup[$server->id]['open'] ?? 0); @endphp
                                                        @if ($insOpenList > 0)
                                                            <a href="{{ route('servers.insights', $server) }}" wire:navigate title="{{ __('Open insights') }}" class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold leading-none {{ $insightBadgeClass($server->id) }}">{{ trans_choice(':count insight|:count insights', $insOpenList, ['count' => $insOpenList]) }}</a>
                                                        @endif
                                                        <span class="hidden sm:inline-flex">
                                                            <x-server-metric-pulse :snapshot="$latestSnapshots[$server->id] ?? null" />
                                                        </span>
                                                    </div>
                                                    <div class="mt-2 sm:hidden">
                                                        <x-server-metric-pulse :snapshot="$latestSnapshots[$server->id] ?? null" />
                                                    </div>
                                                    <p class="mt-1 text-sm text-brand-moss">
                                                        {{ trans_choice(':count site|:count sites', $server->sites_count, ['count' => $server->sites_count]) }}
                                                        @if ($server->workspace)
                                                            @feature('surface.projects')
                                                                <span class="text-brand-mist"> · </span>
                                                                {{ __('Project:') }}
                                                                <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                                                                    {{ $server->workspace->name }}
                                                                </a>
                                                            @endfeature
                                                        @endif
                                                        @if ($isFullyReady($server))
                                                            <span class="text-brand-mist"> · </span>
                                                            {{ __('Online for :days days', ['days' => max(0, (int) $server->created_at->diffInDays(now()))]) }}
                                                        @endif
                                                        <span class="text-brand-mist"> · </span>
                                                        {{ $server->provider->label() }}
                                                        <span class="text-brand-mist"> · </span>
                                                        {{ $displayStatus($server) }}
                                                        @if ($server->scheduled_deletion_at)
                                                            <span class="text-brand-mist"> · </span>
                                                            <span class="text-amber-800 font-medium">{{ __('Removal :date', ['date' => $server->scheduled_deletion_at->timezone(config('app.timezone'))->toFormattedDateString()]) }}</span>
                                                        @endif
                                                        @if ($isFullyReady($server))
                                                            @if ($server->health_status === 'reachable')
                                                                <span class="text-emerald-600"> · {{ __('Reachable') }}</span>
                                                            @elseif ($server->health_status === 'unreachable')
                                                                <span class="text-red-600"> · {{ __('Unreachable') }}</span>
                                                            @endif
                                                        @endif
                                                    </p>

                                                    {{-- Setup-failed detail: red chip + journey link. Shown instead of
                                                         the live progress block when applyProvisionOutcomeToServer
                                                         flipped setup_status to failed. Without this branch the card
                                                         keeps ticking the elapsed counter on a dead provision. --}}
                                                    @if ($isSetupFailed($server))
                                                        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-red-700 ring-1 ring-red-200">
                                                                <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                                                                {{ __('Setup failed') }}
                                                            </span>
                                                            <span class="text-brand-ink">{{ __('Provisioning did not finish — open the journey to see the failing step.') }}</span>
                                                            <a href="{{ route('servers.journey', $server) }}" wire:navigate class="ml-auto inline-flex items-center gap-1 text-[11px] font-semibold text-red-700 hover:text-red-900">
                                                                {{ __('Open journey') }}
                                                                <x-heroicon-m-arrow-right class="h-3 w-3" />
                                                            </a>
                                                        </div>
                                                    @endif

                                                    {{-- Live provisioning detail: phase + current step + elapsed + a
                                                         thin progress bar. Mirrors the journey page's headline so an
                                                         operator scanning the fleet sees "where is this in the build"
                                                         without clicking through. Only renders for in-flight VMs and
                                                         is suppressed once setup_status hits failed (see above). --}}
                                                    @php $digest = $provisioningDigests[$server->id] ?? null; @endphp
                                                    @if ($digest && ! $isSetupFailed($server))
                                                        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-sky-800 ring-1 ring-sky-200">
                                                                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-sky-500"></span>
                                                                {{ $digest->phaseLabel }}
                                                            </span>
                                                            <span class="font-medium text-brand-ink">{{ $digest->stepLabel }}</span>
                                                            @if ($digest->stepIndex && $digest->stepTotal)
                                                                <span class="text-brand-mist">·</span>
                                                                <span class="tabular-nums">{{ __('Step :i of :t', ['i' => $digest->stepIndex, 't' => $digest->stepTotal]) }}</span>
                                                            @endif
                                                            @if ($digest->elapsedHuman())
                                                                <span class="text-brand-mist">·</span>
                                                                <span class="tabular-nums">{{ __(':elapsed elapsed', ['elapsed' => $digest->elapsedHuman()]) }}</span>
                                                            @endif
                                                            <a href="{{ route('servers.journey', $server) }}" wire:navigate class="ml-auto inline-flex items-center gap-1 text-[11px] font-semibold text-sky-700 hover:text-sky-900">
                                                                {{ __('Open journey') }}
                                                                <x-heroicon-m-arrow-right class="h-3 w-3" />
                                                            </a>
                                                        </div>
                                                        @if ($digest->stepIndex && $digest->stepTotal)
                                                            @php $pct = max(0, min(100, (int) round(100 * $digest->stepIndex / $digest->stepTotal))); @endphp
                                                            <div class="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-brand-ink/5">
                                                                <div class="h-full rounded-full bg-sky-500 transition-[width] duration-500" style="width: {{ $pct }}%"></div>
                                                            </div>
                                                        @endif
                                                    @endif
                                                </div>
                                                <div class="flex items-center gap-2 shrink-0">
                                                    <a href="{{ route('servers.show', $server) }}" wire:navigate class="inline-flex items-center justify-center rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-brand-cream hover:bg-brand-forest">
                                                        {{ __('Manage') }}
                                                    </a>
                                                    @can('delete', $server)
                                                        <button type="button" wire:click="openRemoveServerModal(@js($server->id))" class="text-xs font-semibold text-red-600 hover:text-red-800 px-2 py-2">
                                                            {{ __('Remove') }}
                                                        </button>
                                                    @endcan
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>

    @include('livewire.servers.partials.remove-server-modal', [
        'open' => $deleteModalServerId !== null && $deleteModalServer,
        'serverName' => $deleteModalServer?->name ?? '',
        'serverId' => (string) ($deleteModalServer?->id ?? ''),
        'deletionSummary' => $deletionSummary,
    ])

    @if ($showDiscardServerCreateDraftModal)
        @teleport('body')
        <div class="fixed inset-0 isolate z-[100] overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeDiscardServerCreateDraftModal"></div>
            <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10">
                <div class="w-full max-w-md dply-modal-panel" @click.stop>
                    <div class="border-b border-zinc-100 px-6 py-5">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Discard this draft?') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss">{{ __("You'll lose the values you've entered so far. This can't be undone.") }}</p>
                    </div>
                    <div class="flex flex-col-reverse gap-3 border-t border-zinc-100 bg-zinc-50/80 px-6 py-4 sm:flex-row sm:justify-end">
                        <button type="button" wire:click="closeDiscardServerCreateDraftModal" class="inline-flex justify-center rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-zinc-50">
                            {{ __('Keep editing') }}
                        </button>
                        <button type="button" wire:click="confirmDiscardServerCreateDraft" wire:loading.attr="disabled" wire:target="confirmDiscardServerCreateDraft" class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:cursor-wait disabled:opacity-60">
                            {{ __('Discard draft') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endteleport
    @endif
</div>
