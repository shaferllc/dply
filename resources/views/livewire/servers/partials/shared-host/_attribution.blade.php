@php
    $rows = $attribution['rows'] ?? [];
    $hasSnapshot = (bool) ($attribution['has_snapshot'] ?? false);
    $range = (string) ($attribution['range'] ?? 'current');
    $scanCount = (int) ($attribution['scan_count'] ?? 0);
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-chart-bar-square class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Site load attribution') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                    @if ($range === '7d')
                        {{ __('Peak load over 7 days') }}
                    @elseif ($range === '24h')
                        {{ __('Peak load over 24 hours') }}
                    @else
                        {{ __('Who is using the box right now') }}
                    @endif
                </h2>
                <p class="mt-1 text-sm text-brand-moss">
                    @if ($range === 'current')
                        {{ __('Process CPU and memory mapped to each site path from a point-in-time SSH scan.') }}
                    @else
                        {{ trans_choice(':count scan in this window|:count scans in this window', $scanCount, ['count' => $scanCount]) }}
                    @endif
                </p>
            </div>
        </div>
        <div class="inline-flex rounded-lg border border-brand-ink/10 bg-white p-0.5 text-xs font-semibold shadow-sm">
            @foreach (['current' => __('Now'), '24h' => __('24h'), '7d' => __('7d')] as $key => $label)
                <button
                    type="button"
                    wire:click="setAttributionRange('{{ $key }}')"
                    @class([
                        'rounded-md px-2.5 py-1 transition',
                        'bg-brand-forest text-white' => $range === $key,
                        'text-brand-moss hover:bg-brand-sand/50' => $range !== $key,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if (! $hasSnapshot)
        <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">
            @if ($range === 'current')
                {{ __('No attribution scan yet. Run Scan load to map processes to site paths.') }}
            @else
                {{ __('No scan history in this window yet. Run Scan load a few times to build a rollup.') }}
            @endif
        </div>
    @elseif ($rows === [])
        <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">
            {{ __('Scan completed but no site rows were returned.') }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-sage">
                    <tr>
                        <th class="px-6 py-3 sm:px-7">{{ __('Site') }}</th>
                        <th class="px-4 py-3">{{ $range === 'current' ? __('CPU') : __('Peak CPU') }}</th>
                        <th class="px-4 py-3">{{ $range === 'current' ? __('Memory') : __('Peak memory') }}</th>
                        @if ($range !== 'current')
                            <th class="px-4 py-3">{{ __('Avg CPU') }}</th>
                        @endif
                        <th class="px-4 py-3">{{ __('Share') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-6 py-3 sm:px-7">
                                <a href="{{ $row['href'] }}" wire:navigate class="font-semibold text-brand-ink hover:text-brand-forest">{{ $row['name'] }}</a>
                            </td>
                            <td class="px-4 py-3 text-brand-moss">{{ number_format((float) ($row['cpu_pct'] ?? 0), 1) }}%</td>
                            <td class="px-4 py-3 text-brand-moss">{{ number_format((float) ($row['mem_mb'] ?? 0), 0) }} MB</td>
                            @if ($range !== 'current')
                                <td class="px-4 py-3 text-brand-moss">{{ number_format((float) ($row['avg_cpu_pct'] ?? 0), 1) }}%</td>
                            @endif
                            <td class="px-4 py-3">
                                @php
                                    $share = $row['cpu_share_pct'] ?? $row['mem_share_pct'];
                                    $hot = $share !== null && $share >= 70;
                                @endphp
                                @if ($share !== null)
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1',
                                        $tonePalette['amber'] => $hot,
                                        $tonePalette['sage'] => ! $hot,
                                    ])>{{ number_format((float) $share, 0) }}%</span>
                                @else
                                    <span class="text-brand-moss">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($range === 'current' && ($attribution['unattributed'] ?? null))
            <div class="border-t border-brand-ink/10 px-6 py-3 text-xs text-brand-moss sm:px-7">
                {{ __('Unattributed: :cpu% CPU · :mem MB (system + other processes)', [
                    'cpu' => number_format((float) ($attribution['unattributed']['cpu_pct'] ?? 0), 1),
                    'mem' => number_format((float) ($attribution['unattributed']['mem_mb'] ?? 0), 0),
                ]) }}
            </div>
        @endif
    @endif
</section>
