@props([
    'icon',
    'title',
    'note' => null,
    'count' => null,
])

{{-- Compact workspace panel header: small icon + title (+ optional count pill)
     on one line, one muted line of context under it, actions on the right.
     Replaces the tall icon-badge + eyebrow + title + prose stack — the eyebrow
     it drops always either restated the title or said nothing ("Library"). --}}
<div {{ $attributes->class(['flex flex-wrap items-start justify-between gap-x-4 gap-y-2 bg-brand-sand/20 px-5 py-3.5 sm:px-6']) }}>
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <x-dynamic-component :component="$icon" class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            <h2 class="text-sm font-semibold text-brand-ink">{{ $title }}</h2>
            @if (filled($count))
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss ring-1 ring-brand-ink/10">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-forest" aria-hidden="true"></span>
                    {{ $count }}
                </span>
            @endif
        </div>
        @if (filled($note))
            <p class="mt-1 max-w-3xl text-xs leading-relaxed text-brand-moss">{{ $note }}</p>
        @endif
    </div>

    @if (isset($actions) && trim((string) $actions) !== '')
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</div>
