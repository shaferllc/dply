<div id="settings-danger" class="{{ $card }} scroll-mt-24 border-amber-200/80 p-6 sm:p-8">
    <h2 class="text-lg font-semibold text-amber-950">{{ __('Danger zone') }}</h2>
    <p class="mt-2 max-w-2xl text-sm text-brand-moss">
        {{ __('Removing a server from Dply may destroy cloud instances and data depending on choices in the removal flow.') }}
    </p>
    <div class="mt-6">
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
