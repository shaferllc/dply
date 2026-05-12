@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
    $engines = ['redis', 'valkey', 'memcached', 'keydb', 'dragonfly'];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="caches"
    :title="__('Caches')"
    :description="__('Install and manage cache services on this server — Redis, Valkey, Memcached, KeyDB, and Dragonfly. Multiple engines side-by-side are supported.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

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

    <x-explainer class="mb-4">
        <p>{{ __('This workspace manages cache engines installed on this server via apt + systemd — Redis, Valkey, Memcached, KeyDB, and Dragonfly. Multiple engines can run side-by-side (e.g. Redis for queues, Memcached for app cache).') }}</p>
        <p>{{ __('It is independent of how apps deployed here are configured to use cache: this page installs and operates the server, not your app\'s client code. The engine badges are read live from the server; install state lives in the dply database.') }}</p>
    </x-explainer>

    @if ($opsReady)
        @php
            // Any engine in flight on this server. Used to render the global "an apt operation
            // is running" banner and to disable other mutating actions across all engines.
            $busyService = $cacheServices->first(fn ($row) => in_array($row->status, [
                \App\Models\ServerCacheService::STATUS_PENDING,
                \App\Models\ServerCacheService::STATUS_INSTALLING,
                \App\Models\ServerCacheService::STATUS_UNINSTALLING,
            ], true));
            $cacheBusy = $busyService !== null;
        @endphp

        @if ($cacheBusy)
            @php
                $busyEngineLabel = $engineLabels[$busyService->engine] ?? ucfirst($busyService->engine);
                $busyMessage = match ($busyService->status) {
                    \App\Models\ServerCacheService::STATUS_PENDING => __('Queued — :engine install will start shortly…', ['engine' => $busyEngineLabel]),
                    \App\Models\ServerCacheService::STATUS_INSTALLING => __('Installing :engine on the server…', ['engine' => $busyEngineLabel]),
                    \App\Models\ServerCacheService::STATUS_UNINSTALLING => __('Uninstalling :engine from the server…', ['engine' => $busyEngineLabel]),
                    default => __('Working on :engine…', ['engine' => $busyEngineLabel]),
                };
                $busyOutput = (string) ($busyService->install_output ?? '');
            @endphp
            {{-- Polling element only mounts while a job is in flight. The moment status leaves the
                 in-flight set, this disappears and polling stops. --}}
            <div wire:poll.4s class="hidden" aria-hidden="true"></div>
            <div class="mb-4 overflow-hidden rounded-xl border border-sky-200 bg-sky-50/80 text-sm text-sky-900 shadow-sm" role="status" aria-live="polite" x-data="{ expanded: false }">
                <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/70 ring-1 ring-sky-200">
                            <x-spinner variant="forest" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold leading-tight">{{ $busyMessage }}</p>
                            <p class="mt-0.5 truncate text-xs text-sky-700/80">{{ __('Refreshing every 4s · safe to leave this page — the job runs on the queue. Other engines stay paused while apt runs.') }}</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                        @if ($busyService->status === \App\Models\ServerCacheService::STATUS_PENDING)
                            <button
                                type="button"
                                wire:click="cancelCacheServiceChange('{{ $busyService->engine }}')"
                                wire:loading.attr="disabled"
                                wire:target="cancelCacheServiceChange"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-rose-300/70 bg-white px-2.5 py-1.5 text-xs font-medium text-rose-700 shadow-sm hover:bg-rose-50 disabled:opacity-50"
                                title="{{ __('The job has not started apt yet — safe to cancel.') }}"
                            >
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                                <span wire:loading.remove wire:target="cancelCacheServiceChange">{{ __('Cancel') }}</span>
                                <span wire:loading wire:target="cancelCacheServiceChange">{{ __('Cancelling…') }}</span>
                            </button>
                        @elseif ($busyService->status === \App\Models\ServerCacheService::STATUS_INSTALLING && $busyService->cancel_requested_at === null)
                            @php
                                $hasOtherInstancesOfBusyEngine = \App\Models\ServerCacheService::query()
                                    ->where('server_id', $this->server->id)
                                    ->where('engine', $busyService->engine)
                                    ->where('id', '!=', $busyService->id)
                                    ->exists();
                                $cancelMessage = $hasOtherInstancesOfBusyEngine
                                    ? __('Stops the install at the next output chunk, removes this instance\'s systemd unit and config, and deletes the row. The :engine package stays installed because another instance is still using it — uninstall the other instance(s) first if you want it gone.', ['engine' => $busyService->engine])
                                    : __('Stops the install at the next output chunk, runs dpkg --configure -a + apt purge to clean up whatever apt left behind, and removes the row. Apt-purge takes a minute or two.');
                            @endphp
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('cancelCacheServiceChange', ['{{ $busyService->engine }}'], @js(__('Stop install and revert?')), @js($cancelMessage), @js(__('Stop and revert')), true)"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-rose-300/70 bg-white px-2.5 py-1.5 text-xs font-medium text-rose-700 shadow-sm hover:bg-rose-50"
                                title="{{ __('Stop the install and revert this instance.') }}"
                            >
                                <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                                {{ __('Stop & revert') }}
                            </button>
                        @elseif (($busyService->status === \App\Models\ServerCacheService::STATUS_INSTALLING && $busyService->cancel_requested_at !== null) || $busyService->status === \App\Models\ServerCacheService::STATUS_UNINSTALLING)
                            @php
                                // After 60s of "Cancelling — reverting…" / "Uninstalling…" with no
                                // progress, surface a break-glass "Force cancel" affordance. The
                                // soft-cancel path only observes the flag when the install/uninstall
                                // job emits output; an apt-get hung on a dpkg lock, an SSH session
                                // that stopped streaming, or a dead queue worker will never trip
                                // the check. Force-cancel marks the row failed without consulting
                                // the job — operator confirms in a modal with the caveat that
                                // server-side state may be partial.
                                //
                                // The staleness clock is `cancel_requested_at` when set (install
                                // being cancelled), otherwise the row's `updated_at` (uninstall
                                // that's stopped streaming). Either way, 60s without progress is
                                // long enough to give the soft path a fair chance to exit first.
                                $stalenessRef = $busyService->cancel_requested_at ?? $busyService->updated_at;
                                $cancelStale = $stalenessRef !== null
                                    && $stalenessRef->diffInSeconds(now()) >= 60;
                                $forceConfirmCopy = __(
                                    'This operation hasn\'t made progress for over a minute. Force remove deletes the row outright so you can start fresh — server-side state may be partial. Run apt purge / systemctl disable manually if anything was already installed. Continue?'
                                );
                                $busyPillCopy = $busyService->status === \App\Models\ServerCacheService::STATUS_UNINSTALLING
                                    ? __('Uninstalling…')
                                    : __('Cancelling — reverting…');
                            @endphp
                            <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-rose-300/70 bg-rose-50 px-2.5 py-1.5 text-xs font-medium text-rose-700">
                                <x-spinner variant="forest" />
                                {{ $busyPillCopy }}
                            </span>
                            @if ($cancelStale)
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('forceCancelCacheServiceChange', ['{{ $busyService->engine }}'], @js(__('Force remove row?')), @js($forceConfirmCopy), @js(__('Force remove')), true)"
                                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-rose-400 bg-rose-100 px-2.5 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-200"
                                    title="{{ __('No progress observed for over 60s — remove the row and clean up the server manually.') }}"
                                >
                                    <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                    {{ __('Force remove') }}
                                </button>
                            @endif
                        @endif
                        <button
                            type="button"
                            x-on:click="expanded = !expanded"
                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-sky-200 bg-white px-2.5 py-1.5 text-xs font-medium text-sky-900 shadow-sm hover:bg-sky-50"
                            x-bind:aria-expanded="expanded.toString()"
                        >
                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5 transition-transform" x-bind:class="expanded ? 'rotate-180' : ''" />
                            <span x-text="expanded ? @js(__('Hide output')) : @js(__('View output'))"></span>
                        </button>
                    </div>
                </div>
                <div x-show="expanded" x-cloak class="border-t border-sky-200 bg-white/70 px-4 py-3">
                    @if (trim($busyOutput) === '')
                        <p class="text-xs text-sky-800/80">{{ __('No output yet — the worker may still be picking up the job. This refreshes every 4s.') }}</p>
                    @else
                        <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100" x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">{{ $busyOutput }}</pre>
                    @endif
                </div>
            </div>
        @endif

        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
            <x-server-workspace-tablist :aria-label="__('Cache workspace sections')" class="sm:min-w-0 sm:flex-1">
                <x-server-workspace-tab
                    id="cache-tab-overview"
                    :active="$workspace_tab === 'overview'"
                    wire:click="setWorkspaceTab('overview')"
                >
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Overview') }}
                    </span>
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
                            {{ $engineLabels[$engine] }}
                            @if ($row)
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
                >
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Advanced') }}
                    </span>
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
                                <span wire:loading.remove wire:target="refreshCacheCapabilities">{{ __('Recheck engines') }}</span>
                                <span wire:loading wire:target="refreshCacheCapabilities">{{ __('Rechecking…') }}</span>
                            </span>
                            <span class="mt-0.5 block text-xs leading-snug text-brand-mist">{{ __('Re-runs engine detection over SSH. Use this if you installed or removed something on the box and the badges look stale; detection is cached for a few minutes.') }}</span>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>

        <div class="relative">

        {{-- ============================================================================
             OVERVIEW TAB — list every installed engine as its own card. Empty state when
             nothing is installed; otherwise one card per engine with status, version,
             port, and per-engine action buttons.
             ============================================================================ --}}
        <x-server-workspace-tab-panel
            id="cache-panel-overview"
            labelled-by="cache-tab-overview"
            :hidden="$workspace_tab !== 'overview'"
            panel-class="space-y-8"
        >
            @if ($cacheServices->isEmpty())
                <div class="{{ $card }} p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('No cache services installed') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Pick an engine from the tabs above to install one. You can install multiple engines side-by-side — for example Redis for queues and Memcached for app cache.') }}
                    </p>
                </div>
            @else
                @foreach ($cacheServices as $row)
                    @php
                        $engineLabel = $engineLabels[$row->engine] ?? ucfirst($row->engine);
                        $rowInFlight = in_array($row->status, [
                            \App\Models\ServerCacheService::STATUS_PENDING,
                            \App\Models\ServerCacheService::STATUS_INSTALLING,
                            \App\Models\ServerCacheService::STATUS_UNINSTALLING,
                        ], true);
                    @endphp
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-center gap-3">
                            <h2 class="text-lg font-semibold text-brand-ink">
                                {{ $engineLabel }}
                                @if (! $row->isDefaultInstance())
                                    <span class="text-brand-mist">/</span>
                                    <span class="font-mono text-sm text-brand-moss">{{ $row->name }}</span>
                                @endif
                            </h2>
                            @switch($row->status)
                                @case(\App\Models\ServerCacheService::STATUS_RUNNING)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Running') }}</span>
                                    @break
                                @case(\App\Models\ServerCacheService::STATUS_STOPPED)
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Stopped') }}</span>
                                    @break
                                @case(\App\Models\ServerCacheService::STATUS_PENDING)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                        <x-spinner variant="forest" /> {{ __('Queued…') }}
                                    </span>
                                    @break
                                @case(\App\Models\ServerCacheService::STATUS_INSTALLING)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                        <x-spinner variant="forest" /> {{ __('Installing…') }}
                                    </span>
                                    @break
                                @case(\App\Models\ServerCacheService::STATUS_UNINSTALLING)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                        <x-spinner variant="forest" /> {{ __('Uninstalling…') }}
                                    </span>
                                    @break
                                @case(\App\Models\ServerCacheService::STATUS_FAILED)
                                    <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700" title="{{ $row->error_message }}">{{ __('Failed') }}</span>
                                    @break
                                @default
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-medium text-brand-ink">{{ ucfirst($row->status) }}</span>
                            @endswitch
                            <a
                                href="#"
                                wire:click.prevent="setWorkspaceTab('{{ $row->engine }}'); setActiveInstance('{{ $row->name }}')"
                                class="ml-auto text-xs font-medium text-brand-forest hover:underline"
                            >{{ __('Open :engine workspace →', ['engine' => $engineLabel]) }}</a>
                        </div>

                        <dl class="mt-6 grid gap-4 sm:grid-cols-3">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                                <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $row->version ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Port') }}</dt>
                                <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $row->port }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                                <dd class="mt-1 text-sm text-brand-ink">{{ ucfirst($row->status) }}</dd>
                            </div>
                        </dl>

                        @if ($row->status === \App\Models\ServerCacheService::STATUS_FAILED && filled($row->error_message))
                            <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">
                                {{ $row->error_message }}
                            </p>
                        @endif

                        @php $stats = $cacheStatsByInstance[$row->engine][$row->name] ?? []; @endphp
                        @if (! empty($stats))
                            <dl class="mt-4 grid gap-4 rounded-xl border border-brand-ink/10 bg-brand-sand/40 p-4 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ($stats as $label => $value)
                                    <div>
                                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</dt>
                                        <dd class="mt-1 font-mono text-xs text-brand-ink">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                @endforeach

                @foreach ($cacheServices as $row)
                    @if (\App\Models\ServerCacheService::engineSupportsAuth($row->engine) || $row->engine === 'memcached')
                        @include('livewire.servers.partials.cache-connection-snippet', [
                            'cacheService' => $row,
                            'card' => $card,
                            'engineLabels' => $engineLabels,
                        ])
                    @endif
                @endforeach
            @endif
        </x-server-workspace-tab-panel>

        {{-- ============================================================================
             PER-ENGINE TABS — each is independent. Three states:
               1) not installed: rich engine info card + Install button
               2) in flight: status banner (the global busy banner above already covers details)
               3) installed: status, actions, AUTH/memory/config (where applicable)
             ============================================================================ --}}
        @foreach ($engines as $engine)
            @php
                $engineInstances = $cacheInstancesByEngine->get($engine, collect());
                // Multi-instance lookup: use the active-instance name first;
                // fall back to default (legacy single-instance) if the active
                // instance doesn't exist for this engine; fall back to any
                // installed instance after that.
                $row = $engineInstances->get($active_instance)
                    ?? $engineInstances->get(\App\Models\ServerCacheService::DEFAULT_INSTANCE_NAME)
                    ?? $engineInstances->first();
                $info = \App\Support\Servers\CacheEngineInfo::for($engine);
                $rowInFlight = $row && in_array($row->status, [
                    \App\Models\ServerCacheService::STATUS_PENDING,
                    \App\Models\ServerCacheService::STATUS_INSTALLING,
                    \App\Models\ServerCacheService::STATUS_UNINSTALLING,
                ], true);
                // Per-instance probe — uses the row's actual port instead of the
                // engine-level aggregate which hardcoded 6379 and produced
                // false-negatives on non-default-port instances (e.g. KeyDB on 6382).
                // Falls back to the engine-level signal when there's no row yet.
                $probeRunning = $row
                    ? app(\App\Support\Servers\ServerCacheServiceHostCapabilities::class)
                        ->probeInstance($server, $engine, (int) $row->port)
                    : (bool) ($capabilities[$engine] ?? false);
            @endphp
            <x-server-workspace-tab-panel
                :id="'cache-panel-'.$engine"
                :labelled-by="'cache-tab-'.$engine"
                :hidden="$workspace_tab !== $engine"
                panel-class="space-y-8"
            >
                {{-- Sub-tab strip + content. Three top-level states share one tab structure:
                     - Not installed → Overview = install affordance, Info = description.
                     - In flight on this engine → Overview = status note, Info = description.
                     - Installed → Overview / Info / Console / Stats / Configure.
                     Same allowlist + fallback logic used in every branch so `engine_subtab`
                     never lands the operator on a hidden panel. --}}
                @if (! $row || $rowInFlight)
                    @php
                        // Pre-install / in-flight: only Overview + Info make sense — there's
                        // no daemon to console-into, no stats to compute, no config file to
                        // edit. Caches's installed branch picks up the longer list below.
                        $availableSubtabs = ['overview', 'info'];
                        $activeSubtab = in_array($engine_subtab, $availableSubtabs, true) ? $engine_subtab : 'overview';
                    @endphp

                    <x-server-workspace-tablist :aria-label="__(':engine workspace sections', ['engine' => $info['label']])">
                        <x-server-workspace-tab
                            :id="'cache-subtab-'.$engine.'-overview'"
                            :active="$activeSubtab === 'overview'"
                            wire:click="setEngineSubtab('overview')"
                        >
                            <span class="inline-flex items-center gap-2">
                                <x-heroicon-o-presentation-chart-line class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Overview') }}
                            </span>
                        </x-server-workspace-tab>
                        <x-server-workspace-tab
                            :id="'cache-subtab-'.$engine.'-info'"
                            :active="$activeSubtab === 'info'"
                            wire:click="setEngineSubtab('info')"
                        >
                            <span class="inline-flex items-center gap-2">
                                <x-heroicon-o-information-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Info') }}
                            </span>
                        </x-server-workspace-tab>
                    </x-server-workspace-tablist>

                    @if ($activeSubtab === 'info')
                        @include('livewire.servers.partials.cache-engine-info-card', [
                            'info' => $info,
                            'row' => $row,
                            'card' => $card,
                        ])
                    @elseif (! $row)
                        {{-- Overview when not installed: the install affordance. --}}
                        <div class="{{ $card }} p-6 sm:p-8">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Install :engine', ['engine' => $info['label']]) }}</h3>
                            <p class="mt-2 text-sm text-brand-moss">{{ __('Runs apt + systemctl over SSH; takes a few minutes on a small box. Other engines on this server are not affected.') }}</p>
                            @if ($cacheBusy)
                                <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                                    <p class="flex items-start gap-2">
                                        <x-spinner variant="forest" class="mt-0.5 shrink-0" />
                                        <span>{{ __('Apt is busy with another cache change — wait for the running operation to finish before installing :new.', ['new' => $info['label']]) }}</span>
                                    </p>
                                </div>
                            @else
                                <div class="mt-4 flex flex-wrap items-center gap-3">
                                    <button
                                        type="button"
                                        wire:click="installCacheService('{{ $engine }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="installCacheService"
                                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                                    >
                                        <x-heroicon-o-cloud-arrow-down class="h-4 w-4" />
                                        <span wire:loading.remove wire:target="installCacheService">{{ __('Install :engine', ['engine' => $info['label']]) }}</span>
                                        <span wire:loading wire:target="installCacheService">{{ __('Queueing install…') }}</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @else
                        {{-- Overview when in-flight: small status note pointing at the
                             top-of-page console banner for live details. --}}
                        <div class="{{ $card }} p-6 sm:p-8">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ $engineLabels[$engine] }}</h3>
                            <p class="mt-2 text-sm text-brand-moss">
                                {{ __(':engine is changing — see the progress banner above for live status and output.', ['engine' => $engineLabels[$engine]]) }}
                            </p>
                        </div>
                    @endif
                @else
                    @php
                        $isRedisFamily = \App\Models\ServerCacheService::engineSupportsAuth($row->engine);
                        // 'info' tab is universal — every engine has a catalog entry. Subtab order
                        // reads left-to-right: Overview (status/actions), Info (license/wire
                        // protocol/links), Console + Stats (Redis-family only), Configure.
                        $availableSubtabs = $isRedisFamily
                            ? ['overview', 'info', 'console', 'stats', 'configure']
                            : ['overview', 'info', 'configure'];
                        $activeSubtab = in_array($engine_subtab, $availableSubtabs, true) ? $engine_subtab : 'overview';
                    @endphp

                    {{-- Always-visible current-instance banner. The chip row below lets the
                         operator switch instances, but the operator's mental model is "which
                         instance is selected RIGHT NOW" — a glance-level indicator above the
                         chips removes the guess-work. Memcached is single-instance so we
                         skip the banner for it (the chip row also hides). --}}
                    @if ($isRedisFamily)
                        <div class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-brand-forest/30 bg-brand-sage/10 px-4 py-2.5 text-sm">
                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-brand-forest/15 text-brand-forest" aria-hidden="true">
                                <x-heroicon-o-cursor-arrow-rays class="h-3.5 w-3.5" />
                            </span>
                            <span class="text-brand-moss">{{ __('Currently viewing instance') }}</span>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-forest px-2.5 py-0.5 font-mono text-xs font-semibold text-white shadow-sm">
                                {{ $active_instance }}
                                <span class="font-mono text-[10px] text-white/80">:{{ $row->port }}</span>
                            </span>
                            <span class="text-xs text-brand-mist">{{ __('— actions on this page affect this instance only.') }}</span>
                        </div>
                    @endif

                    {{-- Instance chip row — visible across all subtabs so switching instances doesn't
                         require navigating back to Overview. Memcached is single-instance for v1, so
                         the row is hidden for it. The default instance is pinned to the front; named
                         instances follow. --}}
                    @if ($isRedisFamily)
                        <div class="mb-4 flex flex-wrap items-center gap-2">
                            @foreach ($engineInstances as $inst)
                                @php
                                    $isActive = $inst->name === $active_instance;
                                    $instInFlight = in_array($inst->status, [
                                        \App\Models\ServerCacheService::STATUS_PENDING,
                                        \App\Models\ServerCacheService::STATUS_INSTALLING,
                                        \App\Models\ServerCacheService::STATUS_UNINSTALLING,
                                    ], true);
                                @endphp
                                <button
                                    type="button"
                                    wire:click="setActiveInstance('{{ $inst->name }}')"
                                    @class([
                                        'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                                        'border-brand-forest bg-brand-forest text-white shadow-sm' => $isActive,
                                        'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $isActive,
                                    ])
                                    title="{{ __('Switch to instance :name on port :port', ['name' => $inst->name, 'port' => $inst->port]) }}"
                                >
                                    <span class="font-mono">{{ $inst->name }}</span>
                                    <span @class([
                                        'font-mono text-[10px]',
                                        'text-white/80' => $isActive,
                                        'text-brand-mist' => ! $isActive,
                                    ])>:{{ $inst->port }}</span>
                                    @if ($instInFlight)
                                        <x-spinner variant="forest" />
                                    @elseif ($inst->status === \App\Models\ServerCacheService::STATUS_RUNNING)
                                        <span @class([
                                            'h-1.5 w-1.5 rounded-full',
                                            'bg-emerald-300' => $isActive,
                                            'bg-emerald-500' => ! $isActive,
                                        ])></span>
                                    @elseif ($inst->status === \App\Models\ServerCacheService::STATUS_FAILED)
                                        <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                    @elseif ($inst->status === \App\Models\ServerCacheService::STATUS_STOPPED)
                                        <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                                    @else
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                    @endif
                                </button>
                            @endforeach

                            @if (! $cacheBusy)
                                <button
                                    type="button"
                                    wire:click="openAddInstanceForm"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-brand-ink/25 px-3 py-1.5 text-xs font-medium text-brand-moss hover:border-brand-forest hover:bg-brand-sand/40 hover:text-brand-ink"
                                    title="{{ __('Install another instance of :engine on a different port', ['engine' => $engineLabels[$engine]]) }}"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                    {{ __('Add instance') }}
                                </button>
                            @endif
                        </div>

                        @if ($showAddInstanceForm)
                            <div class="{{ $card }} mb-4 p-5">
                                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Add another :engine instance', ['engine' => $engineLabels[$engine]]) }}</h3>
                                <x-explainer class="mt-2">
                                    <p>{{ __('Installs a second :engine instance on a different port, leaving the existing instance untouched. The new instance gets its own systemd unit (templated), config file, and data directory. Apt is not re-run if the package is already installed.', ['engine' => $engineLabels[$engine]]) }}</p>
                                    <p>{{ __('Pick a short name (lowercase letters, digits, hyphens; e.g. sessions, queue, cache) and a free port. Auth, memory limits, and config can all be tuned per-instance after the install completes.') }}</p>
                                </x-explainer>

                                <form wire:submit.prevent="submitAddInstanceForm" class="mt-4 grid gap-4 sm:grid-cols-3 sm:items-end">
                                    <div>
                                        <x-input-label for="newInstanceName" :value="__('Instance name')" />
                                        <x-text-input
                                            id="newInstanceName"
                                            wire:model="newInstanceName"
                                            type="text"
                                            autocomplete="off"
                                            spellcheck="false"
                                            class="mt-1 block w-full font-mono text-sm"
                                            placeholder="sessions"
                                            wire:loading.attr="disabled"
                                            wire:target="submitAddInstanceForm"
                                        />
                                    </div>
                                    <div>
                                        <x-input-label for="newInstancePort" :value="__('Port')" />
                                        <x-text-input
                                            id="newInstancePort"
                                            wire:model="newInstancePort"
                                            type="number"
                                            min="1"
                                            max="65535"
                                            class="mt-1 block w-full font-mono text-sm"
                                            placeholder="6380"
                                            wire:loading.attr="disabled"
                                            wire:target="submitAddInstanceForm"
                                        />
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="submitAddInstanceForm">
                                            <span wire:loading.remove wire:target="submitAddInstanceForm">{{ __('Install instance') }}</span>
                                            <span wire:loading wire:target="submitAddInstanceForm">{{ __('Queueing…') }}</span>
                                        </x-primary-button>
                                        <x-secondary-button type="button" wire:click="closeAddInstanceForm">{{ __('Cancel') }}</x-secondary-button>
                                    </div>
                                </form>
                            </div>
                        @endif
                    @endif

                    {{-- Sub-tab strip — group the per-engine cards so the page isn't a 9-card scroll. --}}
                    <x-server-workspace-tablist :aria-label="__(':engine sections', ['engine' => $engineLabels[$engine]])">
                        <x-server-workspace-tab
                            :id="'cache-subtab-'.$engine.'-overview'"
                            :active="$activeSubtab === 'overview'"
                            wire:click="setEngineSubtab('overview')"
                        >
                            <span class="inline-flex items-center gap-2">
                                <x-heroicon-o-presentation-chart-line class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Overview') }}
                            </span>
                        </x-server-workspace-tab>
                        <x-server-workspace-tab
                            :id="'cache-subtab-'.$engine.'-info'"
                            :active="$activeSubtab === 'info'"
                            wire:click="setEngineSubtab('info')"
                        >
                            <span class="inline-flex items-center gap-2">
                                <x-heroicon-o-information-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Info') }}
                            </span>
                        </x-server-workspace-tab>
                        @if ($isRedisFamily)
                            <x-server-workspace-tab
                                :id="'cache-subtab-'.$engine.'-console'"
                                :active="$activeSubtab === 'console'"
                                wire:click="setEngineSubtab('console')"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <x-heroicon-o-command-line class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Console') }}
                                </span>
                            </x-server-workspace-tab>
                            <x-server-workspace-tab
                                :id="'cache-subtab-'.$engine.'-stats'"
                                :active="$activeSubtab === 'stats'"
                                wire:click="setEngineSubtab('stats')"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <x-heroicon-o-chart-bar class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Stats') }}
                                </span>
                            </x-server-workspace-tab>
                        @endif
                        <x-server-workspace-tab
                            :id="'cache-subtab-'.$engine.'-configure'"
                            :active="$activeSubtab === 'configure'"
                            wire:click="setEngineSubtab('configure')"
                        >
                            <span class="inline-flex items-center gap-2">
                                <x-heroicon-o-adjustments-horizontal class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Configure') }}
                            </span>
                        </x-server-workspace-tab>
                    </x-server-workspace-tablist>

                    @if ($activeSubtab === 'info')
                    {{-- Engine info — what this engine is, license, links, "best for".
                         Lives in its own tab so the Overview stays focused on operational
                         signal (status, port, actions). --}}
                    @include('livewire.servers.partials.cache-engine-info-card', [
                        'info' => $info,
                        'row' => $row,
                        'card' => $card,
                    ])
                    @endif

                    @if ($activeSubtab === 'overview')
                    {{-- Installed and idle: status grid + action row. --}}
                    <div class="{{ $card }} p-6 sm:p-8">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine status', ['engine' => $engineLabels[$engine]]) }}</h3>
                        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                                <dd class="mt-1">
                                    @switch($row->status)
                                        @case(\App\Models\ServerCacheService::STATUS_RUNNING)
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Running') }}</span>
                                            @break
                                        @case(\App\Models\ServerCacheService::STATUS_STOPPED)
                                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Stopped') }}</span>
                                            @break
                                        @case(\App\Models\ServerCacheService::STATUS_FAILED)
                                            <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700">{{ __('Failed') }}</span>
                                            @break
                                        @default
                                            <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-medium text-brand-ink">{{ ucfirst($row->status) }}</span>
                                    @endswitch
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Probe') }}</dt>
                                <dd class="mt-1 flex flex-wrap items-center gap-2">
                                    @if ($probeRunning)
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Reachable') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Not reachable') }}</span>
                                    @endif
                                    {{-- Re-probe THIS instance on its actual port. Cheap; runs a
                                         single `*-cli ping` (or systemctl is-active for memcached
                                         / dragonfly) and toasts the result. --}}
                                    <button
                                        type="button"
                                        wire:click="recheckCacheServiceInstance('{{ $engine }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="recheckCacheServiceInstance"
                                        class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 font-sans text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40 disabled:opacity-50"
                                        title="{{ __('Re-run the reachability probe against this instance\'s port') }}"
                                    >
                                        <span wire:loading.remove wire:target="recheckCacheServiceInstance" class="inline-flex items-center gap-1">
                                            <x-heroicon-o-arrow-path class="h-3 w-3" />
                                            {{ __('Recheck') }}
                                        </span>
                                        <span wire:loading wire:target="recheckCacheServiceInstance" class="inline-flex items-center gap-1">
                                            <x-spinner variant="forest" />
                                            {{ __('Checking…') }}
                                        </span>
                                    </button>
                                    @if (! $probeRunning)
                                        <button
                                            type="button"
                                            wire:click="debugCacheServiceInstance('{{ $engine }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="debugCacheServiceInstance"
                                            class="inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 font-sans text-[11px] font-medium text-amber-900 hover:bg-amber-100 disabled:opacity-50"
                                            title="{{ __('Run systemctl status + port-listener + ping diagnostics and surface the output below') }}"
                                        >
                                            <span wire:loading.remove wire:target="debugCacheServiceInstance" class="inline-flex items-center gap-1">
                                                <x-heroicon-o-bug-ant class="h-3 w-3" />
                                                {{ __('Debug') }}
                                            </span>
                                            <span wire:loading wire:target="debugCacheServiceInstance" class="inline-flex items-center gap-1">
                                                <x-spinner variant="forest" />
                                                {{ __('Running…') }}
                                            </span>
                                        </button>
                                    @endif
                                    {{-- Status / Logs — open the per-instance modal that shows
                                         systemctl status and journalctl -u for THIS instance's
                                         unit. Always available regardless of reachability so the
                                         operator can inspect a stopped or failed instance too. --}}
                                    <button
                                        type="button"
                                        wire:click="showCacheInstanceStatus('{{ $engine }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="showCacheInstanceStatus,showCacheInstanceLogs"
                                        class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 font-sans text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40 disabled:opacity-50"
                                        title="{{ __('Open systemctl status for this instance') }}"
                                    >
                                        <span wire:loading.remove wire:target="showCacheInstanceStatus" class="inline-flex items-center gap-1">
                                            <x-heroicon-o-information-circle class="h-3 w-3" />
                                            {{ __('Status') }}
                                        </span>
                                        <span wire:loading wire:target="showCacheInstanceStatus" class="inline-flex items-center gap-1">
                                            <x-spinner variant="forest" />
                                            {{ __('Loading…') }}
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="showCacheInstanceLogs('{{ $engine }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="showCacheInstanceStatus,showCacheInstanceLogs"
                                        class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 font-sans text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40 disabled:opacity-50"
                                        title="{{ __('Open journalctl tail for this instance') }}"
                                    >
                                        <span wire:loading.remove wire:target="showCacheInstanceLogs" class="inline-flex items-center gap-1">
                                            <x-heroicon-o-document-text class="h-3 w-3" />
                                            {{ __('Logs') }}
                                        </span>
                                        <span wire:loading wire:target="showCacheInstanceLogs" class="inline-flex items-center gap-1">
                                            <x-spinner variant="forest" />
                                            {{ __('Loading…') }}
                                        </span>
                                    </button>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                                <dd class="mt-1 flex flex-wrap items-center gap-2 font-mono text-sm text-brand-ink">
                                    <span>{{ $row->version ?: '—' }}</span>
                                    @if (! $row->version && $row->status === \App\Models\ServerCacheService::STATUS_RUNNING)
                                        <button
                                            type="button"
                                            wire:click="probeCacheServiceVersion('{{ $engine }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="probeCacheServiceVersion"
                                            class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 font-sans text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40 disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="probeCacheServiceVersion">{{ __('Probe') }}</span>
                                            <span wire:loading wire:target="probeCacheServiceVersion" class="inline-flex items-center gap-1">
                                                <x-spinner variant="forest" />
                                            </span>
                                        </button>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Port') }}</dt>
                                <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $row->port }}</dd>
                            </div>
                        </dl>

                        @if ($row->status === \App\Models\ServerCacheService::STATUS_FAILED && filled($row->error_message))
                            <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">
                                {{ $row->error_message }}
                            </p>
                        @endif

                        {{-- Debug-output panel — appears when the operator clicks Debug.
                             Shows systemctl status, port listener, ping probe, and recent
                             journal lines so the operator can diagnose why an instance is
                             marked Not reachable. Dismiss clears the buffer. --}}
                        @if (! empty($debug_output_by_engine[$engine]))
                            <div class="mt-4 overflow-hidden rounded-xl border border-amber-300 bg-amber-50">
                                <div class="flex items-center justify-between gap-3 border-b border-amber-200 bg-amber-100/60 px-4 py-2.5">
                                    <p class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-amber-900">
                                        <x-heroicon-o-bug-ant class="h-3.5 w-3.5" />
                                        {{ __('Debug — :engine instance :name', ['engine' => $engineLabels[$engine] ?? $engine, 'name' => $row->name]) }}
                                    </p>
                                    <button
                                        type="button"
                                        wire:click="clearCacheServiceDebugOutput('{{ $engine }}')"
                                        class="inline-flex items-center gap-1 rounded-md text-[11px] font-medium text-amber-900 hover:bg-amber-200/60 px-1.5 py-0.5"
                                    >
                                        <x-heroicon-o-x-mark class="h-3 w-3" />
                                        {{ __('Dismiss') }}
                                    </button>
                                </div>
                                <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-emerald-100">{{ $debug_output_by_engine[$engine] }}</pre>
                            </div>
                        @endif

                        @if ($cacheBusy)
                            <p class="mt-6 rounded-xl border border-sky-200 bg-sky-50 p-3 text-xs text-sky-900 flex items-start gap-2">
                                <x-spinner variant="forest" class="mt-0.5 shrink-0" />
                                <span>{{ __('Restart, stop, start, flush, and uninstall are paused while another cache service is changing on this server.') }}</span>
                            </p>
                        @php
                            // Multi-instance awareness: stop/restart act on this instance's
                            // systemd unit (or templated unit) only. Uninstall removes this
                            // instance only when there's a sibling instance of the same engine
                            // — package + sibling stay intact. When this IS the last instance,
                            // Uninstall apt-purges the engine.
                            $siblingInstanceCount = \App\Models\ServerCacheService::query()
                                ->where('server_id', $server->id)
                                ->where('engine', $engine)
                                ->where('id', '!=', $row->id)
                                ->count();
                            $isLastInstanceOfEngine = $siblingInstanceCount === 0;
                            $uninstallTitle = $isLastInstanceOfEngine
                                ? __('Uninstall :engine', ['engine' => $engineLabels[$engine]])
                                : __('Uninstall instance :name (:engine)', ['name' => $row->name, 'engine' => $engineLabels[$engine]]);
                            $uninstallConfirm = $isLastInstanceOfEngine
                                ? __('apt purge will remove the package and its data dirs. Cached entries will be lost. Other engines on this server are not affected.')
                                : __('Removes only this instance — its systemd unit, config, and data dir. The apt package stays because :n other :engine instance(s) still use it. Cached entries on this instance will be lost; sibling instances are unaffected.', ['n' => $siblingInstanceCount, 'engine' => $engineLabels[$engine]]);
                            $uninstallLabel = $isLastInstanceOfEngine ? __('Uninstall') : __('Remove instance');
                        @endphp
                        <x-explainer class="mt-6" tone="warn" :title="__('What do these actions do?')">
                            <ul>
                                <li><strong>{{ __('Restart / Stop / Start') }}.</strong> {{ __('Acts on THIS instance\'s systemd unit only. Other instances on this engine are not affected.') }}</li>
                                <li><strong>{{ __('Flush all keys') }}.</strong> {{ __('Drops every key in this instance — sessions, cache, queued tags, rate-limit counters. Cannot be undone. Sibling instances keep their data.') }}</li>
                                @if ($isLastInstanceOfEngine)
                                    <li><strong>{{ __('Uninstall') }}.</strong> {{ __('Last instance of :engine on this server — runs apt purge for the package + data dirs. Other engines on this server are not affected.', ['engine' => $engineLabels[$engine]]) }}</li>
                                @else
                                    <li><strong>{{ __('Remove instance') }}.</strong> {{ __(':n other :engine instance(s) still use the package, so apt purge would break them. This affordance only removes the systemd unit + config + data dir for THIS instance.', ['n' => $siblingInstanceCount, 'engine' => $engineLabels[$engine]]) }}</li>
                                @endif
                            </ul>
                        </x-explainer>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @if (in_array($row->status, [\App\Models\ServerCacheService::STATUS_RUNNING, \App\Models\ServerCacheService::STATUS_STOPPED, \App\Models\ServerCacheService::STATUS_FAILED], true))
                                    <button type="button" wire:click="restartCacheService('{{ $engine }}')" wire:loading.attr="disabled" wire:target="restartCacheService" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                                        <span wire:loading.remove wire:target="restartCacheService">{{ __('Restart') }}</span>
                                        <span wire:loading wire:target="restartCacheService">{{ __('Restarting…') }}</span>
                                    </button>
                                    @if ($row->status !== \App\Models\ServerCacheService::STATUS_STOPPED)
                                        <button type="button" wire:click="stopCacheService('{{ $engine }}')" wire:loading.attr="disabled" wire:target="stopCacheService" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                            <x-heroicon-o-stop-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Stop') }}
                                        </button>
                                    @else
                                        <button type="button" wire:click="startCacheService('{{ $engine }}')" wire:loading.attr="disabled" wire:target="startCacheService" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                            <x-heroicon-o-play-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Start') }}
                                        </button>
                                    @endif
                                    @if ($row->status === \App\Models\ServerCacheService::STATUS_RUNNING)
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('flushCacheService', ['{{ $engine }}'], @js(__('Flush all keys')), @js(__('Drop every key in :engine. App sessions, queued tags, and rate-limit counters in this engine will all be reset. Cannot be undone.', ['engine' => $engineLabels[$engine]])), @js(__('Flush all keys')), true)"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                                        >
                                            <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Flush all keys') }}
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('uninstallCacheService', ['{{ $engine }}'], @js($uninstallTitle), @js($uninstallConfirm), @js($uninstallLabel), true)"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                                        title="{{ $isLastInstanceOfEngine ? __('apt purge — removes the package') : __('Removes this instance only — package stays for the other instances') }}"
                                    >
                                        <x-heroicon-o-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ $uninstallLabel }}
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Connection snippet for this engine. --}}
                    @include('livewire.servers.partials.cache-connection-snippet', [
                        'cacheService' => $row,
                        'card' => $card,
                        'engineLabels' => $engineLabels,
                    ])
                    @endif {{-- /overview subtab --}}

                    {{-- Port card (Configure subtab, all engines). Restarts the unit. --}}
                    @if ($activeSubtab === 'configure')
                        <div class="{{ $card }} p-6 sm:p-8">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine — listen port', ['engine' => $engineLabels[$engine]]) }}</h3>
                            <p class="mt-2 text-sm text-brand-moss">
                                {{ __('Change the TCP port :engine listens on. The systemd unit will restart and connections drop briefly while the new port comes up. If the engine fails to bind, the previous config is restored automatically.', ['engine' => $engineLabels[$engine]]) }}
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">
                                {{ __('Currently on port :port.', ['port' => $row->port]) }}
                            </p>
                            <form wire:submit="changeCachePort" class="mt-6 grid max-w-xl grid-cols-1 gap-4 sm:grid-cols-2 sm:items-end">
                                <div>
                                    <x-input-label for="new_port" :value="__('New port')" />
                                    <x-text-input
                                        id="new_port"
                                        wire:model="new_port"
                                        type="number"
                                        min="1024"
                                        max="65535"
                                        autocomplete="off"
                                        class="mt-1 block w-full font-mono text-sm"
                                        :placeholder="(string) $row->port"
                                        wire:loading.attr="disabled"
                                        wire:target="changeCachePort"
                                    />
                                    <x-input-error :messages="$errors->get('new_port')" class="mt-1" />
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="changeCachePort">
                                        <span wire:loading.remove wire:target="changeCachePort">{{ __('Change port and restart') }}</span>
                                        <span wire:loading wire:target="changeCachePort">{{ __('Updating…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        </div>
                    @endif

                    {{-- AUTH password card (redis-family only, Configure subtab). --}}
                    @if (\App\Models\ServerCacheService::engineSupportsAuth($row->engine))
                        @if ($activeSubtab === 'configure')
                        <div class="{{ $card }} p-6 sm:p-8">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine — AUTH password', ['engine' => $engineLabels[$engine]]) }}</h3>
                            <p class="mt-2 text-sm text-brand-moss">
                                @if (filled($row->auth_password))
                                    {{ __('A password is set. Apps connecting to this engine must send AUTH. Rotate by entering a new value below.') }}
                                @else
                                    {{ __('No AUTH password is set. Anything that can reach the loopback port can issue commands. Set one below to require authentication.') }}
                                @endif
                            </p>
                            <form wire:submit="setAuthPassword" class="mt-6 grid max-w-xl grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <div class="flex items-end justify-between gap-2">
                                        <x-input-label for="new_auth_password" :value="__('New password')" class="mb-0" />
                                        <button type="button" wire:click="generateAuthPassword" wire:loading.attr="disabled" wire:target="setAuthPassword,generateAuthPassword" class="mb-1 text-xs font-medium text-brand-forest hover:underline disabled:opacity-50">{{ __('Generate') }}</button>
                                    </div>
                                    <div class="relative mt-1" x-data="{ shown: false }">
                                        <x-text-input
                                            id="new_auth_password"
                                            x-ref="input"
                                            ::type="shown ? 'text' : 'password'"
                                            type="password"
                                            wire:model="new_auth_password"
                                            autocomplete="new-password"
                                            class="block w-full pr-10 text-sm"
                                            placeholder="••••••••"
                                            wire:loading.attr="disabled"
                                            wire:target="setAuthPassword"
                                        />
                                        <div class="absolute inset-y-0 right-2 flex items-center gap-1">
                                            <button type="button" x-on:click="shown = !shown" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink">
                                                <x-heroicon-o-eye class="h-4 w-4" x-show="!shown" />
                                                <x-heroicon-o-eye-slash class="h-4 w-4" x-show="shown" x-cloak />
                                            </button>
                                        </div>
                                    </div>
                                    <x-input-error :messages="$errors->get('new_auth_password')" class="mt-1" />
                                </div>
                                <div class="sm:col-span-2 flex flex-wrap gap-2">
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="setAuthPassword">
                                        <span wire:loading.remove wire:target="setAuthPassword">{{ filled($row->auth_password) ? __('Rotate password') : __('Set password') }}</span>
                                        <span wire:loading wire:target="setAuthPassword">{{ __('Updating…') }}</span>
                                    </x-primary-button>
                                    @if (filled($row->auth_password))
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('clearAuthPassword', [], @js(__('Clear AUTH password')), @js(__('Allow unauthenticated commands on the loopback port? Only safe if no other process can reach this server.')), @js(__('Clear password')), true)"
                                            class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                                        >
                                            {{ __('Clear password') }}
                                        </button>
                                    @endif
                                </div>
                            </form>
                        </div>

                        {{-- Network exposure card (redis-family only). Default install binds to
                             127.0.0.1; this affordance flips bind to 0.0.0.0 + opens a firewall
                             allow rule for the configured source CIDR. Refuses to expose without
                             an AUTH password set first. --}}
                        @php
                            $networkExposure = app(\App\Support\Servers\CacheServiceNetworkExposure::class);
                            $isExposed = $networkExposure->isExposed($row);
                            $exposedRule = $isExposed ? $networkExposure->findManagedRule($row) : null;
                            $hasAuth = filled($row->auth_password ?? null);
                        @endphp
                        <div class="{{ $card }} p-6 sm:p-8">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine — network exposure', ['engine' => $engineLabels[$engine]]) }}</h3>
                                    <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                                        @if ($isExposed)
                                            {{ __('This instance is exposed to :source on TCP :port. Other servers in that range can connect.', ['source' => $exposedRule?->source ?? '—', 'port' => $row->port]) }}
                                        @else
                                            {{ __('Currently bound to 127.0.0.1 — only processes on this server can connect. Expose to a private network (a VPC peer, a specific app server) to allow cross-server connections.') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    @if ($isExposed)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">
                                            <x-heroicon-m-globe-alt class="h-3 w-3" />
                                            {{ __('Exposed') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                            <x-heroicon-m-lock-closed class="h-3 w-3" />
                                            {{ __('Loopback only') }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            @if (! $hasAuth && ! $isExposed)
                                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-900">
                                    <p class="flex items-start gap-2">
                                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>{{ __('Set an AUTH password above first. Exposing an unauthenticated cache to a network — even a private one — isn\'t allowed from this dialog.') }}</span>
                                    </p>
                                </div>
                            @endif

                            @if ($isExposed)
                                <div class="mt-6 flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-brand-moss">{{ __('Currently allowed from:') }}</span>
                                    <code class="rounded-md bg-brand-sand/40 px-2 py-0.5 font-mono text-xs text-brand-ink">{{ $exposedRule?->source }}</code>
                                </div>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('lockdownCacheToLoopback', [], @js(__('Lock down to loopback?')), @js(__('Rebind :engine to 127.0.0.1, remove the firewall rule, and reapply UFW. Existing remote clients will be cut off as soon as the apply completes.', ['engine' => $engineLabels[$engine]])), @js(__('Lock down')), true)"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-lock-closed class="h-3.5 w-3.5" />
                                        {{ __('Lock down to loopback') }}
                                    </button>
                                    <button
                                        type="button"
                                        x-on:click="$dispatch('open-modal', 'expose-cache-modal-{{ $engine }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                        {{ __('Change source CIDR') }}
                                    </button>
                                </div>
                            @else
                                <div class="mt-4 flex flex-wrap items-end gap-2">
                                    <button
                                        type="button"
                                        @disabled(! $hasAuth)
                                        x-on:click="$dispatch('open-modal', 'expose-cache-modal-{{ $engine }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-forest/30 bg-brand-forest/10 px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm hover:bg-brand-forest/15 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <x-heroicon-o-globe-alt class="h-3.5 w-3.5" />
                                        {{ __('Expose to network…') }}
                                    </button>
                                </div>
                            @endif
                        </div>

                        {{-- Expose modal — captures the source CIDR + final confirm. --}}
                        <x-modal :name="'expose-cache-modal-'.$engine" maxWidth="lg" overlayClass="bg-brand-ink/40">
                            <form wire:submit="exposeCacheToNetwork">
                                <div class="border-b border-brand-ink/10 px-6 py-5">
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Network exposure') }}</p>
                                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Expose :engine to a network', ['engine' => $engineLabels[$engine]]) }}</h2>
                                    <p class="mt-2 text-sm leading-6 text-brand-moss">
                                        {{ __('Rebinds :engine to 0.0.0.0, restarts the unit, opens a firewall allow rule for TCP :port from the source you choose, then queues a UFW apply.', ['engine' => $engineLabels[$engine], 'port' => $row->port]) }}
                                    </p>
                                </div>
                                <div class="px-6 py-6">
                                    <x-input-label for="expose_source_cidr_{{ $engine }}" :value="__('Source CIDR')" />
                                    <x-text-input
                                        id="expose_source_cidr_{{ $engine }}"
                                        wire:model="expose_source_cidr"
                                        type="text"
                                        autocomplete="off"
                                        spellcheck="false"
                                        class="mt-1 block w-full font-mono text-sm"
                                        placeholder="10.0.0.0/8"
                                    />
                                    <p class="mt-2 text-xs text-brand-moss">
                                        {{ __('e.g. 10.0.0.0/8 (full VPC), 10.0.4.5/32 (single peer). "any" / 0.0.0.0/0 are not allowed here — add such a rule manually in the firewall workspace if you really need it.') }}
                                    </p>
                                    <x-input-error :messages="$errors->get('expose_source_cidr')" class="mt-1" />
                                </div>
                                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="exposeCacheToNetwork" x-on:click="$dispatch('close')">
                                        <span wire:loading.remove wire:target="exposeCacheToNetwork">{{ __('Expose') }}</span>
                                        <span wire:loading wire:target="exposeCacheToNetwork">{{ __('Working…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        </x-modal>

                        {{-- Memory limits card (redis-family only). --}}
                        <div class="{{ $card }} p-6 sm:p-8">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine — memory limits', ['engine' => $engineLabels[$engine]]) }}</h3>
                                    <p class="mt-2 text-sm text-brand-moss">{{ __('Cap the engine\'s memory usage and pick what happens when the cap is hit. Backed by maxmemory + maxmemory-policy in the config file.') }}</p>
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2 self-start whitespace-nowrap">
                                    @if (! $cacheMemoryLoaded && $cacheMemoryError === null)
                                        <button type="button" wire:click="loadCacheMemorySettings" wire:loading.attr="disabled" wire:target="loadCacheMemorySettings" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                            <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                                            <span wire:loading.remove wire:target="loadCacheMemorySettings">{{ __('Load current settings') }}</span>
                                            <span wire:loading wire:target="loadCacheMemorySettings">{{ __('Loading…') }}</span>
                                        </button>
                                    @else
                                        <button type="button" wire:click="hideCacheMemorySettings" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                                            <x-heroicon-o-eye-slash class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Hide') }}
                                        </button>
                                    @endif
                                </div>
                            </div>

                            @if ($cacheMemoryError)
                                <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $cacheMemoryError }}</p>
                            @elseif ($cacheMemoryLoaded)
                                <form wire:submit="saveCacheMemorySettings" class="mt-6 grid max-w-xl grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="cache_maxmemory" :value="__('maxmemory')" />
                                        <x-text-input id="cache_maxmemory" wire:model="cache_maxmemory" class="mt-1 block w-full font-mono text-sm" placeholder="256mb" wire:loading.attr="disabled" wire:target="saveCacheMemorySettings" />
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('e.g. 256mb, 1gb, 0 for no limit. Empty removes the directive entirely.') }}</p>
                                        <x-input-error :messages="$errors->get('cache_maxmemory')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="cache_maxmemory_policy" :value="__('maxmemory-policy')" />
                                        <select id="cache_maxmemory_policy" wire:model="cache_maxmemory_policy" wire:loading.attr="disabled" wire:target="saveCacheMemorySettings" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm">
                                            @foreach (\App\Support\Servers\CacheServiceMemoryConfig::POLICIES as $policyOption)
                                                <option value="{{ $policyOption }}">{{ $policyOption }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('cache_maxmemory_policy')" class="mt-1" />
                                    </div>
                                    <div class="sm:col-span-2 flex flex-wrap gap-2">
                                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveCacheMemorySettings">
                                            <span wire:loading.remove wire:target="saveCacheMemorySettings">{{ __('Save and restart') }}</span>
                                            <span wire:loading wire:target="saveCacheMemorySettings">{{ __('Saving…') }}</span>
                                        </x-primary-button>
                                    </div>
                                </form>
                            @endif
                        </div>
                        @endif {{-- /configure subtab (auth + memory) --}}

                        {{-- Connected clients (redis-family only, Stats subtab). --}}
                        @if ($activeSubtab === 'stats')
                        <div class="{{ $card }} p-6 sm:p-8">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine — connected clients', ['engine' => $engineLabels[$engine]]) }}</h3>
                                    <p class="mt-2 text-sm text-brand-moss">{{ __('Snapshot of CLIENT LIST. Pulled on demand — refresh to see who\'s connected right now.') }}</p>
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2 self-start whitespace-nowrap">
                                    @if ($cacheClients === null && $cacheClientsError === null)
                                        <button type="button" wire:click="loadCacheClients" wire:loading.attr="disabled" wire:target="loadCacheClients" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                            <x-heroicon-o-users class="h-3.5 w-3.5" aria-hidden="true" />
                                            <span wire:loading.remove wire:target="loadCacheClients">{{ __('Load clients') }}</span>
                                            <span wire:loading wire:target="loadCacheClients">{{ __('Loading…') }}</span>
                                        </button>
                                    @else
                                        <button type="button" wire:click="loadCacheClients" wire:loading.attr="disabled" wire:target="loadCacheClients" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Refresh') }}
                                        </button>
                                        <button type="button" wire:click="hideCacheClients" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                                            <x-heroicon-o-eye-slash class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Hide') }}
                                        </button>
                                    @endif
                                </div>
                            </div>

                            @if ($cacheClientsError)
                                <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $cacheClientsError }}</p>
                            @elseif ($cacheClients !== null)
                                @if (count($cacheClients) === 0)
                                    <p class="mt-4 text-sm text-brand-moss">{{ __('No clients connected.') }}</p>
                                @else
                                    <div class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10">
                                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                            <thead class="bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wide text-brand-mist">
                                                <tr>
                                                    <th class="px-4 py-3">{{ __('ID') }}</th>
                                                    <th class="px-4 py-3">{{ __('Address') }}</th>
                                                    <th class="px-4 py-3">{{ __('Name') }}</th>
                                                    <th class="px-4 py-3">{{ __('Age (s)') }}</th>
                                                    <th class="px-4 py-3">{{ __('Idle (s)') }}</th>
                                                    <th class="px-4 py-3">{{ __('DB') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-brand-ink/10 bg-white">
                                                @foreach ($cacheClients as $client)
                                                    <tr>
                                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-ink">{{ $client['id'] }}</td>
                                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-ink">{{ $client['addr'] }}</td>
                                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-moss">{{ $client['name'] ?: '—' }}</td>
                                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-moss">{{ $client['age'] }}</td>
                                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-moss">{{ $client['idle'] }}</td>
                                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-moss">{{ $client['db'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            @endif
                        </div>

                        {{-- Live keyspace dashboard — redis-family only, Stats subtab. --}}
                        @include('livewire.servers.partials.cache-keyspace-card', [
                            'engine' => $engine,
                            'engineLabel' => $engineLabels[$engine] ?? ucfirst($engine),
                            'row' => $row,
                            'samples' => $keyspaceSamples,
                            'loaded' => $keyspaceLoaded,
                            'error' => $keyspaceError,
                            'card' => $card,
                        ])

                        {{-- Key browser — redis-family only, Stats subtab. --}}
                        @include('livewire.servers.partials.cache-key-browser-card', [
                            'engine' => $engine,
                            'engineLabel' => $engineLabels[$engine] ?? ucfirst($engine),
                            'row' => $row,
                            'pattern' => $keyBrowserPattern,
                            'keys' => $keyBrowserKeys,
                            'loaded' => $keyBrowserLoaded,
                            'complete' => $keyBrowserComplete,
                            'selected' => $keyBrowserSelected,
                            'value' => $keyBrowserValue,
                            'valueError' => $keyBrowserValueError,
                            'error' => $keyBrowserError,
                            'replUnlocked' => $replUnlocked,
                            'card' => $card,
                        ])

                        {{-- Live MONITOR tail — redis-family only, Stats subtab. --}}
                        @include('livewire.servers.partials.cache-monitor-card', [
                            'engine' => $engine,
                            'engineLabel' => $engineLabels[$engine] ?? ucfirst($engine),
                            'row' => $row,
                            'runId' => $monitorRunId,
                            'duration' => $monitorDurationSeconds,
                            'payload' => $monitorPayload,
                            'replUnlocked' => $replUnlocked,
                            'card' => $card,
                        ])
                        @endif {{-- /stats subtab (clients + keyspace + key browser + monitor) --}}

                        {{-- Interactive console (REPL) — redis-family only, Console subtab. --}}
                        @if ($activeSubtab === 'console')
                        @include('livewire.servers.partials.cache-repl-card', [
                            'engine' => $engine,
                            'engineLabel' => $engineLabels[$engine] ?? ucfirst($engine),
                            'row' => $row,
                            'replInput' => $replInput,
                            'replHistory' => $replHistory,
                            'replUnlocked' => $replUnlocked,
                            'card' => $card,
                        ])
                        @endif {{-- /console subtab --}}
                    @endif

                    {{-- Server config file viewer/editor. Configure subtab.
                         The trigger card stays slim; the viewer/editor itself lives in a modal
                         so the read-only pre + textarea don't push the rest of the Configure
                         subtab off-screen on long config files. --}}
                    @if ($activeSubtab === 'configure')
                    @php $configModalName = 'cache-config-modal-'.$engine; @endphp
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine — server config file', ['engine' => $engineLabels[$engine]]) }}</h3>
                                <p class="mt-2 text-sm text-brand-moss">
                                    {{ __('Read-only view of the engine\'s main config file. Click Edit inside the viewer to change it — Dply backs up, restarts, verifies, and rolls back automatically on failure.') }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2 self-start whitespace-nowrap">
                                <button
                                    type="button"
                                    wire:click="loadCacheConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadCacheConfig"
                                    x-on:click="$dispatch('open-modal', @js($configModalName))"
                                    class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                                >
                                    <x-heroicon-o-document-text class="h-3.5 w-3.5" aria-hidden="true" />
                                    <span wire:loading.remove wire:target="loadCacheConfig">{{ __('View config') }}</span>
                                    <span wire:loading wire:target="loadCacheConfig">{{ __('Loading…') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <x-modal :name="$configModalName" maxWidth="4xl" overlayClass="bg-brand-ink/40">
                        <div class="border-b border-brand-ink/10 px-6 py-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Server config') }}</p>
                                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __(':engine — server config file', ['engine' => $engineLabels[$engine]]) }}</h2>
                                    <p class="mt-2 text-sm leading-6 text-brand-moss">
                                        @if ($cacheConfigEditing)
                                            {{ __('Editing the live config. Save will write, restart the engine, verify it accepts the new config, and roll back if anything goes wrong.') }}
                                        @else
                                            {{ __('Read-only view of the engine\'s main config file. Click Edit to change it — Dply backs up, restarts, verifies, and rolls back automatically on failure.') }}
                                        @endif
                                    </p>
                                    @if ($cacheConfigPath)
                                        <p class="mt-2 break-all font-mono text-xs text-brand-mist">{{ $cacheConfigPath }}</p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2 self-start whitespace-nowrap">
                                    @if (! $cacheConfigEditing)
                                        <button type="button" wire:click="loadCacheConfig" wire:loading.attr="disabled" wire:target="loadCacheConfig" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                                            <span wire:loading.remove wire:target="loadCacheConfig">{{ __('Refresh') }}</span>
                                            <span wire:loading wire:target="loadCacheConfig">{{ __('Loading…') }}</span>
                                        </button>
                                        @if ($cacheConfigContent !== null)
                                            <button type="button" wire:click="startEditingCacheConfig" wire:loading.attr="disabled" wire:target="startEditingCacheConfig" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-forest/30 bg-brand-forest/10 px-3 py-1.5 text-sm font-medium text-brand-forest hover:bg-brand-forest/15">
                                                <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                                {{ __('Edit') }}
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="px-6 py-5">
                            @if ($cacheConfigError)
                                <p class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $cacheConfigError }}</p>
                            @elseif ($cacheConfigEditing)
                                <form wire:submit="saveCacheConfig" id="cache-config-form-{{ $engine }}">
                                    <textarea
                                        id="cache_config_draft"
                                        wire:model="cacheConfigDraft"
                                        rows="22"
                                        spellcheck="false"
                                        class="block w-full rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs leading-relaxed text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                    ></textarea>
                                    <x-input-error :messages="$errors->get('cacheConfigDraft')" class="mt-2" />
                                </form>
                            @elseif ($cacheConfigContent !== null)
                                <pre class="max-h-[60vh] overflow-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs leading-relaxed text-brand-ink whitespace-pre">{{ $cacheConfigContent }}</pre>
                            @else
                                <div class="flex items-center gap-3 text-sm text-brand-moss" wire:loading wire:target="loadCacheConfig">
                                    <x-spinner variant="forest" />
                                    {{ __('Reading config over SSH…') }}
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                            @if ($cacheConfigEditing)
                                <x-secondary-button type="button" wire:click="cancelEditingCacheConfig">{{ __('Cancel') }}</x-secondary-button>
                                <x-primary-button type="submit" form="cache-config-form-{{ $engine }}" wire:loading.attr="disabled" wire:target="saveCacheConfig">
                                    <span wire:loading.remove wire:target="saveCacheConfig">{{ __('Save and restart') }}</span>
                                    <span wire:loading wire:target="saveCacheConfig">{{ __('Saving…') }}</span>
                                </x-primary-button>
                            @else
                                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
                            @endif
                        </div>
                    </x-modal>
                    @endif {{-- /configure subtab (config viewer) --}}
                @endif
            </x-server-workspace-tab-panel>
        @endforeach

        {{-- ============================================================================
             ADVANCED TAB — server-wide audit log across every cache service.
             ============================================================================ --}}
        <x-server-workspace-tab-panel
            id="cache-panel-advanced"
            labelled-by="cache-tab-advanced"
            :hidden="$workspace_tab !== 'advanced'"
            panel-class="space-y-8"
        >
            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Recent install / uninstall / restart / stop / start / flush events on cache services for this server.') }}</p>
                <x-explainer class="mt-3">
                    <p>{{ __('Every operator action through this workspace writes a row here. Events are also forwarded to the organization-wide audit log when a signed-in user is the actor.') }}</p>
                    <p>{{ __('Most recent 40 events shown. Event names are stable identifiers (e.g. cache_service_restarted) so they\'re grep-able from the org log; the engine field tells you which cache the event acted on.') }}</p>
                </x-explainer>
                <ul class="mt-6 divide-y divide-brand-ink/10 text-sm">
                    @forelse ($cacheAuditEvents as $ev)
                        <li class="py-3">
                            <span class="font-medium text-brand-ink">{{ $ev->event }}</span>
                            <span class="text-brand-mist"> · </span>
                            <span class="text-brand-moss">{{ $ev->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                            @if ($ev->user)
                                <span class="text-brand-mist"> · </span>
                                <span class="text-brand-moss">{{ $ev->user->name }}</span>
                            @endif
                            @if (filled($ev->meta) && is_array($ev->meta) && isset($ev->meta['engine']))
                                <span class="text-brand-mist"> · </span>
                                <span class="font-mono text-xs text-brand-moss">
                                    {{ $ev->meta['engine'] }}@if (! empty($ev->meta['name']) && $ev->meta['name'] !== \App\Models\ServerCacheService::DEFAULT_INSTANCE_NAME)<span class="text-brand-mist">/</span>{{ $ev->meta['name'] }}@endif
                                </span>
                            @endif
                        </li>
                    @empty
                        <li class="py-4 text-brand-moss">{{ __('No events yet.') }}</li>
                    @endforelse
                </ul>
            </div>
        </x-server-workspace-tab-panel>
        </div>

        {{-- Per-instance Status / Logs modal. Shows `systemctl status <unit>` or
             `journalctl -u <unit> -n 200` for the currently-active instance
             without leaving the workspace. The unit name is resolved server-
             side by CacheServiceInstallScripts::instanceServiceUnit() so
             default and named instances both target the right systemd unit. --}}
        @if ($showCacheStatusModal)
            <div
                class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center sm:p-6"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cache-status-modal-heading"
            >
                <div class="fixed inset-0 bg-brand-ink/40 backdrop-blur-[1px]" wire:click="closeCacheStatusModal"></div>
                <div class="relative z-10 max-h-[min(92vh,52rem)] w-full max-w-[min(96vw,72rem)] overflow-y-auto overscroll-contain dply-modal-panel [-webkit-overflow-scrolling:touch]">
                    <div class="sticky top-0 z-[1] flex flex-col gap-3 border-b border-brand-ink/10 bg-white px-4 py-4 sm:px-6 sm:py-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10">
                                    @if ($cacheStatusModalView === 'logs')
                                        <x-heroicon-o-document-text class="h-4 w-4" aria-hidden="true" />
                                    @else
                                        <x-heroicon-o-information-circle class="h-4 w-4" aria-hidden="true" />
                                    @endif
                                </span>
                                <div class="min-w-0">
                                    <h2 id="cache-status-modal-heading" class="text-base font-semibold text-brand-ink">
                                        {{ $cacheStatusModalView === 'logs'
                                            ? __(':engine instance logs', ['engine' => $engineLabels[$cacheStatusModalEngine] ?? $cacheStatusModalEngine])
                                            : __(':engine instance status', ['engine' => $engineLabels[$cacheStatusModalEngine] ?? $cacheStatusModalEngine]) }}
                                    </h2>
                                    <p class="mt-0.5 font-mono text-xs text-brand-moss break-all">
                                        {{ $cacheStatusModalInstance }} · {{ $cacheStatusModalUnit }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                <button
                                    type="button"
                                    wire:click="refreshCacheStatusModal"
                                    wire:loading.attr="disabled"
                                    wire:target="refreshCacheStatusModal,setCacheStatusModalView"
                                    @disabled($cacheStatusModalLoading)
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-2 text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40 disabled:opacity-50"
                                >
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0 text-brand-ink/80" wire:loading.class="animate-spin" wire:target="refreshCacheStatusModal,setCacheStatusModalView" />
                                    <span wire:loading.remove wire:target="refreshCacheStatusModal,setCacheStatusModalView">{{ __('Refresh') }}</span>
                                    <span wire:loading wire:target="refreshCacheStatusModal,setCacheStatusModalView">{{ __('Working…') }}</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="closeCacheStatusModal"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-2 text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40"
                                >
                                    {{ __('Close') }}
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center gap-1 text-[11px] font-medium">
                            <button
                                type="button"
                                wire:click="setCacheStatusModalView('status')"
                                @disabled($cacheStatusModalLoading)
                                @class([
                                    'inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 disabled:opacity-50',
                                    'bg-brand-forest text-white' => $cacheStatusModalView === 'status',
                                    'bg-white text-brand-moss border border-brand-ink/15 hover:bg-brand-sand/40' => $cacheStatusModalView !== 'status',
                                ])
                            >
                                <x-heroicon-o-information-circle class="h-3 w-3" />
                                {{ __('Status') }}
                            </button>
                            <button
                                type="button"
                                wire:click="setCacheStatusModalView('logs')"
                                @disabled($cacheStatusModalLoading)
                                @class([
                                    'inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 disabled:opacity-50',
                                    'bg-brand-forest text-white' => $cacheStatusModalView === 'logs',
                                    'bg-white text-brand-moss border border-brand-ink/15 hover:bg-brand-sand/40' => $cacheStatusModalView !== 'logs',
                                ])
                            >
                                <x-heroicon-o-document-text class="h-3 w-3" />
                                {{ __('Logs') }}
                            </button>
                        </div>
                    </div>
                    <div class="px-4 py-4 sm:px-6 sm:py-5">
                        @if ($cacheStatusModalLoading)
                            <p class="text-xs font-medium text-brand-ink">
                                {{ $cacheStatusModalView === 'logs' ? __('Fetching journalctl logs…') : __('Fetching systemctl status…') }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('This can take a few seconds over SSH.') }}</p>
                        @endif
                        @if ($cacheStatusModalError)
                            <div class="mb-3 rounded-lg border border-red-200/80 bg-red-50/90 px-3 py-2 text-xs text-red-900 whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $cacheStatusModalError }}</div>
                        @endif
                        @if ($cacheStatusModalOutput !== '')
                            <div class="rounded-xl border border-brand-ink/15 bg-zinc-50 p-3 shadow-inner">
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-ink">
                                        {{ $cacheStatusModalView === 'logs' ? __('journalctl -u') : __('systemctl status') }}
                                    </p>
                                </div>
                                <pre class="font-mono text-[11px] leading-snug whitespace-pre-wrap break-words text-zinc-900 [overflow-wrap:anywhere]">{{ $cacheStatusModalOutput }}</pre>
                            </div>
                        @elseif (! $cacheStatusModalLoading && $cacheStatusModalError === null)
                            <p class="text-xs text-brand-moss">{{ __('No output yet. Choose Refresh.') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @else
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before you can use this section.') }}
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</x-server-workspace-layout>
