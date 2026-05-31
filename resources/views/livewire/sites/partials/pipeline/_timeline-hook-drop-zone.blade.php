@props([
    'anchor',
    'anchorStepId' => null,
    'items' => [],
    'empty' => null,
    'compact' => false,
])

<div
    data-hook-drop-zone="{{ $anchor }}"
    @if (filled($anchorStepId))
        data-hook-anchor-step-id="{{ $anchorStepId }}"
    @endif
    @class([
        'inline-flex flex-wrap items-center gap-2 rounded-xl px-1 py-1 transition-colors',
        'min-h-[2.75rem] min-w-[4rem]' => ! $compact,
        'min-h-[2rem] min-w-[1.75rem]' => $compact,
        'border border-dashed border-amber-300/50 bg-amber-50/30' => count($items) === 0,
        'border border-dashed border-amber-200/40 bg-amber-50/20' => $compact && count($items) === 0,
        'border border-transparent' => count($items) > 0 && ! $compact,
        'border border-transparent bg-transparent' => count($items) > 0 && $compact,
    ])
    title="{{ __('Drop Shell, Webhook, or Notification here') }}"
>
    @foreach ($items as $item)
        @if (($item['type'] ?? '') === 'hook')
            @include('livewire.sites.partials.pipeline._timeline-hook-pill', ['hook' => $item['hook']])
            @include('livewire.sites.partials.pipeline._timeline-flow-connector')
        @endif
    @endforeach
    @if (count($items) === 0 && $empty)
        <span @class(['px-2 text-xs text-brand-moss', 'px-1 text-[10px] font-semibold uppercase tracking-wide text-amber-800/70' => $compact])>{{ $empty }}</span>
    @endif
</div>
