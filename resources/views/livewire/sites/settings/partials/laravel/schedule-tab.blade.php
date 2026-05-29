<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Schedule') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Scheduled tasks') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Live snapshot of `php artisan schedule:list` parsed into a chart with cron expressions, next-run times, and last exit codes.') }}</p>
        </div>
        <button
            type="button"
            wire:click="loadLaravelSchedule"
            wire:loading.attr="disabled"
            wire:target="loadLaravelSchedule"
            class="inline-flex h-9 shrink-0 items-center gap-2 rounded-xl bg-brand-ink px-4 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
        >
            <x-heroicon-o-arrow-path wire:loading.remove wire:target="loadLaravelSchedule" class="h-4 w-4" />
            <x-spinner wire:loading wire:target="loadLaravelSchedule" variant="cream" size="sm" />
            <span wire:loading.remove wire:target="loadLaravelSchedule">{{ $laravelScheduleLoaded ? __('Refresh') : __('Load schedule') }}</span>
            <span wire:loading wire:target="loadLaravelSchedule">{{ __('Loading…') }}</span>
        </button>
    </div>

    <div class="px-6 py-6 sm:px-7">
    <x-input-error :messages="$errors->get('laravel_schedule')" class="mt-3" />

    @if (! $laravelScheduleLoaded)
        <p class="mt-5 text-center text-sm text-brand-mist">{{ __('Click "Load schedule" to query the live application.') }}</p>
    @elseif (empty($laravelScheduleEntries))
        <p class="mt-5 text-center text-sm text-brand-mist">{{ __('No scheduled tasks defined in app/Console/Kernel.php.') }}</p>
    @else
        <ul class="mt-5 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
            @foreach ($laravelScheduleEntries as $entry)
                <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-mono text-brand-ink">{{ $entry['command'] ?? $entry['description'] ?? '—' }}</p>
                        @if (! empty($entry['description']))
                            <p class="mt-1 text-xs text-brand-moss">{{ $entry['description'] }}</p>
                        @endif
                    </div>
                    <div class="text-right text-xs text-brand-mist">
                        <p class="font-mono text-brand-ink">{{ $entry['expression'] ?? '* * * * *' }}</p>
                        @if (! empty($entry['next_due']))
                            <p class="mt-1">{{ __('Next:') }} {{ $entry['next_due'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
    </div>
</section>
