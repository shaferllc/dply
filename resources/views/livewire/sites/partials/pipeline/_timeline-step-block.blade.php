@php
    $block = $block ?? [];
    $step = $block['step'];
    $showAfterStepHookZone = $showAfterStepHookZone ?? true;
    $afterStepHookItems = collect($block['hooks'] ?? [])->map(fn ($hook) => [
        'type' => 'hook',
        'key' => 'hook-'.$hook->id,
        'hook' => $hook,
    ])->values()->all();
@endphp
<div
    wire:key="timeline-step-{{ $step->id }}"
    data-pipeline-step-id="{{ $step->id }}"
    class="inline-flex shrink-0 max-w-full cursor-grab items-center gap-2 active:cursor-grabbing"
>
    <div class="inline-flex max-w-full items-center gap-1 rounded-full border border-brand-ink/15 bg-white py-0.5 pl-1 pr-1.5 shadow-sm">
        <span data-pipeline-drag-handle class="inline-flex h-7 w-7 items-center justify-center rounded-full text-brand-mist hover:bg-brand-sand/60">
            <x-heroicon-m-bars-3 class="h-4 w-4" />
        </span>
        <span class="truncate text-xs font-semibold text-brand-ink max-w-[10rem]" title="{{ $step->pillLabel() }}">{{ $step->pillLabel() }}</span>
        <span class="rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase {{ $step->phaseBadgeClass() }}">{{ $step->phase ?? 'build' }}</span>
        <button type="button" wire:click="openEditPipelineStep('{{ $step->id }}')" class="inline-flex h-6 w-6 items-center justify-center rounded-full text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink" title="{{ __('Edit step') }}" data-pipeline-no-drag>
            <x-heroicon-m-pencil-square class="h-3.5 w-3.5" />
        </button>
        <button type="button" wire:click="deleteDeployPipelineStep('{{ $step->id }}')" class="inline-flex h-6 w-6 items-center justify-center rounded-full text-brand-mist hover:bg-red-50 hover:text-red-700" data-pipeline-no-drag>
            <x-heroicon-m-x-mark class="h-3.5 w-3.5" />
        </button>
    </div>
    @if ($showAfterStepHookZone)
        @include('livewire.sites.partials.pipeline._timeline-hook-drop-zone', [
            'anchor' => 'after_step',
            'anchorStepId' => $step->id,
            'items' => $afterStepHookItems,
            'empty' => $afterStepHookItems === [] ? __('Hook') : null,
            'compact' => true,
        ])
    @endif
</div>
