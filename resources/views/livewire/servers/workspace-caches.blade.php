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
    hide-hero
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

    @if ($opsReady && ! $capabilitiesLoaded)
        {{-- Probe installed cache engines off the render path so the workspace paints
             instantly; per-engine badges + install gates appear once it returns. --}}
        <div wire:init="loadCacheCapabilities" class="hidden" aria-hidden="true"></div>
    @endif

    @if ($opsReady && $cacheBusy)
        @include('livewire.servers.partials.cache._banner')
    @endif

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching the rest of the workspace. --}}
        @php
            $cachesInstalledCount = collect($cacheServicesByEngine ?? [])->filter()->count();
        @endphp
        <x-workspace-panel-head
            dense
            icon="heroicon-o-bolt"
            :title="$cachesTitle"
            :count="$cachesInstalledCount > 0
                ? trans_choice('{1} :count engine|[2,*] :count engines', $cachesInstalledCount, ['count' => $cachesInstalledCount])
                : null"
            :note="__('Install and manage cache engines on this server — Redis, Valkey, Memcached, KeyDB, and Dragonfly. Multiple engines can run side-by-side.')"
            class="border-b border-brand-ink/10"
        >
            @if ($opsReady)
                <x-slot:actions>
                        <x-dropdown align="right" width="w-80" contentClasses="py-1.5">
                            <x-slot name="trigger">
                                <button
                                    type="button"
                                    aria-label="{{ __('Workspace actions') }}"
                                    aria-haspopup="true"
                                    class="inline-flex h-6 shrink-0 items-center justify-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <span wire:loading.remove wire:target="refreshCacheCapabilities">{{ __('Actions') }}</span>
                                    <span wire:loading wire:target="refreshCacheCapabilities" class="inline-flex items-center gap-1">
                                        <x-spinner variant="forest" />
                                        {{ __('Working…') }}
                                    </span>
                                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 text-brand-ink/70" aria-hidden="true" />
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
                </x-slot:actions>
            @endif
        </x-workspace-panel-head>

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Cache workspace sections')" scroll bare class="!mb-0">
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
                        {{-- Icon only — no spinner here. x-server-workspace-tab
                             already renders one for any tab with a wire:target,
                             so a hand-rolled one made every engine tab show two
                             spinners side by side while it loaded. --}}
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
        </div>

        {{-- One skeleton per tab, each targeting the call WITH its argument.
             The shared stub matched none of the three shapes (engine cards vs
             a status strip + connection panels vs a settings list), so the
             panel resized on every switch. Engine tabs share one shape. --}}
        @foreach (array_merge(['overview'], $engines, ['advanced']) as $skeletonTab)
            <div class="hidden" wire:loading.class.remove="hidden" wire:target="setWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                @include('livewire.servers.partials.cache._tab-skeleton', [
                    'tab' => in_array($skeletonTab, ['overview', 'advanced'], true) ? $skeletonTab : 'engine',
                    'rows' => count($engines),
                ])
            </div>
        @endforeach

        <div wire:loading.class="hidden" wire:target="setWorkspaceTab">
            @if (! $opsReady)
                <div class="px-4 py-3.5 sm:px-5">
                    @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
                </div>
            @else
                @if ($workspace_tab === 'overview')
                    @include('livewire.servers.partials.cache.overview-tab')
                @endif

                @foreach ($engines as $engine)
                    @if ($workspace_tab === $engine)
                        @include('livewire.servers.partials.cache.engine-panel', compact('engine'))
                    @endif
                @endforeach

                @if ($workspace_tab === 'advanced')
                    @include('livewire.servers.partials.cache.advanced-tab')
                @endif
            @endif
        </div>
    </section>

    @include('livewire.servers.partials.cache.status-modal')

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</x-server-workspace-layout>
