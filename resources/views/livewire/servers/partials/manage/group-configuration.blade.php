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
</section>
