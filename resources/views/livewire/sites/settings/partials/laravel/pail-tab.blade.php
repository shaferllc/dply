<section
    class="dply-card overflow-hidden"
    @if ($laravelPailLive) wire:poll.2s="loadLaravelPail" @endif
>
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Logs') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Pail (live tail)') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Streams `storage/logs/laravel.log`. Live mode polls every 2s and only ships bytes appended since the last poll, so a chatty log doesn\'t flood the panel.') }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button
                type="button"
                wire:click="toggleLaravelPailLive"
                @class([
                    'inline-flex h-9 items-center gap-2 rounded-xl border px-3 text-xs font-semibold transition',
                    'border-brand-sage bg-brand-sage text-white' => $laravelPailLive,
                    'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $laravelPailLive,
                ])
            >
                <span @class([
                    'inline-block h-2 w-2 rounded-full',
                    'bg-white animate-pulse' => $laravelPailLive,
                    'bg-brand-mist' => ! $laravelPailLive,
                ])></span>
                {{ $laravelPailLive ? __('Live') : __('Paused') }}
            </button>
            <button
                type="button"
                wire:click="loadLaravelPail"
                wire:loading.attr="disabled"
                wire:target="loadLaravelPail"
                class="inline-flex h-9 items-center gap-2 rounded-xl bg-brand-ink px-4 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
            >
                <x-heroicon-o-arrow-path wire:loading.remove wire:target="loadLaravelPail" class="h-4 w-4" />
                <x-spinner wire:loading wire:target="loadLaravelPail" variant="cream" size="sm" />
                {{ $laravelPailLoaded ? __('Refresh') : __('Tail logs') }}
            </button>
            @if ($laravelPailLoaded)
                <button
                    type="button"
                    wire:click="resetLaravelPail"
                    class="inline-flex h-9 items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-3 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                    title="{{ __('Clear buffer + re-baseline') }}"
                >
                    <x-heroicon-o-arrow-uturn-left class="h-4 w-4" />
                    {{ __('Reset') }}
                </button>
            @endif
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7">
    <x-input-error :messages="$errors->get('laravel_pail')" class="mt-3" />

    @if (! $laravelPailLoaded)
        <p class="mt-5 text-center text-sm text-brand-mist">{{ __('Click "Tail logs" to fetch the most recent entries, then "Live" to keep tailing.') }}</p>
    @elseif (trim($laravelPailBuffer) === '')
        <p class="mt-5 text-center text-sm text-brand-mist">{{ __('Log file is empty.') }}</p>
    @else
        <pre class="mt-5 max-h-[36rem] overflow-auto rounded-lg bg-brand-ink p-4 font-mono text-[11px] leading-relaxed text-brand-cream">{{ $laravelPailBuffer }}</pre>
    @endif
    </div>
</section>
