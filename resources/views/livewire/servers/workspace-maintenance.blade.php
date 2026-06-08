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
@endphp

<x-server-workspace-layout
    :server="$server"
    active="maintenance"
    :title="__('Maintenance')"
    :description="__('Visitor maintenance window, site impact, and related downtime controls for this server.')"
    :pageHeaderToolbar="true"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Enable visitor maintenance to suspend every eligible VM site at once with a shared public message. Deploy hooks and settings still work. Manually suspended sites are left unchanged and are not auto-resumed when maintenance ends.') }}</p>
    </x-explainer>

    @php
        $maintTabBase = 'inline-flex items-center gap-1.5 border-b-2 px-1 py-3 text-sm font-medium transition-colors';
        $maintTabOn = 'border-brand-forest text-brand-ink';
        $maintTabOff = 'border-transparent text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink';
    @endphp
    <div class="mb-6 border-b border-brand-ink/10">
        <nav class="-mb-px flex flex-wrap gap-6" aria-label="{{ __('Maintenance sections') }}">
            <button type="button" wire:click="setMaintenanceTab('window')" @class([$maintTabBase, $maintenance_tab === 'window' ? $maintTabOn : $maintTabOff])>
                <x-heroicon-o-pause-circle class="h-4 w-4" aria-hidden="true" />
                {{ __('Visitor window') }}
                @if ($active)
                    <span class="ml-0.5 inline-flex h-2 w-2 rounded-full bg-amber-500" title="{{ __('Maintenance active') }}"></span>
                @endif
            </button>
            <button type="button" wire:click="setMaintenanceTab('operations')" @class([$maintTabBase, $maintenance_tab === 'operations' ? $maintTabOn : $maintTabOff])>
                <x-heroicon-o-wrench-screwdriver class="h-4 w-4" aria-hidden="true" />
                {{ __('Operations') }}
            </button>
            <button type="button" wire:click="setMaintenanceTab('schedule')" @class([$maintTabBase, $maintenance_tab === 'schedule' ? $maintTabOn : $maintTabOff])>
                <x-heroicon-o-calendar-days class="h-4 w-4" aria-hidden="true" />
                {{ __('Schedule') }}
            </button>
            <button type="button" wire:click="setMaintenanceTab('notifications')" @class([$maintTabBase, $maintenance_tab === 'notifications' ? $maintTabOn : $maintTabOff])>
                <x-heroicon-o-bell class="h-4 w-4" aria-hidden="true" />
                {{ __('Notifications') }}
            </button>
        </nav>
    </div>

    <div class="space-y-6">
        {{-- Overall (window tab) --}}
        <div @class(['hidden' => $maintenance_tab !== 'window'])>
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1 {{ $overallTone }}">
                            <x-heroicon-o-wrench class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Visitor maintenance') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                                @if ($active)
                                    {{ __('Maintenance active — visitors see suspended pages') }}
                                @else
                                    {{ __('No active visitor maintenance window') }}
                                @endif
                            </h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                @if ($active && $startedAt)
                                    {{ __('Started :time', ['time' => $startedAt->diffForHumans()]) }}
                                    @if ($untilAt)
                                        · {{ __('Ends :time', ['time' => $untilAt->format('Y-m-d H:i T')]) }}
                                        @if ($untilAt->isFuture())
                                            ({{ $untilAt->diffForHumans() }})
                                        @endif
                                    @else
                                        · {{ __('Manual clear only') }}
                                    @endif
                                @else
                                    {{ trans_choice(':count eligible site on this server|:count eligible sites on this server', $summary['eligible'] ?? 0, ['count' => $summary['eligible'] ?? 0]) }}
                                    @if (($preview['suspend_count'] ?? 0) > 0)
                                        · {{ trans_choice(':count would suspend now|:count would suspend now', $preview['suspend_count'], ['count' => $preview['suspend_count']]) }}
                                    @endif
                                @endif
                            </p>
                        </div>
                    </div>
                    @if ($active)
                        <button
                            type="button"
                            wire:click="openDisableModal"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-sm transition hover:bg-amber-100"
                        >
                            <x-heroicon-o-play class="h-4 w-4" aria-hidden="true" />
                            {{ __('End maintenance') }}
                        </button>
                    @endif
                </div>
            </div>

            <div class="grid gap-px bg-brand-ink/10 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                @foreach ([
                    ['label' => __('Total sites'), 'value' => $summary['total_sites'] ?? 0],
                    ['label' => __('Eligible'), 'value' => $summary['eligible'] ?? 0],
                    ['label' => __('Would suspend'), 'value' => $summary['would_suspend'] ?? 0],
                    ['label' => __('Suspended (window)'), 'value' => $summary['suspended_by_window'] ?? 0],
                    ['label' => __('Already suspended'), 'value' => $summary['already_suspended'] ?? 0],
                    ['label' => __('Excluded'), 'value' => $summary['skipped'] ?? 0],
                ] as $stat)
                    <div class="bg-white px-4 py-3.5">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $stat['label'] }}</p>
                        <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ number_format((int) $stat['value']) }}</p>
                    </div>
                @endforeach
            </div>

            @if ($active && (! empty($state['note']) || ! empty($state['message'])))
                <div class="border-t border-brand-ink/10 px-6 py-4 text-sm text-brand-moss sm:px-7">
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
        </section>

        </div>

        {{-- Related maintenance controls (schedule tab) --}}
        <div @class(['hidden' => $maintenance_tab !== 'schedule'])>
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-calendar-days class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Schedule') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Preferred maintenance schedule') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Advisory recurring local-time schedule for disruptive server actions (firewall apply, supervisor restarts). Stored in Settings → Connection.') }}</p>
                    </div>
                </div>
                <div class="space-y-3 px-6 py-5 text-sm sm:px-7">
                    @if ($recurringWindow->enabled())
                        <p class="font-medium text-brand-ink">{{ $recurringWindow->summary() }}</p>
                        <p @class([
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1',
                            $recurringWindow->containsNow() ? $tonePalette['emerald'] : $tonePalette['mist'],
                        ])>
                            @if ($recurringWindow->containsNow())
                                <x-heroicon-o-check-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Inside preferred window now') }}
                            @else
                                <x-heroicon-o-clock class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Outside preferred window now') }}
                            @endif
                        </p>
                    @else
                        <p class="text-brand-moss">{{ __('Not configured — disruptive actions proceed without a schedule gate.') }}</p>
                    @endif
                    <a
                        href="{{ route('servers.settings', ['server' => $server, 'section' => 'connection']) }}#settings-maintenance"
                        wire:navigate
                        class="inline-flex items-center gap-1 text-xs font-semibold text-brand-moss hover:text-brand-ink"
                    >
                        {{ __('Edit in Settings') }}
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
                    </a>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Cron') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Org cron maintenance') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Pauses managed cron lines org-wide during migrations or deploy freezes — separate from visitor suspend.') }}</p>
                    </div>
                </div>
                <div class="space-y-3 px-6 py-5 text-sm sm:px-7">
                    @if ($cronMaintenanceActive && $cronMaintenanceUntil)
                        <p class="font-medium text-brand-ink">
                            {{ __('Active until :time', ['time' => $cronMaintenanceUntil->timezone(config('app.timezone'))->format('Y-m-d H:i T')]) }}
                        </p>
                        @if (filled($cronMaintenanceNote))
                            <p class="text-brand-moss">{{ $cronMaintenanceNote }}</p>
                        @endif
                    @else
                        <p class="text-brand-moss">{{ __('No org-wide cron pause is active.') }}</p>
                    @endif
                    <a
                        href="{{ route('servers.cron', $server) }}?tab=maintenance"
                        wire:navigate
                        class="inline-flex items-center gap-1 text-xs font-semibold text-brand-moss hover:text-brand-ink"
                    >
                        {{ __('Manage on Cron') }}
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
                    </a>
                </div>
            </section>
        </div>
        </div>

        {{-- Site impact (window tab) --}}
        <div @class(['hidden' => $maintenance_tab !== 'window'])>
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                            <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Site impact') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Every site on this server and how visitor maintenance affects it.') }}</p>
                        </div>
                    </div>
                    @feature('workspace.patch_advisor')
                        <a
                            href="{{ route('servers.patches', $server) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            {{ __('Patch advisor') }}
                        </a>
                    @endfeature
                </div>
            </div>

            @if ($siteRows === [])
                <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-7">
                    {{ __('No sites on this server yet.') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/20 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">
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
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1',
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
        </section>

        </div>

        {{-- Server maintenance operations (operations tab) --}}
        <div @class(['hidden' => $maintenance_tab !== 'operations'])>
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Operations') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Server maintenance operations') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Run host-level upkeep over SSH — package updates, cleanup, and reboot. Each run is queued and recorded in the activity log below.') }}</p>
                </div>
            </div>

            <div class="space-y-6 px-6 py-6 sm:px-7">
                @if ($maintenanceRemoteTaskId)
                    <x-workspace-console-banner
                        :status="$bannerStatus"
                        :message="$maintenanceActionLabel ?? __('Maintenance operation')"
                        :output="$bannerOutputLines"
                        :busy="$bannerBusy"
                        :dismiss-action="$bannerBusy ? null : 'dismissMaintenanceTask'"
                        :poll-action="$bannerBusy ? 'syncMaintenanceRemoteTaskFromCache' : null"
                        poll-interval="2s"
                        :default-expanded="true"
                    />
                @endif

                @if (! $opsReady)
                    <p class="rounded-lg bg-brand-sand/40 px-4 py-3 text-sm text-brand-moss ring-1 ring-brand-ink/10">
                        {{ __('Provisioning and SSH must be ready, and you need server-management permission, to run these operations.') }}
                    </p>
                @elseif ($recurringWindow->enabled() && ! $recurringWindow->containsNow())
                    <p class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-900 ring-1 ring-amber-200">
                        <x-heroicon-o-clock class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Outside the preferred maintenance window — disruptive actions may be better scheduled.') }}
                    </p>
                @endif

                @foreach ($operationGroups as $group)
                    <div wire:key="maint-ops-{{ \Illuminate\Support\Str::slug($group['title']) }}">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __($group['title']) }}</p>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($group['actions'] as $action)
                                <div wire:key="maint-op-{{ $action['key'] }}" class="flex flex-col rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $action['label'] }}</p>
                                        @if ($action['danger'])
                                            <span class="inline-flex shrink-0 items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700 ring-1 ring-rose-200">{{ __('Disruptive') }}</span>
                                        @endif
                                    </div>
                                    @if ($action['description'] !== '')
                                        <p class="mt-1 flex-1 text-xs leading-relaxed text-brand-moss">{{ $action['description'] }}</p>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="confirmAction('{{ $action['key'] }}')"
                                        @disabled(! $opsReady || $bannerBusy)
                                        @class([
                                            'mt-3 inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm transition disabled:cursor-not-allowed disabled:opacity-50',
                                            'border border-rose-300 bg-white text-rose-700 hover:bg-rose-50' => $action['danger'],
                                            'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $action['danger'],
                                        ])
                                    >
                                        {{ __('Run') }}
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="border-t border-brand-ink/10 pt-5">
                    @livewire(\App\Livewire\Servers\RecentActionsLog::class, ['server' => $server], key('recent-actions-log-'.$server->id))
                </div>
            </div>
        </section>

        </div>

        {{-- Enable / settings form (window tab) --}}
        <div @class(['hidden' => $maintenance_tab !== 'window'])>
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <x-icon-badge tone="amber">
                    <x-heroicon-o-pause-circle class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Maintenance') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $active ? __('Window details') : __('Start visitor maintenance') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            @if ($active)
                                {{ __('End maintenance above to resume sites suspended by this window. Fields below reflect the active window (read-only).') }}
                            @else
                                {{ __('Review timing and messages, then confirm to suspend eligible sites and queue webserver config updates.') }}
                            @endif
                        </p>
                    </div>
            </div>

            <form class="space-y-5 p-6 sm:p-7">
                <div>
                    <x-input-label for="maintenance_until_local" :value="__('End automatically at (optional)')" />
                    <input
                        id="maintenance_until_local"
                        type="datetime-local"
                        wire:model="maintenance_until_local"
                        @disabled($active)
                        class="mt-1 block w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:bg-brand-sand/30"
                    />
                    <p class="mt-1.5 text-xs text-brand-moss">{{ __('Times use :tz. Leave empty for a manual clear-only window.', ['tz' => config('app.timezone')]) }}</p>
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
        </section>
        </div>

        {{-- Notifications tab --}}
        <div @class(['hidden' => $maintenance_tab !== 'notifications'])>
            @include('livewire.servers.partials.maintenance.notifications-tab')
        </div>
    </div>

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
