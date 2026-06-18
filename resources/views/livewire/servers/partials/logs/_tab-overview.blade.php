@php
    $tonePalette = $tonePalette ?? [];
    $summary = $report['summary'] ?? [];
    $viewer = $report['viewer'] ?? [];
    $activeSource = $report['active_source'] ?? [];
    $overall = $report['overall'] ?? 'ready';
    $opsReady = (bool) ($report['ops_ready'] ?? false);
    $isDeployer = (bool) ($report['is_deployer'] ?? false);
    $sshRequiredForActive = (bool) ($report['ssh_required_for_active'] ?? true);
    $lastFetched = $viewer['last_fetched_at'] ?? null;
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
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
                        · {{ __('Open the Viewer tab to fetch lines') }}
                    @endif
                </p>
            </div>
        </div>
        <a
            href="{{ route('servers.logs', ['server' => $server, 'tab' => 'activity', 'cat' => 'background']) }}"
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
