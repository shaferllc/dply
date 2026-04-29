@props([
    'padding' => 'default',
    'tone' => 'default',
])

@php
    $cardClasses = match ($tone) {
        'subtle' => 'rounded-2xl border border-brand-ink/10 bg-brand-sand/15 shadow-sm overflow-hidden',
        default => 'dply-card overflow-hidden',
    };

    $bodyPadding = match ($padding) {
        'none' => '',
        'sm' => 'px-5 py-4',
        'lg' => 'px-8 py-6',
        default => 'px-6 py-4',
    };
@endphp

<section {{ $attributes->class([$cardClasses]) }}>
    @if (isset($header))
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4">
            {{ $header }}
        </div>
    @endif

    <div @class([$bodyPadding])>
        {{ $slot }}
    </div>

    @if (isset($footer))
        <div class="border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-4">
            {{ $footer }}
        </div>
    @endif
</section>
