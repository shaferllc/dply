    {{-- Seed / import .env — surfaced before the first deploy (no .env yet,
         never deployed) and available on demand thereafter. Workers in the same
         pool import VERBATIM (same app → shared APP_KEY + backend); other sites
         import SANITIZED (secrets blanked, APP_KEY regenerated). --}}
    @if ($this->needsFirstEnv())
        <div class="rounded-xl border border-brand-forest/25 bg-brand-sage/10 px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Set up your .env before the first deploy') }}</h3>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Import from a worker or another site, paste your own, or add keys one at a time.') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="$set('env_import_key', null)" x-on:click="$dispatch('open-modal', 'env-import-modal')" class="rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest">{{ __('Import a .env') }}</button>
                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'paste-env-modal')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Paste / add') }}</button>
                </div>
            </div>
        </div>
    @endif
