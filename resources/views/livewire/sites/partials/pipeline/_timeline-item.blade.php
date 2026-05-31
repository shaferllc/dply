@php
    $item = $item ?? [];
@endphp
@if ($item['type'] === 'anchor')
    <div
        wire:key="timeline-{{ $item['key'] }}"
        data-timeline-fixed
        class="inline-flex max-w-full items-center gap-1 rounded-full border border-brand-ink/10 bg-brand-cream/80 py-0.5 pl-2 pr-1"
    >
        @if ($item['key'] === 'clone')
            <x-heroicon-m-arrow-down-on-square-stack class="h-3.5 w-3.5 shrink-0 text-brand-moss" />
            <span class="text-xs font-semibold text-brand-moss">{{ __('Clone') }}</span>
        @else
            <x-heroicon-m-check-badge class="h-3.5 w-3.5 shrink-0 text-brand-moss" />
            <span class="text-xs font-semibold text-brand-moss">{{ __('Activate') }}</span>
        @endif
        <button
            type="button"
            wire:click="openEditPipelineAnchor('{{ $item['key'] }}')"
            class="inline-flex h-6 w-6 items-center justify-center rounded-full text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink"
            title="{{ $item['key'] === 'clone' ? __('Edit clone script') : __('Edit activate script') }}"
            data-pipeline-no-drag
        >
            <x-heroicon-m-pencil-square class="h-3.5 w-3.5" />
        </button>
    </div>
    @include('livewire.sites.partials.pipeline._timeline-flow-connector')
@elseif ($item['type'] === 'hook')
    @include('livewire.sites.partials.pipeline._timeline-hook-pill', ['hook' => $item['hook']])
@endif
