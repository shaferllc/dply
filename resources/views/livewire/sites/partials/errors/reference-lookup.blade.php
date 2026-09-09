{{-- Resolve a server-error reference code (the X-Dply-Ref shown on the branded
     5xx page) to the actual request + error trace. Backed by a queued SSH job
     whose ConsoleAction streams into the banner below.
     Nested inside Errors merged card — compact strip, no second page card. --}}
@if ($referenceLookupAvailable)
    <section class="border-b border-brand-ink/10">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                <x-heroicon-o-magnifying-glass class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                {{ __('Resolve a reference code') }}
            </h3>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
            <p class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ __('Paste the X-Dply-Ref from a branded 5xx page to find the request and error trace in this site’s logs.') }}">
                {{ __('Paste the X-Dply-Ref from a branded 5xx page to find the request and error trace in this site’s logs.') }}
            </p>
        </div>

        <form wire:submit.prevent="lookupReference" class="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:px-4">
            <x-text-input
                wire:model="referenceQuery"
                type="text"
                autocomplete="off"
                spellcheck="false"
                class="w-full flex-1 font-mono text-sm"
                :placeholder="__('e.g. 8f3c1a2b9d4e5f60…')"
                aria-label="{{ __('Reference code') }}"
            />
            <x-secondary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="lookupReference" class="shrink-0">
                <span wire:loading.remove wire:target="lookupReference">{{ __('Resolve') }}</span>
                <span wire:loading wire:target="lookupReference">{{ __('Looking up…') }}</span>
            </x-secondary-button>
        </form>

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
