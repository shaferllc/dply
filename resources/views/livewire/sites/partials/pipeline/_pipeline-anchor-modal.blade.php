@php
    $isClone = $editing_pipeline_anchor === 'clone';
    $title = $isClone ? __('Clone script') : __('Activate script');
@endphp

<x-modal
    name="pipeline-anchor-form"
    :show="false"
    maxWidth="2xl"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,720px)] flex-col"
    focusable
>
    <form wire:submit="savePipelineAnchorFromModal" class="flex min-h-0 flex-1 flex-col">
        <div class="flex shrink-0 items-start gap-3 border-b border-brand-sage/30 bg-brand-cream/40 px-6 py-5">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-code-bracket class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Edit') }}</p>
                <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $title }}</h2>
                <p class="mt-1 text-sm leading-6 text-brand-moss">
                    {{ $isClone
                        ? __('Runs after before-clone hooks. Leave empty to use the built-in Git clone or fetch for this site’s deploy strategy.')
                        : __('Runs after before-activate hooks. Leave empty to use the built-in activate step (symlink for atomic deploys).') }}
                </p>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-6">
            @include('livewire.sites.partials.pipeline._pipeline-anchor-form-fields')
        </div>

        <p class="shrink-0 border-t border-brand-ink/10 bg-brand-cream/40 px-6 py-2 text-xs text-brand-moss">
            {{ __('You can also use Save pipeline on the bar below this dialog.') }}
        </p>
        <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
            <button
                type="button"
                wire:click="resetPipelineAnchorScriptsToDefaults"
                class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-moss hover:bg-brand-sand/40"
            >
                {{ __('Use built-in default') }}
            </button>
            <div class="flex flex-wrap justify-end gap-3">
                <x-secondary-button type="button" wire:click="closePipelineAnchorForm">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="savePipelineAnchorFromModal"
                    class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="savePipelineAnchorFromModal" class="inline-flex items-center gap-2">
                        <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Save script') }}
                    </span>
                    <span wire:loading wire:target="savePipelineAnchorFromModal" class="inline-flex items-center gap-2 whitespace-nowrap">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Saving…') }}
                    </span>
                </button>
            </div>
        </div>
    </form>
</x-modal>
