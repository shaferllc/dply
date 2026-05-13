@props([
    'active' => false,
    'id' => null,
    'as' => 'button',
    'icon' => null,
])

@php
    $sharedClasses = [
        'shrink-0 snap-start whitespace-nowrap rounded-t-md px-3 py-2.5 text-center text-sm font-medium transition-colors',
        'border-b-2 border-brand-forest text-brand-ink' => $active,
        'border-b-2 border-transparent text-brand-moss hover:bg-brand-sand/30 hover:text-brand-ink' => ! $active,
    ];

    // Pull `wire:click` straight out of the raw attribute array so we can
    // also bind `wire:target` to the same action and scope the loading
    // state. Going through $attributes->get('wire:click') sometimes returns
    // null in component context, so we read getAttributes() directly.
    $rawAttributes = $attributes->getAttributes();
    $wireTarget = $rawAttributes['wire:click'] ?? null;
@endphp

@if ($as === 'a')
    <a
        role="tab"
        @if ($id) id="{{ $id }}" @endif
        aria-selected="{{ $active ? 'true' : 'false' }}"
        data-skip-busy="1"
        {{ $attributes->class($sharedClasses) }}
    >
        @if ($icon)
            <span class="inline-flex items-center gap-2">
                <span class="inline-flex" @if ($wireTarget) wire:loading.remove wire:target="{{ $wireTarget }}" @endif>
                    <x-dynamic-component :component="$icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                </span>
                @if ($wireTarget)
                    <span class="inline-flex" wire:loading wire:target="{{ $wireTarget }}">
                        <x-spinner size="sm" />
                    </span>
                @endif
                <span>{{ $slot }}</span>
            </span>
        @else
            {{ $slot }}
        @endif
    </a>
@else
    <button
        type="button"
        role="tab"
        @if ($id) id="{{ $id }}" @endif
        aria-selected="{{ $active ? 'true' : 'false' }}"
        data-skip-busy="1"
        {{ $attributes->class($sharedClasses) }}
    >
        @if ($icon)
            <span class="inline-flex items-center gap-2">
                <span class="inline-flex" @if ($wireTarget) wire:loading.remove wire:target="{{ $wireTarget }}" @endif>
                    <x-dynamic-component :component="$icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                </span>
                @if ($wireTarget)
                    <span class="inline-flex" wire:loading wire:target="{{ $wireTarget }}">
                        <x-spinner size="sm" />
                    </span>
                @endif
                <span>{{ $slot }}</span>
            </span>
        @else
            {{ $slot }}
        @endif
    </button>
@endif
