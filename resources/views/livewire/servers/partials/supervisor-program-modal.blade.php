@props([
    'modalName' => 'daemon-program-modal',
    'titleNew' => __('New Supervisor program'),
    'titleEdit' => __('Edit Supervisor program'),
    'submitNew' => __('Add program'),
    'submitEdit' => __('Update program'),
])

<x-modal
    :name="$modalName"
    :show="false"
    maxWidth="3xl"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,920px)] flex-col"
    focusable
>
    <form wire:submit="saveSupervisorProgram" class="flex min-h-0 flex-1 flex-col">
        <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $editing_program_id ? __('Edit') : __('New') }}</p>
                <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $editing_program_id ? $titleEdit : $titleNew }}</h2>
                <p class="mt-1 text-sm leading-6 text-brand-moss">
                    {{ __('Each program becomes a conf file on the server. After saving here, use “Sync” to write files and reload Supervisor.') }}
                </p>
            </div>
        </div>

        <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-6">
            @include('livewire.servers.partials.supervisor-program-form-fields')
        </div>

        <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
            <x-secondary-button type="button" wire:click="closeCreateSupervisorProgramModal">
                {{ __('Cancel') }}
            </x-secondary-button>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="saveSupervisorProgram"
                class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="saveSupervisorProgram" class="inline-flex items-center gap-2">
                    @if ($editing_program_id)
                        <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ $submitEdit }}
                    @else
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ $submitNew }}
                    @endif
                </span>
                <span wire:loading wire:target="saveSupervisorProgram" class="inline-flex items-center gap-2 whitespace-nowrap">
                    <x-spinner variant="cream" size="sm" />
                    {{ __('Saving…') }}
                </span>
            </button>
        </div>
    </form>
</x-modal>
