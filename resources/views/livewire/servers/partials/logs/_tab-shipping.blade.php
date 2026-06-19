@php
    $status = $agent?->status;
    $busy = $agent !== null && in_array($status, ['installing', 'uninstalling'], true);
    $statusMeta = match ($status) {
        'running' => ['tone' => 'emerald', 'label' => __('Running')],
        'installing' => ['tone' => 'sky', 'label' => __('Installing…')],
        'uninstalling' => ['tone' => 'sky', 'label' => __('Removing…')],
        'failed' => ['tone' => 'rose', 'label' => __('Failed')],
        default => ['tone' => 'mist', 'label' => __('Idle')],
    };
    $statusTone = $tonePalette[$statusMeta['tone']] ?? $tonePalette['mist'];
@endphp

<div class="space-y-6">
    {{-- Poll while a job is in flight so install output + status stream in --}}
    @if ($busy)
        <div wire:poll.2s="pollLogShipping" class="hidden" aria-hidden="true"></div>
    @endif

    {{-- Aggregator role: this box is the dply Logs ingest tier. Prompt a re-sync
         when it's running an older rendered config than this build of dply ships. --}}
    @php
        $aggregator = $this->logAggregator;
    @endphp
    @if ($aggregator !== null)
        @php
            $aggCurrent = \App\Models\ServerLogAggregator::currentConfigVersion();
            $aggInstalled = $aggregator->installedConfigVersion();
            $aggStale = $aggregator->isConfigStale();
            $aggBusy = $aggregator->isBusy();
        @endphp
        @if ($aggBusy)
            <div wire:poll.2s="pollLogShipping" class="hidden" aria-hidden="true"></div>
        @endif
        <section class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 px-6 py-4 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-inbox-arrow-down class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Log aggregator') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                        {{ __('This server is the dply Logs ingest tier') }}
                    </h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Edges across the fleet ship here over mTLS; it writes to ClickHouse.') }}
                        <span class="font-medium text-brand-ink">
                            {{ __('Config') }} {{ $aggInstalled ? 'v'.$aggInstalled : __('unknown') }}
                            @if (! $aggStale && $aggregator->isRunning())
                                <span class="text-brand-sage">· {{ __('up to date') }}</span>
                            @else
                                <span class="opacity-70">→ v{{ $aggCurrent }}</span>
                            @endif
                        </span>
                    </p>
                </div>
                @if ($aggStale)
                    <button type="button" wire:click="resyncLogAggregator" wire:loading.attr="disabled"
                        @disabled($aggBusy)
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-brand-ink/90 disabled:opacity-50">
                        <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                        {{ $aggBusy ? __('Updating…') : __('Update aggregator') }}
                    </button>
                @endif
            </div>
            @if ($aggStale)
                <div class="border-t border-amber-200 bg-amber-50 px-6 py-3 text-sm text-amber-900 sm:px-7">
                    {{ __('A newer aggregator config is available (v:current). Re-sync to apply it — existing log rows are unaffected; new logs use the updated pipeline.', ['current' => $aggCurrent]) }}
                </div>
            @endif
        </section>
    @endif

    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-paper-airplane class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Add-on') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('dply Logs') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Run a lightweight agent (Vector) on this server to ship system + service logs to dply for persistent, searchable storage — beyond the live SSH tail. Hard-capped so it never competes with your app.') }}
                </p>
            </div>
            @if ($agent !== null)
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $statusTone }}">
                    {{ $statusMeta['label'] }}
                    @if ($agent->version)
                        <span class="font-normal opacity-70">· v{{ $agent->version }}</span>
                    @endif
                </span>
            @endif
        </div>

        <div class="space-y-5 px-6 py-5 sm:px-7">
            @unless ($this->logShippingEnabled)
                <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">
                    {{ __('The Logs add-on is not enabled in this environment yet. Set SERVER_LOGS_ENABLED=true to turn it on.') }}
                </div>
            @endunless

            @if ($agent?->error_message)
                <div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-inset ring-rose-200">
                    <p class="font-semibold">{{ __('Last error') }}</p>
                    <p class="mt-0.5 break-words">{{ $agent->error_message }}</p>
                </div>
            @endif

            {{-- Stale edge config: this build of dply renders a newer agent config than
                 the box is running. Re-sync (same action as a source change) to apply it. --}}
            @if ($agent?->isConfigStale())
                <div class="flex flex-wrap items-center gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">
                    <x-heroicon-o-arrow-path class="h-5 w-5 shrink-0" aria-hidden="true" />
                    <p class="min-w-0 flex-1">
                        {{ __('A newer log agent config is available (v:installed → v:current). Re-sync to apply it on this server.', [
                            'installed' => $agent->installedConfigVersion() ?? '—',
                            'current' => \App\Models\ServerLogAgent::currentConfigVersion(),
                        ]) }}
                    </p>
                    <button type="button" wire:click="resyncLogShipping" wire:loading.attr="disabled"
                        @disabled($busy)
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-brand-ink/90 disabled:opacity-50">
                        {{ $busy ? __('Re-syncing…') : __('Re-sync agent') }}
                    </button>
                </div>
            @endif

            {{-- Source toggles (collapsible) --}}
            @php
                $sourcesTotal = count($this->logShippingSourceCatalog);
                $sourcesOnCount = 0;
                foreach ($this->logShippingSourceCatalog as $catalogKey => $catalogLabel) {
                    if ((bool) ($this->logShippingSources[$catalogKey] ?? false)) {
                        $sourcesOnCount++;
                    }
                }
            @endphp
            <div x-data="{ open: {{ $agent === null ? 'true' : 'false' }} }" class="rounded-lg border border-brand-ink/10">
                <button
                    type="button"
                    x-on:click="open = ! open"
                    class="flex w-full items-start justify-between gap-3 px-4 py-3 text-left"
                >
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ __('Sources') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Toggle which logs this server collects. Fewer sources = less volume.') }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="rounded-full bg-brand-ink/5 px-2 py-0.5 text-[11px] font-semibold text-brand-moss">
                            {{ __(':on / :total on', ['on' => $sourcesOnCount, 'total' => $sourcesTotal]) }}
                        </span>
                        <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                    </div>
                </button>
                <div x-show="open" x-collapse>
                    <div class="grid gap-2 border-t border-brand-ink/10 px-4 py-4 sm:grid-cols-2">
                        @foreach ($this->logShippingSourceCatalog as $key => $label)
                            @php $on = (bool) ($this->logShippingSources[$key] ?? false); @endphp
                            <button
                                type="button"
                                wire:click="toggleLogShippingSource('{{ $key }}')"
                                @disabled($busy)
                                class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2.5 text-left text-sm transition disabled:opacity-50
                                    {{ $on ? 'border-brand-sage/40 bg-brand-sage/10' : 'border-brand-ink/10 bg-white hover:bg-brand-sand/20' }}"
                            >
                                <span class="min-w-0 truncate text-brand-ink">{{ $label }}</span>
                                <span class="inline-flex h-5 w-9 shrink-0 items-center rounded-full px-0.5 transition {{ $on ? 'bg-brand-sage justify-end' : 'bg-brand-ink/15 justify-start' }}">
                                    <span class="h-4 w-4 rounded-full bg-white shadow"></span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex flex-wrap items-center gap-3 border-t border-brand-ink/10 pt-4">
                @if ($agent === null || $status === 'failed')
                    <button
                        type="button"
                        wire:click="enableLogShipping"
                        wire:loading.attr="disabled"
                        @disabled(! $this->logShippingEnabled || $busy)
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-forest/90 disabled:opacity-50"
                    >
                        <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                        {{ $status === 'failed' ? __('Retry install') : __('Enable log shipping') }}
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="resyncLogShipping"
                        wire:loading.attr="disabled"
                        @disabled($busy)
                        class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/20 disabled:opacity-50"
                    >
                        <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                        {{ __('Re-sync agent') }}
                    </button>
                    <button
                        type="button"
                        wire:click="disableLogShipping"
                        wire:confirm="{{ __('Remove the log agent from this server? Shipping will stop.') }}"
                        @disabled($busy)
                        class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50"
                    >
                        <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                        {{ __('Disable') }}
                    </button>
                @endif

                @if ($busy)
                    <span class="inline-flex items-center gap-2 text-xs text-brand-moss">
                        <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                        {{ __('Working on the server…') }}
                    </span>
                @endif
            </div>

            {{-- Streaming install output --}}
            @if ($agent && trim((string) $agent->install_output) !== '')
                <div x-data="{ open: {{ $busy ? 'true' : 'false' }} }" class="rounded-lg border border-brand-ink/10 bg-brand-ink/[0.02]">
                    <button type="button" x-on:click="open = !open" class="flex w-full items-center justify-between px-4 py-2.5 text-xs font-semibold text-brand-moss">
                        {{ __('Install output') }}
                        <x-heroicon-o-chevron-down class="h-4 w-4 transition" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                    </button>
                    <div x-show="open" x-collapse>
                        <pre class="max-h-72 overflow-auto border-t border-brand-ink/10 px-4 py-3 text-[11px] leading-relaxed text-brand-ink/80">{{ $agent->install_output }}</pre>
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{-- Correlation histogram: log volume over time + deploy/error/incident overlay --}}
    @if ($agent?->isRunning())
        @if (($logCorrelationEnabled ?? true) && ($logHistogram ?? null) !== null)
            <x-logs-correlation-chart :histogram="$logHistogram" />
        @elseif (! ($logCorrelationEnabled ?? true))
            <div class="dply-card flex flex-wrap items-center justify-between gap-3 px-6 py-3 sm:px-7">
                <span class="inline-flex items-center gap-2 text-sm text-brand-moss">
                    <x-heroicon-o-chart-bar class="h-4 w-4" aria-hidden="true" />
                    {{ __('Events vs logs graph is hidden.') }}
                </span>
                <button type="button" wire:click="toggleLogCorrelation" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/20">
                    <x-heroicon-o-eye class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Show graph') }}
                </button>
            </div>
        @endif
    @endif

    {{-- Shipped logs explorer (reads ClickHouse, org + server scoped) --}}
    @if ($logExplorer !== null)
        <section class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Shipped logs') }}</h3>
                    <p class="text-xs text-brand-moss">{{ __('Searchable, persisted logs from this server (newest first).') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @unless ($logExplorer['windowed'] ?? false)
                        <button type="button" wire:click="toggleLogExplorerLive"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition',
                                'border-emerald-300 bg-emerald-50 text-emerald-800' => $logExplorerLive,
                                'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/20' => ! $logExplorerLive,
                            ])>
                            <span @class(['inline-block h-2 w-2 rounded-full', 'animate-pulse bg-emerald-500' => $logExplorerLive, 'bg-brand-ink/30' => ! $logExplorerLive])></span>
                            {{ $logExplorerLive ? __('Live') : __('Go live') }}
                        </button>
                    @endunless
                    <button type="button" wire:click="$refresh" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/20">
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" wire:loading.class="animate-spin" wire:target="$refresh" />
                        {{ __('Refresh') }}
                    </button>
                </div>
            </div>

            {{-- Auto-refresh while Live (and not pinned to a correlation window). --}}
            @if ($logExplorerLive && ! ($logExplorer['windowed'] ?? false))
                <div wire:poll.10s class="hidden" aria-hidden="true"></div>
            @endif

            @if (! ($logExplorer['available'] ?? false))
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-7">
                    <x-heroicon-o-signal-slash class="mx-auto h-6 w-6 text-brand-ink/30" aria-hidden="true" />
                    <p class="mt-2 font-medium text-brand-ink">{{ __('Log store unavailable') }}</p>
                    <p class="mt-0.5">{{ __('Could not reach the dply Logs store.') }}</p>
                </div>
            @else
                {{-- Pinned-window banner (arrived via a correlation deep-link, e.g. error → logs) --}}
                @if ($logExplorer['windowed'] ?? false)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-sage/30 bg-brand-sage/10 px-6 py-2.5 text-sm sm:px-7">
                        <span class="min-w-0 text-brand-ink">
                            <x-heroicon-o-viewfinder-circle class="mr-1 inline h-4 w-4 -translate-y-0.5 text-brand-forest" aria-hidden="true" />
                            {{ __('Pinned to a fixed window') }}
                            <span class="text-brand-moss">· {{ $logExplorer['from'] }} → {{ $logExplorer['to'] }} ({{ __('UTC') }})</span>
                        </span>
                        <button type="button" wire:click="backToLiveLogs" class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                            <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                            {{ __('Back to live') }}
                        </button>
                    </div>
                @endif

                {{-- Filters --}}
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-6 py-3 sm:px-7">
                    <div class="relative min-w-0 flex-1">
                        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-ink/30" aria-hidden="true" />
                        <input
                            type="search"
                            wire:model.live.debounce.500ms="logExplorerSearch"
                            placeholder="{{ __('Search message…') }}"
                            class="w-full rounded-lg border border-brand-ink/15 bg-white py-1.5 pl-8 pr-3 text-sm placeholder:text-brand-ink/30 focus:border-brand-sage focus:ring-brand-sage"
                        />
                    </div>
                    <select wire:model.live="logExplorerLevel" class="rounded-lg border border-brand-ink/15 bg-white py-1.5 pl-3 pr-8 text-sm focus:border-brand-sage focus:ring-brand-sage">
                        <option value="">{{ __('All levels') }}</option>
                        @foreach (['error', 'warn', 'warning', 'info', 'notice', 'debug', 'critical'] as $lvl)
                            <option value="{{ $lvl }}">{{ ucfirst($lvl) }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="logExplorerSource" class="rounded-lg border border-brand-ink/15 bg-white py-1.5 pl-3 pr-8 text-sm focus:border-brand-sage focus:ring-brand-sage">
                        <option value="">{{ __('All sources') }}</option>
                        @foreach ($this->logShippingSourceCatalog as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="logExplorerRange" class="rounded-lg border border-brand-ink/15 bg-white py-1.5 pl-3 pr-8 text-sm focus:border-brand-sage focus:ring-brand-sage">
                        <option value="15">{{ __('Last 15m') }}</option>
                        <option value="60">{{ __('Last 1h') }}</option>
                        <option value="360">{{ __('Last 6h') }}</option>
                        <option value="1440">{{ __('Last 24h') }}</option>
                    </select>
                    @if ($logExplorerSearch !== '' || $logExplorerLevel !== '' || $logExplorerSource !== '')
                        <button type="button" wire:click="clearLogExplorerFilters" class="text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('Clear') }}</button>
                    @endif
                </div>

                {{-- Results — log lines interleaved with deploy markers (deploys
                     overlapping the window shown inline, the reverse of deploy→logs) --}}
                @php
                    $rows = $logExplorer['rows'] ?? [];
                    $entries = $logExplorer['entries'] ?? [];
                    $deployCount = (int) ($logExplorer['deploy_count'] ?? 0);
                    // journald ships syslog severities as numbers — name them.
                    $syslogLevels = [0 => 'emerg', 1 => 'alert', 2 => 'crit', 3 => 'error', 4 => 'warning', 5 => 'notice', 6 => 'info', 7 => 'debug'];
                @endphp
                @if (count($entries) === 0)
                    <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-7">
                        <x-heroicon-o-inbox class="mx-auto h-6 w-6 text-brand-ink/30" aria-hidden="true" />
                        <p class="mt-2">{{ __('No log lines match in this window.') }}</p>
                        <p class="mt-0.5 text-xs">{{ __('If you just enabled shipping, allow a minute for logs to arrive.') }}</p>
                    </div>
                @else
                    <div class="max-h-[28rem] overflow-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="sticky top-0 z-10 bg-brand-sand text-[10px] uppercase tracking-wider text-brand-moss shadow-sm">
                                <tr class="border-b border-brand-ink/10">
                                    <th class="whitespace-nowrap px-4 py-2 font-semibold">{{ __('Time') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ __('Level') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ __('Source') }}</th>
                                    <th class="px-4 py-2 font-semibold">{{ __('Message') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @foreach ($entries as $entry)
                                    @if (($entry['kind'] ?? 'log') === 'deploy')
                                        @php
                                            $deployTone = match (strtolower($entry['status'] ?? '')) {
                                                'success' => 'text-emerald-800',
                                                'failed' => 'text-rose-800',
                                                default => 'text-amber-900',
                                            };
                                        @endphp
                                        <tr class="bg-brand-sage/[0.07]" wire:key="dep-{{ $entry['deployment_id'] }}">
                                            <td class="whitespace-nowrap px-4 py-1.5 font-mono text-[11px] tabular-nums text-brand-forest">{{ $entry['timestamp'] }}</td>
                                            <td colspan="3" class="px-3 py-1.5">
                                                <span class="inline-flex flex-wrap items-center gap-1.5 text-[11px] font-semibold text-brand-forest">
                                                    <x-heroicon-m-rocket-launch class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    {{ $entry['running'] ? __('Deploy in progress') : __('Deployed') }} · {{ $entry['site_name'] }}
                                                    <span class="rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wide ring-1 ring-inset ring-brand-sage/30 {{ $deployTone }}">{{ $entry['status'] }}</span>
                                                    @if ($entry['site'])
                                                        <a href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $entry['site'], 'deployment' => $entry['deployment_id']]) }}"
                                                            wire:navigate
                                                            class="inline-flex items-center gap-0.5 font-normal text-brand-moss underline-offset-2 hover:underline">
                                                            {{ __('view deploy') }} <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                                        </a>
                                                    @endif
                                                </span>
                                            </td>
                                        </tr>
                                    @else
                                        @php
                                            $rawLvl = trim((string) ($entry['level'] ?? ''));
                                            $lvl = is_numeric($rawLvl) ? ($syslogLevels[(int) $rawLvl] ?? $rawLvl) : strtolower($rawLvl);
                                            $lvlTone = match (true) {
                                                str_contains($lvl, 'err'), str_contains($lvl, 'crit'), str_contains($lvl, 'fatal'), str_contains($lvl, 'alert'), str_contains($lvl, 'emerg') => 'bg-rose-50 text-rose-700 ring-rose-200',
                                                str_contains($lvl, 'warn') => 'bg-amber-50 text-amber-800 ring-amber-200',
                                                default => 'bg-brand-sand/60 text-brand-moss ring-brand-ink/10',
                                            };
                                        @endphp
                                        <tr class="align-top hover:bg-brand-sand/10">
                                            <td class="whitespace-nowrap px-4 py-1.5 font-mono text-[11px] tabular-nums text-brand-moss">{{ $entry['timestamp'] ?? '' }}</td>
                                            <td class="px-3 py-1.5">
                                                @if ($lvl !== '')
                                                    <span class="inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase ring-1 ring-inset {{ $lvlTone }}">{{ $lvl }}</span>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-1.5 text-brand-moss">{{ $entry['source'] ?? '' }}</td>
                                            <td class="px-4 py-1.5 font-mono text-[11px] leading-relaxed text-brand-ink/80">{{ $entry['message'] ?? '' }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-brand-ink/10 px-6 py-2 text-[11px] text-brand-moss sm:px-7">
                        <span class="inline-flex flex-wrap items-center gap-x-2">
                            <span>{{ __(':n lines', ['n' => count($rows)]) }}@if (count($rows) >= $logExplorerLimit) · {{ __('showing newest :n', ['n' => $logExplorerLimit]) }}@endif</span>
                            @if ($deployCount > 0)
                                <span class="inline-flex items-center gap-1 text-brand-forest">· <x-heroicon-m-rocket-launch class="h-3 w-3" aria-hidden="true" /> {{ trans_choice(':count deploy in view|:count deploys in view', $deployCount, ['count' => $deployCount]) }}</span>
                            @endif
                        </span>
                        @php $loadMax = ($logExplorer['windowed'] ?? false) ? 2000 : 1000; @endphp
                        @if (count($rows) >= $logExplorerLimit && $logExplorerLimit < $loadMax)
                            <button type="button" wire:click="loadMoreLogExplorer" wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/20 disabled:opacity-50">
                                <x-heroicon-o-chevron-down class="h-3.5 w-3.5" aria-hidden="true" wire:loading.class="animate-spin" wire:target="loadMoreLogExplorer" />
                                {{ __('Load more') }}
                            </button>
                        @endif
                    </div>
                @endif
            @endif
        </section>
    @endif
</div>
