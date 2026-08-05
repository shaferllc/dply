<section id="settings-group-export" aria-labelledby="settings-group-export-title">
    <div class="{{ $card }} scroll-mt-24">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-arrow-down-tray"
            :title="__('Manifest')"
            :note="__('Download a JSON summary for runbooks or handoffs — server details, sites, and your notes. Secrets are never included. Account-to-account server transfer is not available yet.')"
            title-id="settings-group-export-title"
            class="border-b border-brand-ink/10"
        />
        <div class="px-5 py-4 sm:px-6">
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
