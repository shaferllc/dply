@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    ];

    $isContainerHost = in_array($server->hostKind(), [\App\Models\Server::HOST_KIND_DOCKER, \App\Models\Server::HOST_KIND_KUBERNETES], true);

    $newCardEyebrow = $isContainerHost ? __('Container apps') : __('Sites');
    $newCardHeading = $isContainerHost ? __('New container app') : __('New site');
    $newCardDescription = $isContainerHost
        ? __('Point dply at a Git repo. We inspect the Dockerfile or Kubernetes manifest and deploy onto this host.')
        : __('Add a domain to get started. Stack, paths, and PHP options are available in advanced settings.');
    $addCtaLabel = $isContainerHost ? __('Add container') : __('Add site');
    $listHeading = $isContainerHost ? __('Container apps') : __('Site directory');
    $listEyebrow = __('Library');
    $emptyHeadline = $isContainerHost ? __('No container apps yet') : __('No sites yet');
    $emptyLead = $isContainerHost
        ? __('Add one to deploy a Git repo onto this host.')
        : __('Add a site to manage web server config, SSL, Git deploys, and environment files.');
    $siteCount = $server->sites->count();
@endphp

<x-server-workspace-layout
    :server="$server"
    active="sites"
    :title="__('Sites')"
    :description="__('Manage sites, databases, automation, and deploy tools for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div class="space-y-6">

        {{-- Hero: new site/container CTA. --}}
        <section class="dply-card overflow-hidden">
            <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                <div class="lg:col-span-7">
                    <div class="flex items-start gap-3">
                        <x-icon-badge size="md">
                            @if ($isContainerHost)
                                <x-heroicon-o-cube-transparent class="h-6 w-6" aria-hidden="true" />
                            @else
                                <x-heroicon-o-globe-alt class="h-6 w-6" aria-hidden="true" />
                            @endif
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $newCardEyebrow }}</p>
                            <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ $newCardHeading }}</h2>
                            <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                {{ $newCardDescription }}
                            </p>
                            @if (! $this->canAddSite && $this->addSiteBlockedReason !== '')
                                <div class="mt-3 inline-flex items-start gap-2 whitespace-nowrap rounded-xl border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs leading-relaxed text-amber-900">
                                    <x-heroicon-m-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    <span class="whitespace-normal">{{ $this->addSiteBlockedReason }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        @if ($this->canAddSite)
                            <button
                                type="button"
                                wire:click="openAddSiteModal"
                                class="inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ $addCtaLabel }}
                            </button>
                        @else
                            <span
                                class="inline-flex cursor-not-allowed items-center gap-2 whitespace-nowrap rounded-xl bg-brand-mist/30 px-4 py-2 text-sm font-semibold text-brand-moss"
                                title="{{ $this->addSiteBlockedReason }}"
                            >
                                <x-heroicon-o-no-symbol class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ $addCtaLabel }}
                            </span>
                        @endif
                    </div>
                </div>
                <dl class="grid grid-cols-2 gap-2 lg:col-span-5">
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-brand-sage/30 bg-brand-sage/8' => $siteCount > 0,
                        'border-brand-ink/10 bg-white' => $siteCount === 0,
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $isContainerHost ? __('Apps') : __('Sites') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $siteCount }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('on this host|on this host', $siteCount) }}</span>
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Per server') }}</p>
                    </div>
                    <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Host') }}</dt>
                        <dd class="mt-1 truncate text-sm font-semibold text-brand-ink">
                            {{ $isContainerHost ? __('Container') : __('VM') }}
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ ucfirst((string) $server->hostKind()) }}</p>
                    </div>
                </dl>
            </div>
        </section>

        {{-- Sites list. --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $listEyebrow }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $listHeading }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        @if ($bulkActionsEnabled && ! $isContainerHost)
                            {{ __('Select sites to run bulk actions, or click a row to open that workspace.') }}
                        @else
                            {{ __('Click a row to open that workspace and manage deploys, env, and settings.') }}
                        @endif
                    </p>
                </div>
                @if ($siteCount > 0)
                    <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $siteCount }}</span>
                @endif
            </div>

            @php $bulkSelectedCount = count(array_filter($selectedSiteIds ?? [])); @endphp
            @if ($bulkActionsEnabled && ! $isContainerHost && $bulkSelectedCount > 0)
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-3 sm:px-7">
                    <span class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ trans_choice(':count site selected|:count sites selected', $bulkSelectedCount, ['count' => $bulkSelectedCount]) }}</span>
                    <button
                        type="button"
                        wire:click="selectAllSites"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                    >
                        <x-heroicon-o-check-circle wire:loading.remove wire:target="selectAllSites" class="h-3.5 w-3.5 text-brand-moss" />
                        <span wire:loading wire:target="selectAllSites" class="inline-flex h-4 w-4 items-center justify-center">
                            <x-spinner variant="forest" size="sm" />
                        </span>
                        <span wire:loading.remove wire:target="selectAllSites">{{ __('Select all') }}</span>
                        <span wire:loading wire:target="selectAllSites">{{ __('Selecting…') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="clearSiteSelection"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-medium text-brand-moss hover:bg-brand-sand/30"
                    >
                        <x-heroicon-o-x-circle wire:loading.remove wire:target="clearSiteSelection" class="h-4 w-4" />
                        <span wire:loading wire:target="clearSiteSelection" class="inline-flex h-4 w-4 items-center justify-center">
                            <x-spinner variant="forest" size="sm" />
                        </span>
                        <span wire:loading.remove wire:target="clearSiteSelection">{{ __('Clear') }}</span>
                        <span wire:loading wire:target="clearSiteSelection">{{ __('Clearing…') }}</span>
                    </button>
                    @if ($this->selectedBulkPreview['redeploy_count'] > 0)
                        <button
                            type="button"
                            wire:click="openRedeployAllModal"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ trans_choice('Redeploy :count site|Redeploy :count sites', $this->selectedBulkPreview['redeploy_count'], ['count' => $this->selectedBulkPreview['redeploy_count']]) }}
                        </button>
                    @endif
                    @if ($this->selectedBulkPreview['renewable_count'] > 0)
                        <a
                            href="{{ route('servers.cert-inventory', $server) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-lock-closed class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ trans_choice(':count renewable certificate|:count renewable certificates', $this->selectedBulkPreview['renewable_count'], ['count' => $this->selectedBulkPreview['renewable_count']]) }}
                        </a>
                    @endif
                </div>
            @endif

            @if ($server->sites->isEmpty())
                <div class="px-6 py-12 text-center sm:px-7">
                    <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        @if ($isContainerHost)
                            <x-heroicon-o-cube-transparent class="h-6 w-6" aria-hidden="true" />
                        @else
                            <x-heroicon-o-globe-alt class="h-6 w-6" aria-hidden="true" />
                        @endif
                    </span>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ $emptyHeadline }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">{{ $emptyLead }}</p>
                    @if ($this->canAddSite)
                        <button
                            type="button"
                            wire:click="openAddSiteModal"
                            class="mt-5 inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ $addCtaLabel }}
                        </button>
                    @endif
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($server->sites as $s)
                        @php
                            $primaryDomain = $s->domains->sortByDesc('is_primary')->first();
                            $displayHost = $primaryDomain?->hostname ?? $s->name;
                            $siteInitial = (string) str($s->isCustom() ? $s->name : $displayHost)->substr(0, 1)->upper();
                            $statusOk = $s->isReadyForTraffic();
                            $sslOn = $s->ssl_status === \App\Models\Site::SSL_ACTIVE;
                            $gitRef = $s->git_repository_url;
                            $gitShort = $gitRef ? (preg_match('#([^/:]+/[^/]+?)(?:\.git)?$#', $gitRef, $m) ? $m[1] : \Illuminate\Support\Str::limit($gitRef, 40)) : null;
                            $debugOn = filter_var($s->meta['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);

                            // Whether the fleet-style Deploy / Sync buttons apply
                            // to this row: a VM site (not a functions/edge runtime)
                            // the viewer may update. The action handlers re-check
                            // this, so a stale click is a safe no-op either way.
                            $siteDeployable = $server->isVmHost()
                                && ! $s->usesFunctionsRuntime()
                                && ! $s->usesEdgeRuntime()
                                && auth()->user()?->can('update', $s);

                            $containerLaunchStatus = (string) data_get($server->meta, 'container_launch.status', '');
                            $isWaitingOnHost = $s->status === \App\Models\Site::STATUS_PENDING
                                && in_array($containerLaunchStatus, ['waiting_for_server', 'queued'], true)
                                && (string) data_get($server->meta, 'container_launch.site_id', '') === (string) $s->id;

                            // Status chip (right-side): replaces the colored
                            // left bar so the row matches the family pattern.
                            $statusChip = match (true) {
                                $s->status === \App\Models\Site::STATUS_ERROR => ['tone' => 'border-rose-200 bg-rose-50 text-rose-700', 'icon' => 'm-x-circle', 'label' => __('Error')],
                                $statusOk => ['tone' => 'border-emerald-200 bg-emerald-50 text-emerald-700', 'icon' => 'm-check-circle', 'label' => __('Ready')],
                                default => ['tone' => 'border-amber-200 bg-amber-50 text-amber-800', 'icon' => 'm-clock', 'label' => __('Pending')],
                            };
                        @endphp
                        <li wire:key="site-{{ $s->id }}" class="flex items-stretch">
                            @if ($bulkActionsEnabled && ! $isContainerHost)
                                <label class="flex shrink-0 items-center px-4 sm:px-5">
                                    <input
                                        type="checkbox"
                                        wire:model.live="selectedSiteIds"
                                        value="{{ $s->id }}"
                                        class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest"
                                        aria-label="{{ __('Select :site', ['site' => $s->isCustom() ? $s->name : $displayHost]) }}"
                                    />
                                </label>
                            @endif
                            <a
                                href="{{ route('sites.show', [$server, $s]) }}"
                                wire:navigate
                                class="flex min-w-0 flex-1 items-center justify-between gap-4 py-4 pr-6 transition-colors hover:bg-brand-sand/15 sm:pr-7 {{ $bulkActionsEnabled && ! $isContainerHost ? 'pl-4 sm:pl-5' : 'px-6 sm:px-7' }}"
                            >
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-brand-ink/10 bg-brand-sand/40 text-sm font-semibold text-brand-moss">
                                    @if ($s->logoUrl())
                                        <img src="{{ $s->logoUrl() }}" alt="" class="h-full w-full object-cover" />
                                    @else
                                        {{ $siteInitial !== '' ? $siteInitial : '•' }}
                                    @endif
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                        <span class="truncate text-sm font-semibold text-brand-ink">{{ $s->isCustom() ? $s->name : $displayHost }}</span>
                                        @if ($s->isCustom())
                                            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                                <x-heroicon-m-wrench-screwdriver class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Custom') }}
                                            </span>
                                        @elseif ($sslOn)
                                            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700" title="{{ __('SSL active') }}">
                                                <x-heroicon-s-lock-closed class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('SSL') }}
                                            </span>
                                        @endif
                                        @if ($debugOn)
                                            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800">
                                                <x-heroicon-m-bug-ant class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Debug') }}
                                            </span>
                                        @endif
                                        @if ($isWaitingOnHost)
                                            <span data-testid="container-site-waiting-host" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-sky-200 bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">
                                                <x-heroicon-m-clock class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Waiting for host') }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-brand-moss">
                                        @if ($gitShort)
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-m-code-bracket class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                <span class="font-mono text-brand-ink">{{ $gitShort }}</span>
                                                @if ($s->git_branch)
                                                    <span class="text-brand-mist">({{ $s->git_branch }})</span>
                                                @endif
                                            </span>
                                        @elseif ($s->isCustomNoRepoMode())
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-m-folder class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                <span class="font-mono text-brand-ink">{{ $s->repository_path ?: '/home/'.$s->effectiveSystemUser($server).'/'.$s->slug }}</span>
                                                <span class="text-brand-mist">{{ __('no repo') }}</span>
                                            </span>
                                        @endif
                                        <span class="inline-flex items-center gap-1">
                                            <x-heroicon-m-user class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                            <span class="font-mono text-brand-ink">{{ $s->effectiveSystemUser($server) }}</span>
                                        </span>
                                        @if ($s->type?->value === 'php' && $s->php_version)
                                            <span class="inline-flex items-center gap-1">
                                                <span class="text-[10px] uppercase tracking-wide text-brand-mist">PHP</span>
                                                <span class="font-mono text-brand-ink">{{ $s->php_version }}</span>
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusChip['tone'] }}">
                                        @if ($statusChip['icon'] === 'm-check-circle')
                                            <x-heroicon-m-check-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        @elseif ($statusChip['icon'] === 'm-x-circle')
                                            <x-heroicon-m-x-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        @else
                                            <x-heroicon-m-clock class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        @endif
                                        {{ $statusChip['label'] }}
                                    </span>
                                    <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                </div>
                            </a>
                            @if ($siteDeployable)
                                {{-- Deploy / Sync sit outside the row link so they
                                     fire the deploy console instead of navigating.
                                     Same handlers + slide-over as the fleet page. --}}
                                <div class="flex shrink-0 items-center gap-1 border-l border-brand-ink/10 px-3 sm:px-4">
                                    <button
                                        type="button"
                                        wire:click="deploySite('{{ $s->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="deploySite('{{ $s->id }}')"
                                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                                        title="{{ __('Deploy this site') }}"
                                    >
                                        <x-heroicon-m-rocket-launch wire:loading.remove wire:target="deploySite('{{ $s->id }}')" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        <span wire:loading wire:target="deploySite('{{ $s->id }}')" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                            <x-spinner variant="cream" size="sm" />
                                        </span>
                                        {{ __('Deploy') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="deploySyncedSites('{{ $s->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="deploySyncedSites('{{ $s->id }}')"
                                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                                        title="{{ __('Deploy this site and any sharing its repository') }}"
                                    >
                                        <x-heroicon-m-arrow-path wire:loading.remove wire:target="deploySyncedSites('{{ $s->id }}')" class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                                        <span wire:loading wire:target="deploySyncedSites('{{ $s->id }}')" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                            <x-spinner variant="forest" size="sm" />
                                        </span>
                                        {{ __('Sync') }}
                                    </button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- Deploy console: opens when Deploy / Sync is clicked on a site row so the
         launched deploy(s) can be watched live without leaving the page. Mirrors
         the per-site deploy sidebar and the fleet console; reuses the same live
         row partial. The `deploy-console-open` window event is dispatched by
         watchDeploys() (see WatchesSiteDeploys). --}}
    <div x-data="{ deployConsoleOpen: false }" x-on:deploy-console-open.window="deployConsoleOpen = true">
        @if (count($this->watchedRows) > 0)
            <button
                type="button"
                x-show="!deployConsoleOpen"
                x-on:click="deployConsoleOpen = true"
                class="fixed bottom-4 left-4 z-40 inline-flex items-center gap-2 rounded-full border border-brand-ink/10 bg-white px-3.5 py-2 text-xs font-semibold text-brand-ink shadow-lg shadow-brand-ink/15 transition hover:bg-brand-sand/40"
                title="{{ __('Open deploy console') }}"
            >
                @if ($this->watchedInProgress)
                    <x-spinner size="sm" />
                    {{ trans_choice('Deploying :n site|Deploying :n sites', count($this->watchedRows), ['n' => count($this->watchedRows)]) }}
                @else
                    <x-heroicon-m-check-circle class="h-4 w-4 text-emerald-600" />
                    {{ __('Deploys finished') }}
                @endif
            </button>
        @endif

        <div x-show="deployConsoleOpen" x-cloak class="fixed inset-0 z-50" style="display: none;">
            <div class="absolute inset-0 bg-brand-ink/40" x-on:click="deployConsoleOpen = false" x-transition.opacity></div>
            <div
                class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
            >
                <div class="flex items-center justify-between border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy console') }}</p>
                        <p class="truncate text-sm font-semibold text-brand-ink">
                            @if ($this->watchedInProgress)
                                {{ trans_choice('Deploying :n site|Deploying :n sites', count($this->watchedRows), ['n' => count($this->watchedRows)]) }}
                            @else
                                {{ trans_choice('{0}No deploys yet|{1}:n deploy|[2,*]:n deploys', count($this->watchedRows), ['n' => count($this->watchedRows)]) }}
                            @endif
                        </p>
                    </div>
                    <button type="button" x-on:click="deployConsoleOpen = false" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto px-5 py-4" @if ($this->watchedInProgress) wire:poll.3s @endif>
                    @forelse ($this->watchedRows as $row)
                        @include('livewire.sites.partials._deploy-console-row', ['row' => $row, 'keyPrefix' => 'workspace'])
                    @empty
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-10 text-center text-sm text-brand-moss">
                            {{ __('Hit Deploy or Sync on a site to watch it here.') }}
                        </div>
                    @endforelse
                </div>

                <div class="border-t border-brand-ink/10 bg-brand-sand/20 px-5 py-3 text-center text-[11px] text-brand-moss">
                    @if ($this->watchedInProgress)
                        <span class="inline-flex items-center gap-1.5"><x-spinner size="sm" /> {{ __('Deploying — this updates live.') }}</span>
                    @elseif (count($this->watchedRows) > 0)
                        {{ __('All deploys finished.') }}
                    @endif
                </div>
            </div>
        </div>
    </div>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])

        @if ($this->supportsQuickAdd)
            <x-modal
                name="add-site-modal"
                :show="$showAddSiteModal"
                maxWidth="2xl"
                overlayClass="bg-brand-ink/30"
                panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
                focusable
            >
                <form wire:submit="addSite" x-data="{ showAdvanced: false }" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                        <x-icon-badge>
                            <x-heroicon-o-plus-circle class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New site') }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Add a site to :server', ['server' => $server->name]) }}</h2>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">
                                {{ __('Enter a primary domain. Stack, paths, and PHP options are available below.') }}
                            </p>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                        <div>
                            <x-input-label for="add-site-hostname" :value="__('Primary domain')" />
                            <x-text-input
                                id="add-site-hostname"
                                wire:model.live.debounce.300ms="form.primary_hostname"
                                type="text"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="app.example.com"
                                required
                                autocomplete="off"
                            />
                            <x-input-error :messages="$errors->get('form.primary_hostname')" class="mt-1" />
                        </div>

                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/30">
                            <button
                                type="button"
                                x-on:click="showAdvanced = !showAdvanced"
                                class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-semibold text-brand-ink hover:bg-brand-sand/30"
                                x-bind:aria-expanded="showAdvanced"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <x-heroicon-m-cog-6-tooth class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                                    {{ __('Advanced settings') }}
                                </span>
                                <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 transition-transform" x-bind:class="showAdvanced ? 'rotate-180' : ''" />
                            </button>

                            <div x-show="showAdvanced" x-collapse class="space-y-5 border-t border-brand-ink/10 px-4 py-4">
                                <div>
                                    <x-input-label for="add-site-name" :value="__('Site name')" />
                                    <x-text-input
                                        id="add-site-name"
                                        wire:model="form.name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        autocomplete="off"
                                    />
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('Used for the slug and deploy path. Auto-derived from the domain.') }}</p>
                                    <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
                                </div>

                                <div class="grid gap-5 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="add-site-doc-root" :value="__('Web directory')" />
                                        <x-text-input
                                            id="add-site-doc-root"
                                            wire:model.blur="form.document_root"
                                            type="text"
                                            class="mt-1 block w-full font-mono text-sm"
                                            required
                                        />
                                        <x-input-error :messages="$errors->get('form.document_root')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="add-site-deploy-path" :value="__('Project directory')" />
                                        <x-text-input
                                            id="add-site-deploy-path"
                                            wire:model.blur="form.repository_path"
                                            type="text"
                                            class="mt-1 block w-full font-mono text-sm"
                                        />
                                        <x-input-error :messages="$errors->get('form.repository_path')" class="mt-1" />
                                    </div>
                                </div>

                                <div>
                                    <x-input-label for="add-site-framework" :value="__('Project type')" />
                                    <select
                                        id="add-site-framework"
                                        wire:model.live="form.framework"
                                        class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                    >
                                        <option value="">{{ __('None (Static HTML or PHP)') }}</option>
                                        <option value="laravel">Laravel</option>
                                        <option value="nodejs">NodeJS</option>
                                        <option value="statamic">Statamic</option>
                                        <option value="craft">Craft CMS</option>
                                        <option value="symfony">Symfony</option>
                                        <option value="wordpress">WordPress</option>
                                        <option value="october">OctoberCMS</option>
                                        <option value="cakephp3">CakePHP 3</option>
                                    </select>
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('PHP version and runtime details are detected from the repository when the first deploy clones the project.') }}</p>
                                    <x-input-error :messages="$errors->get('form.framework')" class="mt-1" />
                                </div>

                                <div>
                                    <x-input-label for="add-site-template" :value="__('Webserver template')" />
                                    <select
                                        id="add-site-template"
                                        wire:model="form.webserver_template"
                                        class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                    >
                                        <option value="default">{{ __('Default template') }}</option>
                                    </select>
                                </div>

                                @if ($form->type === 'node')
                                    <div>
                                        <x-input-label for="add-site-port" :value="__('App listens on (localhost)')" />
                                        <x-text-input
                                            id="add-site-port"
                                            type="number"
                                            wire:model="form.app_port"
                                            class="mt-1 block w-32"
                                        />
                                        <p class="mt-1 text-xs text-brand-mist">{{ __('Nginx will proxy requests to this port.') }}</p>
                                    </div>
                                @endif

                                <div class="space-y-3 border-t border-brand-ink/10 pt-4">
                                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                                        <input
                                            type="checkbox"
                                            wire:model="form.create_system_user"
                                            class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                                        />
                                        <span>
                                            <span class="font-medium">{{ __('Create system user') }}</span>
                                            <span class="block text-xs text-brand-mist">{{ __('Creates a system user with a random generated name dedicated to this site.') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                                        <input
                                            type="checkbox"
                                            wire:model="form.create_staging_site"
                                            class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                                        />
                                        <span>
                                            <span class="font-medium">{{ __('Create staging site') }}</span>
                                            <span class="block text-xs text-brand-mist">{{ __('Creates an extra site for development. After development is done you can push the code over to the main site.') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                                        <input
                                            type="checkbox"
                                            wire:model="form.use_as_redirect_domain"
                                            class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                                        />
                                        <span>
                                            <span class="font-medium">{{ __('Use as redirect domain') }}</span>
                                            <span class="block text-xs text-brand-mist">{{ __('Redirects this whole domain to another domain.') }}</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeAddSiteModal">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="addSite"
                            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="addSite" class="inline-flex items-center gap-2">
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Add site') }}
                            </span>
                            <span wire:loading wire:target="addSite" class="inline-flex items-center gap-2 whitespace-nowrap">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Adding…') }}
                            </span>
                        </button>
                    </div>
                </form>
            </x-modal>
        @endif

        @if ($bulkActionsEnabled && ! $isContainerHost)
            <x-modal name="redeploy-all-sites" maxWidth="lg" overlayClass="bg-brand-ink/40">
                <div class="relative border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3 pr-10">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Bulk deploy') }}</p>
                            <h2 class="mt-0.5 text-xl font-semibold text-brand-ink">{{ __('Redeploy selected sites?') }}</h2>
                            <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                                {{ __('Queues a manual deploy for each selected site that is ready for traffic. Suspended or still-provisioning sites in your selection are skipped.') }}
                            </p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeRedeployAllModal" class="absolute right-4 top-4 rounded-lg p-1.5 text-brand-moss transition hover:bg-brand-sand/50 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
                @if (count($this->selectedBulkPreview['site_names'] ?? []) > 0)
                    <div class="max-h-48 overflow-y-auto border-b border-brand-ink/10 px-6 py-4 sm:px-7">
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Sites included') }}</p>
                        <ul class="mt-2 space-y-1 text-sm text-brand-moss">
                            @foreach ($this->selectedBulkPreview['site_names'] as $siteName)
                                <li class="truncate">{{ $siteName }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
                    <button type="button" wire:click="closeRedeployAllModal" class="inline-flex items-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmRedeployAll"
                        wire:loading.attr="disabled"
                        wire:target="confirmRedeployAll"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="confirmRedeployAll">{{ __('Queue redeploys') }}</span>
                        <span wire:loading wire:target="confirmRedeployAll" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Queueing…') }}
                        </span>
                    </button>
                </div>
            </x-modal>
        @endif
    </x-slot>
</x-server-workspace-layout>
