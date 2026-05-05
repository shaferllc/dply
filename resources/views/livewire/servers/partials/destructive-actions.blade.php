@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ServerDatabase> $databases */
    $databases = $databases ?? collect();
    $engineLabels = $engineLabels ?? ['mysql' => 'MySQL', 'postgres' => 'PostgreSQL', 'sqlite' => 'SQLite'];
@endphp
@if ($databases->isNotEmpty())
    <div class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8">
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Destructive actions') }}</h2>
        <p class="mt-2 text-sm text-brand-moss">{{ __('Detach a database from Dply, or permanently drop it (and its user) from the server.') }}</p>
        <ul class="mt-4 space-y-3">
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
@endif
