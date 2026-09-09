@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ServerDatabase> $databases */
    $databases = $databases ?? collect();
    $engineLabels = $engineLabels ?? ['mysql' => 'MySQL', 'postgres' => 'PostgreSQL', 'sqlite' => 'SQLite'];
@endphp
@if ($databases->isNotEmpty())
    <div class="{{ $card ?? 'dply-card overflow-hidden' }} overflow-hidden border-rose-200">
        <x-workspace-panel-head
            dense
            tone="danger"
            icon="heroicon-o-trash"
            :title="__('Destructive actions')"
            :count="trans_choice('{1} :count database|[2,*] :count databases', $databases->count(), ['count' => $databases->count()])"
            :note="$databases->contains(fn ($db) => $db->engine === 'sqlite')
                ? __('Detach a database from Dply, or permanently delete the SQLite file on the server.')
                : __('Detach a database from Dply, or permanently drop it (and its user) from the server.')"
            class="border-b border-brand-ink/10"
        />
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($databases->sortBy('name') as $db)
                <li class="flex flex-wrap items-center gap-x-2 gap-y-2 px-4 py-3 sm:px-5">
                    <p class="truncate font-mono text-sm font-semibold text-brand-ink">{{ $db->name }}</p>
                    <span class="inline-flex items-center rounded-md bg-brand-sand/50 px-1.5 py-0.5 text-xs text-brand-ink/80 ring-1 ring-brand-ink/10">
                        {{ $engineLabels[$db->engine] ?? ucfirst((string) $db->engine) }}
                    </span>
                    <div class="ml-auto flex shrink-0 flex-wrap gap-1.5">
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('deleteDatabase', ['{{ $db->id }}'], @js(__('Remove database from Dply')), @js(__('Remove this entry from Dply only? The database will stay on the server.')), @js(__('Remove from Dply')), true)"
                            wire:loading.attr="disabled"
                            wire:target="deleteDatabase"
                            class="inline-flex h-7 items-center whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            {{ __('Remove from Dply') }}
                        </button>
                        <button
                            type="button"
                            wire:click="openDropConfirm(@js($db->id))"
                            wire:loading.attr="disabled"
                            wire:target="openDropConfirm,dropDatabaseOnServer"
                            class="inline-flex h-7 items-center whitespace-nowrap rounded-md border border-red-200 bg-red-50 px-2 text-xs font-medium text-red-700 shadow-sm transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ __('Drop on server') }}
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif
