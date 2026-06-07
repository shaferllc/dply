<div id="settings-danger" class="{{ $card }} scroll-mt-24 border-rose-200">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-rose-50/60 px-6 py-5 sm:px-7">
        <x-icon-badge tone="danger">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Danger') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Danger zone') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Removing a server from Dply may destroy cloud instances and data depending on choices in the removal flow.') }}
            </p>
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7">
        <h3 class="text-sm font-semibold text-red-800">{{ __('Delete server') }}</h3>
        @can('delete', $server)
            <button
                type="button"
                wire:click="openRemoveServerModal"
                class="mt-4 inline-flex items-center rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-red-700"
            >
                {{ __('Remove or schedule removal…') }}
            </button>
        @endcan
    </div>
</div>
