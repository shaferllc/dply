@php
    // Dedicated cache-role boxes (server_role redis/valkey) badge the workspace
    // with the engine name itself; co-located caches on app servers keep the
    // generic "Caches" title since they may run several engines side-by-side.
    $cachesRole = (string) ($server->meta['server_role'] ?? '');
    $cachesTitle = match ($cachesRole) {
        'redis' => __('Redis'),
        'valkey' => __('Valkey'),
        default => __('Caches'),
    };
@endphp
<x-server-workspace-layout
    :server="$server"
    active="caches"
    :title="$cachesTitle"
    :description="__('Install and manage cache services on this server — Redis, Valkey, Memcached, KeyDB, and Dragonfly. Multiple engines side-by-side are supported.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($manageRemoteTaskId)
        {{-- Polls the cache row written by ServerManageRemoteSshJob so the success
             toast for Show Redis INFO fires when the queued task finishes. The
             ConsoleAction banner partial inside the Stats subtab handles the
             output stream independently via its own wire:poll. --}}
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif

    {{-- Reverb subscribe context for the live MONITOR tail. JS in bootstrap.js
         picks up the data-attrs and (un)subscribes accordingly. The 1s wire:poll
         fallback inside the monitor card keeps things working when Reverb is off
         or events are missed. --}}
    <div
        id="dply-server-cache-monitor-context"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $monitorRunId !== '' ? '1' : '0' }}"
        class="hidden"
        aria-hidden="true"
    ></div>

    {{-- Top-of-page console banner — Debug, Restart/Stop/Start/Disable/Enable
         and the rest stream into the shared ConsoleAction partial here so it
         lives next to the page header alongside every other workspace's banner
         (PHP / Manage / Databases / etc.). The banner shows the most recent
         run for the currently active engine tab; the redundant per-engine
         render inside engine-panel.blade.php was removed so the operator
         doesn't have to scroll past the status grid to see action output. --}}
    @php
        $topConsoleRun = isset($cacheRunsByEngine[$workspace_tab])
            ? $cacheRunsByEngine[$workspace_tab]
            : ($manageActionRun ?? null);
    @endphp
    @if ($topConsoleRun)
        @include('livewire.partials.console-action-banner-static', [
            'run' => $topConsoleRun,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ])
    @endif

    @if ($opsReady)
        @if (! $capabilitiesLoaded)
            {{-- Probe installed cache engines off the render path so the workspace paints
                 instantly; per-engine badges + install gates appear once it returns. --}}
            <div wire:init="loadCacheCapabilities" class="hidden" aria-hidden="true"></div>
        @endif

        @if ($cacheBusy)
            @include('livewire.servers.partials.cache._banner')
        @endif

        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
            <x-server-workspace-tablist :aria-label="__('Cache workspace sections')" scroll class="sm:min-w-0 sm:flex-1">
                <x-server-workspace-tab
                    id="cache-tab-overview"
                    :active="$workspace_tab === 'overview'"
                    wire:click="setWorkspaceTab('overview')"
                    icon="heroicon-o-bolt"
                >
                    {{ __('Overview') }}
                </x-server-workspace-tab>
                @foreach ($engines as $engine)
                    @php
                        $row = $cacheServicesByEngine[$engine] ?? null;
                        $isInFlight = $row && in_array($row->status, [
                            \App\Models\ServerCacheService::STATUS_PENDING,
                            \App\Models\ServerCacheService::STATUS_INSTALLING,
                            \App\Models\ServerCacheService::STATUS_UNINSTALLING,
                        ], true);
                    @endphp
                    <x-server-workspace-tab
                        :id="'cache-tab-'.$engine"
                        :active="$workspace_tab === $engine"
                        wire:click="setWorkspaceTab('{{ $engine }}')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center" wire:loading.remove wire:target="setWorkspaceTab('{{ $engine }}')">
                                @switch($engine)
                                    @case('memcached')
                                        <x-heroicon-o-archive-box class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        @break
                                    @case('valkey')
                                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-sky-600" aria-hidden="true" />
                                        @break
                                    @case('keydb')
                                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-violet-600" aria-hidden="true" />
                                        @break
                                    @case('dragonfly')
                                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                                        @break
                                    @default
                                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
                                @endswitch
                            </span>
                            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center" wire:loading wire:target="setWorkspaceTab('{{ $engine }}')">
                                <x-spinner class="h-4 w-4" />
                            </span>
                            {{ $engineLabels[$engine] }}
                            @if (($comingSoonEngines[$engine] ?? false) && ! $row)
                                <span class="inline-flex items-center rounded-full bg-brand-sand/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Soon') }}</span>
                            @elseif ($row)
                                @if ($isInFlight)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700">
                                        <x-spinner variant="forest" />
                                        {{ __('Working') }}
                                    </span>
                                @elseif ($row->status === \App\Models\ServerCacheService::STATUS_FAILED)
                                    <span class="inline-flex items-center rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">{{ __('Failed') }}</span>
                                @elseif ($row->status === \App\Models\ServerCacheService::STATUS_RUNNING)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('Running') }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">{{ ucfirst($row->status) }}</span>
                                @endif
                            @endif
                        </span>
                    </x-server-workspace-tab>
                @endforeach
                <x-server-workspace-tab
                    id="cache-tab-advanced"
                    :active="$workspace_tab === 'advanced'"
                    wire:click="setWorkspaceTab('advanced')"
                    icon="heroicon-o-wrench-screwdriver"
                >
                    {{ __('Advanced') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 sm:pb-0.5">
                <x-dropdown align="right" width="w-80" contentClasses="py-1.5">
                    <x-slot name="trigger">
                        <button
                            type="button"
                            aria-label="{{ __('Workspace actions') }}"
                            aria-haspopup="true"
                            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <span wire:loading.remove wire:target="refreshCacheCapabilities">{{ __('Actions') }}</span>
                            <span wire:loading wire:target="refreshCacheCapabilities" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" />
                                {{ __('Working…') }}
                            </span>
                            <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-brand-ink/70" />
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <button
                            type="button"
                            wire:click="refreshCacheCapabilities"
                            wire:loading.attr="disabled"
                            wire:target="refreshCacheCapabilities"
                            class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span class="block font-medium">
                                <span wire:loading.remove wire:target="refreshCacheCapabilities">{{ __('Refresh data') }}</span>
                                <span wire:loading wire:target="refreshCacheCapabilities">{{ __('Refreshing…') }}</span>
                            </span>
                            <span class="mt-0.5 block text-xs leading-snug text-brand-mist">{{ __('Re-runs engine detection, distro probe, and per-engine stats over SSH. Results are cached for 24 hours; use this whenever you want live numbers or after installing/removing something on the box.') }}</span>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>

        <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setWorkspaceTab">

        @if ($workspace_tab === 'overview')
            <x-server-workspace-tab-panel
                id="cache-panel-overview"
                labelled-by="cache-tab-overview"
                panel-class="space-y-8"
            >
                @include('livewire.servers.partials.cache.overview-tab')
            </x-server-workspace-tab-panel>
        @endif

        @foreach ($engines as $engine)
            @if ($workspace_tab === $engine)
                <x-server-workspace-tab-panel
                    :id="'cache-panel-'.$engine"
                    :labelled-by="'cache-tab-'.$engine"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cache.engine-panel', compact('engine'))
                </x-server-workspace-tab-panel>
            @endif
        @endforeach

        @if ($workspace_tab === 'advanced')
            <x-server-workspace-tab-panel
                id="cache-panel-advanced"
                labelled-by="cache-tab-advanced"
                panel-class="space-y-8"
            >
                @include('livewire.servers.partials.cache.advanced-tab')
            </x-server-workspace-tab-panel>
        @endif

        </div>

        @include('livewire.servers.partials.cache.status-modal')
    @else
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <x-icon-badge tone="amber">
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before you can use this section.') }}</p>
                </div>
            </div>
        </section>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</x-server-workspace-layout>
