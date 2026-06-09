@php
    $pipelineBusyTargets = implode(',', [
        'addDeployPipelineStepFromPalette',
        'reorderDeployPipelineBuildSteps',
        'reorderDeployPipelineReleaseSteps',
        'addDeployPipelineHookFromPalette',
        'confirmAddDuplicatePipelineStep',
    ]);
@endphp

<div
    class="pointer-events-auto absolute inset-0 z-20 items-center justify-center rounded-2xl bg-brand-cream/80 backdrop-blur-[2px]"
    :class="dropBusy ? 'flex' : 'hidden'"
    wire:loading.class.remove="hidden"
    wire:loading.class="flex"
    wire:target="{{ $pipelineBusyTargets }}"
    role="status"
    aria-live="polite"
    :aria-busy="dropBusy ? 'true' : 'false'"
>
    <span class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-md ring-1 ring-brand-ink/10">
        <x-spinner variant="forest" size="sm" />
        <span x-text="dropBusyLabel">{{ __('Updating pipeline…') }}</span>
    </span>
</div>
