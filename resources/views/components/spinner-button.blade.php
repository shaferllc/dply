{{--
    Loading-aware button. Wraps the design-system primary/secondary/danger button
    and adds a spinner + busy label, so no surface has to hand-roll the
    wire:loading dance (which is easy to get wrong — see the hygiene "Scan disk"
    button that got stuck on "Scanning…").

    Two busy modes — pick based on how the work runs:

      • target="someAction"  → request-scoped. The spinner shows only while THAT
        Livewire action is in flight. Right for quick synchronous actions.

      • :busy="$flag"        → state-driven. The spinner shows whenever the bound
        component property is true. Right for queued-job + wire:poll flows, where
        the request returns immediately and a poll resolves later — this can NEVER
        get stuck on a dead request because the flag is cleared by the poll/timeout.

    Both can be set together: while :busy is true it wins; otherwise target drives.

    Props:
      variant   primary | secondary | danger   (default secondary)
      size      default | sm | xs               (passed through to the base button)
      target    wire target(s) for request-scoped spinner (comma-separated ok)
      busy      force the busy state from a component property
      disabled  extra disabled condition (ORed with busy)
      icon      heroicon component name for the idle state, e.g. 'heroicon-o-arrow-path'
      label     idle text (falls back to the slot)
      busyLabel text while busy (falls back to label/slot)
--}}
@props([
    'variant' => 'secondary',
    'size' => 'sm',
    'target' => null,
    'busy' => false,
    'disabled' => false,
    'icon' => null,
    'label' => null,
    'busyLabel' => null,
])
@php
    $base = match ($variant) {
        'primary' => 'primary-button',
        'danger' => 'danger-button',
        default => 'secondary-button',
    };
    $busyText = $busyLabel ?? $label;

    // Merge the request-scoped loading attributes here — Blade can't compile a
    // conditional (@if) inside a component opening tag's attribute list, and a
    // bare {{ $forward }} echo isn't forwarded by x-dynamic-component (it lands
    // as a junk `forward` attribute and drops wire:click). The bag must be passed
    // through the :attributes prop instead.
    $forward = $target
        ? $attributes->merge(['wire:loading.attr' => 'disabled', 'wire:target' => $target])
        : $attributes;
@endphp
<x-dynamic-component
    :component="$base"
    :size="$size"
    :disabled="$busy || $disabled"
    :attributes="$forward"
>
    @if ($busy)
        <x-spinner class="h-4 w-4" aria-hidden="true" />
        <span>{{ $busyText ?? $slot }}</span>
    @elseif ($target)
        <span wire:loading.remove wire:target="{{ $target }}" class="inline-flex items-center gap-1.5">
            @if ($icon)
                <x-dynamic-component :component="$icon" class="h-4 w-4" aria-hidden="true" />
            @endif
            {{ $label ?? $slot }}
        </span>
        <span wire:loading wire:target="{{ $target }}" class="inline-flex items-center gap-1.5">
            <x-spinner class="h-4 w-4" aria-hidden="true" />
            {{ $busyText ?? $slot }}
        </span>
    @else
        @if ($icon)
            <x-dynamic-component :component="$icon" class="h-4 w-4" aria-hidden="true" />
        @endif
        {{ $label ?? $slot }}
    @endif
</x-dynamic-component>
