<section id="settings-group-export" class="space-y-4" aria-labelledby="settings-group-export-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-export-title',
        'kicker' => __('Export'),
        'title' => __('Manifest & shortcuts'),
        'description' => __('Download a JSON summary for runbooks or handoffs. Account-to-account server transfer is not available yet.'),
    ])

    <div class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <div class="flex flex-wrap items-center gap-3">
            <button
                type="button"
                wire:click="downloadServerManifest"
                class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Download manifest (JSON)') }}
            </button>
            <button
                type="button"
                class="rounded-lg border border-dashed border-brand-ink/20 px-4 py-2.5 text-sm font-medium text-brand-moss hover:border-brand-ink/30 hover:text-brand-ink"
                x-on:click="settingsHelpOpen = ! settingsHelpOpen"
            >
                {{ __('Keyboard shortcuts') }} <span class="font-mono text-xs">?</span>
            </button>
        </div>
        <div
            x-show="settingsHelpOpen"
            x-cloak
            x-transition
            class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 text-sm text-brand-ink"
        >
            <p class="font-medium">{{ __('This page') }}</p>
            <ul class="mt-2 list-inside list-disc space-y-1 text-brand-moss">
                <li>{{ __('Press') }} <kbd class="rounded border border-brand-ink/20 bg-white px-1.5 py-0.5 font-mono text-xs text-brand-ink">?</kbd> {{ __('when not typing in a field to toggle this panel.') }}</li>
                <li>{{ __('Switch categories with the tabs above.') }}</li>
            </ul>
        </div>
    </div>
</section>
