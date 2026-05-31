@php
    $isEditing = ($editing_deploy_hook_id ?? null) !== null;
    $hookKindLabel = ($deployHookKinds ?? [])[$new_hook_kind] ?? $new_hook_kind;
@endphp

<x-modal
    name="pipeline-hook-form"
    :show="false"
    maxWidth="2xl"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,720px)] flex-col"
    focusable
>
    <form wire:submit="saveDeployPipelineHook" class="flex min-h-0 flex-1 flex-col">
        <div class="flex shrink-0 items-start gap-3 border-b border-amber-200/60 bg-amber-50/50 px-6 py-5">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-950 ring-1 ring-amber-200/80">
                <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-800/80">{{ $isEditing ? __('Edit') : __('New') }}</p>
                <h2 class="mt-1 text-lg font-semibold text-brand-ink">
                    {{ $isEditing ? __('Edit pipeline hook') : __('Configure hook') }}
                </h2>
                <p class="mt-1 text-sm leading-6 text-brand-moss">
                    @if ($isEditing)
                        {{ __('Update the fields for this :type hook.', ['type' => $hookKindLabel]) }}
                    @elseif ($hook_form_anchor_locked ?? false)
                        {{ __('Placement comes from the timeline zone you dropped onto. Fill in the fields for :type.', ['type' => $hookKindLabel]) }}
                    @else
                        {{ __('Choose when this hook runs, then fill in the fields for its type.') }}
                    @endif
                </p>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-6">
            @include('livewire.sites.partials.pipeline._pipeline-hook-form-fields')
        </div>

        <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
            <x-secondary-button type="button" wire:click="closeAddPipelineHookForm">
                {{ __('Cancel') }}
            </x-secondary-button>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="saveDeployPipelineHook"
                class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="saveDeployPipelineHook" class="inline-flex items-center gap-2">
                    @if ($isEditing)
                        <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Save hook') }}
                    @else
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add hook') }}
                    @endif
                </span>
                <span wire:loading wire:target="saveDeployPipelineHook" class="inline-flex items-center gap-2 whitespace-nowrap">
                    <x-spinner variant="cream" size="sm" />
                    {{ $isEditing ? __('Saving…') : __('Adding…') }}
                </span>
            </button>
        </div>
    </form>
</x-modal>
