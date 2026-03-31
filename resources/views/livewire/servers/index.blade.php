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
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Servers') }}</li>
            </ol>
        </nav>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Servers') }}</h1>
                <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
                    {{ __('Manage servers in your workspace. Search, filter, and open a server to configure sites, SSH, and automation.') }}
                </p>
            </div>
            @can('create', App\Models\Server::class)
                <a href="{{ route('servers.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest shrink-0">
                    {{ __('Create server') }}
                </a>
            @endcan
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">{{ session('success') }}</div>
        @endif

        @if (! $hasServersInScope)
            <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm p-10 text-center text-sm text-brand-moss">
                <p class="mb-4">{{ __('No servers yet. Connect a provider or add an existing server.') }}</p>
                @can('create', App\Models\Server::class)
                    <a href="{{ route('servers.create') }}" wire:navigate class="font-semibold text-brand-sage hover:text-brand-ink">{{ __('Add your first server') }}</a>
                @endcan
            </div>
        @else
            <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center sm:flex-wrap">
                        <div class="w-full sm:max-w-xs">
                            <label for="servers_search" class="sr-only">{{ __('Search') }}</label>
                            <x-text-input id="servers_search" type="search" wire:model.live.debounce.300ms="search" class="block w-full" placeholder="{{ __('Search…') }}" autocomplete="off" />
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <label for="servers_status" class="sr-only">{{ __('Options') }}</label>
                            <select
                                id="servers_status"
                                wire:model.live="statusFilter"
                                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            >
                                @foreach ($statusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <label for="servers_sort" class="sr-only">{{ __('Order by') }}</label>
                            <select
                                id="servers_sort"
                                wire:model.live="sort"
                                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            >
                                @foreach ($sortOptions as $value => $label)
                                    <option value="{{ $value }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 lg:justify-end">
                        <button type="button" wire:click="resetFilters" class="text-sm font-medium text-brand-sage hover:text-brand-ink">
                            {{ __('Reset filters') }}
                        </button>
                        <div class="inline-flex rounded-lg border border-brand-ink/15 p-0.5 bg-white" role="group" aria-label="{{ __('View') }}">
                            <button
                                type="button"
                                wire:click="$set('viewMode', 'list')"
                                class="rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors {{ $viewMode === 'list' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:text-brand-ink' }}"
                                aria-pressed="{{ $viewMode === 'list' ? 'true' : 'false' }}"
                            >
                                <span class="sr-only">{{ __('List') }}</span>
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.008v.008H3.75V6.75zm0 5.25h.008v.008H3.75v-.008zm0 5.25h.008v.008H3.75v-.008z"/></svg>
                            </button>
                            <button
                                type="button"
                                wire:click="$set('viewMode', 'grid')"
                                class="rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors {{ $viewMode === 'grid' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:text-brand-ink' }}"
                                aria-pressed="{{ $viewMode === 'grid' ? 'true' : 'false' }}"
                            >
                                <span class="sr-only">{{ __('Grid') }}</span>
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25A2.25 2.25 0 018.25 8.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 8.25h-2.25A2.25 2.25 0 0113.5 6V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                @if ($groupedServers->flatten()->isEmpty())
                    <div class="px-6 py-14 text-center text-sm text-brand-moss">
                        {{ __('No servers match your filters.') }}
                    </div>
                @elseif ($viewMode === 'grid')
                    <div class="p-4 sm:p-6 space-y-10">
                        @foreach ($groupedServers as $groupLabel => $groupServers)
                            <div>
                                <h2 class="text-sm font-semibold text-brand-ink mb-4 pb-2 border-b border-brand-ink/10">
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
                                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg>
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
                    <div class="divide-y divide-brand-ink/10">
                        @foreach ($groupedServers as $groupLabel => $groupServers)
                            <div wire:key="group-{{ \Illuminate\Support\Str::slug($groupLabel) }}">
                                <div class="px-4 sm:px-6 py-3 bg-brand-sand/30 border-b border-brand-ink/10">
                                    <h2 class="text-sm font-semibold text-brand-ink">
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
