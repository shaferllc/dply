<section class="{{ $card ?? 'dply-card' }} overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Server sync') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Refresh engine and database inventory') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Re-probe which engines are installed, then query MySQL and PostgreSQL for database names to compare with Dply.') }}
                    </p>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="refreshDatabaseCapabilities"
                    wire:loading.attr="disabled"
                    wire:target="refreshDatabaseCapabilities"
                    @disabled($isDeployer ?? false)
                    title="{{ __('Re-run engine detection (cached for a few minutes)') }}"
                    class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="refreshDatabaseCapabilities" class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 opacity-80" aria-hidden="true" />
                        {{ __('Recheck engines') }}
                    </span>
                    <span wire:loading wire:target="refreshDatabaseCapabilities" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Rechecking…') }}
                    </span>
                </button>
                <button
                    type="button"
                    wire:click="synchronizeDatabases"
                    wire:loading.attr="disabled"
                    wire:target="synchronizeDatabases"
                    @disabled($isDeployer ?? false)
                    class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="synchronizeDatabases" class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-magnifying-glass class="h-4 w-4 shrink-0 opacity-80" aria-hidden="true" />
                        {{ __('Synchronize databases') }}
                    </span>
                    <span wire:loading wire:target="synchronizeDatabases" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Scanning…') }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</section>
