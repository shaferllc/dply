{{-- Live system metrics body (bars + load + "why CPU busy"), no card wrapper.
     Rendered inside the identity-hero header card so load reads as part of the
     same "where does this server stand" story. Relies on parent-scope
     $latestMetricSnapshot + $server. --}}
@php
    $metricPayload = is_object($latestMetricSnapshot) && is_array($latestMetricSnapshot->payload ?? null)
        ? $latestMetricSnapshot->payload
        : [];
    $metricCpu = isset($metricPayload['cpu_pct']) && is_numeric($metricPayload['cpu_pct']) ? (float) $metricPayload['cpu_pct'] : null;
    $metricMem = isset($metricPayload['mem_pct']) && is_numeric($metricPayload['mem_pct']) ? (float) $metricPayload['mem_pct'] : null;
    $metricDisk = isset($metricPayload['disk_pct']) && is_numeric($metricPayload['disk_pct']) ? (float) $metricPayload['disk_pct'] : null;
    $metricLoad1m = isset($metricPayload['load_1m']) && is_numeric($metricPayload['load_1m']) ? (float) $metricPayload['load_1m'] : null;
    $metricLoadPerCpu = isset($metricPayload['load_per_cpu_1m']) && is_numeric($metricPayload['load_per_cpu_1m']) ? (float) $metricPayload['load_per_cpu_1m'] : null;
    $metricHasAny = $metricCpu !== null || $metricMem !== null || $metricDisk !== null;
    // Top CPU consumers captured by the metrics agent (top_cpu in the
    // payload): [{pid,user,command,cpu_pct,mem_pct}, ...]. Surfaced when
    // CPU is elevated to answer "why is it busy?".
    $topCpu = isset($metricPayload['top_cpu']) && is_array($metricPayload['top_cpu']) ? $metricPayload['top_cpu'] : [];
    $cpuBusy = $metricCpu !== null && $metricCpu >= 85;
    $metricCapturedAt = is_object($latestMetricSnapshot) ? $latestMetricSnapshot->captured_at : null;
    $metricStale = $metricCapturedAt && $metricCapturedAt->lt(now()->subMinutes(10));
    $metricBar = function (?float $pct): array {
        if ($pct === null) {
            return ['width' => 0, 'color' => 'bg-brand-mist/40'];
        }
        $clamped = max(0.0, min(100.0, $pct));
        if ($pct >= 95) {
            $color = 'bg-rose-500';
        } elseif ($pct >= 85) {
            $color = 'bg-amber-500';
        } else {
            $color = 'bg-emerald-500';
        }

        return ['width' => $clamped, 'color' => $color];
    };
    $metricRow = function (string $label, ?float $pct) use ($metricBar): string {
        $bar = $metricBar($pct);
        $val = $pct === null ? '—' : number_format($pct, 0).'%';

        return view('livewire.servers.partials._overview-metric-row', [
            'label' => $label,
            'value' => $val,
            'barColor' => $bar['color'],
            'barWidth' => $bar['width'],
        ])->render();
    };
@endphp
<div class="mb-3 flex items-center gap-2">
    <x-heroicon-o-chart-bar class="h-4 w-4 text-brand-mist" aria-hidden="true" />
    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('System load') }}</p>
    @if ($metricCapturedAt)
        <span class="text-[11px] {{ $metricStale ? 'font-semibold text-amber-700' : 'text-brand-mist' }}">
            · {{ __('Sampled :ago', ['ago' => $metricCapturedAt->diffForHumans()]) }}@if ($metricStale) · {{ __('STALE') }}@endif
        </span>
    @endif
    <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="ml-auto inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
        <x-heroicon-m-chart-bar class="h-4 w-4 shrink-0" aria-hidden="true" />
        {{ __('Open Monitor') }}
    </a>
</div>
@if (! $metricHasAny)
    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-4 py-3 text-sm text-brand-moss">
        {{ __('Waiting for the first snapshot — the monitor agent is installed during provisioning and reports every minute. Open the Monitor tab if this card stays empty.') }}
    </div>
@else
    <div class="grid gap-3 sm:grid-cols-3">
        {!! $metricRow(__('CPU'), $metricCpu) !!}
        {!! $metricRow(__('Memory'), $metricMem) !!}
        {!! $metricRow(__('Disk'), $metricDisk) !!}
    </div>
    @if ($metricLoad1m !== null)
        <p class="mt-3 text-xs text-brand-moss">
            {{ __('Load (1m)') }}:
            <span class="font-mono font-semibold text-brand-ink">{{ number_format($metricLoad1m, 2) }}</span>
            @if ($metricLoadPerCpu !== null)
                <span class="text-brand-mist"> · </span>
                {{ __('per CPU') }}:
                <span class="font-mono font-semibold text-brand-ink">{{ number_format($metricLoadPerCpu, 2) }}</span>
            @endif
        </p>
    @endif

    {{-- Why is CPU busy? Surface the agent's top CPU processes
         (+ a remediation hint each) when CPU is elevated. --}}
    @if ($cpuBusy)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/60 p-4">
            <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">
                <x-heroicon-m-fire class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Why CPU is busy') }}
            </p>
            @if (count($topCpu) > 0)
                <ul class="mt-3 space-y-2.5">
                    @foreach (array_slice($topCpu, 0, 5) as $proc)
                        @php
                            $cmd = (string) ($proc['command'] ?? '—');
                            $pcpu = isset($proc['cpu_pct']) && is_numeric($proc['cpu_pct']) ? (float) $proc['cpu_pct'] : null;
                            $puser = (string) ($proc['user'] ?? '');
                            $ppid = $proc['pid'] ?? null;
                            $hint = \App\Support\Servers\TopProcessRemediation::for($cmd);
                        @endphp
                        <li class="text-sm">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 truncate font-mono font-medium text-brand-ink">{{ $cmd }}</span>
                                <span class="shrink-0 font-mono text-xs {{ $pcpu !== null && $pcpu >= 50 ? 'font-semibold text-rose-600' : 'text-brand-moss' }}">{{ $pcpu !== null ? number_format($pcpu, 0).'% CPU' : '—' }}</span>
                            </div>
                            <div class="text-[11px] text-brand-mist">{{ $puser !== '' ? $puser : '—' }}@if ($ppid !== null) · pid {{ $ppid }}@endif</div>
                            @if ($hint)
                                <p class="mt-0.5 text-xs leading-relaxed text-amber-900">{{ $hint }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="mt-3 inline-block text-xs font-semibold text-brand-forest hover:text-brand-sage hover:underline">{{ __('Full history in Monitor') }} →</a>
            @else
                <p class="mt-2 text-xs leading-relaxed text-amber-900">{{ __('CPU is high but this server’s metrics agent hasn’t reported per-process detail yet. Reinstall/upgrade the monitor agent (Monitor → Diagnostics) to see the top processes here.') }}</p>
            @endif
        </div>
    @endif
@endif
