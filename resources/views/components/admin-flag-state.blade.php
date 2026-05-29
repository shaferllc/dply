@props([
    'active' => false,
])

<span @class([
    'inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold',
    'bg-brand-sage/15 text-brand-moss' => $active,
    'bg-brand-ink/10 text-brand-mist' => ! $active,
])>{{ $active ? __('On') : __('Off') }}</span>
