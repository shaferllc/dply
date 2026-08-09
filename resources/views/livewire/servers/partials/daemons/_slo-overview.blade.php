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

@php
    // Verdict, last-check line, and alert count all belong on ONE dense head —
    // they were an eyebrow + title + prose stack roughly as tall as the alert
    // list underneath it.
    $healthTitle = match ($report['overall']) {
        'critical' => __('Workers need attention'),
        'warning' => __('Review supervisor state'),
        default => __('All workers healthy'),
    };

    $healthNoteParts = [];
    if ($report['health']['checked_at']) {
        $healthNoteParts[] = __('Last check :time', ['time' => $report['health']['checked_at']->diffForHumans()])
            .($report['health']['stale'] ? ' ('.__('stale').')' : '');
    } else {
        $healthNoteParts[] = __('No health snapshot yet.');
    }
    if ($report['health']['summary'] && $report['overall'] !== 'ok') {
        $healthNoteParts[] = $report['health']['summary'];
    }

    // The "no alerts from the latest snapshot" paragraph said the same thing as
    // a zero count next to a green verdict — it's the count pill now.
    $alertCountLabel = $report['alert_count'] > 0
        ? trans_choice('{1} :count alert|[2,*] :count alerts', $report['alert_count'], ['count' => $report['alert_count']])
        : __('no alerts');
@endphp

<div class="min-w-0">
    @if ($isDeployer)
        <div class="flex items-center gap-2 border-b border-amber-200/80 bg-amber-50/60 px-4 py-2 text-xs text-amber-900 sm:px-5">
            <x-heroicon-o-eye class="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
            <p class="min-w-0">
                <span class="font-semibold">{{ __('Read-only (deployer role).') }}</span>
                {{ __('You can view worker health but cannot refresh supervisor status over SSH.') }}
            </p>
        </div>
    @endif

    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-server-stack"
            :title="$healthTitle"
            :count="$alertCountLabel"
            :note="implode(' · ', $healthNoteParts)"
            :tone="match ($report['overall']) { 'critical' => 'danger', 'warning' => 'amber', default => null }"
            class="border-b border-brand-ink/10"
        >
            @if ($opsReady && ! $isDeployer && $supervisorInstalled)
                <x-slot:actions>
                    <button
                        type="button"
                        wire:click="refreshSupervisorHealth"
                        wire:loading.attr="disabled"
                        wire:target="refreshSupervisorHealth"
                        class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="refreshSupervisorHealth" class="inline-flex items-center gap-1">
                            <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Refresh status') }}
                        </span>
                        <span wire:loading wire:target="refreshSupervisorHealth" class="inline-flex items-center gap-1">
                            <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
                            {{ __('Refreshing…') }}
                        </span>
                    </button>
                </x-slot:actions>
            @endif
        </x-workspace-panel-head>

        @if ($report['alert_count'] > 0)
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($report['alerts'] as $alert)
                    @php
                        $alertTone = match ($alert['severity']) {
                            'critical' => $sloTonePalette['rose'],
                            'warning' => $sloTonePalette['amber'],
                            default => $sloTonePalette['sage'],
                        };
                        $alertTab = str_contains((string) ($alert['title'] ?? ''), 'drift') ? 'sync' : 'programs';
                    @endphp
                    <li class="flex flex-wrap items-start justify-between gap-2 px-4 py-2.5 sm:px-5">
                        <div class="flex min-w-0 items-start gap-2.5">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md ring-1 {{ $alertTone }}">
                                <x-heroicon-m-exclamation-triangle class="h-3 w-3" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                                <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $alert['message'] }}</p>
                            </div>
                        </div>
                        @if ($alert['link_label'])
                            <button
                                type="button"
                                wire:click="setDaemonsWorkspaceTab(@js($alertTab))"
                                x-data="{}"
                                x-on:click="$nextTick(() => { const el = document.getElementById('daemons-workspace-tablist'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); })"
                                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                            >
                                {{ $alert['link_label'] }}
                                <x-heroicon-m-arrow-down class="h-3 w-3" aria-hidden="true" />
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Program inventory and Supervisor snapshot were two side-by-side cards,
         each with its own header and six `text-2xl` figures. Same numbers, one
         head and one strip. --}}
    <x-workspace-panel-head
        dense
        icon="heroicon-o-chart-bar"
        :title="__('Snapshot')"
        :note="$hasDetail
            ? __('Program counts and supervisor state from the last health check or scheduled probe.')
            : __('From the last health check. No supervisorctl output stored yet — refresh status to capture a snapshot.')"
        class="border-b border-brand-ink/10"
    />

    <x-workspace-stat-strip class="border-b border-brand-ink/10" :stats="[
        ['label' => __('Total'), 'value' => $report['programs']['total'], 'hint' => __('Configured programs')],
        ['label' => __('Active'), 'value' => $report['programs']['active'], 'hint' => __('Enabled programs')],
        [
            'label' => __('Running'),
            'value' => $report['programs']['running'],
            'tone' => $report['programs']['running'] === $report['programs']['active'] && $report['programs']['active'] > 0 ? 'ok' : null,
        ],
        [
            'label' => __('Not healthy'),
            'value' => $report['programs']['unhealthy'],
            'tone' => $report['programs']['unhealthy'] > 0 ? 'bad' : null,
        ],
        ['label' => __('Inactive'), 'value' => $report['programs']['inactive']],
        [
            'label' => __('Config drift'),
            'value' => $report['health']['config_drift'] ? __('Detected') : __('None'),
            'tone' => $report['health']['config_drift'] ? 'warn' : 'ok',
        ],
        [
            'label' => __('Supervisor'),
            'value' => $supervisorInstalled ? __('Installed') : __('Not installed'),
            'tone' => $supervisorInstalled ? 'ok' : 'warn',
        ],
        [
            'label' => __('Health OK'),
            'value' => $report['health']['ok'] === null ? '—' : ($report['health']['ok'] ? __('Yes') : __('No')),
            'tone' => $report['health']['ok'] === null ? null : ($report['health']['ok'] ? 'ok' : 'bad'),
        ],
    ]" />

    @if ($hasDetail)
        <details class="border-b border-brand-ink/10">
            <summary class="cursor-pointer select-none px-4 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 sm:px-5">
                {{ __('Raw supervisorctl output') }}
            </summary>
            <pre class="max-h-64 overflow-auto border-t border-brand-ink/10 bg-white p-4 font-mono text-xs leading-relaxed text-brand-moss">{{ $report['health']['detail'] }}</pre>
        </details>
    @endif

    @if ($report['programs']['active'] > 0)
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-table-cells"
                :title="__('Snapshot by program')"
                :count="$report['programs']['unhealthy'] > 0
                    ? trans_choice('{1} :count issue|[2,*] :count issues', $report['programs']['unhealthy'], ['count' => $report['programs']['unhealthy']])
                    : null"
                :note="__('RUNNING state from the last refresh. Unhealthy rows sort to the top.')"
                :tone="$report['programs']['unhealthy'] > 0 ? 'danger' : null"
                class="border-b border-brand-ink/10"
            />
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
                                        <p class="mt-0.5 text-2xs text-amber-800">{{ __('Missing from last supervisorctl output') }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1 {{ $sloTonePalette['mist'] }}">
                                        {{ $row['program_type'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-brand-moss">{{ $row['site_name'] ?? __('Server') }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1 {{ $stateTone($row['state']) }}">
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
