@props([
    'anchor',
    'anchorStepId' => null,
    'items' => [],
    'empty' => null,
    'compact' => false,
])

@if (count($items) > 0 || filled($empty))
<div
    data-hook-drop-zone="{{ $anchor }}"
    @if (filled($anchorStepId))
        data-hook-anchor-step-id="{{ $anchorStepId }}"
    @endif
    {{-- Click the slot to open the hook chooser (Shell / Webhook / Notification)
         pre-pointed at this anchor; drag-and-drop from the palette still works.
         Pill buttons inside stop propagation so editing/removing won't re-open it. --}}
    role="button"
    tabindex="0"
    wire:click="openAddPipelineHookForm(null, '{{ $anchor }}', false, {{ filled($anchorStepId) ? "'".$anchorStepId."'" : 'null' }})"
    x-on:keydown.enter.prevent="$el.click()"
    x-on:keydown.space.prevent="$el.click()"
    @class([
        'group/hookzone inline-flex shrink-0 cursor-pointer flex-nowrap items-center gap-2 rounded-xl px-1 py-1 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400/60',
        'min-h-[2.75rem] min-w-[4rem]' => ! $compact,
        'min-h-[2rem] min-w-[1.75rem]' => $compact,
        'border border-dashed border-amber-300/50 bg-amber-50/30 hover:border-amber-400 hover:bg-amber-50/70' => count($items) === 0,
        'border border-dashed border-amber-200/40 bg-amber-50/20' => $compact && count($items) === 0,
        'border border-transparent hover:border-amber-200' => count($items) > 0 && ! $compact,
        'border border-transparent bg-transparent' => count($items) > 0 && $compact,
    ])
    title="{{ __('Click to add — or drop a Shell, Webhook, or Notification here') }}"
>
    @foreach ($items as $index => $item)
        @if (($item['type'] ?? '') === 'hook')
            @include('livewire.sites.partials.pipeline._timeline-hook-pill', ['hook' => $item['hook']])
            @if ($index < count($items) - 1)
                @include('livewire.sites.partials.pipeline._timeline-flow-connector')
            @endif
        @endif
    @endforeach
    @if (count($items) === 0 && $empty)
        <span @class(['inline-flex items-center gap-1 px-2 text-xs text-brand-moss', 'px-1 text-[10px] font-semibold uppercase tracking-wide text-amber-800/70' => $compact])>
            <x-heroicon-m-plus class="h-3.5 w-3.5 opacity-60 transition-opacity group-hover/hookzone:opacity-100" aria-hidden="true" />
            {{ $empty }}
        </span>
    @endif
</div>
@endif
