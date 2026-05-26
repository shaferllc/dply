@php
    $guardrail = $site->edgeGuardrail();
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <div>
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Monthly usage quota') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">
                {{ __('Soft cap on requests and bandwidth per calendar month. You get a heads-up at :pct%; once you cross 100%, the dashboard flags the site as over quota.', ['pct' => $guardrail['warn_at_percent'] ?? config('edge.guardrail.warn_at_percent', 80)]) }}
            </p>
        </div>
        @if ($guardrail !== null)
            @php
                $state = $guardrail['state'] ?? 'ok';
                $stateBadge = match ($state) {
                    'over' => 'bg-red-100 text-red-800',
                    'warn' => 'bg-amber-100 text-amber-800',
                    default => 'bg-emerald-100 text-emerald-800',
                };
                $stateLabel = match ($state) {
                    'over' => __('Over'),
                    'warn' => __('Warn'),
                    default => __('OK'),
                };
            @endphp
            <span class="rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide {{ $stateBadge }}">
                {{ $stateLabel }}
            </span>
        @endif
    </div>

    @if ($guardrail === null)
        <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
            {{ __('Quota status not evaluated yet — runs daily at 02:45 UTC.') }}
        </div>
    @else
        @php
            $requests = (int) ($guardrail['requests'] ?? 0);
            $bytes = (int) ($guardrail['bytes_egress'] ?? 0);
            $requestsCap = (int) ($guardrail['requests_cap'] ?? 0);
            $bytesCap = (int) ($guardrail['bytes_egress_cap'] ?? 0);
            $reqPct = (int) ($guardrail['requests_percent'] ?? 0);
            $bytesPct = (int) ($guardrail['bytes_percent'] ?? 0);
            $warnAt = (int) ($guardrail['warn_at_percent'] ?? 80);
            $evaluatedAt = $guardrail['evaluated_at'] ?? null;

            $humanBytes = static function (int $b): string {
                if ($b <= 0) {
                    return '0 B';
                }
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $i = (int) min(count($units) - 1, floor(log($b, 1024)));

                return sprintf('%.1f %s', $b / (1024 ** $i), $units[$i]);
            };

            $bar = static function (int $pct, string $state) {
                $clamped = max(0, min(100, $pct));
                $color = match (true) {
                    $pct >= 100 => 'bg-red-500',
                    $state === 'warn' && $pct >= 50 => 'bg-amber-500',
                    $pct >= 50 => 'bg-amber-400',
                    default => 'bg-emerald-500',
                };

                return [$clamped, $color];
            };

            [$reqW, $reqColor] = $bar($reqPct, $state);
            [$bytesW, $bytesColor] = $bar($bytesPct, $state);
        @endphp
        <div class="grid gap-6 px-6 py-5 sm:px-8 sm:grid-cols-2">
            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Requests') }}</span>
                    <span class="font-mono text-xs text-brand-moss">{{ $reqPct }}%</span>
                </div>
                <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-brand-sand/80">
                    <div class="h-full rounded-full {{ $reqColor }} transition-[width]" style="width: {{ $reqW }}%"></div>
                </div>
                <p class="mt-2 text-sm tabular-nums text-brand-ink">
                    {{ number_format($requests) }}
                    <span class="text-xs text-brand-moss">/ {{ number_format($requestsCap) }}</span>
                </p>
            </div>
            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Bandwidth') }}</span>
                    <span class="font-mono text-xs text-brand-moss">{{ $bytesPct }}%</span>
                </div>
                <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-brand-sand/80">
                    <div class="h-full rounded-full {{ $bytesColor }} transition-[width]" style="width: {{ $bytesW }}%"></div>
                </div>
                <p class="mt-2 text-sm tabular-nums text-brand-ink">
                    {{ $humanBytes($bytes) }}
                    <span class="text-xs text-brand-moss">/ {{ $humanBytes($bytesCap) }}</span>
                </p>
            </div>
        </div>
        @if ($evaluatedAt)
            <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-2 text-right text-[11px] text-brand-moss sm:px-8">
                {{ __('Evaluated :ts', ['ts' => \Illuminate\Support\Carbon::parse($evaluatedAt)->diffForHumans()]) }}
            </div>
        @endif
    @endif
</section>
