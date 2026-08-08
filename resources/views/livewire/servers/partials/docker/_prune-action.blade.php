{{-- Prune button for the Images head. Amber rather than filled: it deletes,
     but it's a housekeeping action, not the tab's primary one. --}}
<button
    type="button"
    wire:click="confirmDockerImagePrune"
    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-amber-200 bg-amber-50 px-2 text-[11px] font-semibold text-amber-900 shadow-sm transition hover:bg-amber-100"
>
    <x-heroicon-m-sparkles class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
    {{ $label }}
</button>
