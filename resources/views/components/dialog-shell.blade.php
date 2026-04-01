@props([
    'title',
    'description' => null,
    'maxWidth' => 'md',
    'titleId' => null,
])

@php
    $maxWidthClass = match ($maxWidth) {
        'sm' => 'max-w-sm',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        default => 'max-w-md',
    };
@endphp

<div {{ $attributes->class(['relative w-full rounded-2xl border border-brand-ink/10 bg-white shadow-xl', $maxWidthClass]) }} wire:click.stop>
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-7">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 @if($titleId) id="{{ $titleId }}" @endif class="text-lg font-semibold text-brand-ink">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-1 text-sm text-brand-moss">{{ $description }}</p>
                @endif
            </div>

            @if (isset($dismiss))
                {{ $dismiss }}
            @endif
        </div>
    </div>

    <div class="px-6 py-5 sm:px-7">
        {{ $slot }}
    </div>

    @if (isset($footer))
        <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3 sm:px-7">
            {{ $footer }}
        </div>
    @endif
</div>
