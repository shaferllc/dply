@props([
    'promptUser' => 'root',
    'promptHost' => 'server',
    'maxHeight' => null,
    /** light = cream toolbar (server console); dark = full terminal chrome */
    'tone' => 'light',
])

@php
    $prompt = $promptUser.'@'.$promptHost;
    $isDark = $tone === 'dark';
@endphp

<div {{ $attributes->class([
    'flex min-h-0 flex-1 flex-col overflow-hidden shadow-sm ring-1',
    'rounded-xl border border-brand-ink/10 bg-white ring-brand-ink/[0.04]' => ! $isDark,
    'rounded-2xl border border-white/10 bg-[#0b1020] ring-black/20' => $isDark,
]) }}>
    @isset($toolbar)
        <div @class([
            'flex shrink-0 flex-wrap items-center gap-2 px-3 py-2 sm:px-4',
            'border-b border-brand-ink/10 bg-brand-cream/50' => ! $isDark,
            'border-b border-white/10 bg-white/[0.03]' => $isDark,
        ])>
            {{ $toolbar }}
        </div>
    @endisset

    <div
        @class([
            'relative min-h-0 flex-1 overflow-y-auto font-mono text-[12px] leading-relaxed sm:text-[12.5px]',
            'bg-[#0b1020] text-slate-100' => ! $isDark,
            'bg-transparent text-slate-100' => $isDark,
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
        <div @class([
            'shrink-0 px-3 py-2.5 sm:px-4 sm:py-3',
            'border-t border-brand-ink/10 bg-[#0b1020]' => ! $isDark,
            'border-t border-white/10 bg-black/25' => $isDark,
        ])>
            {{ $footer }}
        </div>
    @endisset
</div>
