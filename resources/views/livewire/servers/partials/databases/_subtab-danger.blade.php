@if (! $showEngineWorkspace)
    <div class="{{ $card }} overflow-hidden border-rose-200 px-6 py-6 sm:px-8">
        <x-empty-state
            borderless
            icon="heroicon-o-exclamation-triangle"
            tone="rose"
            :title="__('Danger zone unavailable')"
            :description="__('Install :engine on Overview first — destructive drop and detach actions appear here.', ['engine' => $dbEngineInfoForTab['label']])"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="setEngineSubtab('overview')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                >
                    {{ __('Go to Overview') }}
                </button>
            </x-slot:actions>
        </x-empty-state>
    </div>
@elseif ($engineDatabases->isEmpty())
    <div class="{{ $card }} overflow-hidden border-rose-200">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-rose-50/60 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Danger zone') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Destructive actions') }}</h3>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
            <x-empty-state
                borderless
                icon="heroicon-o-shield-check"
                tone="rose"
                :title="__('Nothing to remove yet')"
                :description="__('No :engine databases are tracked. Create one on Basics or Overview first — drop and detach actions appear here.', ['engine' => $dbEngineInfoForTab['label']])"
            >
                <x-slot:actions>
                    <button
                        type="button"
                        wire:click="setEngineSubtab('overview')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        {{ __('Go to Overview') }}
                    </button>
                </x-slot:actions>
            </x-empty-state>
        </div>
    </div>
@else
    @include('livewire.servers.partials.destructive-actions', [
        'databases' => $engineDatabases,
        'engineLabels' => $engineLabels,
        'card' => $card,
    ])
@endif
