@props([
    'active' => false,
    'id' => null,
    'as' => 'button',
    'icon' => null,
    'variant' => 'default',
    /** When set, tab highlight + click use parent Alpine `subtab` (@entangle engine_subtab). */
    'subtabKey' => null,
    'wireClick' => null,
])

@php
    $sharedClasses = [
        'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold transition',
        'bg-brand-ink text-brand-cream shadow-sm' => $active && $variant !== 'danger' && $subtabKey === null,
        'bg-red-700 text-white shadow-sm' => $active && $variant === 'danger' && $subtabKey === null,
        'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! $active && $variant !== 'danger' && $subtabKey === null,
        'text-red-800/90 hover:bg-red-50 hover:text-red-950' => ! $active && $variant === 'danger' && $subtabKey === null,
    ];

    $optimisticActiveClass = 'bg-brand-ink text-brand-cream shadow-sm';
    $optimisticInactiveClass = $variant === 'danger'
        ? 'text-red-800/90 hover:bg-red-50 hover:text-red-950'
        : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink';
    $optimisticDangerActiveClass = 'bg-red-700 text-white shadow-sm';

    // Pull `wire:click` straight out of the raw attribute array so we can
    // also bind `wire:target` to the same action and scope the loading
    // state. Going through $attributes->get('wire:click') sometimes returns
    // null in component context, so we read getAttributes() directly.
    $rawAttributes = $attributes->getAttributes();
    $wireTarget = $subtabKey === null ? ($wireClick ?? ($rawAttributes['wire:click'] ?? null)) : null;
    $wireClickAction = $subtabKey === null ? ($wireClick ?? ($rawAttributes['wire:click'] ?? null)) : null;
    $attributesWithoutWireClick = $attributes->except('wire:click');
@endphp

@if ($as === 'a')
    <a
        role="tab"
        @if ($id) id="{{ $id }}" @endif
        @if ($subtabKey === null)
            aria-selected="{{ $active ? 'true' : 'false' }}"
        @else
            x-bind:aria-selected="subtab === @js($subtabKey) ? 'true' : 'false'"
            x-on:click="subtab = @js($subtabKey)"
        @endif
        @if ($wireClickAction)
            wire:click="{{ $wireClickAction }}"
        @endif
        data-skip-busy="1"
        @if ($subtabKey === null)
            {{ $attributes->class($sharedClasses) }}
        @else
            {{ $attributesWithoutWireClick->class('inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold transition') }}
            x-bind:class="subtab === @js($subtabKey)
                ? (@js($variant === 'danger') ? @js($optimisticDangerActiveClass) : @js($optimisticActiveClass))
                : @js($optimisticInactiveClass)"
        @endif
    >
        @if ($icon)
            <span class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center" @if ($wireTarget) wire:loading.remove wire:target="{{ $wireTarget }}" @endif>
                <x-dynamic-component :component="$icon" class="h-3.5 w-3.5" aria-hidden="true" />
            </span>
            @if ($wireTarget)
                <span class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center" wire:loading wire:target="{{ $wireTarget }}">
                    <x-spinner class="h-3.5 w-3.5" />
                </span>
            @endif
        @endif
        <span>{{ $slot }}</span>
    </a>
@else
    <button
        type="button"
        role="tab"
        @if ($id) id="{{ $id }}" @endif
        @if ($subtabKey === null)
            aria-selected="{{ $active ? 'true' : 'false' }}"
        @else
            x-bind:aria-selected="subtab === @js($subtabKey) ? 'true' : 'false'"
            x-on:click="subtab = @js($subtabKey)"
        @endif
        @if ($wireClickAction)
            wire:click="{{ $wireClickAction }}"
        @endif
        data-skip-busy="1"
        @if ($subtabKey === null)
            {{ $attributes->class($sharedClasses) }}
        @else
            {{ $attributesWithoutWireClick->class('inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold transition') }}
            x-bind:class="subtab === @js($subtabKey)
                ? (@js($variant === 'danger') ? @js($optimisticDangerActiveClass) : @js($optimisticActiveClass))
                : @js($optimisticInactiveClass)"
        @endif
    >
        @if ($icon)
            <span class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center" @if ($wireTarget) wire:loading.remove wire:target="{{ $wireTarget }}" @endif>
                <x-dynamic-component :component="$icon" class="h-3.5 w-3.5" aria-hidden="true" />
            </span>
            @if ($wireTarget)
                <span class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center" wire:loading wire:target="{{ $wireTarget }}">
                    <x-spinner class="h-3.5 w-3.5" />
                </span>
            @endif
        @endif
        <span>{{ $slot }}</span>
    </button>
@endif
