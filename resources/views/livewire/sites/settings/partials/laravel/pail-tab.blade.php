<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
    <header class="flex items-start justify-between gap-3">
        <div>
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Pail (recent logs)') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Last 200 lines of `storage/logs/laravel.log` — the same data the `php artisan pail` command would surface, polled on demand. Real-time streaming via SSE lands in a follow-up.') }}</p>
        </div>
        <button
            type="button"
            wire:click="loadLaravelPail"
            wire:loading.attr="disabled"
            wire:target="loadLaravelPail"
            class="inline-flex h-9 items-center gap-2 rounded-xl bg-brand-ink px-4 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
        >
            <x-heroicon-o-arrow-path wire:loading.remove wire:target="loadLaravelPail" class="h-4 w-4" />
            <x-spinner wire:loading wire:target="loadLaravelPail" variant="cream" size="sm" />
            <span wire:loading.remove wire:target="loadLaravelPail">{{ $laravelPailLoaded ? __('Refresh') : __('Tail logs') }}</span>
            <span wire:loading wire:target="loadLaravelPail">{{ __('Tailing…') }}</span>
        </button>
    </header>

    <x-input-error :messages="$errors->get('laravel_pail')" class="mt-3" />

    @if (! $laravelPailLoaded)
        <p class="mt-5 text-center text-sm text-brand-mist">{{ __('Click "Tail logs" to fetch the latest entries.') }}</p>
    @elseif (trim($laravelPailBuffer) === '')
        <p class="mt-5 text-center text-sm text-brand-mist">{{ __('Log file is empty.') }}</p>
    @else
        <pre class="mt-5 max-h-[32rem] overflow-auto rounded-lg bg-brand-ink p-4 font-mono text-[11px] leading-relaxed text-brand-cream">{{ $laravelPailBuffer }}</pre>
    @endif
</section>
