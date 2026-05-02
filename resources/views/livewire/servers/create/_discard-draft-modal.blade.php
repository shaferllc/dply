{{--
  Discard-draft confirm modal for the create-server wizard.
  Expects:
    - $showDiscardDraftModal (bool)  : opens the modal
  Component must implement closeDiscardDraftModal() and confirmDiscardDraft().
--}}
@if ($showDiscardDraftModal ?? false)
    @teleport('body')
    <div class="fixed inset-0 isolate z-[100] overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="discard-draft-title">
        <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeDiscardDraftModal"></div>
        <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10">
            <div class="w-full max-w-md dply-modal-panel" @click.stop>
                <div class="border-b border-zinc-100 px-6 py-5">
                    <h2 id="discard-draft-title" class="text-base font-semibold text-brand-ink">{{ __('Discard this draft?') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss">{{ __("You'll lose the values you've entered so far. This can't be undone.") }}</p>
                </div>
                <div class="flex flex-col-reverse gap-3 border-t border-zinc-100 bg-zinc-50/80 px-6 py-4 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="closeDiscardDraftModal" class="inline-flex justify-center rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-zinc-50">
                        {{ __('Keep editing') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmDiscardDraft"
                        wire:loading.attr="disabled"
                        wire:target="confirmDiscardDraft"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:cursor-wait disabled:opacity-60"
                    >
                        <svg wire:loading wire:target="confirmDiscardDraft" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        {{ __('Discard draft') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endteleport
@endif
