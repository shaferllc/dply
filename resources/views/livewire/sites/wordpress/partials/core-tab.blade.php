<section class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-cube class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Core') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('WordPress core') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Installed version from `wp core version` with an availability check against WordPress.org.') }}</p>
        </div>
        @if ($coreLoaded)
            <button type="button" wire:click="loadCore" wire:loading.attr="disabled" wire:target="loadCore" class="ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                <span wire:loading.remove wire:target="loadCore" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Refresh') }}
                </span>
                <span wire:loading wire:target="loadCore" class="inline-flex items-center gap-1.5">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Checking…') }}
                </span>
            </button>
        @endif
    </div>

    @if (! $coreLoaded)
        <div wire:init="loadCore" class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" size="sm" />
            {{ __('Checking WordPress core…') }}
        </div>
    @else
        <div class="grid gap-4 px-6 py-6 sm:grid-cols-2">
            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Installed version') }}</p>
                <p class="mt-1 font-mono text-lg font-semibold text-brand-ink">{{ data_get($core, 'version') ?: __('Unknown') }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Update status') }}</p>
                @if (data_get($core, 'update_available'))
                    <p class="mt-1 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-ink">
                        <span class="rounded-full bg-brand-gold/20 px-2 py-0.5 text-[11px]">{{ __('Update available') }}</span>
                        @if (data_get($core, 'latest'))
                            <span class="font-mono text-brand-moss">→ {{ data_get($core, 'latest') }}</span>
                        @endif
                    </p>
                @else
                    <p class="mt-1 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-forest">
                        <x-heroicon-m-check-circle class="h-4 w-4" aria-hidden="true" />
                        {{ __('Up to date') }}
                    </p>
                @endif
            </div>
        </div>

        @if (data_get($core, 'update_available') && $canMutate)
            <div class="border-t border-brand-ink/10 px-6 py-4">
                <button
                    type="button"
                    wire:click="updateCore"
                    wire:loading.attr="disabled"
                    wire:target="updateCore"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-brand-ink px-5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
                >
                    <x-heroicon-o-arrow-up-circle class="h-4 w-4" aria-hidden="true" />
                    <span wire:loading.remove wire:target="updateCore">{{ __('Update WordPress core') }}</span>
                    <span wire:loading wire:target="updateCore">{{ __('Queueing…') }}</span>
                </button>
                <p class="mt-2 text-[11px] text-brand-mist">{{ __('The core update queues and applies in the background — refresh to confirm the new version.') }}</p>
            </div>
        @endif
    @endif

    <x-input-error :messages="$errors->get('core')" class="px-6 pb-4" />
</section>
