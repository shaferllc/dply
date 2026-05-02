<section id="settings-group-export" class="space-y-4" aria-labelledby="settings-group-export-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-export-title',
        'kicker' => __('Export'),
        'title' => __('Manifest'),
        'description' => __('Download a JSON summary for runbooks or handoffs. Account-to-account server transfer is not available yet.'),
    ])

    <div class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <button
            type="button"
            wire:click="downloadServerManifest"
            class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
        >
            {{ __('Download manifest (JSON)') }}
        </button>
    </div>
</section>
