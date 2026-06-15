@php
    $card = 'dply-card overflow-hidden';
    $canEdit = auth()->user()->can('update', $site);
    $monitorCount = $site->uptimeMonitors->count();

    $stateStyles = [
        'operational' => ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-700', 'bg' => 'bg-emerald-50', 'ring' => 'ring-emerald-200', 'label' => __('Operational')],
        'degraded' => ['dot' => 'bg-amber-500', 'text' => 'text-amber-700', 'bg' => 'bg-amber-50', 'ring' => 'ring-amber-200', 'label' => __('Degraded')],
        'outage' => ['dot' => 'bg-red-500', 'text' => 'text-red-700', 'bg' => 'bg-red-50', 'ring' => 'ring-red-200', 'label' => __('Outage')],
        'unknown' => ['dot' => 'bg-slate-400', 'text' => 'text-slate-600', 'bg' => 'bg-slate-50', 'ring' => 'ring-slate-200', 'label' => __('Unknown')],
    ];

    // Monitor only triggers uptime_check runs — scope the banner to that kind so
    // unrelated site-scoped actions (webserver config applies, basic-auth rotations,
    // …) don't surface here.
    $consoleActionRun = \App\Models\ConsoleAction::query()
        ->where('subject_type', $site->getMorphClass())
        ->where('subject_id', $site->id)
        ->where('kind', 'uptime_check')
        ->whereNull('dismissed_at')
        ->orderByDesc('created_at')
        ->first();
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Monitor'),
        'currentIcon' => 'chart-bar',
        'contextualDocSlug' => 'vm-site-monitor',
    ])

    <x-hero-card
        :eyebrow="__('Site')"
        :title="__('Monitor')"
        :description="__('Uptime, SSL, and response-time monitors for this site, each running checks from dply infrastructure on demand or on a schedule.')"
        icon="chart-bar"
        class="mb-6"
    />

    @if (session('success'))
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('success') }}</div>
    @endif

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">

            <x-server-workspace-tablist :aria-label="__('Monitor workspace sections')" scroll class="sm:min-w-0 sm:flex-1">
                <x-server-workspace-tab
                    id="monitor-tab-monitors"
                    icon="heroicon-o-signal"
                    :active="$monitorTab === 'monitors'"
                    wire:click="setMonitorWorkspaceTab('monitors')"
                >
                    {{ __('Monitors') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="monitor-tab-alerts"
                    icon="heroicon-o-bell-alert"
                    :active="$monitorTab === 'alerts'"
                    wire:click="setMonitorWorkspaceTab('alerts')"
                >
                    {{ __('Alerts') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            @if ($monitorTab === 'monitors')
            {{-- Single console-actions banner: shows the latest non-dismissed run for this
                 site. Uptime checks queue/run/finish here just like webserver_config and
                 the other site-scoped jobs do on Settings — newer runs supersede older ones. --}}
            @include('livewire.partials.console-action-banner-static', [
                'run' => $consoleActionRun,
                'kindLabels' => (array) config('console_actions.kinds', []),
            ])

            {{-- Function activity — serverless only. Auto-populated from
                 function_invocations; no setup, no manual wiring. --}}
            @if (($runtimeMode ?? '') === 'serverless' && $functionStats)
                @php $fnSummary = $functionStats['summary']; @endphp
                <section class="{{ $card }}">
                    <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7">
                        <div class="flex min-w-0 items-start gap-3">
                            <x-icon-badge>
                                <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Insights') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Function activity') }}</h2>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Invocations, errors, latency and cold starts — every recorded call to this function.') }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/10 bg-white p-1">
                            @foreach (['1h' => __('1h'), '24h' => __('24h'), '7d' => __('7d')] as $rangeKey => $rangeLabel)
                                <button type="button" wire:click="setStatsRange('{{ $rangeKey }}')" @class([
                                    'rounded-md px-2.5 py-1 text-xs font-semibold transition',
                                    'bg-white text-brand-ink shadow-sm' => $statsRange === $rangeKey,
                                    'text-brand-moss hover:text-brand-ink' => $statsRange !== $rangeKey,
                                ])>{{ $rangeLabel }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-5 px-6 py-5 sm:px-8">
                        @if ($fnSummary['invocations'] === 0)
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-4 py-6 text-center text-sm text-brand-moss">
                                {{ __('No invocations in this window yet. Background ticks, test requests, and live traffic all land here.') }}
                            </div>
                        @else
                            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                @foreach ([
                                    ['label' => __('Invocations'), 'value' => number_format($fnSummary['invocations'])],
                                    ['label' => __('Error rate'), 'value' => $fnSummary['error_rate'].'%'],
                                    ['label' => __('Avg duration'), 'value' => $fnSummary['avg_duration'].'ms'],
                                    ['label' => __('p95 duration'), 'value' => $fnSummary['p95_duration'].'ms'],
                                    ['label' => __('Cold starts'), 'value' => $fnSummary['cold_rate'].'%'],
                                ] as $stat)
                                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3">
                                        <dt class="text-[10px] font-medium uppercase tracking-wide text-brand-moss/70">{{ $stat['label'] }}</dt>
                                        <dd class="mt-0.5 text-lg font-bold text-brand-ink">{{ $stat['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>

                            <div class="grid gap-5 sm:grid-cols-2">
                                @foreach ([
                                    ['title' => __('Invocations'), 'series' => $functionStats['series']['invocations'], 'color' => 'text-brand-forest', 'fmt' => 'load', 'ymax' => null],
                                    ['title' => __('Error rate %'), 'series' => $functionStats['series']['error_rate'], 'color' => 'text-rose-500', 'fmt' => 'percent', 'ymax' => 100],
                                    ['title' => __('Duration (ms)'), 'series' => $functionStats['series']['duration'], 'color' => 'text-sky-600', 'fmt' => 'load', 'ymax' => null],
                                    ['title' => __('Cold-start rate %'), 'series' => $functionStats['series']['cold_rate'], 'color' => 'text-brand-gold', 'fmt' => 'percent', 'ymax' => 100],
                                ] as $chart)
                                    <div>
                                        <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ $chart['title'] }}</p>
                                        <x-metrics-line-chart :series="$chart['series']" :yMax="$chart['ymax']" :colorClass="$chart['color']" :format="$chart['fmt']" heightClass="h-24" />
                                    </div>
                                @endforeach
                            </div>

                            <p class="text-[11px] text-brand-moss/60">
                                {{ __(':tick ticks · :test test · :web web in this window.', ['tick' => $fnSummary['by_source']['tick'], 'test' => $fnSummary['by_source']['test'], 'web' => $fnSummary['by_source']['web']]) }}
                                <button type="button" wire:click="refreshStats" class="ml-1 font-semibold text-brand-sage hover:underline">{{ __('Refresh') }}</button>
                            </p>
                        @endif
                    </div>
                </section>
            @endif

            {{-- Slim trigger card. "Add a monitor" resets the form and opens the modal. --}}
            <div class="{{ $card }}">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
                    <div class="flex min-w-0 items-start gap-3">
                        <x-icon-badge>
                            <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Uptime') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add a monitor') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                {{ __('Check uptime and content over HTTP, or watch a TLS certificate. The first check runs immediately.') }}
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                    {{ trans_choice('{0} no monitors yet|{1} :count monitor|[2,*] :count monitors', $monitorCount, ['count' => $monitorCount]) }}
                                </span>
                                @if ($resolvedBaseUrl !== null)
                                    <span class="text-brand-mist/60">·</span>
                                    <span class="font-mono">{{ $hostnameDisplay }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="startAddMonitor"
                            @disabled(! $canEdit || $resolvedBaseUrl === null)
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-o-plus class="h-4 w-4" />
                            {{ __('Add a monitor') }}
                        </button>
                    </div>
                </div>

                @if ($resolvedBaseUrl === null)
                    <div class="border-t border-amber-200/70 bg-amber-50 px-6 py-4 sm:px-8">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-100 text-amber-700 ring-amber-200">
                                <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Blocked') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-amber-950">{{ __('No public URL yet') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-amber-900">{{ __('Add a primary domain, preview hostname, or publication URL before uptime checks can run.') }}</p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Add / edit monitor modal. Check type drives which fields show
                 (Alpine entangle keeps it instant). Closes on success. --}}
            <x-modal name="uptime-monitor-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
                <div x-data="{ ctype: $wire.entangle('newCheckType') }">
                    <div class="border-b border-brand-ink/10 px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Uptime monitor') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-brand-ink">
                            <span x-show="! $wire.editingMonitorId">{{ __('Add a monitor') }}</span>
                            <span x-show="$wire.editingMonitorId" x-cloak>{{ __('Edit monitor') }}</span>
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-brand-moss">
                            {{ __('A check runs immediately after save, from the selected worker location.') }}
                        </p>
                    </div>

                    <div class="px-6 py-6">
                        <form wire:submit="saveMonitor" id="uptime-monitor-form" class="space-y-4">
                            <div>
                                <x-input-label for="uptime-label" :value="__('Label')" />
                                <x-text-input id="uptime-label" wire:model="newLabel" class="mt-1 block w-full" placeholder="{{ __('e.g. Homepage check') }}" :disabled="! $canEdit" />
                                <x-input-error :messages="$errors->get('newLabel')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="uptime-check-type" :value="__('Check type')" />
                                <select
                                    id="uptime-check-type"
                                    wire:model="newCheckType"
                                    x-model="ctype"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                    @disabled(! $canEdit)
                                >
                                    <option value="http">{{ __('HTTP — reachability & content') }}</option>
                                    <option value="ssl">{{ __('SSL — certificate expiry') }}</option>
                                </select>
                                <x-input-error :messages="$errors->get('newCheckType')" class="mt-1" />
                            </div>

                            {{-- HTTP fields --}}
                            <div x-show="ctype === 'http'" class="space-y-4">
                                <div>
                                    <x-input-label for="uptime-path" :value="__('Path (optional)')" />
                                    <div class="mt-1 flex rounded-lg border border-brand-ink/15 bg-slate-50 shadow-sm focus-within:border-brand-sage focus-within:ring-2 focus-within:ring-brand-sage/30">
                                        <span class="inline-flex items-center border-r border-brand-ink/10 bg-slate-100 px-3 text-xs font-mono text-slate-600 truncate max-w-[14rem] sm:max-w-md" title="{{ $hostnameDisplay ?? '' }}">{{ $hostnameDisplay ?? '—' }} /</span>
                                        <input
                                            id="uptime-path"
                                            type="text"
                                            wire:model="newPath"
                                            class="block min-w-0 flex-1 border-0 bg-white px-3 py-2 text-sm text-brand-ink focus:ring-0"
                                            placeholder="{{ __('api/health') }}"
                                            @disabled(! $canEdit)
                                        />
                                    </div>
                                    <x-input-error :messages="$errors->get('newPath')" class="mt-1" />
                                </div>

                                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 space-y-4">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Assertions (optional)') }}</p>

                                    <div>
                                        <x-input-label for="uptime-expected-status" :value="__('Expected status code')" />
                                        <x-text-input id="uptime-expected-status" type="number" min="100" max="599" wire:model="newExpectedStatus" class="mt-1 block w-full" placeholder="{{ __('Any 2xx') }}" :disabled="! $canEdit" />
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('Leave blank to accept any 2xx response.') }}</p>
                                        <x-input-error :messages="$errors->get('newExpectedStatus')" class="mt-1" />
                                    </div>

                                    <div>
                                        <x-input-label for="uptime-keyword" :value="__('Body text')" />
                                        <div class="mt-1 flex flex-col gap-2 sm:flex-row">
                                            <select wire:model="newMatchMode" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 sm:w-44" @disabled(! $canEdit)>
                                                <option value="must_contain">{{ __('Must contain') }}</option>
                                                <option value="must_not_contain">{{ __('Must not contain') }}</option>
                                            </select>
                                            <x-text-input id="uptime-keyword" wire:model="newKeyword" class="block w-full" placeholder="{{ __('e.g. Welcome') }}" :disabled="! $canEdit" />
                                        </div>
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('A failed body assertion reads as an outage even when the server returns 200.') }}</p>
                                        <x-input-error :messages="$errors->get('newKeyword')" class="mt-1" />
                                    </div>

                                    <div>
                                        <x-input-label for="uptime-threshold" :value="__('Response-time threshold (ms)')" />
                                        <x-text-input id="uptime-threshold" type="number" min="1" max="120000" wire:model="newResponseThresholdMs" class="mt-1 block w-full" placeholder="{{ __('Off') }}" :disabled="! $canEdit" />
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('Slower-than-this responses read as degraded (still up). Leave blank to disable.') }}</p>
                                        <x-input-error :messages="$errors->get('newResponseThresholdMs')" class="mt-1" />
                                    </div>
                                </div>
                            </div>

                            {{-- SSL fields --}}
                            <div x-show="ctype === 'ssl'" x-cloak class="space-y-4">
                                <div>
                                    <x-input-label for="uptime-ssl-warn" :value="__('Warn before expiry (days)')" />
                                    <x-text-input id="uptime-ssl-warn" type="number" min="1" max="90" wire:model="newSslWarnDays" class="mt-1 block w-full" placeholder="{{ (string) config('site_uptime.ssl_warn_days', 14) }}" :disabled="! $canEdit" />
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Alert this many days before the certificate expires. SSL checks run daily. Default :days.', ['days' => config('site_uptime.ssl_warn_days', 14)]) }}</p>
                                    <x-input-error :messages="$errors->get('newSslWarnDays')" class="mt-1" />
                                </div>
                            </div>

                            {{-- Adaptive worker selector — dropdown once ≥2 workers exist. --}}
                            @if (count($probeWorkerOptions) > 1)
                                <div>
                                    <x-input-label for="uptime-worker" :value="__('Check from')" />
                                    <select
                                        id="uptime-worker"
                                        wire:model="newProbeWorker"
                                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                        @disabled(! $canEdit)
                                    >
                                        @foreach ($probeWorkerOptions as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('newProbeWorker')" class="mt-1" />
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('The dply worker location the check runs from.') }}</p>
                                </div>
                            @elseif (count($probeWorkerOptions) === 1)
                                <div>
                                    <x-input-label :value="__('Check from')" />
                                    <p class="mt-1 text-sm font-medium text-brand-ink">{{ reset($probeWorkerOptions) }}</p>
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('The only dply worker location available today. More regions appear here as they come online.') }}</p>
                                </div>
                            @endif
                        </form>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                        <p class="mr-auto text-xs text-brand-moss">{{ __('A check runs immediately after save.') }}</p>
                        <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button type="submit" form="uptime-monitor-form" wire:loading.attr="disabled" wire:target="saveMonitor">
                            <span wire:loading.remove wire:target="saveMonitor">{{ __('Save monitor') }}</span>
                            <span wire:loading wire:target="saveMonitor">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </div>
            </x-modal>

            <section class="{{ $card }}">
                <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex min-w-0 items-start gap-3">
                        <x-icon-badge>
                            <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Checks') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Monitors') }}</h2>
                        </div>
                    </div>
                    @if ($monitorCount > 0)
                        <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[11px] font-semibold text-brand-ink">{{ $monitorCount }}</span>
                    @endif
                </div>
                @if ($site->uptimeMonitors->isEmpty())
                    <p class="px-6 py-10 text-sm text-brand-moss text-center">{{ __('No monitors yet — add one to start checking this site.') }}</p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($site->uptimeMonitors as $m)
                            @php
                                $regionLabel = $probeRegions[$m->probe_region] ?? $m->probe_region;
                                $state = $operationalState->state($m);
                                $style = $stateStyles[$state] ?? $stateStyles['unknown'];
                                $isExpanded = $expandedMonitorId === $m->id;
                                $sslDays = is_array($m->last_meta) ? ($m->last_meta['ssl_days_remaining'] ?? null) : null;
                            @endphp
                            <li wire:key="monitor-{{ $m->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-4">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $style['bg'] }} {{ $style['text'] }} {{ $style['ring'] }}">
                                                <span class="h-1.5 w-1.5 rounded-full {{ $style['dot'] }}"></span>
                                                {{ $style['label'] }}
                                            </span>
                                            <p class="font-medium text-brand-ink">{{ $m->label }}</p>
                                            <span class="inline-flex items-center rounded bg-brand-sand/50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $m->isSslCheck() ? __('SSL') : __('HTTP') }}</span>
                                        </div>
                                        <p class="mt-0.5 text-xs font-mono text-brand-moss truncate" title="{{ $hostnameDisplay }}{{ $m->normalizedPath() }}">{{ $hostnameDisplay }}{{ $m->isSslCheck() ? '' : ($m->normalizedPath() ?: '/') }}</p>
                                        <p class="mt-1 text-xs text-brand-moss">{{ $regionLabel }}</p>
                                        @if ($m->last_checked_at)
                                            <p class="mt-2 text-xs text-brand-moss">
                                                @if ($m->isSslCheck() && is_numeric($sslDays) && (int) $sslDays >= 0)
                                                    {{ trans_choice('{0}Expires today|{1}Expires in :count day|[2,*]Expires in :count days', (int) $sslDays, ['count' => (int) $sslDays]) }}
                                                @endif
                                                @if ($m->last_http_status)
                                                    · HTTP {{ $m->last_http_status }}
                                                @endif
                                                @if ($m->last_latency_ms !== null)
                                                    · {{ $m->last_latency_ms }} ms
                                                @endif
                                                · {{ $m->last_checked_at->timezone(config('app.timezone'))->toDayDateTimeString() }}
                                            </p>
                                            @if ($m->last_error)
                                                <p class="mt-1 text-xs {{ $state === 'degraded' ? 'text-amber-700' : 'text-red-700' }} truncate" title="{{ $m->last_error }}">{{ $m->last_error }}</p>
                                            @endif
                                        @else
                                            <p class="mt-2 text-xs text-brand-moss">{{ __('Not checked yet.') }}</p>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            wire:click="toggleHistory('{{ $m->id }}')"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-chart-bar class="h-4 w-4" />
                                            {{ $isExpanded ? __('Hide history') : __('History') }}
                                        </button>
                                        @if ($canEdit)
                                            <button
                                                type="button"
                                                wire:click="runCheckNow('{{ $m->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="runCheckNow"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <x-heroicon-o-arrow-path class="h-4 w-4" />
                                                <span wire:loading.remove wire:target="runCheckNow">{{ __('Check now') }}</span>
                                                <span wire:loading wire:target="runCheckNow">{{ __('Queueing…') }}</span>
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="editMonitor('{{ $m->id }}')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                            >
                                                <x-heroicon-o-pencil-square class="h-4 w-4" />
                                                {{ __('Edit') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="confirmRemoveMonitor('{{ $m->id }}')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-800 shadow-sm hover:bg-red-50"
                                            >
                                                <x-heroicon-o-trash class="h-4 w-4" />
                                                {{ __('Remove') }}
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                @if ($isExpanded)
                                    <div class="border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-5">
                                        @include('livewire.sites.partials.monitor.history-detail', [
                                            'history' => $expandedHistory,
                                            'monitor' => $m,
                                        ])
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <p class="text-sm text-brand-moss">
                {{ __('Show monitors on a public') }}
                <a href="{{ route('status-pages.index') }}" class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('status page') }}</a>.
            </p>

            <x-cli-snippet :commands="[
                ['label' => __('Check uptime'), 'command' => 'dply sites:uptime '.$site->slug],
                ['label' => __('View history'), 'command' => 'dply sites:uptime:history '.$site->slug],
            ]" />
            @endif

            @if ($monitorTab === 'alerts')
                {{-- Alerts: route channels to this site's uptime / SSL events. --}}
                @include('livewire.sites.partials.monitor.notifications-card')
            @endif
        </main>
    </div>

    @include('livewire.partials.confirm-action-modal')
    @include('livewire.partials.create-notification-channel-modal')
</div>
