@php
    $isEditing = ($editing_deploy_step_id ?? null) !== null;
@endphp

<x-modal
    name="pipeline-step-form"
    :show="false"
    maxWidth="2xl"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,720px)] flex-col"
    focusable
>
    <form wire:submit="saveDeployPipelineStep" class="flex min-h-0 flex-1 flex-col">
        <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-queue-list class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $isEditing ? __('Edit') : __('New') }}</p>
                <h2 class="mt-1 text-lg font-semibold text-brand-ink">
                    {{ $isEditing ? __('Edit pipeline step') : __('New pipeline step') }}
                </h2>
                @unless ($isEditing)
                    <p class="mt-1 text-sm leading-6 text-brand-moss">
                        {{ __('Choose a preset or custom shell command. Custom steps can run any command in the release directory.') }}
                    </p>
                @endunless
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-6">
            @include('livewire.sites.partials.pipeline._pipeline-step-form-fields')
        </div>

        <p class="shrink-0 border-t border-brand-ink/10 bg-brand-cream/40 px-6 py-2 text-xs text-brand-moss">
            {{ __('You can also use Save pipeline on the bar below this dialog.') }}
        </p>
        <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
            <x-secondary-button type="button" wire:click="closePipelineStepForm">
                {{ __('Cancel') }}
            </x-secondary-button>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="saveDeployPipelineStep"
                class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="saveDeployPipelineStep" class="inline-flex items-center gap-2">
                    @if ($isEditing)
                        <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Save step') }}
                    @else
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add step') }}
                    @endif
                </span>
                <span wire:loading wire:target="saveDeployPipelineStep" class="inline-flex items-center gap-2 whitespace-nowrap">
                    <x-spinner variant="cream" size="sm" />
                    {{ __('Saving…') }}
                </span>
            </button>
        </div>
    </form>
</x-modal>
