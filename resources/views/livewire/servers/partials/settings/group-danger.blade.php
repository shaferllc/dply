<div id="settings-danger" class="{{ $card }} scroll-mt-24">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-exclamation-triangle"
        :title="__('Danger zone')"
        :note="__('Removing a server from Dply may destroy cloud instances and data depending on choices in the removal flow.')"
        tone="danger"
        class="border-b border-brand-ink/10"
    />

    <div class="px-5 py-4 sm:px-6">
        <h3 class="text-sm font-semibold text-red-800">{{ __('Delete server') }}</h3>
        @can('delete', $server)
            <button
                type="button"
                wire:click="openRemoveServerModal"
                class="mt-4 inline-flex items-center rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-red-700"
            >
                {{ __('Remove or schedule removal…') }}
            </button>
        @elseif ($server->isDeletionProtected())
            <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-brand-ink/10 bg-brand-sand/40 px-4 py-3 text-sm text-brand-moss">
                <x-heroicon-o-lock-closed class="mt-0.5 h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                <p>{{ __('This is dply infrastructure and is protected from deletion. It cannot be removed from the host or the database.') }}</p>
            </div>
        @endcan
    </div>
</div>
