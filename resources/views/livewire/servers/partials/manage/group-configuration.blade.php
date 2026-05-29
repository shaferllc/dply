<section class="space-y-6" aria-labelledby="manage-configuration-title">
    <div class="{{ $card }} p-6 sm:p-8">
        <h2 id="manage-configuration-title" class="text-lg font-semibold text-brand-ink">{{ __('Configuration') }}</h2>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Server configuration editing moved to the dedicated Configuration workspace in the sidebar.') }}
        </p>
        <a
            href="{{ route('servers.configuration', $server) }}"
            wire:navigate
            class="mt-4 inline-flex items-center gap-2 rounded-lg border border-brand-forest bg-brand-forest px-3 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest/90"
        >
            <x-heroicon-o-document-text class="h-4 w-4" />
            {{ __('Open configuration editor') }}
        </a>
    </div>

    @if ($this->canCloneServer())
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Clone server') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Snapshot this DigitalOcean droplet and provision a new server from the snapshot. The clone lands in the same region and size, with a fresh SSH key. Snapshots typically take 3–8 minutes.') }}
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="openCloneServerModal"
                    @disabled($isDeployer)
                    class="inline-flex shrink-0 items-center gap-2 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <x-heroicon-o-document-duplicate class="h-4 w-4 opacity-80" aria-hidden="true" />
                    {{ __('Clone server') }}
                </button>
            </div>
        </div>
    @endif
</section>
