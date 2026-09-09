@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200',
    ];

    $summary = $report['summary'] ?? [];
    $siteRows = $report['site_rows'] ?? [];
    $overallTone = $active ? $tonePalette['amber'] : $tonePalette['emerald'];

    $statusTone = static function (string $status) use ($tonePalette): string {
        return match ($status) {
            'suspended_window' => $tonePalette['amber'],
            'suspended_other' => $tonePalette['mist'],
            'excluded' => $tonePalette['mist'],
            'live' => $tonePalette['emerald'],
            default => $tonePalette['sky'],
        };
    };

    $startedAt = ! empty($state['started_at'])
        ? \Illuminate\Support\Carbon::parse($state['started_at'])->timezone(config('app.timezone'))
        : null;
    $untilAt = ! empty($state['until'])
        ? \Illuminate\Support\Carbon::parse($state['until'])->timezone(config('app.timezone'))
        : null;

    $maintenanceDescription = __('Visitor maintenance window, site impact, and related downtime controls for this server.');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="maintenance"
    :title="__('Maintenance')"
    :description="$maintenanceDescription"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    {{-- The one shared console-action banner for everything that runs on this
         page — host-upkeep ops (apt/cleanup/reboot, vhost prune) AND the
         webserver applies a maintenance toggle queues. Same component every
         other workspace op uses; no per-page output box. --}}
    {{-- Bridge poll: a just-queued webserver apply hasn't written its console
         row yet, so nudge a few re-renders after a toggle until the banner can
         self-poll. Op/prune rows are seeded synchronously and need no bridge. --}}
    @if ($watchApply)
        <div wire:poll.2s="tickApplyWatch" class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.partials.console-action-banner-static', [
        'run' => $consoleRun,
        'kindLabels' => $consoleKindLabels,
    ])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-wrench"
            :title="__('Maintenance')"
            :note="$maintenanceDescription"
            class="border-b border-brand-ink/10"
        />

        @if (! empty($applyFailures))
            <div class="border-b border-rose-200/80 bg-rose-50/70 px-5 py-4 sm:px-6">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-rose-600" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-rose-800">{{ __('Webserver config apply failed') }}</p>
                        <p class="mt-0.5 text-sm text-rose-700">{{ __('The live webserver on these sites may not match their saved state. Re-apply to reconcile.') }}</p>
                        <ul class="mt-3 space-y-2">
                            @foreach ($applyFailures as $fail)
                                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 border border-rose-200/60 bg-white/70 px-3 py-2">
                                    <span class="font-medium text-brand-ink">{{ $fail['name'] }}</span>
                                    @if ($fail['at'])
                                        <span class="text-xs text-rose-700/80">{{ $fail['at']->timezone(config('app.timezone'))->diffForHumans() }}</span>
                                    @endif
                                    <span class="w-full truncate font-mono text-xs text-rose-700/80" title="{{ $fail['error'] }}">{{ \Illuminate\Support\Str::limit($fail['error'], 160) }}</span>
                                    <button
                                        type="button"
                                        wire:click="reapplyWebserverConfig('{{ $fail['site_id'] }}')"
                                        wire:loading.attr="disabled"
                                        class="ml-auto inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm transition hover:bg-rose-100"
                                    >
                                        <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                                        {{ __('Re-apply') }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Maintenance sections')" scroll bare class="!mb-0 w-full">
                <x-server-workspace-tab icon="heroicon-o-pause-circle" :active="$maintenance_tab === 'window'" wire:click="setMaintenanceTab('window')">
                    {{ __('Visitor window') }}
                    @if ($active)
                        <span class="ml-0.5 inline-flex h-2 w-2 rounded-full bg-amber-500" title="{{ __('Maintenance active') }}"></span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab icon="heroicon-o-wrench-screwdriver" :active="$maintenance_tab === 'operations'" wire:click="setMaintenanceTab('operations')">
                    {{ __('Operations') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab icon="heroicon-o-calendar-days" :active="$maintenance_tab === 'schedule'" wire:click="setMaintenanceTab('schedule')">
                    {{ __('Schedule') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab icon="heroicon-o-bell" :active="$maintenance_tab === 'notifications'" wire:click="setMaintenanceTab('notifications')">
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        {{-- Skeleton swap, not a dim-and-lock — matches the Logs tab strip. --}}
        <div class="hidden" wire:loading.class.remove="hidden" wire:target="setMaintenanceTab">
            @include('livewire.sites.partials._panel-skeleton')
        </div>

        <div class="relative min-w-0" wire:loading.class="hidden" wire:target="setMaintenanceTab">
        {{-- Overall (window tab) --}}
        @if ($maintenance_tab === 'window')
        <div class="border-b border-brand-ink/10">
            {{-- The status line IS the heading — the "VISITOR MAINTENANCE"
                 eyebrow restated the tab. Timing / eligibility folds into the
                 head's single note line. --}}
            @php
                if ($active && $startedAt) {
                    $windowNote = __('Started :time', ['time' => $startedAt->diffForHumans()]);
                    if ($untilAt) {
                        $windowNote .= ' · '.__('Ends :time', ['time' => $untilAt->format('Y-m-d H:i T')]);
                        if ($untilAt->isFuture()) {
                            $windowNote .= ' ('.$untilAt->diffForHumans().')';
                        }
                    } else {
                        $windowNote .= ' · '.__('Manual clear only');
                    }
                } else {
                    $windowNote = trans_choice(':count eligible site on this server|:count eligible sites on this server', $summary['eligible'] ?? 0, ['count' => $summary['eligible'] ?? 0]);
                    if (($preview['suspend_count'] ?? 0) > 0) {
                        $windowNote .= ' · '.trans_choice(':count would suspend now|:count would suspend now', $preview['suspend_count'], ['count' => $preview['suspend_count']]);
                    }
                }
            @endphp
            <x-workspace-panel-head
                dense
                icon="heroicon-o-wrench"
                :tone="$active ? 'amber' : null"
                :title="$active ? __('Maintenance active — visitors see suspended pages') : __('No active visitor maintenance window')"
                :note="$windowNote"
                class="border-b border-brand-ink/10"
            >
                @if ($active)
                    <x-slot:actions>
                        <button
                            type="button"
                            wire:click="openDisableModal"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-2.5 py-1 text-xs font-semibold text-amber-900 shadow-sm transition hover:bg-amber-100"
                        >
                            <x-heroicon-o-play class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('End maintenance') }}
                        </button>
                    </x-slot:actions>
                @endif
            </x-workspace-panel-head>

            <div class="grid gap-px border-b border-brand-ink/10 bg-brand-ink/10 sm:grid-cols-3 xl:grid-cols-6">
                @foreach ([
                    ['label' => __('Total sites'), 'value' => $summary['total_sites'] ?? 0],
                    ['label' => __('Eligible'), 'value' => $summary['eligible'] ?? 0],
                    ['label' => __('Would suspend'), 'value' => $summary['would_suspend'] ?? 0],
                    ['label' => __('Suspended (window)'), 'value' => $summary['suspended_by_window'] ?? 0],
                    ['label' => __('Already suspended'), 'value' => $summary['already_suspended'] ?? 0],
                    ['label' => __('Excluded'), 'value' => $summary['skipped'] ?? 0],
                ] as $stat)
                    <div class="bg-white px-3 py-2">
                        <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $stat['label'] }}</p>
                        <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums text-brand-ink">{{ number_format((int) $stat['value']) }}</p>
                    </div>
                @endforeach
            </div>

            @if ($active && (! empty($state['note']) || ! empty($state['message'])))
                <div class="border-b border-brand-ink/10 px-5 py-2.5 text-xs leading-relaxed text-brand-moss sm:px-6">
                    @if (! empty($state['note']))
                        <p><span class="font-medium text-brand-ink">{{ __('Operator note') }}:</span> {{ $state['note'] }}</p>
                    @endif
                    @if (! empty($state['message']))
                        <p @class(['mt-1' => ! empty($state['note'])])>
                            <span class="font-medium text-brand-ink">{{ __('Public message') }}:</span> {{ $state['message'] }}
                        </p>
                    @endif
                </div>
            @endif
        </div>
        @endif

        {{-- Related maintenance controls (schedule tab) --}}
        @if ($maintenance_tab === 'schedule')
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-calendar-days"
                    :title="__('Preferred maintenance schedule')"
                    :note="__('Advisory only — the days and hours you\'d prefer Dply to run disruptive work (upgrades, reboots, firewall apply, supervisor restarts). Dply warns before risky actions outside it; it doesn\'t pause cron or suspend sites. Times use your Dply timezone.')"
                    class="border-b border-brand-ink/10"
                />
                <div class="px-5 py-3 sm:px-6">
                    @if ($recurringWindow->enabled())
                        <p @class([
                            'mb-4 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1',
                            $recurringWindow->containsNow() ? $tonePalette['emerald'] : $tonePalette['mist'],
                        ])>
                            @if ($recurringWindow->containsNow())
                                <x-heroicon-o-check-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Inside preferred window now') }} · {{ $recurringWindow->summary() }}
                            @else
                                <x-heroicon-o-clock class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Outside preferred window now') }} · {{ $recurringWindow->summary() }}
                            @endif
                        </p>
                    @endif

                    <form wire:submit="savePreferredMaintenanceSchedule" class="space-y-5">
                        <fieldset @disabled(! $canEditSchedule)>
                            <legend class="text-sm font-medium text-brand-ink">{{ __('Preferred days') }}</legend>
                            <div class="mt-2 flex flex-wrap gap-2.5">
                                @foreach ($maintenanceWeekdays as $key => $label)
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-sm">
                                        <input type="checkbox" wire:model="schedule_days" value="{{ $key }}" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" @disabled(! $canEditSchedule) />
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('schedule_days')" class="mt-2" />
                        </fieldset>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="schedule-start" value="{{ __('Start (local)') }}" />
                                <input id="schedule-start" type="time" wire:model="schedule_start" @disabled(! $canEditSchedule)
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:bg-brand-sand/30" />
                                <x-input-error :messages="$errors->get('schedule_start')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="schedule-end" value="{{ __('End (local)') }}" />
                                <input id="schedule-end" type="time" wire:model="schedule_end" @disabled(! $canEditSchedule)
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:bg-brand-sand/30" />
                                <x-input-error :messages="$errors->get('schedule_end')" class="mt-2" />
                            </div>
                        </div>
                        <div>
                            <x-input-label for="schedule-note" value="{{ __('Note (optional)') }}" />
                            <textarea id="schedule-note" wire:model="schedule_note" rows="2" maxlength="2000" @disabled(! $canEditSchedule)
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:bg-brand-sand/30"
                                placeholder="{{ __('e.g. Prefer Sundays 02:00–04:00 — low traffic') }}"></textarea>
                            <x-input-error :messages="$errors->get('schedule_note')" class="mt-2" />
                        </div>
                        @if ($canEditSchedule)
                            <div class="flex justify-end">
                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="savePreferredMaintenanceSchedule">
                                    {{ __('Save preferred schedule') }}
                                </x-primary-button>
                            </div>
                        @else
                            <p class="text-xs text-brand-moss">{{ __('Not configured — disruptive actions proceed without a schedule gate.') }}</p>
                        @endif
                    </form>
                </div>
            </div>

            {{-- Maintenance history --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-clock"
                    :title="__('Maintenance history')"
                    :note="__('Recent visitor-maintenance windows on this server — when they started, ended, and how many sites were affected.')"
                    :count="count($maintenanceHistory) ?: null"
                    class="border-b border-brand-ink/10"
                />
                <div class="px-5 py-3 sm:px-6">
                    @if (empty($maintenanceHistory))
                        <p class="text-xs text-brand-moss">{{ __('No maintenance windows recorded yet.') }}</p>
                    @else
                        <ol class="relative space-y-2.5 border-l border-brand-ink/10 pl-4">
                            @foreach ($maintenanceHistory as $event)
                                <li class="relative">
                                    <span @class([
                                        'absolute -left-[1.42rem] mt-1 inline-flex h-3 w-3 rounded-full ring-4 ring-white',
                                        $event['ok'] ? 'bg-emerald-500' : 'bg-amber-500',
                                    ])></span>
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <span class="text-xs font-semibold text-brand-ink">{{ $event['label'] }}</span>
                                        <span class="text-xs text-brand-moss" title="{{ $event['at']->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}">
                                            {{ $event['at']->timezone(config('app.timezone'))->diffForHumans() }}
                                        </span>
                                        @if ($event['by'])
                                            <span class="text-xs text-brand-mist">· {{ $event['by'] }}</span>
                                        @endif
                                    </div>
                                    @if ($event['detail'])
                                        <p class="mt-0.5 text-xs text-brand-moss">{{ $event['detail'] }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </div>
        @endif

        {{-- Site impact (window tab) --}}
        @if ($maintenance_tab === 'window')
        <div class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-globe-alt"
                :title="__('Site impact')"
                :note="__('Every site on this server and how visitor maintenance affects it.')"
                :count="count($siteRows) ?: null"
                class="border-b border-brand-ink/10 !bg-brand-cream/40"
            >
                @feature('workspace.patch_advisor')
                    <x-slot:actions>
                        <a
                            href="{{ route('servers.patches', $server) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            {{ __('Patch advisor') }}
                        </a>
                    </x-slot:actions>
                @endfeature
            </x-workspace-panel-head>

            @if ($siteRows === [])
                <div class="px-5 py-6 text-center text-xs text-brand-moss sm:px-6">
                    {{ __('No sites on this server yet.') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/20 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-mist">
                            <tr>
                                <th scope="col" class="px-6 py-3">{{ __('Site') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Hostname') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Impact') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Detail') }}</th>
                                <th scope="col" class="px-6 py-3 text-right">{{ __('Open') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8 bg-white">
                            @foreach ($siteRows as $row)
                                <tr wire:key="maint-site-{{ $row['id'] }}">
                                    <td class="px-6 py-3.5 font-medium text-brand-ink">{{ $row['name'] }}</td>
                                    <td class="px-4 py-3.5 font-mono text-xs text-brand-moss">{{ $row['primary_hostname'] }}</td>
                                    <td class="px-4 py-3.5">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1',
                                            $statusTone($row['status']),
                                        ])>
                                            {{ $row['status_label'] }}
                                        </span>
                                    </td>
                                    <td class="max-w-xs px-4 py-3.5 text-xs text-brand-moss">{{ $row['detail'] ?? '—' }}</td>
                                    <td class="px-6 py-3.5 text-right">
                                        <a href="{{ $row['show_url'] }}" wire:navigate class="text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('Workspace') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @endif

        {{-- Server maintenance operations (operations tab) --}}
        @if ($maintenance_tab === 'operations')
        <div class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-wrench-screwdriver"
                :title="__('Server maintenance operations')"
                :note="__('Run host-level upkeep over SSH — package updates, cleanup, and reboot. Each run is queued and recorded in the activity log below.')"
                class="border-b border-brand-ink/10"
            />

            <div class="space-y-4 px-5 py-3 sm:px-6">
                {{-- Host-upkeep ops render in the shared console-action banner at
                     the top of the page (same as every other workspace op).
                     While one is in flight we disable the Run buttons; this poll
                     re-enables them once the mirrored ConsoleAction reaches a
                     terminal state. --}}
                @if ($opBusy)
                    <div wire:poll.3s class="hidden" aria-hidden="true"></div>
                @endif

                @if (! $opsReady)
                    <p class="rounded-lg bg-brand-sand/40 px-3 py-2 text-xs text-brand-moss ring-1 ring-brand-ink/10">
                        {{ __('Provisioning and SSH must be ready, and you need server-management permission, to run these operations.') }}
                    </p>
                @elseif ($recurringWindow->enabled() && ! $recurringWindow->containsNow())
                    <p class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-amber-200">
                        <x-heroicon-o-clock class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Outside the preferred maintenance window — disruptive actions may be better scheduled.') }}
                    </p>
                @endif

                @foreach ($operationGroups as $group)
                    <div wire:key="maint-ops-{{ \Illuminate\Support\Str::slug($group['title']) }}">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __($group['title']) }}</p>
                        <div class="mt-2 overflow-hidden rounded-xl border border-brand-ink/10 bg-white shadow-sm">
                            <table class="w-full table-auto text-xs">
                                <tbody class="divide-y divide-brand-ink/10">
                                    @foreach ($group['actions'] as $action)
                                        <tr wire:key="maint-op-{{ $action['key'] }}" class="align-top">
                                            <td class="px-3 py-2">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-semibold text-brand-ink">{{ $action['label'] }}</span>
                                                    @if ($action['danger'])
                                                        <span class="inline-flex shrink-0 items-center rounded-full bg-rose-50 px-2 py-0.5 text-2xs font-semibold text-rose-700 ring-1 ring-rose-200">{{ __('Disruptive') }}</span>
                                                    @endif
                                                </div>
                                                @if ($action['description'] !== '')
                                                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $action['description'] }}</p>
                                                @endif
                                            </td>
                                            <td class="w-px whitespace-nowrap px-4 py-3 text-right">
                                                <button
                                                    type="button"
                                                    wire:click="confirmAction('{{ $action['key'] }}')"
                                                    @disabled(! $opsReady || $opBusy)
                                                    @class([
                                                        'inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-1.5 text-xs font-semibold shadow-sm transition disabled:cursor-not-allowed disabled:opacity-50',
                                                        'border border-rose-300 bg-white text-rose-700 hover:bg-rose-50' => $action['danger'],
                                                        'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $action['danger'],
                                                    ])
                                                >
                                                    {{ __('Run') }}
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach

                {{-- Webserver hygiene: sweep orphaned dply vhosts (dply-*.conf
                     whose owning site is gone). A stale suspended block left by a
                     site recreate can shadow a live site's vhost and silently keep
                     it on the maintenance page — this removes them and reloads. --}}
                <div wire:key="maint-ops-vhost-hygiene">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Webserver hygiene') }}</p>
                    <div class="mt-2 overflow-hidden rounded-xl border border-brand-ink/10 bg-white shadow-sm">
                        <table class="w-full table-auto text-xs">
                            <tbody class="divide-y divide-brand-ink/10">
                                <tr class="align-top">
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-brand-ink">{{ __('Prune orphaned vhosts') }}</span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Remove dply-managed nginx configs whose site no longer exists, then reload. Fixes a stale block shadowing a live site (e.g. a recreate left it stuck on the maintenance page). Never touches hand-authored configs.') }}</p>
                                    </td>
                                    <td class="w-px whitespace-nowrap px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            wire:click="pruneOrphanVhosts"
                                            wire:loading.attr="disabled"
                                            @disabled(! $opsReady || $opBusy)
                                            class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ __('Scan & prune') }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="border-t border-brand-ink/10 pt-5">
                    @livewire(\App\Livewire\Servers\RecentActionsLog::class, ['server' => $server], key('recent-actions-log-'.$server->id))
                </div>
            </div>
        </div>
        @endif

        {{-- Enable / settings form (window tab) --}}
        @if ($maintenance_tab === 'window')
        <div class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                tone="amber"
                icon="heroicon-o-pause-circle"
                :title="$active ? __('Window details') : __('Start visitor maintenance')"
                :note="$active
                    ? __('End maintenance above to resume sites suspended by this window. Fields below reflect the active window (read-only).')
                    : __('Review timing and messages, then confirm to suspend eligible sites and queue webserver config updates.')"
                class="border-b border-brand-ink/10"
            />

            <form class="space-y-4 px-5 py-3 sm:px-6">
                <div
                    x-data="{ tz: '' }"
                    x-init="
                        tz = Intl.DateTimeFormat().resolvedOptions().timeZone || @js(config('app.timezone'));
                        $wire.set('maintenance_timezone', tz, false);
                        if ($refs.until && $refs.until.value) {
                            const utc = new Date($refs.until.value + 'Z');
                            if (! isNaN(utc.getTime())) {
                                const local = new Date(utc.getTime() - utc.getTimezoneOffset() * 60000);
                                $refs.until.value = local.toISOString().slice(0, 16);
                            }
                        }
                    "
                >
                    <x-input-label for="maintenance_until_local" :value="__('End automatically at (optional)')" />
                    <input
                        x-ref="until"
                        id="maintenance_until_local"
                        type="datetime-local"
                        wire:model="maintenance_until_local"
                        @disabled($active)
                        class="mt-1 block w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:bg-brand-sand/30"
                    />
                    <p class="mt-1.5 text-xs text-brand-moss">
                        <span x-text="tz
                            ? @js(__('Times use your local timezone')) + ' (' + tz + ').'
                            : @js(__('Times use your local timezone.'))"></span>
                        {{ __('Leave empty for a manual clear-only window.') }}
                    </p>
                    <x-input-error :messages="$errors->get('maintenance_until_local')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="maintenance_note" :value="__('Operator note (internal)')" />
                    <textarea
                        id="maintenance_note"
                        wire:model="maintenance_note"
                        rows="2"
                        maxlength="500"
                        @disabled($active)
                        class="mt-1 block w-full max-w-2xl rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:bg-brand-sand/30"
                        placeholder="{{ __('e.g. kernel patch + nginx reload — ETA 30 minutes') }}"
                    ></textarea>
                    <x-input-error :messages="$errors->get('maintenance_note')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="maintenance_message" :value="__('Public visitor message (optional)')" />
                    <textarea
                        id="maintenance_message"
                        wire:model="maintenance_message"
                        rows="2"
                        maxlength="500"
                        @disabled($active)
                        class="mt-1 block w-full max-w-2xl rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:bg-brand-sand/30"
                        placeholder="{{ __('Shown on each site\'s suspended page — e.g. Scheduled maintenance until 18:00 UTC.') }}"
                    ></textarea>
                    <x-input-error :messages="$errors->get('maintenance_message')" class="mt-1" />
                </div>

                @if (! $active)
                    <x-primary-button type="button" wire:click="openEnableModal">
                        {{ __('Review and enable maintenance') }}
                    </x-primary-button>
                @endif
            </form>
        </div>
        @endif

        {{-- Notifications tab --}}
        @if ($maintenance_tab === 'notifications')
            @include('livewire.servers.partials.maintenance.notifications-tab')
        @endif
        </div>
    </section>

    @include('livewire.partials.create-notification-channel-modal')

    <x-modal name="enable-maintenance-confirmation" maxWidth="md">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Enable server maintenance?') }}</h2>
            <p class="mt-2 text-sm text-brand-moss">
                {{ trans_choice('This will suspend :count eligible site on this server and queue webserver config updates.|This will suspend :count eligible sites on this server and queue webserver config updates.', $preview['suspend_count'], ['count' => $preview['suspend_count']]) }}
                {{ __('Visitors will see the suspended page until you end maintenance.') }}
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeEnableModal">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="enableMaintenance" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="enableMaintenance">{{ __('Enable maintenance') }}</span>
                    <span wire:loading wire:target="enableMaintenance">{{ __('Enabling…') }}</span>
                </x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="disable-maintenance-confirmation" maxWidth="md">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('End server maintenance?') }}</h2>
            <p class="mt-2 text-sm text-brand-moss">
                {{ __('Sites suspended by this maintenance window will be resumed and webserver configs re-applied. Manually suspended sites are unchanged.') }}
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeDisableModal">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="disableMaintenance" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="disableMaintenance">{{ __('End maintenance') }}</span>
                    <span wire:loading wire:target="disableMaintenance">{{ __('Ending…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="maintenance-operation-confirmation" maxWidth="md">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">{{ $pendingAction['label'] ?? __('Run operation') }}</h2>
            <p class="mt-2 text-sm text-brand-moss">{{ $pendingAction['confirm'] ?? __('Run this operation on the server?') }}</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeActionModal">{{ __('Cancel') }}</x-secondary-button>
                @if (($pendingAction['danger'] ?? false))
                    <x-danger-button type="button" wire:click="runConfirmedAction" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="runConfirmedAction">{{ __('Run') }}</span>
                        <span wire:loading wire:target="runConfirmedAction">{{ __('Starting…') }}</span>
                    </x-danger-button>
                @else
                    <x-primary-button type="button" wire:click="runConfirmedAction" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="runConfirmedAction">{{ __('Run') }}</span>
                        <span wire:loading wire:target="runConfirmedAction">{{ __('Starting…') }}</span>
                    </x-primary-button>
                @endif
            </div>
        </div>
    </x-modal>
</x-server-workspace-layout>
