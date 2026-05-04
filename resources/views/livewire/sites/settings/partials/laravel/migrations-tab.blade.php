<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
    <header class="flex items-start justify-between gap-3">
        <div>
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Migrations') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Live status from `php artisan migrate:status`. Roll back the last batch with a one-click pre-rollback safety snapshot.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="loadLaravelMigrations"
                wire:loading.attr="disabled"
                wire:target="loadLaravelMigrations"
                class="inline-flex h-9 items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60"
            >
                <x-heroicon-o-arrow-path wire:loading.remove wire:target="loadLaravelMigrations" class="h-4 w-4" />
                <x-spinner wire:loading wire:target="loadLaravelMigrations" variant="ink" size="sm" />
                {{ $laravelMigrationsLoaded ? __('Refresh') : __('Load migrations') }}
            </button>
            @if ($laravelMigrationsLoaded && ! empty($laravelMigrationEntries))
                <button
                    type="button"
                    wire:click="rollbackLastMigrationBatch"
                    wire:loading.attr="disabled"
                    wire:target="rollbackLastMigrationBatch"
                    class="inline-flex h-9 items-center gap-2 rounded-xl bg-rose-700 px-4 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-800 disabled:opacity-60"
                >
                    <x-heroicon-o-arrow-uturn-left class="h-4 w-4" />
                    <span wire:loading.remove wire:target="rollbackLastMigrationBatch">{{ __('Roll back last batch') }}</span>
                    <span wire:loading wire:target="rollbackLastMigrationBatch">{{ __('Rolling back…') }}</span>
                </button>
            @endif
        </div>
    </header>

    @if ($laravelMigrationsFlash)
        <div class="mt-4 rounded-xl border border-brand-sage/30 bg-brand-sage/10 px-3 py-2 text-sm text-brand-forest">
            {{ $laravelMigrationsFlash }}
        </div>
    @endif

    <x-input-error :messages="$errors->get('laravel_migrations')" class="mt-3" />

    @if (! $laravelMigrationsLoaded)
        <p class="mt-5 text-center text-sm text-brand-mist">{{ __('Click "Load migrations" to query the current schema state.') }}</p>
    @elseif (empty($laravelMigrationEntries))
        <p class="mt-5 text-center text-sm text-brand-mist">{{ __('No migrations found in database/migrations.') }}</p>
    @else
        <div class="mt-5 overflow-hidden rounded-xl border border-brand-ink/10">
            <table class="w-full text-sm">
                <thead class="bg-brand-cream/30 text-left text-[11px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-2">{{ __('Migration') }}</th>
                        <th class="px-4 py-2">{{ __('Batch') }}</th>
                        <th class="px-4 py-2">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10">
                    @foreach ($laravelMigrationEntries as $entry)
                        <tr>
                            <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $entry['migration'] ?? $entry['name'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-brand-moss">{{ $entry['batch'] ?? '—' }}</td>
                            <td class="px-4 py-2">
                                @if (($entry['ran'] ?? false) === true || ($entry['ran'] ?? '') === 'Yes')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold text-brand-forest">
                                        <x-heroicon-m-check class="h-3 w-3" />
                                        {{ __('Ran') }}
                                    </span>
                                @else
                                    <span class="rounded-full bg-brand-gold/15 px-2 py-0.5 text-[10px] font-semibold text-brand-ink">{{ __('Pending') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
