{{-- Resolve a server-error reference code (the X-Dply-Ref shown on the branded
     5xx page) to the actual request + error trace. Backed by a queued SSH job
     whose ConsoleAction streams into the banner below.
     Nested inside Errors merged card — strip, no second page card. --}}
@if ($referenceLookupAvailable)
    <section class="border-b border-brand-ink/10">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
            <x-icon-badge>
                <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Lookup') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Resolve a reference code') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('When a visitor hits a 500, the branded error page shows a Reference code. Paste it here to find the exact request and its error trace in this site’s logs.') }}
                </p>
            </div>
        </div>

        <div class="px-5 py-5 sm:px-6">
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
        </div>

        @if ($lookupRun)
            <div
                id="reference-lookup-result"
                class="border-t border-brand-ink/10"
                x-data="{}"
                x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))"
            >
                @include('livewire.partials.console-action-banner-static', [
                    'run' => $lookupRun,
                    'kindLabels' => (array) config('console_actions.kinds', []),
                    'embedded' => true,
                ])
            </div>
        @endif
    </section>
@endif
