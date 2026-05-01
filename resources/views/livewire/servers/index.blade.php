@php
    $stripe = function (\App\Models\Server $server): string {
        if ($server->scheduled_deletion_at) {
            return 'bg-orange-500';
        }
        if ($server->status === \App\Models\Server::STATUS_READY) {
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
        if ($server->status === \App\Models\Server::STATUS_PROVISIONING || $server->status === \App\Models\Server::STATUS_PENDING) {
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

<div wire:key="servers-epoch-{{ $serverListEpoch }}">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <x-dashboard-breadcrumb :current="__('Servers')" current-icon="server-stack" />

        @if (session('success'))
            <x-alert tone="success">{{ session('success') }}</x-alert>
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
                    <a
                        href="{{ route('launches.create') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Open launchpad') }}
                    </a>
                    <a
                        href="{{ route('docs.create-first-server') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-document-text class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ __('First server guide') }}
                    </a>
                @endcan
            </x-slot>
        </x-page-header>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                    <x-heroicon-o-server-stack class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('Servers') }}
                </div>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $summary['total'] }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                    <x-heroicon-o-check-circle class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('Ready') }}
                </div>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $summary['ready'] }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                    <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('Attention') }}
                </div>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $summary['attention'] }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                    <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('Sites') }}
                </div>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $summary['sites'] }}</p>
            </div>
        </div>

        @if ($hasServersInScope)
            <x-section-card padding="sm">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="resetFilters" class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            {{ __('Reset filters') }}
                        </button>
                        <div class="inline-flex rounded-xl border border-brand-ink/15 bg-brand-sand/30 p-0.5" role="group" aria-label="{{ __('View') }}">
                            <button
                                type="button"
                                wire:click="$set('viewMode', 'list')"
                                class="rounded-lg px-3 py-2 text-sm font-medium transition-colors {{ $viewMode === 'list' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:bg-white/80' }}"
                                aria-pressed="{{ $viewMode === 'list' ? 'true' : 'false' }}"
                            >
                                <span class="sr-only">{{ __('List') }}</span>
                                <x-heroicon-o-list-bullet class="h-5 w-5" aria-hidden="true" />
                            </button>
                            <button
                                type="button"
                                wire:click="$set('viewMode', 'grid')"
                                class="rounded-lg px-3 py-2 text-sm font-medium transition-colors {{ $viewMode === 'grid' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:bg-white/80' }}"
                                aria-pressed="{{ $viewMode === 'grid' ? 'true' : 'false' }}"
                            >
                                <span class="sr-only">{{ __('Grid') }}</span>
                                <x-heroicon-o-squares-2x2 class="h-5 w-5" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex flex-col gap-3 xl:flex-row xl:items-center">
                    <div class="flex flex-1 flex-col gap-3 md:flex-row md:items-center">
                        <div class="w-full md:max-w-sm">
                            <label for="servers_search" class="sr-only">{{ __('Search') }}</label>
                            <x-text-input id="servers_search" type="search" wire:model.live.debounce.300ms="search" class="block w-full" placeholder="{{ __('Search servers, IPs, or providers…') }}" autocomplete="off" />
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <label for="servers_status" class="sr-only">{{ __('Options') }}</label>
                            <x-select id="servers_status" wire:model.live="statusFilter" class="mt-0">
                                @foreach ($statusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-select>
                            <label for="servers_sort" class="sr-only">{{ __('Order by') }}</label>
                            <x-select id="servers_sort" wire:model.live="sort" class="mt-0">
                                @foreach ($sortOptions as $value => $label)
                                    <option value="{{ $value }}">{{ __($label) }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    </div>
                </div>
            </x-section-card>
        @endif

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
                            <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <x-heroicon-o-squares-2x2 class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span>
                                    <span class="font-semibold text-brand-ink">{{ __('Open launchpad') }}</span>
                                    <span class="text-brand-mist"> — </span>
                                    {{ __('Explore BYO, Docker, serverless, Kubernetes, and more.') }}
                                </span>
                            </li>
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
                                    href="{{ route('launches.create') }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-5 py-3 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                    {{ __('Open launchpad') }}
                                </a>
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
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink mb-4 pb-2 border-b border-brand-ink/10">
                                    {{ $groupLabel }}
                                    <span class="text-brand-moss font-normal">({{ $groupServers->count() }})</span>
                                </h2>
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
                                                    @if ($server->workspace)
                                                        <p class="mt-1 text-xs text-brand-moss">
                                                            {{ __('Project:') }}
                                                            <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                                                                {{ $server->workspace->name }}
                                                            </a>
                                                        </p>
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
                                                    @if ($server->status === \App\Models\Server::STATUS_READY)
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
                                <div class="px-4 sm:px-6 py-3 bg-brand-sand/30 border-b border-brand-ink/10">
                                    <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">
                                        {{ $groupLabel }}
                                        <span class="text-brand-moss font-normal">({{ $groupServers->count() }})</span>
                                    </h2>
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
                                                    </div>
                                                    <p class="mt-1 text-sm text-brand-moss">
                                                        {{ trans_choice(':count site|:count sites', $server->sites_count, ['count' => $server->sites_count]) }}
                                                        @if ($server->workspace)
                                                            <span class="text-brand-mist"> · </span>
                                                            {{ __('Project:') }}
                                                            <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                                                                {{ $server->workspace->name }}
                                                            </a>
                                                        @endif
                                                        @if ($server->status === \App\Models\Server::STATUS_READY)
                                                            <span class="text-brand-mist"> · </span>
                                                            {{ __('Online for :days days', ['days' => max(0, (int) $server->created_at->diffInDays(now()))]) }}
                                                        @endif
                                                        <span class="text-brand-mist"> · </span>
                                                        {{ $server->provider->label() }}
                                                        <span class="text-brand-mist"> · </span>
                                                        {{ $server->status }}
                                                        @if ($server->scheduled_deletion_at)
                                                            <span class="text-brand-mist"> · </span>
                                                            <span class="text-amber-800 font-medium">{{ __('Removal :date', ['date' => $server->scheduled_deletion_at->timezone(config('app.timezone'))->toFormattedDateString()]) }}</span>
                                                        @endif
                                                        @if ($server->status === 'ready')
                                                            @if ($server->health_status === 'reachable')
                                                                <span class="text-emerald-600"> · {{ __('Reachable') }}</span>
                                                            @elseif ($server->health_status === 'unreachable')
                                                                <span class="text-red-600"> · {{ __('Unreachable') }}</span>
                                                            @endif
                                                        @endif
                                                    </p>
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
</div>
