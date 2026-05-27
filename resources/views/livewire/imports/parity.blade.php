<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Import parity') }}</h1>
            <p class="max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('What we migrated and how it compares to the source today. Source credentials stay connected as a comparison oracle — keep them around until you no longer care about drift.') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('imports.forge.inventory') }}" wire:navigate>
                <x-secondary-button type="button">
                    <x-heroicon-o-server-stack class="mr-1.5 h-4 w-4" />
                    {{ __('Forge inventory') }}
                </x-secondary-button>
            </a>
            <a href="{{ route('imports.ploi.inventory') }}" wire:navigate>
                <x-secondary-button type="button">
                    <x-heroicon-o-server-stack class="mr-1.5 h-4 w-4" />
                    {{ __('Ploi inventory') }}
                </x-secondary-button>
            </a>
        </div>
    </header>

    @if ($totals['migrations'] === 0)
        <section class="dply-card overflow-hidden">
            <div class="space-y-3 p-8 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-sand/50">
                    <x-heroicon-o-arrows-right-left class="h-6 w-6 text-brand-forest" />
                </div>
                <h2 class="text-base font-semibold text-brand-ink">{{ __('No completed migrations yet.') }}</h2>
                <p class="mx-auto max-w-md text-sm leading-relaxed text-brand-moss">
                    {{ __('Once you run a Forge or Ploi migration, this page will keep showing you the diff between the source and dply until you decide to disconnect.') }}
                </p>
            </div>
        </section>
    @else
        <div class="mb-6 grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-5 py-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Migrations') }}</p>
                <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ $totals['migrations'] }}</p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800">{{ __('In sync') }}</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-900">{{ $totals['in_sync'] }}</p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50/60 px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-900">{{ __('Drifted') }}</p>
                <p class="mt-1 text-2xl font-semibold text-amber-900">{{ $totals['drifted'] }}</p>
            </div>
        </div>

        <div class="space-y-4">
            @foreach ($rows as $row)
                @php($migration = $row['migration'])
                <section class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-ink/[0.06] px-2.5 py-1 text-xs font-semibold text-brand-ink">
                                    {{ $row['source_label'] }}
                                </span>
                                <p class="font-semibold text-brand-ink">
                                    {{ $row['source_server']?->name ?? __('source server removed') }}
                                </p>
                                @if ($row['source_server']?->ip_address)
                                    <span class="text-sm text-brand-moss">· {{ $row['source_server']->ip_address }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-brand-moss">
                                @if ($row['target_server'])
                                    {{ __('Migrated to') }}
                                    <a href="{{ route('servers.show', $row['target_server']) }}" wire:navigate class="font-semibold text-brand-forest hover:text-brand-ink">{{ $row['target_server']->name }}</a>
                                    @if ($row['target_server']->ip_address)
                                        <span>· {{ $row['target_server']->ip_address }}</span>
                                    @endif
                                @else
                                    {{ __('Target server removed') }}
                                @endif
                                @if ($migration->completed_at)
                                    · {{ __('completed') }} {{ $migration->completed_at->diffForHumans() }}
                                @endif
                            </p>
                        </div>
                        @if ($row['has_drift'])
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-amber-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                                {{ __('Drift detected') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-900 ring-1 ring-emerald-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                {{ __('In sync') }}
                            </span>
                        @endif
                    </header>

                    <div class="grid gap-4 px-5 py-4 sm:grid-cols-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Site count') }}</p>
                            <p class="mt-1 text-sm text-brand-ink">
                                <span class="font-mono text-base">{{ $row['migrated_site_count'] }}</span>
                                <span class="text-brand-moss">/ {{ $row['source_site_count'] }}</span>
                                <span class="ms-1 text-xs text-brand-moss">{{ __('migrated / on source') }}</span>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Source inventory') }}</p>
                            @if ($row['source_last_synced_at'])
                                <p class="mt-1 text-sm {{ $row['source_inventory_stale'] ? 'text-amber-800' : 'text-brand-ink' }}">
                                    {{ __('synced :ago', ['ago' => $row['source_last_synced_at']->diffForHumans()]) }}
                                </p>
                            @else
                                <p class="mt-1 text-sm text-brand-moss">{{ __('never synced') }}</p>
                            @endif
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Status') }}</p>
                            <p class="mt-1 text-sm text-brand-ink capitalize">{{ str_replace('_', ' ', $migration->status) }}</p>
                        </div>
                    </div>

                    @if ($row['added_after_migration'] !== [])
                        <div class="border-t border-brand-ink/10 px-5 py-4">
                            <p class="flex items-center gap-2 text-sm font-semibold text-amber-900">
                                <x-heroicon-o-plus-circle class="h-4 w-4" />
                                {{ trans_choice('{1} 1 site added to source after migration|[2,*] :count sites added to source after migration', count($row['added_after_migration']), ['count' => count($row['added_after_migration'])]) }}
                            </p>
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($row['added_after_migration'] as $added)
                                    <li class="flex flex-wrap items-center gap-2 rounded-lg bg-amber-50/60 px-3 py-1.5">
                                        <span class="font-mono text-xs text-amber-900">{{ $added['domain'] ?? '—' }}</span>
                                        @if (! empty($added['site_type']))
                                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">{{ $added['site_type'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-2 text-xs text-brand-moss">{{ __('These exist on the source but never went through a migration. Re-run the import to bring them across.') }}</p>
                        </div>
                    @endif

                    @if ($row['removed_from_source'] !== [])
                        <div class="border-t border-brand-ink/10 px-5 py-4">
                            <p class="flex items-center gap-2 text-sm font-semibold text-rose-900">
                                <x-heroicon-o-minus-circle class="h-4 w-4" />
                                {{ trans_choice('{1} 1 site removed from source after migration|[2,*] :count sites removed from source after migration', count($row['removed_from_source']), ['count' => count($row['removed_from_source'])]) }}
                            </p>
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($row['removed_from_source'] as $removed)
                                    <li class="flex flex-wrap items-center gap-2 rounded-lg bg-rose-50/60 px-3 py-1.5">
                                        <span class="font-mono text-xs text-rose-900">{{ $removed['domain'] }}</span>
                                        @if ($removed['target_site_name'])
                                            <span class="text-xs text-rose-900/70">· {{ __('dply site') }} {{ $removed['target_site_name'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-2 text-xs text-brand-moss">{{ __('Still live in dply; gone on the source. Decide whether to keep them or sunset.') }}</p>
                        </div>
                    @endif

                    @if ($row['failed_cutover'] !== [])
                        <div class="border-t border-brand-ink/10 px-5 py-4">
                            <p class="flex items-center gap-2 text-sm font-semibold text-rose-900">
                                <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
                                {{ trans_choice('{1} 1 site cutover did not complete|[2,*] :count site cutovers did not complete', count($row['failed_cutover']), ['count' => count($row['failed_cutover'])]) }}
                            </p>
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($row['failed_cutover'] as $failed)
                                    <li class="rounded-lg bg-rose-50/60 px-3 py-1.5">
                                        <p class="font-mono text-xs text-rose-900">{{ $failed['domain'] }} <span class="text-rose-900/70">· {{ str_replace('_', ' ', $failed['status']) }}</span></p>
                                        @if (! empty($failed['failure_summary']))
                                            <p class="mt-0.5 text-xs text-rose-900/80">{{ $failed['failure_summary'] }}</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
