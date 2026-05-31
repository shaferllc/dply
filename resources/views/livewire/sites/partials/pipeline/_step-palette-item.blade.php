@php
    $entry = $entry ?? [];
    $phase = $entry['phase'] ?? 'build';
    $type = $entry['type'] ?? 'custom';
    $customCommand = $entry['custom_command'] ?? '';
    $isBuild = $phase === 'build';
    $border = $isBuild ? 'border-sky-300/70 ring-sky-200/40' : 'border-emerald-300/70 ring-emerald-200/40';
    $handle = $isBuild ? 'border-sky-200/80 bg-sky-50 text-sky-800/70' : 'border-emerald-200/80 bg-emerald-50 text-emerald-800/70';
    $labelClass = $isBuild ? 'text-sky-900 hover:bg-sky-50' : 'text-emerald-900 hover:bg-emerald-50';
@endphp

<div
    data-palette-type="{{ $type }}"
    data-palette-phase="{{ $phase }}"
    @if (filled($customCommand))
        data-palette-command="{{ $customCommand }}"
    @endif
    class="inline-flex max-w-full select-none items-stretch rounded-full border bg-white shadow-sm ring-1 {{ $border }}"
    title="{{ __('Drag by the handle, or click to add to the :phase palette zone.', ['phase' => $phase]) }}"
>
    <span
        data-palette-drag-handle
        class="dply-palette-drag-handle inline-flex h-9 w-9 shrink-0 cursor-grab items-center justify-center rounded-l-full border-r {{ $handle }} active:cursor-grabbing"
        aria-hidden="true"
    >
        <x-heroicon-m-bars-3 class="h-4 w-4" />
    </span>
    <button
        type="button"
        wire:click="addDeployPipelineStepFromPalette(@js($type), null, @js($phase), @js(filled($customCommand) ? $customCommand : null))"
        wire:loading.attr="disabled"
        wire:target="addDeployPipelineStepFromPalette"
        data-pipeline-no-drag
        class="inline-flex min-h-9 max-w-[14rem] items-center gap-1.5 rounded-r-full px-3 py-2 text-xs font-semibold {{ $labelClass }} disabled:opacity-60"
    >
        <x-dynamic-component :component="$entry['icon'] ?? 'heroicon-o-plus'" class="h-4 w-4 shrink-0" wire:loading.remove wire:target="addDeployPipelineStepFromPalette" />
        <x-spinner variant="forest" size="sm" class="shrink-0" wire:loading wire:target="addDeployPipelineStepFromPalette" />
        <span class="truncate" wire:loading.remove wire:target="addDeployPipelineStepFromPalette">{{ __($entry['label'] ?? $type) }}</span>
        <span class="truncate" wire:loading wire:target="addDeployPipelineStepFromPalette">{{ __('Adding…') }}</span>
    </button>
</div>
