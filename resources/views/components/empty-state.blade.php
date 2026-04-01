@props([
    'title',
    'description' => null,
    'dashed' => true,
])

<div {{ $attributes->class([
    'rounded-2xl px-5 py-6',
    'border border-dashed border-brand-ink/15 bg-brand-sand/10' => $dashed,
    'border border-brand-ink/10 bg-white shadow-sm' => ! $dashed,
]) }}>
    <p class="text-sm font-medium text-brand-ink">{{ $title }}</p>

    @if ($description)
        <p class="mt-2 text-sm leading-6 text-brand-moss">{{ $description }}</p>
    @endif

    @if (isset($actions))
        <div class="mt-4 flex flex-wrap gap-3">
            {{ $actions }}
        </div>
    @endif
</div>
