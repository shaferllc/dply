@props([
    'title',
    'description' => null,
    'icon' => null,
    'dashed' => true,
    'borderless' => false,
    'compact' => false,
    'centered' => null,
    'tone' => 'default',
])

@php
    $isCentered = $centered ?? filled($icon);

    $iconTone = match ($tone) {
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
        default => 'bg-brand-sand/60 text-brand-moss ring-brand-ink/10',
    };

    $padding = match (true) {
        $compact => 'px-4 py-6',
        $borderless => 'px-2 py-8',
        default => 'px-5 py-6',
    };
@endphp

<div
    {{ $attributes->class([
        'rounded-xl',
        $padding,
        'border border-dashed border-brand-ink/15 bg-brand-sand/10' => $dashed && ! $borderless,
        'dply-card' => ! $dashed && ! $borderless,
        'flex flex-col items-center justify-center text-center' => $isCentered,
        'min-h-[10rem]' => $isCentered && ! $compact && ! $borderless,
    ]) }}
    role="status"
>
    @if ($icon && $isCentered)
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ring-1 {{ $iconTone }}">
            <x-dynamic-component :component="$icon" class="h-6 w-6" aria-hidden="true" />
        </span>
        <p class="mt-4 text-sm font-semibold text-brand-ink">{{ $title }}</p>

        @if ($description)
            <p class="mt-2 max-w-md text-sm leading-relaxed text-brand-moss">{{ $description }}</p>
        @endif

        @if (isset($actions))
            <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
                {{ $actions }}
            </div>
        @endif
    @else
        <p class="text-sm font-medium text-brand-ink">{{ $title }}</p>

        @if ($description)
            <p class="mt-2 text-sm leading-6 text-brand-moss">{{ $description }}</p>
        @endif

        @if (isset($actions))
            <div class="mt-4 flex flex-wrap gap-3">
                {{ $actions }}
            </div>
        @endif
    @endif
</div>
