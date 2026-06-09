@props([
    'promptUser' => 'root',
    'promptHost' => 'server',
    'maxHeight' => null,
])

@php
    $prompt = $promptUser.'@'.$promptHost;
@endphp

<div {{ $attributes->class(['flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-brand-ink/10 bg-white shadow-sm ring-1 ring-brand-ink/[0.04]']) }}>
    @isset($toolbar)
        <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-brand-cream/50 px-3 py-2 sm:px-4">
            {{ $toolbar }}
        </div>
    @endisset

    <div
        @class([
            'relative min-h-0 flex-1 overflow-y-auto bg-[#0b1020] font-mono text-[12px] leading-relaxed text-slate-100 sm:text-[12.5px]',
        ])
        @if ($maxHeight) style="max-height: {{ $maxHeight }};" @endif
    >
        <div class="pointer-events-none absolute -end-12 -top-16 h-40 w-40 rounded-full bg-brand-sage/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-16 start-6 h-32 w-32 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>

        <div class="relative px-3 py-3 sm:px-4 sm:py-3.5">
            {{ $body ?? $slot }}
        </div>
    </div>

    @isset($footer)
        <div class="shrink-0 border-t border-brand-ink/10 bg-[#0b1020] px-3 py-2.5 sm:px-4 sm:py-3">
            {{ $footer }}
        </div>
    @endisset
</div>
