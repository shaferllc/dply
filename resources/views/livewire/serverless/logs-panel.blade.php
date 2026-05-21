<div class="dply-card p-6 sm:p-8 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Logs') }}</p>
            <h2 class="mt-1 text-lg font-bold text-brand-ink">{{ __('Activations') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Recent invocations of this function — status, duration, and runtime output.') }}</p>
        </div>
        <button type="button" wire:click="refreshLogs" wire:loading.attr="disabled"
                class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
            {{ __('Refresh') }}
        </button>
    </div>

    @if ($ok && count($activations) > 0)
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['label' => __('Invocations'), 'value' => $metrics['total']],
                ['label' => __('Error rate'), 'value' => $metrics['error_rate'].'%'],
                ['label' => __('Avg duration'), 'value' => $metrics['avg_duration'].'ms'],
                ['label' => __('Cold starts'), 'value' => $metrics['cold_starts']],
            ] as $card)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss/70">{{ $card['label'] }}</dt>
                    <dd class="mt-0.5 text-lg font-bold text-brand-ink">{{ $card['value'] }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="text-xs text-brand-moss/60">{{ __('Across the last :n invocations.', ['n' => $metrics['total']]) }}</p>
    @endif

    @if (! $ok)
        <div class="rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-sm text-brand-ink">
            {{ $error ?? __('Activation logs are not available yet.') }}
        </div>
    @elseif (count($activations) === 0)
        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
            {{ __('No activations recorded yet — this function has not been invoked.') }}
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($activations as $activation)
                <li class="py-3 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span @class([
                            'inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold',
                            'bg-brand-forest/15 text-brand-forest' => $activation['success'],
                            'bg-rose-100 text-rose-700' => ! $activation['success'],
                        ])>{{ $activation['success'] ? __('OK') : __('Error') }}</span>
                        <span class="font-mono text-xs text-brand-ink">{{ $activation['name'] ?: '—' }}</span>
                        <span class="text-xs text-brand-moss">{{ $activation['duration'] }}ms</span>
                        @if ($activation['start'] > 0)
                            <span class="text-xs text-brand-moss/60">{{ \Illuminate\Support\Carbon::createFromTimestampMs($activation['start'])->diffForHumans() }}</span>
                        @endif
                        <span class="ml-auto font-mono text-[11px] text-brand-moss/50">{{ $activation['id'] }}</span>
                    </div>
                    @if (! $activation['success'] && $activation['result'] !== null)
                        <pre class="mt-2 overflow-auto rounded-lg bg-rose-50 p-3 text-[11px] leading-relaxed text-rose-800">{{ json_encode($activation['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif
                    @if (count($activation['logs']) > 0)
                        <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-brand-ink p-3 text-[11px] leading-relaxed text-brand-cream">{{ implode("\n", $activation['logs']) }}</pre>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
