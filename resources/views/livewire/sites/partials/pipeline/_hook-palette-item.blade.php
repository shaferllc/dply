@php
    $entry = $entry ?? [];
    $kind = $entry['kind'] ?? 'shell';
    $label = $entry['label'] ?? $kind;
    $icon = $entry['icon'] ?? 'heroicon-o-bolt';
@endphp

<div
    data-palette-hook-kind="{{ $kind }}"
    class="inline-flex max-w-full select-none items-stretch rounded-full border border-amber-300/70 bg-amber-50 shadow-sm ring-1 ring-amber-200/40"
    title="{{ __('Drag onto a dashed zone in the timeline — the zone sets when it runs. Click to add manually.') }}"
>
    <span
        data-palette-drag-handle
        class="dply-palette-drag-handle inline-flex h-9 w-9 shrink-0 cursor-grab items-center justify-center rounded-l-full border-r border-amber-200/80 bg-amber-100/80 text-amber-900/70 active:cursor-grabbing"
        aria-label="{{ __('Drag :kind hook', ['kind' => __($label)]) }}"
    >
        <x-heroicon-m-bars-3 class="h-4 w-4" />
    </span>
    <button
        type="button"
        wire:click="openAddPipelineHookForm('{{ $kind }}')"
        data-pipeline-no-drag
        class="inline-flex min-h-9 items-center gap-1.5 rounded-r-full px-3 py-2 text-left text-xs font-semibold text-amber-950 hover:bg-amber-100/60"
    >
        <x-dynamic-component :component="$icon" class="h-4 w-4 shrink-0" />
        {{ __($label) }}
    </button>
</div>
