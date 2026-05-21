@php
    /** @var \App\Models\FunctionInvocation $invocation */
    $logLines = $invocation->logLines();
    $excerpt = trim((string) $invocation->result_excerpt);
    $contextPairs = $invocation->contextPairs();
    $hasDetail = count($logLines) > 0 || $excerpt !== '' || count($contextPairs) > 0;
@endphp
<li class="py-3 first:pt-0 last:pb-0">
    <details>
        <summary @class([
            'flex flex-wrap items-center gap-2 list-none',
            'cursor-pointer' => $hasDetail,
        ])>
            <span @class([
                'inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold',
                'bg-brand-forest/15 text-brand-forest' => $invocation->success,
                'bg-rose-100 text-rose-700' => ! $invocation->success,
            ])>{{ $invocation->success ? __('OK') : __('Error') }}</span>

            <span class="font-mono text-xs text-brand-ink">{{ $invocation->method }} {{ \Illuminate\Support\Str::limit((string) $invocation->path, 64) }}</span>

            @if ($invocation->status_code)
                <span class="font-mono text-[11px] text-brand-moss">HTTP {{ $invocation->status_code }}</span>
            @endif

            <span class="text-xs text-brand-moss">{{ $invocation->duration_ms }}ms</span>

            @if ($invocation->task)
                <span class="inline-flex items-center rounded-md bg-brand-sand px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $invocation->task }}</span>
            @endif

            @if ($invocation->cold)
                <span class="inline-flex items-center rounded-md bg-brand-gold/15 px-1.5 py-0.5 text-[10px] font-semibold text-brand-ink/70">{{ __('cold') }}</span>
            @endif

            @if ($invocation->created_at)
                <span class="text-xs text-brand-moss/60">{{ $invocation->created_at->diffForHumans() }}</span>
            @endif

            @if ($invocation->activation_id)
                <span class="ml-auto font-mono text-[11px] text-brand-moss/50">{{ $invocation->activation_id }}</span>
            @elseif (! empty($invocation->context['ip']) || ! empty($invocation->context['country']))
                <span class="ml-auto font-mono text-[11px] text-brand-moss/50">{{ trim(($invocation->context['country'] ?? '').' '.($invocation->context['ip'] ?? '')) }}</span>
            @endif
        </summary>

        @if (count($contextPairs) > 0)
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 rounded-lg bg-brand-sand/30 p-3 sm:grid-cols-3">
                @foreach ($contextPairs as $label => $value)
                    <div class="min-w-0">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss/60">{{ $label }}</dt>
                        <dd class="truncate font-mono text-[11px] text-brand-ink" title="{{ $value }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($excerpt !== '')
            <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-brand-sand/40 p-3 text-[11px] leading-relaxed text-brand-ink">{{ $excerpt }}</pre>
        @endif

        @if (count($logLines) > 0)
            <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-brand-ink p-3 text-[11px] leading-relaxed text-brand-cream">{{ implode("\n", $logLines) }}</pre>
        @endif
    </details>
</li>
