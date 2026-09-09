{{-- "Add a shared Git token" as a dialog, matching Add storage and Connect a
     provider on the org Credentials page. Driven by $addingPatProvider, not an
     event: the panel is re-rendered on every Livewire round trip, and an
     event-only open would snap shut after the first validation error. --}}
@if ($addingPatProvider)
    @php
        $provider = \App\Models\GitProviderToken::providerDescriptor($addingPatProvider);
    @endphp
    <div
        class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-git-token-title"
        x-data
        x-on:keydown.escape.window="$wire.cancelAddPat()"
    >
        <div class="fixed inset-0 bg-brand-ink/30" wire:click="cancelAddPat"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
            <div class="my-auto flex w-full max-w-2xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-code-bracket class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Shared credential') }}</p>
                        <h2 id="add-git-token-title" class="mt-1 text-lg font-semibold text-brand-ink">
                            {{ __('Add a :name token', ['name' => $provider['name']]) }}
                        </h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('Owned by the organization, not by you — sites keep deploying with it after you leave.') }}
                        </p>
                    </div>
                </div>

                <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-6 py-5">
                    @include('livewire.settings.partials.source-control._pat-hint')

                    @include('livewire.settings.partials.source-control._pat-fields')
                </div>

                <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="cancelAddPat">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <button
                        type="button"
                        wire:click="savePat"
                        wire:loading.attr="disabled"
                        wire:target="savePat"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="savePat" class="inline-flex items-center gap-2">
                            <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Validate and save') }}
                        </span>
                        <span wire:loading wire:target="savePat" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Validating…') }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
