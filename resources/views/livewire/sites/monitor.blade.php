@php
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

{{-- Standalone Monitor page — merged chrome (no floating hero). --}}
<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Monitor'),
        'currentIcon' => 'chart-bar',
        'contextualDocSlug' => 'vm-site-monitor',
    ])

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-chart-bar"
                    :title="__('Monitor')"
                    :note="__('Uptime, SSL, and response-time monitors for this site, each running checks from dply infrastructure on demand or on a schedule.')"
                />

                @if (session('success'))
                    <div class="border-b border-emerald-200/80 bg-emerald-50/70 px-5 py-3 text-sm text-emerald-900 sm:px-6">{{ session('success') }}</div>
                @endif

                <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                    <x-server-workspace-tablist
                        :aria-label="__('Monitor workspace sections')"
                        scroll
                        bare class="!mb-0 w-full"
                    >
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
                </div>

                {{-- Same skeleton-swap the Repository / Deployments / Laravel tabs
                     use: on a tab switch wire:loading paints the shared panel
                     skeleton instantly (client-side, no extra request) instead of
                     leaving the previous tab frozen until the round-trip lands. --}}
                <div class="hidden" wire:loading.class.remove="hidden" wire:target="setMonitorWorkspaceTab">
                    @include('livewire.sites.partials._panel-skeleton')
                </div>

                <div wire:loading.class="hidden" wire:target="setMonitorWorkspaceTab">
                @if ($monitorTab === 'monitors')
                    @if ($consoleActionRun)
                        <div class="border-b border-brand-ink/10">
                            @include('livewire.partials.console-action-banner-static', [
                                'run' => $consoleActionRun,
                                'kindLabels' => (array) config('console_actions.kinds', []),
                                'embedded' => true,
                            ])
                        </div>
                    @endif


                    {{-- One header, not two. "Uptime / Add a monitor" and "Checks /
                         Monitors" were stacked panel heads describing the same list —
                         two icon badges, two eyebrows and two titles above a single
                         row. Merged: the add action and the target hostname ride in
                         the head, the count is the head's pill. --}}
                    <section class="border-b border-brand-ink/10">
                        <x-workspace-panel-head
                            icon="heroicon-o-signal"
                            :title="__('Monitors')"
                            :note="__('Check uptime and content over HTTP, or watch a TLS certificate. The first check runs immediately.')"
                            :count="$monitorCount ?: null"
                            class="border-b border-brand-ink/10"
                        >
                            <x-slot:actions>
                                @if ($resolvedBaseUrl !== null)
                                    <span class="inline-flex items-center rounded-md bg-white px-2 py-1 font-mono text-xs text-brand-moss ring-1 ring-inset ring-brand-ink/10">{{ $hostnameDisplay }}</span>
                                @endif
                                <button
                                    type="button"
                                    wire:click="startAddMonitor"
                                    @disabled(! $canEdit || $resolvedBaseUrl === null)
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Add a monitor') }}
                                </button>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        @if ($resolvedBaseUrl === null)
                            <div class="flex items-start gap-2.5 border-b border-amber-200/70 bg-amber-50 px-5 py-3 sm:px-6">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                                <p class="min-w-0 text-xs leading-relaxed text-amber-900">
                                    <span class="font-semibold text-amber-950">{{ __('No public URL yet.') }}</span>
                                    {{ __('Add a primary domain, preview hostname, or publication URL before uptime checks can run.') }}
                                </p>
                            </div>
                        @endif

                        @if ($site->uptimeMonitors->isEmpty())
                            <p class="px-5 py-10 text-center text-sm text-brand-moss sm:px-6">{{ __('No monitors yet — add one to start checking this site.') }}</p>
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
                                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3 sm:px-6">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $style['bg'] }} {{ $style['text'] }} {{ $style['ring'] }}">
                                                        <span class="h-1.5 w-1.5 rounded-full {{ $style['dot'] }}"></span>
                                                        {{ $style['label'] }}
                                                    </span>
                                                    <p class="text-sm font-medium text-brand-ink">{{ $m->label }}</p>
                                                    <span class="inline-flex items-center rounded bg-brand-sand/50 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ $m->checkTypeLabel() }}</span>
                                                    <span class="truncate font-mono text-xs text-brand-moss" title="{{ $hostnameDisplay }}{{ $m->normalizedPath() }}">{{ $hostnameDisplay }}{{ $m->isSslCheck() ? '' : ($m->normalizedPath() ?: '/') }}</span>
                                                </div>
                                                {{-- Region folded into the result line: it was its own line for one
                                                     short label, and the two read as a single "where + what" fact. --}}
                                                <p class="mt-1 text-xs text-brand-moss">
                                                    {{ $regionLabel }}
                                                    @if ($m->last_checked_at)
                                                        @if ($m->isSslCheck() && is_numeric($sslDays) && (int) $sslDays >= 0)
                                                            · {{ trans_choice('{0}Expires today|{1}Expires in :count day|[2,*]Expires in :count days', (int) $sslDays, ['count' => (int) $sslDays]) }}
                                                        @endif
                                                        @if ($m->last_http_status)
                                                            · HTTP {{ $m->last_http_status }}
                                                        @endif
                                                        @if ($m->last_latency_ms !== null)
                                                            · {{ $m->last_latency_ms }} ms
                                                        @endif
                                                        · {{ $m->last_checked_at->timezone(config('app.timezone'))->toDayDateTimeString() }}
                                                    @else
                                                        · {{ __('Not checked yet.') }}
                                                    @endif
                                                </p>
                                                @if ($m->last_checked_at && $m->last_error)
                                                    <p class="mt-1 truncate text-xs {{ $state === 'degraded' ? 'text-amber-700' : 'text-red-700' }}" title="{{ $m->last_error }}">{{ $m->last_error }}</p>
                                                @endif
                                            </div>
                                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                                <button
                                                    type="button"
                                                    wire:click="toggleHistory('{{ $m->id }}')"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                                >
                                                    <x-heroicon-o-chart-bar class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                                    {{ $isExpanded ? __('Hide history') : __('History') }}
                                                </button>
                                                @if ($canEdit)
                                                    <button
                                                        type="button"
                                                        wire:click="runCheckNow('{{ $m->id }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="runCheckNow"
                                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                                        <span wire:loading.remove wire:target="runCheckNow">{{ __('Check now') }}</span>
                                                        <span wire:loading wire:target="runCheckNow">{{ __('Queueing…') }}</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="editMonitor('{{ $m->id }}')"
                                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                                    >
                                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                                        {{ __('Edit') }}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="confirmRemoveMonitor('{{ $m->id }}')"
                                                        class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-2 py-1 text-xs font-semibold text-red-800 shadow-sm hover:bg-red-50"
                                                    >
                                                        <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                                        {{ __('Remove') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </div>

                                        @if ($isExpanded)
                                            <div class="border-t border-brand-ink/10 bg-brand-sand/10 px-5 py-5 sm:px-6">
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

                    <div class="border-b border-brand-ink/10 px-5 py-2.5 sm:px-6">
                        <p class="text-xs text-brand-moss">
                            {{ __('Show monitors on a public') }}
                            <a href="{{ route('status-pages.index') }}" class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('status page') }}</a>.
                        </p>
                    </div>

                    @php
                        // Everything this tab does, from a terminal. `uptime` is
                        // site-kind agnostic, so one set covers vm/cloud/edge/
                        // serverless; the function-only rollup is appended for
                        // functions. Ids come from the monitor list above.
                        $cliSite = $site->slug;
                        $cliMonitorId = $site->uptimeMonitors->first()?->id ?? '<id>';
                        $cliCommands = [
                            ['label' => __('Every monitor: status, code, latency'), 'command' => 'dply sites:uptime '.$cliSite],
                            ['label' => __('Uptime % + recent incidents'), 'command' => 'dply sites:uptime:history '.$cliSite],
                            ['label' => __('One monitor’s history'), 'command' => 'dply uptime history '.$cliSite.' --monitor '.$cliMonitorId],
                            ['label' => __('Probe one monitor now'), 'command' => 'dply uptime check '.$cliMonitorId.' --site '.$cliSite],
                            ['label' => __('Probe every monitor now'), 'command' => 'dply uptime check --all --site '.$cliSite],
                            ['label' => __('Watch for status changes'), 'command' => 'dply uptime '.$cliSite.' --watch'],
                            ['label' => __('Raw payload for scripts'), 'command' => 'dply uptime '.$cliSite.' --json'],
                            ['label' => __('Gate a deploy on a healthy site (exits 1 while anything is down)'), 'command' => 'dply deploy --wait && dply uptime '.$cliSite.' --no-prompt'],
                            ['label' => __('Errors raised by these checks'), 'command' => 'dply sites:errors '.$cliSite],
                        ];

                        if ($site->siteKind() === 'serverless') {
                            $cliCommands[] = [
                                'label' => __('Function health rollup (24h)'),
                                'command' => 'dply serverless status '.$cliSite,
                            ];
                        }
                    @endphp

                    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
                        <x-cli-snippet
                            :commands="$cliCommands"
                            :intro="__('`dply monitor` is an alias for `dply uptime`. On a terminal, leaving the id off opens a picker.')"
                        />
                    </div>
                @endif

                @if ($monitorTab === 'alerts')
                    @include('livewire.sites.partials.monitor.notifications-card')
                @endif
                </div>
            </section>
        </div>
    </div>

    {{-- Add / edit monitor modal. --}}
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
                        <x-text-input id="uptime-label" wire:model="newLabel" class="mt-1 block w-full" placeholder="{{ __('e.g. Homepage (HTTPS)') }}" :disabled="! $canEdit" />
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
                            <option value="https">{{ __('HTTPS — reachability & content') }}</option>
                            <option value="http">{{ __('HTTP — reachability & content') }}</option>
                            <option value="ssl">{{ __('SSL — certificate expiry') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('newCheckType')" class="mt-1" />
                    </div>

                    <div x-show="ctype === 'http' || ctype === 'https'" class="space-y-4" x-cloak>
                        <div>
                            <x-input-label for="uptime-path" :value="__('Path (optional)')" />
                            <div class="mt-1 flex rounded-lg border border-brand-ink/15 bg-slate-50 shadow-sm focus-within:border-brand-sage focus-within:ring-2 focus-within:ring-brand-sage/30">
                                <span class="inline-flex max-w-[14rem] items-center truncate border-r border-brand-ink/10 bg-slate-100 px-3 font-mono text-xs text-slate-600 sm:max-w-md" title="{{ $hostnameDisplay ?? '' }}">{{ $hostnameDisplay ?? '—' }} /</span>
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

                        <div class="space-y-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Assertions (optional)') }}</p>

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

                    <div x-show="ctype === 'ssl'" x-cloak class="space-y-4">
                        <div>
                            <x-input-label for="uptime-ssl-warn" :value="__('Warn before expiry (days)')" />
                            <x-text-input id="uptime-ssl-warn" type="number" min="1" max="90" wire:model="newSslWarnDays" class="mt-1 block w-full" placeholder="{{ (string) config('site_uptime.ssl_warn_days', 14) }}" :disabled="! $canEdit" />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Alert this many days before the certificate expires. SSL checks run daily. Default :days.', ['days' => config('site_uptime.ssl_warn_days', 14)]) }}</p>
                            <x-input-error :messages="$errors->get('newSslWarnDays')" class="mt-1" />
                        </div>
                    </div>

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

    @include('livewire.partials.confirm-action-modal')
    @include('livewire.partials.create-notification-channel-modal')
    @include('livewire.partials.window-logs-drawer')
</div>
