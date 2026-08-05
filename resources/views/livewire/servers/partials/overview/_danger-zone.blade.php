{{-- Danger zone.

     Collapsed from an icon-badge + eyebrow + title + four-line paragraph to a
     single strip. The confirm-by-typing-the-name flow and the schedule-for-later
     option both live in the modal, which is where you actually read them — the
     card only has to name the action and be unmistakably destructive. --}}
@if (! $setupIncomplete)
    @can('delete', $server)
        <section class="dply-card overflow-hidden border-rose-200 p-0">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 bg-rose-50/60 px-5 py-3 sm:px-6">
                <div class="flex min-w-0 flex-1 basis-72 items-center gap-2">
                    <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
                    <p class="min-w-0 text-sm text-brand-moss">
                        <span class="font-semibold text-brand-ink">{{ __('Remove this server.') }}</span>
                        {{ __('Deletes the dply record, runs provider teardown, and detaches sites, databases and backups.') }}
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="openRemoveServerModal"
                    class="inline-flex shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-red-700"
                >
                    <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Remove or schedule removal') }}
                </button>
            </div>
        </section>
    @endcan
@endif
