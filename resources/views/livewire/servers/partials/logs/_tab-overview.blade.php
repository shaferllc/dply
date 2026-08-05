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

<section class="border-b border-brand-ink/10">
    @php
        // The status *is* the heading — the old "LOG VIEWER" eyebrow above it just
        // restated the tab you were already looking at.
        $overviewTitle = match ($overall) {
            'blocked' => __('SSH log access unavailable'),
            'degraded' => __('Last fetch reported an error'),
            default => __('Ready — :source', ['source' => $activeSource['label'] ?? __('Unknown source')]),
        };

        if ($overall === 'blocked') {
            $overviewNote = $isDeployer && $sshRequiredForActive
                ? __('File log sources require admin or owner SSH access.')
                : __('Provisioning and SSH must be ready before file log sources can be read.');
        } elseif ($overall === 'degraded' && filled($viewer['error'] ?? null)) {
            $overviewNote = $viewer['error'];
        } elseif ($lastFetched) {
            $overviewNote = __('Last fetched :time', ['time' => $lastFetched->diffForHumans()]);
            if ($viewer['auto_refresh'] ?? false) {
                $overviewNote .= ' · '.__('Auto-refresh every :seconds s', ['seconds' => $viewer['auto_refresh_seconds'] ?? 30]);
            }
            if ($viewer['broadcast_subscribable'] ?? false) {
                $overviewNote .= ' · '.__('Reverb live stream enabled');
            }
        } else {
            $overviewNote = trans_choice(':count log source available|:count log sources available', $summary['source_count'] ?? 0, ['count' => $summary['source_count'] ?? 0])
                .' · '.__('Open the Viewer tab to fetch lines');
        }
    @endphp

    <x-workspace-panel-head
        icon="heroicon-o-document-text"
        :title="$overviewTitle"
        :note="$overviewNote"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <a
                href="{{ route('servers.logs', ['server' => $server, 'tab' => 'activity', 'cat' => 'background']) }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                {{ __('Background activity') }}
            </a>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="grid gap-px bg-brand-ink/10 sm:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['label' => __('Sources'), 'value' => number_format((int) ($summary['source_count'] ?? 0))],
            ['label' => __('Groups'), 'value' => number_format((int) ($summary['group_count'] ?? 0))],
            ['label' => __('Site sources'), 'value' => number_format((int) ($summary['site_source_count'] ?? 0))],
            ['label' => __('Lines shown'), 'value' => number_format((int) ($summary['filtered_lines'] ?? 0))],
            ['label' => __('Lines fetched'), 'value' => number_format((int) ($summary['total_lines'] ?? 0))],
            ['label' => __('SSH ready'), 'value' => $opsReady ? __('Yes') : __('No')],
        ] as $stat)
            <div class="bg-white px-3 py-2">
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $stat['label'] }}</p>
                <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums text-brand-ink">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>

    @if (($viewer['truncated'] ?? false) || ($viewer['raw_bytes'] ?? 0) > 0)
        <div class="border-t border-brand-ink/10 px-5 py-2.5 text-xs text-brand-moss sm:px-6">
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
