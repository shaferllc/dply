@php
    $hook = $hook ?? null;
@endphp
@if ($hook)
    <div
        wire:key="timeline-hook-{{ $hook->id }}"
        data-timeline-fixed
        x-on:click.stop
        class="inline-flex max-w-full items-center gap-1 rounded-full border py-0.5 pl-2 pr-1 {{ $hook->pillToneClass() }}"
    >
        <x-dynamic-component :component="$hook->pillIcon()" class="h-3.5 w-3.5 shrink-0" />
        <span class="truncate text-xs font-semibold max-w-[10rem]" title="{{ $hook->pillLabel() }}">{{ $hook->pillLabel() }}</span>
        <button
            type="button"
            wire:click.stop="openEditPipelineHook('{{ $hook->id }}')"
            class="inline-flex h-6 w-6 items-center justify-center rounded-full hover:bg-black/5"
            title="{{ __('Edit hook') }}"
            data-pipeline-no-drag
        >
            <x-heroicon-m-pencil-square class="h-3.5 w-3.5" />
        </button>
        <button
            type="button"
            wire:click.stop="deleteDeployHook('{{ $hook->id }}')"
            class="inline-flex h-6 w-6 items-center justify-center rounded-full hover:bg-black/5"
            title="{{ __('Remove hook') }}"
            data-pipeline-no-drag
        >
            <x-heroicon-m-x-mark class="h-3.5 w-3.5" />
        </button>
    </div>
@endif
