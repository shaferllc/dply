@php
    $capacityMetrics = $report['capacity']['metrics'] ?? [];

    // pct → stat-strip tone. Below the warning line stays default ink: a strip
    // of four green figures reads as decoration, not signal.
    $capacityTone = static fn (?float $pct): ?string => match (true) {
        $pct === null => null,
        $pct >= 95 => 'bad',
        $pct >= 85 => 'warn',
        default => null,
    };

    $capacityValue = static fn (?float $pct): string => $pct === null ? '—' : number_format($pct, 0).'%';
@endphp

{{-- Nested inside the merged Health card — dense heads, no second outer card.
     The icon-badge + eyebrow + title + prose stack and the four `text-3xl`
     tiles cost ~260px before the first alert. --}}
<div>
    <x-workspace-panel-head
        dense
        icon="heroicon-o-heart"
        :title="__('Overall')"
        :count="$report['alert_count'] > 0
            ? trans_choice(':count alert|:count alerts', $report['alert_count'], ['count' => $report['alert_count']])
            : null"
        :note="__('Headroom :headroom — the verdict beside the tab strip rolls up every signal below.', ['headroom' => ucfirst((string) ($report['capacity']['headroom'] ?? 'unknown'))])"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <a
                href="{{ route('servers.monitor', $server) }}"
                wire:navigate
                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                {{ __('Full metrics') }}
                <x-heroicon-m-arrow-right class="h-3 w-3 shrink-0" aria-hidden="true" />
            </a>
        </x-slot:actions>
    </x-workspace-panel-head>

    @if (count($report['alerts']) > 0)
        <ul class="divide-y divide-brand-ink/10 border-b border-brand-ink/10">
            @foreach ($report['alerts'] as $alert)
                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-1 px-4 py-2 sm:px-5">
                    <span @class([
                        'shrink-0 rounded-full px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                        'bg-rose-100 text-rose-800' => $alert['severity'] === 'critical',
                        'bg-amber-100 text-amber-900' => $alert['severity'] === 'warning',
                    ])>{{ $alert['severity'] }}</span>
                    <p class="shrink-0 text-xs font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                    <p class="min-w-0 flex-1 truncate text-xs text-brand-moss" title="{{ $alert['message'] }}">{{ $alert['message'] }}</p>
                    @if ($alert['href'] && $alert['link_label'])
                        <a href="{{ $alert['href'] }}" wire:navigate class="ml-auto shrink-0 text-xs font-semibold text-brand-forest hover:underline">{{ $alert['link_label'] }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-brand-ink/10 px-4 py-2.5 text-xs text-brand-moss sm:px-5">
            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0 text-emerald-600" aria-hidden="true" />
            {{ __('No active health alerts on this server.') }}
        </p>
    @endif

    @if ($report['capacity']['has_samples'] ?? false)
        <x-workspace-panel-head
            dense
            icon="heroicon-o-chart-bar-square"
            :title="__('Capacity snapshot')"
            :note="$report['capacity']['captured_at']
                ? __('Sampled :ago', ['ago' => $report['capacity']['captured_at']->diffForHumans()])
                : __('Latest guest sample from the monitor agent.')"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="setHealthWorkspaceTab('capacity')"
                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                >
                    {{ __('Capacity details') }}
                    <x-heroicon-m-chevron-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                </button>
            </x-slot:actions>
        </x-workspace-panel-head>

        <x-workspace-stat-strip :stats="[
            [
                'label' => __('CPU'),
                'value' => $capacityValue($capacityMetrics['cpu_pct'] ?? null),
                'tone' => $capacityTone($capacityMetrics['cpu_pct'] ?? null),
            ],
            [
                'label' => __('Memory'),
                'value' => $capacityValue($capacityMetrics['mem_pct'] ?? null),
                'tone' => $capacityTone($capacityMetrics['mem_pct'] ?? null),
            ],
            [
                'label' => __('Root disk'),
                'value' => $capacityValue($capacityMetrics['disk_pct'] ?? null),
                'tone' => $capacityTone($capacityMetrics['disk_pct'] ?? null),
            ],
            [
                'label' => __('Load (1m)'),
                'value' => isset($capacityMetrics['load_1m']) ? number_format((float) $capacityMetrics['load_1m'], 2) : '—',
            ],
        ]" />
    @endif
</div>
