@props([
    'active' => false,
    'id' => null,
])

<button
    type="button"
    role="tab"
    @if ($id) id="{{ $id }}" @endif
    aria-selected="{{ $active ? 'true' : 'false' }}"
    {{ $attributes->class([
        'shrink-0 snap-start whitespace-nowrap rounded-t-md px-3 py-2.5 text-center text-sm font-medium transition-colors',
        'border-b-2 border-brand-forest text-brand-ink' => $active,
        'border-b-2 border-transparent text-brand-moss hover:bg-brand-sand/30 hover:text-brand-ink' => ! $active,
    ]) }}
>
    {{ $slot }}
</button>
