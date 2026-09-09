{{-- Inline add-a-token form for $provider, used on /profile/source-control
     where it opens inside the provider's own card. The org Credentials page
     uses _pat-modal instead — same hint, same fields. --}}
<div class="space-y-2.5 border-t border-brand-ink/10 bg-brand-sage/5 px-3 py-2.5 sm:px-4">
    @include('livewire.settings.partials.source-control._pat-hint')

    @include('livewire.settings.partials.source-control._pat-fields')

    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-2">
        <button type="button" wire:click="cancelAddPat" class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
        <button type="button" wire:click="savePat" wire:loading.attr="disabled" wire:target="savePat" class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-60">
            <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ __('Validate and save') }}
        </button>
    </div>
</div>
