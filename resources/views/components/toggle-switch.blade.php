@props([
    /** Current on/off state — drives the initial checked attribute and the label shown. */
    'enabled' => false,
    /** Text shown when on / off. */
    'onLabel' => null,
    'offLabel' => null,
])

@php
    $onLabel ??= __('Enabled');
    $offLabel ??= __('Disabled');
@endphp

{{-- iOS-style switch built on a real checkbox: the input is the `peer`, so the
     track, thumb, and label all react instantly via CSS — no round-trip lag.
     Forward `wire:model.live="…"` (and optionally `disabled`) on the element. --}}
<label class="relative inline-flex shrink-0 cursor-pointer items-center gap-2.5">
    <input type="checkbox" @checked($enabled) {{ $attributes }} class="peer sr-only">

    {{-- Track --}}
    <span aria-hidden="true"
          class="h-6 w-11 rounded-full bg-brand-ink/20 transition-colors duration-200 peer-checked:bg-brand-forest peer-focus-visible:ring-2 peer-focus-visible:ring-brand-forest/40 peer-focus-visible:ring-offset-2 peer-disabled:opacity-50"></span>

    {{-- Thumb --}}
    <span aria-hidden="true"
          class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200 peer-checked:translate-x-5"></span>

    <span class="text-sm font-semibold text-brand-moss peer-checked:hidden peer-disabled:opacity-50">{{ $offLabel }}</span>
    <span class="hidden text-sm font-semibold text-brand-forest peer-checked:inline peer-disabled:opacity-50">{{ $onLabel }}</span>
</label>
