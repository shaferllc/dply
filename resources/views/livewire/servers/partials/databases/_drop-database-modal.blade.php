@php
    /** @var \App\Models\ServerDatabase|null $dropDb */
    $dropDb = $this->dropConfirmDatabase;
    $dropConsumers = $this->dropConfirmConsumers;
    $dropIsSqlite = $dropDb?->engine === 'sqlite';
    $dropOwnerSite = $dropDb?->site;
    $dropBackups = $dropDb?->backups ?? collect();
@endphp

<x-modal
    name="drop-database-modal"
    :show="false"
    maxWidth="2xl"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
    focusable
>
    @if ($dropDb)
        <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 bg-rose-50/60 px-6 py-5">
            <x-icon-badge tone="danger">
                <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Danger zone') }}</p>
                <h2 class="mt-0.5 text-lg font-semibold text-brand-ink">
                    {{ $dropIsSqlite ? __('Delete SQLite file on server') : __('Drop database on server') }}
                </h2>
                <p class="mt-1 font-mono text-sm text-brand-moss">{{ $dropDb->name }}</p>
            </div>
        </div>

        <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
            <p class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-relaxed text-rose-800">
                @if ($dropIsSqlite)
                    {{ __('This permanently deletes the database file on the server and removes the entry from Dply. This cannot be undone.') }}
                @else
                    {{ __('This permanently drops the database and its user on the server and removes the entry from Dply. This cannot be undone.') }}
                @endif
            </p>

            {{-- Attached resources — warn and proceed --}}
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Attached resources') }}</p>
                @if (! $dropOwnerSite && empty($dropConsumers))
                    <p class="mt-2 text-sm text-brand-moss">{{ __('No sites are attached to this database.') }}</p>
                @else
                    <p class="mt-1 text-sm text-brand-moss">{{ __('These will lose this database. Migrate or detach them first, or confirm to proceed anyway.') }}</p>
                    <ul class="mt-3 space-y-2">
                        @if ($dropOwnerSite)
                            <li class="flex flex-wrap items-center gap-2 rounded-lg border border-brand-ink/10 px-3 py-2 text-sm">
                                <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                <a href="{{ route('sites.show', ['server' => $dropOwnerSite->server_id, 'site' => $dropOwnerSite->id, 'section' => 'general']) }}"
                                   class="font-medium text-brand-ink hover:underline" wire:navigate>
                                    {{ $dropOwnerSite->name ?: __('Site') }}
                                </a>
                                <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('owner') }}</span>
                            </li>
                        @endif
                        @foreach ($dropConsumers as $consumer)
                            <li class="flex flex-wrap items-center gap-2 rounded-lg border border-brand-ink/10 px-3 py-2 text-sm">
                                <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                <a href="{{ $consumer['site_url'] }}" class="font-medium text-brand-ink hover:underline" wire:navigate>
                                    {{ $consumer['site_name'] }}
                                </a>
                                <span class="text-xs text-brand-moss">{{ $consumer['server_name'] }}</span>
                                @if ($consumer['is_remote'])
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">{{ __('remote') }}</span>
                                @endif
                                <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $consumer['type'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Back up first — offer, not a gate --}}
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Download a copy first') }}</p>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Run a backup now — it appears on the Backups tab and is downloadable once ready.') }}</p>
                    </div>
                    @if (\App\Support\Servers\DatabaseWorkspaceEngines::supportsBackup($dropDb->engine))
                        <button
                            type="button"
                            wire:click="openBackupModal(@js($dropDb->id))"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-cloud-arrow-down class="h-4 w-4" aria-hidden="true" />
                            {{ __('Back up now') }}
                        </button>
                    @else
                        <span class="shrink-0 text-xs text-brand-moss">{{ __('Backup not supported for this engine yet.') }}</span>
                    @endif
                </div>

                @if ($dropBackups->isNotEmpty())
                    <ul class="mt-3 divide-y divide-brand-ink/5 border-t border-brand-ink/10">
                        @foreach ($dropBackups as $backup)
                            <li class="flex items-center justify-between gap-3 py-2 text-xs">
                                <span class="text-brand-moss">
                                    {{ $backup->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                    @if ($backup->bytes)
                                        · {{ \Illuminate\Support\Number::fileSize($backup->bytes, precision: 1) }}
                                    @endif
                                </span>
                                <span class="inline-flex items-center gap-3">
                                    @switch($backup->status)
                                        @case(\App\Models\ServerDatabaseBackup::STATUS_COMPLETED)
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700">{{ __('Ready') }}</span>
                                            @break
                                        @case(\App\Models\ServerDatabaseBackup::STATUS_FAILED)
                                            <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 font-medium text-rose-700">{{ __('Failed') }}</span>
                                            @break
                                        @default
                                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700">{{ __('In progress') }}</span>
                                    @endswitch
                                    @if ($backup->isDownloadable())
                                        <button type="button" wire:click="downloadBackup(@js($backup->id))" class="font-medium text-brand-forest hover:underline">
                                            {{ __('Download') }}
                                        </button>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
            <x-secondary-button type="button" wire:click="closeDropConfirm">{{ __('Cancel') }}</x-secondary-button>
            <button
                type="button"
                wire:click="dropDatabaseOnServer(@js($dropDb->id))"
                wire:loading.attr="disabled"
                wire:target="dropDatabaseOnServer"
                class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-md transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="dropDatabaseOnServer" class="inline-flex items-center gap-2">
                    <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ $dropIsSqlite ? __('Delete file') : __('Drop database') }}
                </span>
                <span wire:loading wire:target="dropDatabaseOnServer" class="inline-flex items-center gap-2 whitespace-nowrap">
                    <x-spinner variant="cream" size="sm" />
                    {{ __('Dropping…') }}
                </span>
            </button>
        </div>
    @endif
</x-modal>
