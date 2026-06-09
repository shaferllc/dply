<section id="settings-group-reference" class="space-y-6" aria-labelledby="settings-group-reference-title">
    <div id="settings-notes" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Notes') }}</p>
                <h3 id="settings-group-reference-title" class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Internal notes') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Free-form context: runbooks, customer IDs, things the next engineer should know.') }}</p>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
        <form wire:submit="saveServerNotes">
            <textarea
                wire:model="settingsNotes"
                rows="6"
                class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                @disabled(! $this->canEditServerSettings)
            ></textarea>
            <x-input-error :messages="$errors->get('settingsNotes')" class="mt-2" />
            @if ($this->canEditServerSettings)
                <x-primary-button type="submit" class="mt-4" wire:loading.attr="disabled">{{ __('Save notes') }}</x-primary-button>
            @endif
        </form>
        </div>
    </div>
</section>
