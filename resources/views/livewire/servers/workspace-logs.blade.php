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
    $sourceRows = $report['source_rows'] ?? [];
    $activeSource = $report['active_source'] ?? [];
    $viewer = $report['viewer'] ?? [];

    $overall = $report['overall'] ?? 'ready';
    $overallTone = match ($overall) {
        'blocked' => $tonePalette['amber'],
        'degraded' => $tonePalette['rose'],
        default => $tonePalette['emerald'],
    };

    $opsReady = (bool) ($report['ops_ready'] ?? false);
    $isDeployer = (bool) ($report['is_deployer'] ?? false);
    $sshRequiredForActive = (bool) ($report['ssh_required_for_active'] ?? true);
    $lastFetched = $viewer['last_fetched_at'] ?? null;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="logs"
    :title="__('Logs')"
    :description="__('Dply activity and system log tailing for this server — live SSH reads with Reverb streaming.')"
    :pageHeaderToolbar="true"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Logs from systemd, nginx/caddy, PHP-FPM, and dply\'s own activity stream — read live from the server over SSH. Pick a source below, and the viewer tails the most recent lines and streams new ones via Reverb (with a poll fallback).') }}</p>
        <p>{{ __('Time ranges are server-side filters: "Last 5 minutes" reads only the recent slice of each file; broader ranges page through more of the file. Sources that rotate (e.g. nginx access.log) honor the rotation — older entries roll off naturally.') }}</p>
    </x-explainer>

    <div
        id="dply-server-log-broadcast-context"
        class="hidden"
        aria-hidden="true"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $logBroadcastEchoSubscribable ? '1' : '0' }}"
    ></div>

    <div class="space-y-6">
        @if ($isDeployer)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                        <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Deployers can review Dply activity logs but cannot read server log files over SSH. Switch to Dply activity or ask an admin to grant broader access.') }}
                        </p>
                    </div>
                </div>
            </section>
        @endif

        @if (! $opsReady && $sshRequiredForActive)
            @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
        @endif

        {{-- Overall --}}
        <section class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Log viewer') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                                @switch($overall)
                                    @case('blocked')
                                        {{ __('SSH log access unavailable') }}
                                        @break
                                    @case('degraded')
                                        {{ __('Last fetch reported an error') }}
                                        @break
                                    @default
                                        {{ __('Ready — :source', ['source' => $activeSource['label'] ?? __('Unknown source')]) }}
                                @endswitch
                            </h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                @if ($overall === 'blocked')
                                    @if ($isDeployer && $sshRequiredForActive)
                                        {{ __('File log sources require admin or owner SSH access.') }}
                                    @else
                                        {{ __('Provisioning and SSH must be ready before file log sources can be read.') }}
                                    @endif
                                @elseif ($overall === 'degraded' && filled($viewer['error'] ?? null))
                                    {{ $viewer['error'] }}
                                @elseif ($lastFetched)
                                    {{ __('Last fetched :time', ['time' => $lastFetched->diffForHumans()]) }}
                                    @if ($viewer['auto_refresh'] ?? false)
                                        · {{ __('Auto-refresh every :seconds s', ['seconds' => $viewer['auto_refresh_seconds'] ?? 30]) }}
                                    @endif
                                    @if ($viewer['broadcast_subscribable'] ?? false)
                                        · {{ __('Reverb live stream enabled') }}
                                    @endif
                                @else
                                    {{ trans_choice(':count log source available|:count log sources available', $summary['source_count'] ?? 0, ['count' => $summary['source_count'] ?? 0]) }}
                                    · {{ __('Select a source in the viewer below to fetch lines') }}
                                @endif
                            </p>
                        </div>
                    </div>
                    <a
                        href="{{ route('servers.activity', $server) }}?category=background"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        {{ __('Background activity') }}
                    </a>
            </div>

            <div class="grid gap-px bg-brand-ink/10 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                @foreach ([
                    ['label' => __('Sources'), 'value' => number_format((int) ($summary['source_count'] ?? 0))],
                    ['label' => __('Groups'), 'value' => number_format((int) ($summary['group_count'] ?? 0))],
                    ['label' => __('Site sources'), 'value' => number_format((int) ($summary['site_source_count'] ?? 0))],
                    ['label' => __('Lines shown'), 'value' => number_format((int) ($summary['filtered_lines'] ?? 0))],
                    ['label' => __('Lines fetched'), 'value' => number_format((int) ($summary['total_lines'] ?? 0))],
                    ['label' => __('SSH ready'), 'value' => $opsReady ? __('Yes') : __('No')],
                ] as $stat)
                    <div class="bg-white px-4 py-3.5">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $stat['label'] }}</p>
                        <p class="mt-1 font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ $stat['value'] }}</p>
                    </div>
                @endforeach
            </div>

            @if (($viewer['truncated'] ?? false) || ($viewer['raw_bytes'] ?? 0) > 0)
                <div class="border-t border-brand-ink/10 px-6 py-4 text-sm text-brand-moss sm:px-7">
                    @if ($viewer['truncated'] ?? false)
                        <p>{{ __('Last fetch was truncated — narrow the time range or reduce tail lines for the full slice.') }}</p>
                    @endif
                    @if (($viewer['raw_bytes'] ?? 0) > 0)
                        <p @class(['mt-1' => $viewer['truncated'] ?? false])>
                            {{ __('Raw payload :bytes', ['bytes' => number_format((int) $viewer['raw_bytes']).' B']) }}
                        </p>
                    @endif
                </div>
            @endif
        </section>

        {{-- Source catalog --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-queue-list class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Sources') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Available sources') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Catalog filtered by installed services and sites on this server. Active source is highlighted.') }}</p>
                </div>
            </div>

            @if ($sourceRows === [])
                <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-7">
                    {{ __('No log sources configured for this server.') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/20 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">
                            <tr>
                                <th scope="col" class="px-6 py-3">{{ __('Source') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Group') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Type') }}</th>
                                <th scope="col" class="px-6 py-3">{{ __('Access') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8 bg-white">
                            @foreach ($sourceRows as $row)
                                <tr wire:key="log-source-row-{{ $row['key'] }}" @class(['bg-brand-sage/5' => $row['active']])>
                                    <td class="px-6 py-3.5">
                                        <div class="font-medium text-brand-ink">{{ $row['label'] }}</div>
                                        @if ($row['path'])
                                            <div class="mt-0.5 font-mono text-[11px] text-brand-mist">{{ $row['path'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3.5 text-brand-moss">{{ $row['group_label'] }}</td>
                                    <td class="px-4 py-3.5 font-mono text-xs text-brand-moss">{{ $row['type'] }}</td>
                                    <td class="px-6 py-3.5">
                                        @if ($row['active'])
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $tonePalette['emerald'] }}">{{ __('Active') }}</span>
                                        @elseif ($row['ssh_required'] && (! $opsReady || $isDeployer))
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $tonePalette['amber'] }}">{{ __('SSH blocked') }}</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $tonePalette['mist'] }}">{{ __('Available') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Related --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Security') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Security digest') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Auth failure counts, fail2ban jails, firewall posture, and sshd settings — lightweight read-only scan.') }}</p>
                    </div>
                </div>
                <div class="px-6 py-5 text-sm sm:px-7">
                    <a
                        href="{{ route('servers.security-digest', $server) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1 text-xs font-semibold text-brand-moss hover:text-brand-ink"
                    >
                        {{ __('Open security digest') }}
                        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-calendar-days class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploys') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deploy windows') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Server-wide deny windows that skip deploy jobs — check recent skips when investigating deploy gaps in logs.') }}</p>
                    </div>
                </div>
                <div class="px-6 py-5 text-sm sm:px-7">
                    <a
                        href="{{ route('servers.deploy-policy', $server) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1 text-xs font-semibold text-brand-moss hover:text-brand-ink"
                    >
                        {{ __('Open deploy windows') }}
                        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>
            </section>
        </div>

        @include('livewire.servers.partials.log-viewer-panel', ['logSources' => $logSources])
    </div>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
