@props([
    /** Optional bold lead line. */
    'title' => null,
])

{{-- Flush empty state inside fleet-shell body — comfortable spacing, no nested card. --}}
<div {{ $attributes->class(['px-5 py-10 text-center text-sm text-brand-moss sm:px-6']) }}>
    @if ($title)
        <p class="font-medium text-brand-ink">{{ $title }}</p>
    @endif
    {{ $slot }}
</div>
