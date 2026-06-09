{{-- Resolve a server-error reference code (the X-Dply-Ref shown on the branded
     5xx page) to the actual request + error trace. Backed by a queued SSH job
     whose ConsoleAction streams into the banner below. --}}
@if ($referenceLookupAvailable)
    <x-section-card>
        <div class="space-y-4">
            <div class="space-y-1">
                <h3 class="text-sm font-semibold text-brand-ink dark:text-white">
                    {{ __('Resolve a reference code') }}
                </h3>
                <p class="text-sm text-brand-ink/60 dark:text-gray-400">
                    {{ __('When a visitor hits a 500, the branded error page shows a Reference code. Paste it here to find the exact request and its error trace in this site’s logs.') }}
                </p>
            </div>

            <form wire:submit.prevent="lookupReference" class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <x-text-input
                    wire:model="referenceQuery"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    class="w-full flex-1 font-mono"
                    :placeholder="__('e.g. 8f3c1a2b9d4e5f60…')"
                    aria-label="{{ __('Reference code') }}"
                />
                <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="lookupReference">
                    <span wire:loading.remove wire:target="lookupReference">{{ __('Resolve') }}</span>
                    <span wire:loading wire:target="lookupReference">{{ __('Looking up…') }}</span>
                </x-secondary-button>
            </form>

            @if ($lookupRun)
                <div
                    id="reference-lookup-result"
                    x-data="{}"
                    x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))"
                >
                    @include('livewire.partials.console-action-banner-static', [
                        'run' => $lookupRun,
                        'kindLabels' => (array) config('console_actions.kinds', []),
                    ])
                </div>
            @endif
        </div>
    </x-section-card>
@endif
