@php
    $report = $daemonSloReport;
    $sloTonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
    ];

    $overallTone = match ($report['overall']) {
        'critical' => $sloTonePalette['rose'],
        'warning' => $sloTonePalette['amber'],
        default => $sloTonePalette['emerald'],
    };

    $stateTone = static function (string $state) use ($sloTonePalette): string {
        return match (strtoupper($state)) {
            'RUNNING' => $sloTonePalette['emerald'],
            'STARTING' => $sloTonePalette['sage'],
            'BACKOFF', 'FATAL', 'EXITED', 'STOPPED' => $sloTonePalette['rose'],
            default => $sloTonePalette['amber'],
        };
    };

    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
    $supervisorInstalled = $report['supervisor']['installed'];
    $hasDetail = filled($report['health']['detail'] ?? '');
@endphp

<div class="space-y-6">
    @if ($isDeployer)
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <x-icon-badge tone="amber">
                        <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can view worker health but cannot refresh supervisor status over SSH.') }}</p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Worker health') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                            @switch($report['overall'])
                                @case('critical') {{ __('Workers need attention') }} @break
                                @case('warning') {{ __('Review supervisor state') }} @break
                                @default {{ __('All workers healthy') }}
                            @endswitch
                        </h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            @if ($report['health']['checked_at'])
                                {{ __('Last check :time', ['time' => $report['health']['checked_at']->diffForHumans()]) }}
                                @if ($report['health']['stale'])
                                    · <span class="font-medium text-amber-800">{{ __('stale') }}</span>
                                @endif
                            @else
                                {{ __('No health snapshot yet.') }}
                            @endif
                            @if ($report['health']['summary'] && $report['overall'] !== 'ok')
                                · {{ $report['health']['summary'] }}
                            @endif
                        </p>
                    </div>
                </div>
                @if ($opsReady && ! $isDeployer && $supervisorInstalled)
                    <button
                        type="button"
                        wire:click="refreshSupervisorHealth"
                        wire:loading.attr="disabled"
                        wire:target="refreshSupervisorHealth"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="refreshSupervisorHealth" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                            {{ __('Refresh status') }}
                        </span>
                        <span wire:loading wire:target="refreshSupervisorHealth" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                            {{ __('Refreshing…') }}
                        </span>
                    </button>
                @endif
            </div>
        </div>

        @if ($report['alert_count'] > 0)
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($report['alerts'] as $alert)
                    @php
                        $alertTone = match ($alert['severity']) {
                            'critical' => $sloTonePalette['rose'],
                            'warning' => $sloTonePalette['amber'],
                            default => $sloTonePalette['sage'],
                        };
                        $alertTab = str_contains((string) ($alert['title'] ?? ''), 'drift') ? 'sync' : 'programs';
                    @endphp
                    <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $alertTone }}">
                                <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                                <p class="mt-0.5 text-sm text-brand-moss">{{ $alert['message'] }}</p>
                            </div>
                        </div>
                        @if ($alert['link_label'])
                            <button
                                type="button"
                                wire:click="setDaemonsWorkspaceTab(@js($alertTab))"
                                x-data="{}"
                                x-on:click="$nextTick(() => { const el = document.getElementById('daemons-workspace-tablist'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); })"
                                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                            >
                                {{ $alert['link_label'] }}
                                <x-heroicon-m-arrow-down class="h-3 w-3" aria-hidden="true" />
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">
                {{ __('No worker or supervisor alerts from the latest snapshot.') }}
            </div>
        @endif
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Program inventory') }}</h2>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Active supervisor programs from the last health snapshot.') }}</p>
            </div>
            <div class="px-6 py-4 sm:px-7">
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Total') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $report['programs']['total'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Active') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $report['programs']['active'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Running') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold {{ $report['programs']['running'] === $report['programs']['active'] && $report['programs']['active'] > 0 ? 'text-emerald-700' : 'text-brand-ink' }}">
                            {{ $report['programs']['running'] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Not healthy') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold {{ $report['programs']['unhealthy'] > 0 ? 'text-rose-700' : 'text-brand-ink' }}">
                            {{ $report['programs']['unhealthy'] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Inactive') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $report['programs']['inactive'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Config drift') }}</dt>
                        <dd class="mt-1 text-lg font-semibold {{ $report['health']['config_drift'] ? 'text-amber-800' : 'text-emerald-700' }}">
                            {{ $report['health']['config_drift'] ? __('Detected') : __('None') }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Supervisor snapshot') }}</h2>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('From the last health check or scheduled probe.') }}</p>
            </div>
            <div class="space-y-3 px-6 py-4 text-sm sm:px-7">
                <dl class="grid grid-cols-2 gap-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Supervisor') }}</dt>
                        <dd class="mt-1 font-semibold {{ $supervisorInstalled ? 'text-emerald-700' : 'text-amber-800' }}">
                            {{ $supervisorInstalled ? __('Installed') : __('Not installed') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Health OK') }}</dt>
                        <dd class="mt-1 font-semibold text-brand-ink">
                            @if ($report['health']['ok'] === null)
                                —
                            @elseif ($report['health']['ok'])
                                <span class="text-emerald-700">{{ __('Yes') }}</span>
                            @else
                                <span class="text-rose-700">{{ __('No') }}</span>
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($hasDetail)
                    <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/20">
                        <summary class="cursor-pointer select-none px-4 py-3 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                            {{ __('Raw supervisorctl output') }}
                        </summary>
                        <pre class="max-h-64 overflow-auto border-t border-brand-ink/10 bg-white p-4 font-mono text-[11px] leading-relaxed text-brand-moss">{{ $report['health']['detail'] }}</pre>
                    </details>
                @else
                    <p class="text-xs text-brand-moss">{{ __('No supervisorctl output stored yet — refresh status to capture a snapshot.') }}</p>
                @endif
            </div>
        </section>
    </div>

    @if ($report['programs']['active'] > 0)
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Snapshot by program') }}</h2>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('RUNNING state from the last refresh. Unhealthy rows sort to the top.') }}</p>
                    </div>
                    @if ($report['programs']['unhealthy'] > 0)
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 {{ $sloTonePalette['rose'] }}">
                            {{ trans_choice(':count issue|:count issues', $report['programs']['unhealthy'], ['count' => $report['programs']['unhealthy']]) }}
                        </span>
                    @endif
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-xs">
                    <thead class="bg-brand-sand/30 text-brand-moss">
                        <tr>
                            <th class="px-3 py-2 font-semibold">{{ __('Program') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('Type') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('Scope') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('State') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('Uptime') }}</th>
                            <th class="px-3 py-2 font-semibold text-right">{{ __('Go to') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                        @foreach ($report['programs']['rows'] as $row)
                            <tr @class(['bg-rose-50/40' => ! $row['healthy']])>
                                <td class="px-3 py-2">
                                    <span class="font-medium text-brand-ink">{{ $row['slug'] }}</span>
                                    @if (! ($row['in_snapshot'] ?? true))
                                        <p class="mt-0.5 text-[10px] text-amber-800">{{ __('Missing from last supervisorctl output') }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $sloTonePalette['mist'] }}">
                                        {{ $row['program_type'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-brand-moss">{{ $row['site_name'] ?? __('Server') }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $stateTone($row['state']) }}">
                                        {{ $row['state'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-mono text-brand-moss">{{ $row['uptime'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if ($row['site_id'] !== null)
                                        <a href="{{ $row['href'] }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('Site workers') }}</a>
                                    @else
                                        <button type="button" wire:click="setDaemonsWorkspaceTab('programs')" x-data="{}" x-on:click="$nextTick(() => { const el = document.getElementById('daemons-workspace-tablist'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); })" class="font-semibold text-brand-forest hover:underline">{{ __('Programs tab') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
