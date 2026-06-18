<section id="settings-group-export" class="space-y-4" aria-labelledby="settings-group-export-title">
    <div class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-arrow-down-tray class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Export') }}</p>
                <h3 id="settings-group-export-title" class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Manifest') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Download a JSON summary for runbooks or handoffs — server details, sites, and your notes. Secrets are never included. Account-to-account server transfer is not available yet.') }}</p>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
            <button
                type="button"
                wire:click="downloadServerManifest"
                class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Download manifest (JSON)') }}
            </button>
        </div>
    </div>
</section>
