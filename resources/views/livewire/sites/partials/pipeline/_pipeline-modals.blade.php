@include('livewire.sites.partials.pipeline._pipeline-step-modal')
@include('livewire.sites.partials.pipeline._pipeline-hook-modal')
@include('livewire.sites.partials.pipeline._pipeline-anchor-modal')
@include('livewire.sites.partials.pipeline._pipeline-share-modal')

@if ($show_apply_starter_modal ?? false)
    <x-modal name="apply-pipeline-starter" :show="true" focusable>
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Apply starter pipeline?') }}</h2>
            <p class="mt-2 text-sm text-brand-moss">
                {{ __('This replaces every step and hook on the target pipeline and updates site rollout settings.') }}
            </p>
            @if (! empty($starter_preview_lines))
                <ul class="mt-4 list-inside list-disc space-y-1 text-sm text-brand-ink">
                    @foreach ($starter_preview_lines as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-5 space-y-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                <label class="flex items-start gap-2 text-sm text-brand-ink">
                    <input type="checkbox" wire:model.live="starter_create_new_pipeline" class="mt-0.5 rounded border-brand-ink/30" />
                    <span>{{ __('Create new pipeline (and use it for deploys)') }}</span>
                </label>
                @if ($starter_create_new_pipeline)
                    <div>
                        <label for="starter_new_pipeline_name" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('New pipeline name') }}</label>
                        <input
                            type="text"
                            id="starter_new_pipeline_name"
                            wire:model="starter_new_pipeline_name"
                            class="w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm"
                        />
                        <x-input-error :messages="$errors->get('starter_new_pipeline_name')" class="mt-1" />
                    </div>
                @endif
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeApplyStarterModal">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="confirmApplyStarterPipeline">{{ __('Apply starter') }}</x-danger-button>
            </div>
        </div>
    </x-modal>
@endif

@if ($show_import_pipeline_modal ?? false)
    <x-modal name="import-pipeline-json" :show="true" focusable>
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Import pipeline?') }}</h2>
            <p class="mt-2 text-sm text-brand-moss">
                {{ __('This replaces every step and hook on the target pipeline.') }}
            </p>
            @if (! empty($import_preview_lines))
                <ul class="mt-4 list-inside list-disc space-y-1 text-sm text-brand-ink">
                    @foreach ($import_preview_lines as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-5 space-y-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                <label class="flex items-start gap-2 text-sm text-brand-ink">
                    <input type="checkbox" wire:model.live="import_apply_rollout" class="mt-0.5 rounded border-brand-ink/30" />
                    <span>{{ __('Also apply rollout settings from file (deploy strategy, health check)') }}</span>
                </label>
                <label class="flex items-start gap-2 text-sm text-brand-ink">
                    <input type="checkbox" wire:model.live="import_create_new_pipeline" class="mt-0.5 rounded border-brand-ink/30" />
                    <span>{{ __('Create new pipeline (and use it for deploys)') }}</span>
                </label>
                @if ($import_create_new_pipeline)
                    <div>
                        <label for="import_new_pipeline_name" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('New pipeline name') }}</label>
                        <input
                            type="text"
                            id="import_new_pipeline_name"
                            wire:model="import_new_pipeline_name"
                            class="w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm"
                        />
                        <x-input-error :messages="$errors->get('import_new_pipeline_name')" class="mt-1" />
                    </div>
                @endif
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeImportPipelineModal">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="confirmImportPipelineJson">{{ __('Import pipeline') }}</x-danger-button>
            </div>
        </div>
    </x-modal>
@endif

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
