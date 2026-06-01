@php
    $row = $cacheServicesByEngine->get($engine);
    $info = \App\Support\Servers\CacheEngineInfo::for($engine);
    $rowInFlight = $row && in_array($row->status, [
        \App\Models\ServerCacheService::STATUS_PENDING,
        \App\Models\ServerCacheService::STATUS_INSTALLING,
        \App\Models\ServerCacheService::STATUS_UNINSTALLING,
    ], true);
    $probeRunning = (bool) ($capabilities[$engine] ?? false);
@endphp
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
                    @elseif (($comingSoonEngines[$engine] ?? false) && ! $row)
                        {{-- Coming soon: engine is gated behind cache.{engine}. Redis stays
                             installable; this engine shows a teaser instead of the install
                             affordance until platform admin flips the flag on. The Info tab
                             still describes the engine so operators can evaluate it now. --}}
                        <div class="{{ $card }} p-6 sm:p-8">
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/60 text-brand-moss ring-1 ring-brand-ink/10">
                                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Coming soon') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine support is on the way', ['engine' => $info['label']]) }}</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                        {{ $info['tagline'] }}
                                        {{ __('One-click install on this server is coming soon — for now, Redis is the supported cache engine. See the Info tab for details on :engine.', ['engine' => $info['label']]) }}
                                    </p>
                                    <div class="mt-4 flex flex-wrap items-center gap-3">
                                        <button
                                            type="button"
                                            wire:click="setEngineSubtab('info')"
                                            class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-information-circle class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Learn more') }}
                                        </button>
                                        <span class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg bg-brand-forest/30 px-4 py-2 text-sm font-medium text-white opacity-70">
                                            <x-heroicon-o-no-symbol class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Install :engine', ['engine' => $info['label']]) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @elseif (! $row)
                        @php
                            $unsupportedReason = $engineUnsupportedReasons[$engine] ?? null;
                        @endphp
                        {{-- Overview when not installed: the install affordance. --}}
                        <div class="{{ $card }} p-6 sm:p-8">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Install :engine', ['engine' => $info['label']]) }}</h3>
                            <p class="mt-2 text-sm text-brand-moss">{{ __('Runs apt + systemctl over SSH; takes a few minutes on a small box. Other engines on this server are not affected.') }}</p>
                            @if ($unsupportedReason)
                                {{-- Distro gate: the host's /etc/os-release codename isn't in the engine's
                                     supported list (e.g. KeyDB on Ubuntu 24.04 — upstream doesn't ship
                                     for noble). Disable the button instead of letting apt fail later. --}}
                                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                    <p class="flex items-start gap-2">
                                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>{{ $unsupportedReason }}</span>
                                    </p>
                                </div>
                                <div class="mt-4 flex flex-wrap items-center gap-3">
                                    <button
                                        type="button"
                                        disabled
                                        title="{{ $unsupportedReason }}"
                                        class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg bg-brand-forest/40 px-4 py-2 text-sm font-medium text-white opacity-60"
                                    >
                                        <x-heroicon-o-no-symbol class="h-4 w-4" />
                                        {{ __('Install :engine', ['engine' => $info['label']]) }}
                                    </button>
                                </div>
                            @elseif ($cacheBusy)
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
                            <h3 class="text-base font-semibold text-brand-ink">{{ $engineLabels[$engine] }}</h3>
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

                    {{-- Multi-instance UI retired in the family-collapse refactor: one row per
                         (server, engine) means there's only ever one instance to view, so the
                         banner / chip row / Add-instance form are gone. The per-engine port is
                         still shown in the status grid below. --}}

                    {{-- Sub-tab strip — group the per-engine cards so the page isn't a 9-card scroll. --}}
                    <x-server-workspace-tablist :aria-label="__(':engine sections', ['engine' => $engineLabels[$engine]])">
                        <x-server-workspace-tab
                            :id="'cache-subtab-'.$engine.'-overview'"
                            icon="heroicon-o-presentation-chart-line"
                            :active="$activeSubtab === 'overview'"
                            wire:click="setEngineSubtab('overview')"
                        >
                            {{ __('Overview') }}
                        </x-server-workspace-tab>
                        <x-server-workspace-tab
                            :id="'cache-subtab-'.$engine.'-info'"
                            icon="heroicon-o-information-circle"
                            :active="$activeSubtab === 'info'"
                            wire:click="setEngineSubtab('info')"
                        >
                            {{ __('Info') }}
                        </x-server-workspace-tab>
                        @if ($isRedisFamily)
                            <x-server-workspace-tab
                                :id="'cache-subtab-'.$engine.'-console'"
                                icon="heroicon-o-command-line"
                                :active="$activeSubtab === 'console'"
                                wire:click="setEngineSubtab('console')"
                            >
                                {{ __('Console') }}
                            </x-server-workspace-tab>
                            <x-server-workspace-tab
                                :id="'cache-subtab-'.$engine.'-stats'"
                                icon="heroicon-o-chart-bar"
                                :active="$activeSubtab === 'stats'"
                                wire:click="setEngineSubtab('stats')"
                            >
                                {{ __('Stats') }}
                            </x-server-workspace-tab>
                        @endif
                        <x-server-workspace-tab
                            :id="'cache-subtab-'.$engine.'-configure'"
                            icon="heroicon-o-adjustments-horizontal"
                            :active="$activeSubtab === 'configure'"
                            wire:click="setEngineSubtab('configure')"
                        >
                            {{ __('Configure') }}
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
                        <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine status', ['engine' => $engineLabels[$engine]]) }}</h3>
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
                                <dd class="mt-1">
                                    {{-- Three-state badge. "Status: Running, Probe: Not reachable"
                                         used to surface because the SSH-probe couldn't get a PONG
                                         back (AUTH password mismatch, port firewalled from inside,
                                         distro-default cli not in PATH). When the dply row says
                                         RUNNING but the probe couldn't verify, we say so explicitly
                                         rather than claiming the engine is down — the operator
                                         clicks Recheck/Debug below to dig in. --}}
                                    @php
                                        $rowSaysRunning = $row && $row->status === \App\Models\ServerCacheService::STATUS_RUNNING;
                                    @endphp
                                    @if ($probeRunning)
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Reachable') }}</span>
                                    @elseif ($rowSaysRunning)
                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700" title="{{ __('Engine is running per dply state, but the SSH probe couldn\'t get a PONG back. Click Recheck/Debug below to see why — common causes are AUTH password mismatch, in-host firewall, or missing cli in PATH.') }}">{{ __('Couldn\'t verify') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Not reachable') }}</span>
                                    @endif
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

                        {{-- Command-output banner — Debug, Restart/Stop/Start/Disable/Enable all
                             route through the shared ConsoleAction banner so the operator sees the
                             actual shell output with consistent chrome, tone-coded lines, copy-output
                             and "open in modal" affordances. Subject is the ServerCacheService row;
                             kind is `cache_*` (filtered in render() via `latestConsoleActionFor`). --}}
                        @php $cacheRun = $cacheRunsByEngine[$engine] ?? null; @endphp
                        @if ($cacheRun)
                            <div class="mt-4">
                                @include('livewire.partials.console-action-banner-static', [
                                    'run' => $cacheRun,
                                    'kindLabels' => [],
                                ])
                            </div>
                        @endif

                        @if ($cacheBusy)
                            <p class="mt-6 rounded-xl border border-sky-200 bg-sky-50 p-3 text-xs text-sky-900 flex items-start gap-2">
                                <x-spinner variant="forest" class="mt-0.5 shrink-0" />
                                <span>{{ __('Restart, stop, start, flush, and uninstall are paused while another cache service is changing on this server.') }}</span>
                            </p>
                        @endif

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

                            // Always-visible escape hatch — clears the dply row only without
                            // touching the server. Use when uninstall is stuck or the install
                            // never landed on the box.
                            $forceRemoveConfirm = __(
                                'Removes this :engine row from dply only. The server\'s package, config, and data dirs are NOT touched — if the daemon is actually installed, run apt purge / systemctl disable manually after. Use this when the install never landed or uninstall keeps failing. Continue?',
                                ['engine' => $engineLabels[$engine] ?? $engine],
                            );

                            // Lifecycle buttons are gated by terminal states; PENDING/INSTALLING/
                            // UNINSTALLING surface their own affordances via the busy banner up top.
                            $lifecycleAvailable = in_array($row->status, [
                                \App\Models\ServerCacheService::STATUS_RUNNING,
                                \App\Models\ServerCacheService::STATUS_STOPPED,
                                \App\Models\ServerCacheService::STATUS_FAILED,
                            ], true);

                            // Centralise button-class strings so the three groups stay visually
                            // consistent without copy-pasting the same Tailwind soup five times.
                            $btnDiagnose = 'inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40 disabled:opacity-50';
                            $btnLifecycle = 'inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50';
                            $btnDanger = 'inline-flex items-center gap-1.5 rounded-md border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-100 disabled:opacity-50';
                            $btnDebug = 'inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-900 hover:bg-amber-100 disabled:opacity-50';
                            $btnMuted = 'inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-mist hover:bg-brand-sand/40';
                            $groupShell = 'rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3';
                            $groupLabel = 'mb-2 text-[10px] font-semibold uppercase tracking-wide text-brand-mist';
                            $groupButtons = 'flex flex-wrap gap-1.5';
                        @endphp

                        {{-- Three-group toolbar: Diagnose / Lifecycle / Cleanup. Same visual
                             treatment per group, semantic grouping by intent. Lifecycle renders a
                             muted explanation when the row's state can't take those actions
                             (PENDING/INSTALLING/UNINSTALLING — see the busy banner up top for
                             those). Force remove row is always visible in Cleanup so orphaned
                             rows can be cleared without an SSH round-trip. --}}
                        <div class="mt-6 grid gap-3 sm:grid-cols-3">
                            <div class="{{ $groupShell }}">
                                <p class="{{ $groupLabel }}">{{ __('Diagnose') }}</p>
                                <div class="{{ $groupButtons }}">
                                    <button type="button" wire:click="recheckCacheServiceInstance('{{ $engine }}')" wire:loading.attr="disabled" wire:target="recheckCacheServiceInstance" class="{{ $btnDiagnose }}" title="{{ __('Re-run the reachability probe against this instance\'s port') }}">
                                        <span wire:loading.remove wire:target="recheckCacheServiceInstance" class="inline-flex items-center gap-1.5">
                                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                            {{ __('Recheck') }}
                                        </span>
                                        <span wire:loading wire:target="recheckCacheServiceInstance" class="inline-flex items-center gap-1.5">
                                            <x-spinner variant="forest" />
                                            {{ __('Checking…') }}
                                        </span>
                                    </button>
                                    @if (! $probeRunning)
                                        <button type="button" wire:click="debugCacheServiceInstance('{{ $engine }}')" wire:loading.attr="disabled" wire:target="debugCacheServiceInstance" class="{{ $btnDebug }}" title="{{ __('Run systemctl status + port-listener + ping diagnostics and surface the output below') }}">
                                            <span wire:loading.remove wire:target="debugCacheServiceInstance" class="inline-flex items-center gap-1.5">
                                                <x-heroicon-o-bug-ant class="h-3.5 w-3.5" />
                                                {{ __('Debug') }}
                                            </span>
                                            <span wire:loading wire:target="debugCacheServiceInstance" class="inline-flex items-center gap-1.5">
                                                <x-spinner variant="forest" />
                                                {{ __('Running…') }}
                                            </span>
                                        </button>
                                    @endif
                                    <button type="button" wire:click="showCacheInstanceStatus('{{ $engine }}')" wire:loading.attr="disabled" wire:target="showCacheInstanceStatus,showCacheInstanceLogs" class="{{ $btnDiagnose }}" title="{{ __('Open systemctl status for this instance') }}">
                                        <x-heroicon-o-information-circle class="h-3.5 w-3.5" />
                                        {{ __('Status') }}
                                    </button>
                                    <button type="button" wire:click="showCacheInstanceLogs('{{ $engine }}')" wire:loading.attr="disabled" wire:target="showCacheInstanceStatus,showCacheInstanceLogs" class="{{ $btnDiagnose }}" title="{{ __('Open journalctl tail for this instance') }}">
                                        <x-heroicon-o-document-text class="h-3.5 w-3.5" />
                                        {{ __('Logs') }}
                                    </button>
                                </div>
                            </div>

                            <div class="{{ $groupShell }}">
                                <p class="{{ $groupLabel }}">{{ __('Lifecycle') }}</p>
                                <div class="{{ $groupButtons }}">
                                    @if ($lifecycleAvailable)
                                        <button type="button" wire:click="restartCacheService('{{ $engine }}')" wire:loading.attr="disabled" wire:target="restartCacheService" class="{{ $btnLifecycle }}">
                                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                                            <span wire:loading.remove wire:target="restartCacheService">{{ __('Restart') }}</span>
                                            <span wire:loading wire:target="restartCacheService">{{ __('Restarting…') }}</span>
                                        </button>
                                        @if ($row->status !== \App\Models\ServerCacheService::STATUS_STOPPED)
                                            <button type="button" wire:click="stopCacheService('{{ $engine }}')" wire:loading.attr="disabled" wire:target="stopCacheService" class="{{ $btnLifecycle }}" title="{{ __('Stop the daemon now. Boot-time auto-start is unchanged — it will come back on reboot.') }}">
                                                <x-heroicon-o-stop-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('Stop') }}
                                            </button>
                                            {{-- Disable: stronger than Stop. `systemctl disable --now`
                                                 halts the daemon AND removes its boot-time enablement
                                                 so it won't auto-start on reboot. Use when the
                                                 operator wants the service off for the long haul
                                                 without uninstalling. --}}
                                            <button type="button" wire:click="disableCacheService('{{ $engine }}')" wire:loading.attr="disabled" wire:target="disableCacheService" class="{{ $btnLifecycle }}" title="{{ __('Stop the daemon AND prevent it from starting at boot. Package + data dirs stay; re-enable later when you need it back.') }}">
                                                <x-heroicon-o-no-symbol class="h-3.5 w-3.5" aria-hidden="true" />
                                                <span wire:loading.remove wire:target="disableCacheService">{{ __('Disable') }}</span>
                                                <span wire:loading wire:target="disableCacheService">{{ __('Disabling…') }}</span>
                                            </button>
                                        @else
                                            <button type="button" wire:click="startCacheService('{{ $engine }}')" wire:loading.attr="disabled" wire:target="startCacheService" class="{{ $btnLifecycle }}" title="{{ __('Start the daemon now. Boot-time enablement is unchanged.') }}">
                                                <x-heroicon-o-play-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('Start') }}
                                            </button>
                                            {{-- Enable: counterpart to Disable. `systemctl enable --now`
                                                 re-arms boot auto-start AND starts the daemon
                                                 immediately, so one click instead of two. --}}
                                            <button type="button" wire:click="enableCacheService('{{ $engine }}')" wire:loading.attr="disabled" wire:target="enableCacheService" class="{{ $btnLifecycle }}" title="{{ __('Start the daemon AND re-arm boot-time auto-start. Use this when bringing the service back after a Disable.') }}">
                                                <x-heroicon-o-check-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                                <span wire:loading.remove wire:target="enableCacheService">{{ __('Enable') }}</span>
                                                <span wire:loading wire:target="enableCacheService">{{ __('Enabling…') }}</span>
                                            </button>
                                        @endif
                                        @if ($row->status === \App\Models\ServerCacheService::STATUS_RUNNING)
                                            <button type="button" wire:click="openConfirmActionModal('flushCacheService', ['{{ $engine }}'], @js(__('Flush all keys')), @js(__('Drop every key in :engine. App sessions, queued tags, and rate-limit counters in this engine will all be reset. Cannot be undone.', ['engine' => $engineLabels[$engine]])), @js(__('Flush all keys')), true)" class="{{ $btnDanger }}">
                                                <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('Flush all keys') }}
                                            </button>
                                        @endif
                                    @else
                                        <p class="text-xs italic text-brand-mist">
                                            {{ __('Not available — row is :status. The busy banner above offers cancel/force-cancel for in-flight installs.', ['status' => $row->status]) }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="{{ $groupShell }}">
                                <p class="{{ $groupLabel }}">{{ __('Cleanup') }}</p>
                                <div class="{{ $groupButtons }}">
                                    @if ($lifecycleAvailable)
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('uninstallCacheService', ['{{ $engine }}'], @js($uninstallTitle), @js($uninstallConfirm), @js($uninstallLabel), true)"
                                            class="{{ $btnDanger }}"
                                            title="{{ $isLastInstanceOfEngine ? __('apt purge — removes the package') : __('Removes this instance only — package stays for the other instances') }}"
                                        >
                                            <x-heroicon-o-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ $uninstallLabel }}
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('forceRemoveCacheServiceRow', ['{{ $engine }}'], @js(__('Force remove dply row?')), @js($forceRemoveConfirm), @js(__('Force remove')), true)"
                                        class="{{ $btnMuted }}"
                                        title="{{ __('Delete this row from dply without touching the server. Use when uninstall is stuck or the install never landed.') }}"
                                    >
                                        <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Force remove row') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <x-explainer class="mt-6" tone="warn" :title="__('What do these actions do?')">
                            <ul>
                                <li><strong>{{ __('Diagnose') }}.</strong> {{ __('Recheck re-runs the per-instance ping. Debug runs systemctl status + port listener + journal. Status / Logs open the per-instance modal.') }}</li>
                                <li><strong>{{ __('Lifecycle: Restart / Stop / Start') }}.</strong> {{ __('Acts on THIS instance\'s systemd unit only. Other instances on this engine are not affected.') }}</li>
                                <li><strong>{{ __('Lifecycle: Disable / Enable') }}.</strong> {{ __('Disable = stop now AND remove boot auto-start. Enable = start now AND re-arm boot auto-start. Use these when you want the daemon off (or back on) across reboots without uninstalling.') }}</li>
                                <li><strong>{{ __('Lifecycle: Flush all keys') }}.</strong> {{ __('Drops every key in this instance — sessions, cache, queued tags, rate-limit counters. Cannot be undone. Sibling instances keep their data.') }}</li>
                                @if ($isLastInstanceOfEngine)
                                    <li><strong>{{ __('Cleanup: Uninstall') }}.</strong> {{ __('Last instance of :engine on this server — runs apt purge for the package + data dirs. Other engines on this server are not affected.', ['engine' => $engineLabels[$engine]]) }}</li>
                                @else
                                    <li><strong>{{ __('Cleanup: Remove instance') }}.</strong> {{ __(':n other :engine instance(s) still use the package, so apt purge would break them. This affordance only removes the systemd unit + config + data dir for THIS instance.', ['n' => $siblingInstanceCount, 'engine' => $engineLabels[$engine]]) }}</li>
                                @endif
                                <li><strong>{{ __('Cleanup: Force remove row') }}.</strong> {{ __('Deletes the dply row only. Does NOT touch the server. Use when uninstall keeps failing or the install never landed on the box.') }}</li>
                            </ul>
                        </x-explainer>
                    </div>

                    {{-- Connection details: host, port, AUTH password (with
                         show/copy), and a ready-to-paste CLI command. Renders
                         BEFORE the language-specific snippet so the values
                         operators need are visible without scrolling. --}}
                    @include('livewire.servers.partials.cache.connection-details', [
                        'row' => $row,
                        'server' => $server,
                        'card' => $card,
                        'engineLabels' => $engineLabels,
                    ])

                    {{-- Connection snippet for this engine. --}}
                    @include('livewire.servers.partials.cache-connection-snippet', [
                        'cacheService' => $row,
                        'card' => $card,
                        'engineLabels' => $engineLabels,
                        'server' => $server,
                    ])
                    @endif {{-- /overview subtab --}}

                    {{-- Port card (Configure subtab, all engines). Restarts the unit. --}}
                    @if ($activeSubtab === 'configure')
                        <div class="{{ $card }} p-6 sm:p-8">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine — listen port', ['engine' => $engineLabels[$engine]]) }}</h3>
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
                            <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine — AUTH password', ['engine' => $engineLabels[$engine]]) }}</h3>
                            <p class="mt-2 text-sm text-brand-moss">
                                @if (filled($row->auth_password))
                                    {{ __('A password is set. Apps connecting to this engine must send AUTH. Rotate by entering a new value below.') }}
                                @else
                                    {{ __('No AUTH password is set. Anything that can reach the loopback port can issue commands. Set one below to require authentication.') }}
                                @endif
                            </p>

                            {{-- Current password reveal. The Overview tab carries this too via
                                 the connection details card; mirroring it here lets operators
                                 who land directly on Configure recover the value without
                                 round-tripping the Overview subtab. --}}
                            @if (filled($row->auth_password))
                                <div class="mt-5 max-w-xl" x-data="{
                                    shown: false,
                                    copyCurrent() {
                                        navigator.clipboard?.writeText(@js((string) $row->auth_password));
                                        this.$dispatch('toast', { message: @js(__('Password copied')) });
                                    },
                                }">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Current password') }}</p>
                                    <div class="mt-1 flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-zinc-50 px-3 py-2">
                                        <code class="min-w-0 flex-1 truncate font-mono text-sm text-brand-ink">
                                            <span x-show="! shown">{{ str_repeat('•', min(strlen((string) $row->auth_password), 24)) }}</span>
                                            <span x-show="shown" x-cloak>{{ $row->auth_password }}</span>
                                        </code>
                                        <button
                                            type="button"
                                            x-on:click="shown = !shown"
                                            class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink"
                                            :title="shown ? @js(__('Hide')) : @js(__('Show'))"
                                        >
                                            <x-heroicon-o-eye class="h-4 w-4" x-show="! shown" />
                                            <x-heroicon-o-eye-slash class="h-4 w-4" x-show="shown" x-cloak />
                                        </button>
                                        <button
                                            type="button"
                                            x-on:click="copyCurrent()"
                                            class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink"
                                            title="{{ __('Copy') }}"
                                        >
                                            <x-heroicon-o-clipboard class="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            @endif

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
                                    <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine — network exposure', ['engine' => $engineLabels[$engine]]) }}</h3>
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
                                        <span wire:loading wire:target="exposeCacheToNetwork">{{ __('Exposing…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        </x-modal>

                        {{-- Memory limits card (redis-family only). --}}
                        <div class="{{ $card }} p-6 sm:p-8">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine — memory limits', ['engine' => $engineLabels[$engine]]) }}</h3>
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

                        {{-- Redis system snapshot — live INFO stats + raw INFO action.
                             Reads $server->meta['manage_redis']['info_raw'], populated by the
                             server inventory probe (RunsServerInventoryProbe in Manage). Card
                             stays gated on $engine === 'redis' because the probe targets the
                             system Redis package, not Valkey/KeyDB/Dragonfly. Migrated here
                             from /servers/{id}/manage/data when that tab was retired. --}}
                        @if ($activeSubtab === 'stats' && $engine === 'redis')
                            @php
                                $manageRedis = is_array($server->meta['manage_redis'] ?? null) ? $server->meta['manage_redis'] : [];
                                $manageRedisInfo = [];
                                if (! empty($manageRedis['info_raw']) && is_string($manageRedis['info_raw'])) {
                                    foreach (explode("\n", $manageRedis['info_raw']) as $rline) {
                                        $rline = trim($rline);
                                        if ($rline === '' || str_starts_with($rline, '#')) {
                                            continue;
                                        }
                                        if (str_contains($rline, ':')) {
                                            [$rk, $rv] = explode(':', $rline, 2);
                                            $manageRedisInfo[trim($rk)] = trim($rv);
                                        }
                                    }
                                }
                                $manageRedisHitRate = null;
                                if (isset($manageRedisInfo['keyspace_hits'], $manageRedisInfo['keyspace_misses'])) {
                                    $hits = (int) $manageRedisInfo['keyspace_hits'];
                                    $misses = (int) $manageRedisInfo['keyspace_misses'];
                                    if ($hits + $misses > 0) {
                                        $manageRedisHitRate = round($hits / ($hits + $misses) * 100, 1);
                                    }
                                }
                                $manageRedisFormatBytes = function ($bytes): string {
                                    if (! is_numeric($bytes) || $bytes <= 0) {
                                        return '—';
                                    }
                                    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                                    $i = 0;
                                    $val = (float) $bytes;
                                    while ($val >= 1024 && $i < count($units) - 1) {
                                        $val /= 1024;
                                        $i++;
                                    }

                                    return rtrim(rtrim(number_format($val, $val < 10 ? 1 : 0), '0'), '.').' '.$units[$i];
                                };
                            @endphp
                            <div class="{{ $card }} p-6 sm:p-8">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="max-w-2xl">
                                        <h3 class="text-base font-semibold text-brand-ink">{{ __('System INFO snapshot') }}</h3>
                                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Last `redis-cli INFO` output captured by the host inventory probe. Refresh the probe from Manage → Overview to update.') }}</p>
                                    </div>
                                    @if (! empty($serviceActions['redis_info']) && ! $rowInFlight)
                                        @php $redisInfoAction = $serviceActions['redis_info']; @endphp
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('runAllowlistedManageAction', ['redis_info'], @js($redisInfoAction['label']), @js($redisInfoAction['confirm']), @js($redisInfoAction['label']), false)"
                                            class="inline-flex shrink-0 items-center gap-2 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                                            {{ $redisInfoAction['label'] }}
                                        </button>
                                    @endif
                                </div>

                                @if ($manageActionRun)
                                    <div class="mt-4">
                                        @include('livewire.partials.console-action-banner-static', [
                                            'run' => $manageActionRun,
                                            'kindLabels' => (array) config('console_actions.kinds', []),
                                        ])
                                    </div>
                                @endif

                                @if (! empty($manageRedisInfo))
                                    <dl class="mt-5 grid gap-4 sm:grid-cols-4">
                                        <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Connected clients') }}</dt>
                                            <dd class="mt-1 text-xl font-semibold text-brand-ink">{{ $manageRedisInfo['connected_clients'] ?? '—' }}</dd>
                                        </div>
                                        <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Used memory') }}</dt>
                                            <dd class="mt-1 text-xl font-semibold text-brand-ink">{{ $manageRedisInfo['used_memory_human'] ?? $manageRedisFormatBytes($manageRedisInfo['used_memory'] ?? 0) }}</dd>
                                        </div>
                                        <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Hit rate') }}</dt>
                                            <dd class="mt-1 text-xl font-semibold text-brand-ink">{{ $manageRedisHitRate !== null ? $manageRedisHitRate.'%' : '—' }}</dd>
                                        </div>
                                        <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Last RDB save') }}</dt>
                                            <dd class="mt-1 text-xs font-medium text-brand-ink">
                                                @if (isset($manageRedisInfo['rdb_last_save_time']))
                                                    {{ \Illuminate\Support\Carbon::createFromTimestamp((int) $manageRedisInfo['rdb_last_save_time'])->diffForHumans() }}
                                                @else
                                                    —
                                                @endif
                                            </dd>
                                        </div>
                                    </dl>
                                @else
                                    <p class="mt-4 text-sm text-brand-moss">{{ __('No INFO snapshot yet. Visit Manage → Overview and Refresh state, or run Show Redis INFO above.') }}</p>
                                @endif
                            </div>
                        @endif

                        {{-- Connected clients (redis-family only, Stats subtab). --}}
                        @if ($activeSubtab === 'stats')
                        <div class="{{ $card }} p-6 sm:p-8">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine — connected clients', ['engine' => $engineLabels[$engine]]) }}</h3>
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
                                <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine — server config file', ['engine' => $engineLabels[$engine]]) }}</h3>
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
