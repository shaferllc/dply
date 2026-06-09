{{--
    Global table/list row loading shell.

    Dims the row and shows a centered "Working…" pill while any action in
    `$wireTarget` runs. Pair with per-button wire:loading labels (Install →
    Installing…) on the row's action controls — see workspace-php.blade.php.
--}}
@props([
    'as' => 'li',
    'wireTarget',
    'wireKey' => null,
    'busyLabel' => null,
])

@php
    $tag = $as;
    $label = $busyLabel ?? __('Working…');
@endphp

<{{ $tag }}
    @if ($wireKey !== null)
        wire:key="{{ $wireKey }}"
    @endif
    {{ $attributes->class(['relative transition']) }}
    wire:loading.class.delay="opacity-60 pointer-events-none"
    wire:target="{{ $wireTarget }}"
>
    <div
        class="pointer-events-none absolute inset-0 hidden items-center justify-center bg-white/40 backdrop-blur-[1px]"
        wire:loading.flex.delay
        wire:target="{{ $wireTarget }}"
        aria-hidden="true"
    >
        <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm ring-1 ring-brand-ink/10">
            <x-spinner variant="forest" size="sm" />
            {{ $label }}
        </span>
    </div>

    {{ $slot }}
</{{ $tag }}>
