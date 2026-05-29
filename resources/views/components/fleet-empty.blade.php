@props([
    /** Optional bold lead line. */
    'title' => null,
])

{{-- Shared empty / zero-state for fleet pages. --}}
<div {{ $attributes->class(['rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/20 p-8 text-center text-sm text-brand-moss']) }}>
    @if ($title)
        <p class="font-medium text-brand-ink">{{ $title }}</p>
    @endif
    {{ $slot }}
</div>
