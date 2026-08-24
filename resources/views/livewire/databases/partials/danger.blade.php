<div class="rounded-2xl border border-rose-200 bg-white shadow-sm">
    <div class="border-b border-rose-200 px-5 py-4">
        <h2 class="text-sm font-semibold text-rose-900">{{ __('Tear down this database') }}</h2>
        <p class="mt-1 text-xs text-slate-600">
            @if ($database->isExternal())
                {{ __('This is an operator-supplied connection: dply forgets it and never touches the database itself.') }}
            @else
                {{ __('The cluster is permanently deleted on the provider, along with its backups. There is no undo — restore first if you might want the data.') }}
            @endif
        </p>
    </div>

    <div class="px-5 py-4">
        @if ($database->status === \App\Models\CloudDatabase::STATUS_DELETING)
            <p class="text-sm text-slate-500">{{ __('Deleting…') }}</p>
        @elseif ($database->sites->isNotEmpty())
            <p class="text-sm text-slate-700">
                {{ __(':count app(s) are still attached. Tearing down leaves them pointing at a database that no longer exists.', ['count' => $database->sites->count()]) }}
            </p>
            <button type="button" wire:click="confirmTearDown" class="mt-3 inline-flex items-center rounded-xl border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                {{ __('Tear down anyway') }}
            </button>
        @else
            <button type="button" wire:click="confirmTearDown" class="inline-flex items-center rounded-xl border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                {{ __('Tear down') }}
            </button>
        @endif

        @if ($confirmingTearDown)
            <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                <p class="font-semibold">{{ __('Delete :name permanently?', ['name' => $database->name]) }}</p>
                <div class="mt-3 flex gap-3">
                    <button type="button" wire:click="tearDown" class="inline-flex items-center rounded-xl bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800">
                        {{ __('Yes, tear it down') }}
                    </button>
                    <button type="button" wire:click="cancelTearDown" class="text-sm font-medium text-rose-900 underline">
                        {{ __('Cancel') }}
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>
