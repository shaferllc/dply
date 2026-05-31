@include('livewire.sites.partials.pipeline._pipeline-step-modal')
@include('livewire.sites.partials.pipeline._pipeline-hook-modal')
@include('livewire.sites.partials.pipeline._pipeline-anchor-modal')

@if ($show_apply_template_modal)
    <x-modal name="apply-pipeline-template" :show="true" focusable>
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Replace pipeline steps?') }}</h2>
            <p class="mt-2 text-sm text-brand-moss">
                {{ __('Applying a template removes every step on this pipeline and replaces them with the template recipe. This cannot be undone.') }}
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeApplyTemplateModal">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="confirmApplyDeployPipelineTemplate">{{ __('Apply template') }}</x-danger-button>
            </div>
        </div>
    </x-modal>
@endif

@if ($show_duplicate_pipeline_step_modal ?? false)
    <x-modal name="duplicate-pipeline-step" :show="true" focusable>
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">
                {{ ($pending_duplicate_step_source ?? '') === 'edit' ? __('Save duplicate step?') : __('Add duplicate step?') }}
            </h2>
            <p class="mt-2 text-sm text-brand-moss">
                @if ($pending_duplicate_existing_label ?? null)
                    {{ __('This pipeline already includes :label. Saving will run the same command twice on every deploy.', ['label' => $pending_duplicate_existing_label]) }}
                @else
                    {{ __('This pipeline already includes a step with the same command. Saving will run that command twice on every deploy.') }}
                @endif
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeDuplicatePipelineStepModal">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="confirmAddDuplicatePipelineStep">
                    {{ ($pending_duplicate_step_source ?? '') === 'edit' ? __('Save anyway') : __('Add anyway') }}
                </x-primary-button>
            </div>
        </div>
    </x-modal>
@endif

@if ($pending_delete_pipeline_id)
    <x-modal name="delete-deploy-pipeline" :show="true" focusable>
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Delete this pipeline?') }}</h2>
            <p class="mt-2 text-sm text-brand-moss">
                {{ __('All steps on this pipeline are removed. If it was used for deploys, another pipeline becomes active.') }}
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeDeletePipelineModal">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="confirmDeleteDeployPipeline">{{ __('Delete pipeline') }}</x-danger-button>
            </div>
        </div>
    </x-modal>
@endif
