@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ServerDatabase> $databases */
    $databases = $databases ?? collect();
    $engineLabels = $engineLabels ?? ['mysql' => 'MySQL', 'postgres' => 'PostgreSQL', 'sqlite' => 'SQLite'];
@endphp
@if ($databases->isNotEmpty())
    <div class="{{ $card ?? 'dply-card overflow-hidden' }} overflow-hidden border-rose-200">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-rose-50/60 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Danger zone') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Destructive actions') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Detach a database from Dply, or permanently drop it (and its user) from the server.') }}</p>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
        <ul class="space-y-3">
            @foreach ($databases->sortBy('name') as $db)
                <li class="flex flex-col gap-3 rounded-xl border border-brand-ink/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-mono text-sm font-semibold text-brand-ink">{{ $db->name }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ $engineLabels[$db->engine] ?? ucfirst((string) $db->engine) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('deleteDatabase', ['{{ $db->id }}'], @js(__('Remove database from Dply')), @js(__('Remove this entry from Dply only? The database will stay on the server.')), @js(__('Remove from Dply')), true)"
                            wire:loading.attr="disabled"
                            wire:target="deleteDatabase"
                            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                        >
                            {{ __('Remove from Dply') }}
                        </button>
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('dropDatabaseOnServer', ['{{ $db->id }}'], @js(__('Drop database on server')), @js(__('Permanently drop this database and user on the server? This cannot be undone.')), @js(__('Drop database')), true)"
                            wire:loading.attr="disabled"
                            wire:target="dropDatabaseOnServer"
                            class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ __('Drop on server') }}
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
        </div>
    </div>
@endif
