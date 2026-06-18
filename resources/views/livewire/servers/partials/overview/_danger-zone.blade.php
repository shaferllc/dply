{{-- Danger zone --}}
@if (! $setupIncomplete)
    @can('delete', $server)
        <section class="dply-card overflow-hidden border-rose-200">
            <div class="border-b border-rose-200 bg-rose-50/60 px-6 py-5 sm:px-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-3">
                        <x-icon-badge tone="danger">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Danger zone') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Remove this server') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Deletes the dply server record, runs any provider teardown, and detaches sites / databases / backups. You\'ll be asked to type the server name to confirm and can schedule removal for a future date (runs at the end of that day in your app timezone).') }}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        wire:click="openRemoveServerModal"
                        class="inline-flex shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-red-700"
                    >
                        <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Remove or schedule removal') }}
                    </button>
                </div>
            </div>
        </section>
    @endcan
@endif
