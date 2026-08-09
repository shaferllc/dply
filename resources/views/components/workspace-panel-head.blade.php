@props([
    'icon',
    'title',
    'note' => null,
    'count' => null,
    /** One-line variant: note rides beside the title (truncated, full text on hover). */
    'dense' => false,
    /** For aria-labelledby on the surrounding section. */
    'titleId' => null,
    /** null | 'danger' | 'amber' — tints the strip and the icon. */
    'tone' => null,
])

@php
    $toneStrip = match ($tone) {
        'danger' => 'bg-rose-50/60',
        'amber' => 'bg-amber-50/60',
        default => 'bg-brand-sand/20',
    };
    $toneIcon = match ($tone) {
        'danger' => 'text-rose-600',
        'amber' => 'text-amber-700',
        default => 'text-brand-sage',
    };
@endphp

@if ($dense)
    {{-- One-line header, Run-page metric: icon + title, hairline divider, then the
         context line truncated to whatever width is left (full text on hover).
         Use where the header is pure chrome above real content — a form, a tab
         strip, a table — and the prose is reference, not something to read now. --}}
    <div {{ $attributes->class(['flex flex-wrap items-center gap-x-2 gap-y-1', $toneStrip, 'px-3 py-2 sm:px-4']) }}>
        <h2 @if ($titleId) id="{{ $titleId }}" @endif class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-dynamic-component :component="$icon" class="h-4 w-4 shrink-0 {{ $toneIcon }}" aria-hidden="true" />
            {{ $title }}
        </h2>

        @if (filled($count))
            <span class="inline-flex shrink-0 items-center rounded-full bg-white px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $count }}</span>
        @endif

        @if (filled($note))
            <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
            <p class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ $note }}">{{ $note }}</p>
        @endif

        {{-- ml-auto so actions stay right-aligned when there's no note; with a
             note the flex-1 paragraph already fills the gap and it's a no-op. --}}
        @if (isset($actions) && trim((string) $actions) !== '')
            <div class="ml-auto flex shrink-0 flex-wrap items-center gap-1.5">{{ $actions }}</div>
        @endif
    </div>
@else
    {{-- Compact workspace panel header: small icon + title (+ optional count pill)
         on one line, one muted line of context under it, actions on the right.
         Replaces the tall icon-badge + eyebrow + title + prose stack — the eyebrow
         it drops always either restated the title or said nothing ("Library"). --}}
    <div {{ $attributes->class(['flex flex-wrap items-start justify-between gap-x-4 gap-y-2', $toneStrip, 'px-5 py-3.5 sm:px-6']) }}>
        {{-- flex-1 with a basis floor: a long note wraps its own text instead of
             pushing the actions onto their own row. --}}
        <div class="min-w-0 flex-1 basis-72">
            <div class="flex flex-wrap items-center gap-2">
                <x-dynamic-component :component="$icon" class="h-4 w-4 shrink-0 {{ $toneIcon }}" aria-hidden="true" />
                <h2 @if ($titleId) id="{{ $titleId }}" @endif class="text-sm font-semibold text-brand-ink">{{ $title }}</h2>
                @if (filled($count))
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss ring-1 ring-brand-ink/10">
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
@endif
