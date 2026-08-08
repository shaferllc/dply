{{--
    Dense database list — one row per tracked database, divided rather than a
    stack of floating rounded-2xl cards. The engine and user count ride the
    title line as chips (the old layout spent a whole second line on
    "MySQL · 1 database user"), and the row actions are icon-first compact
    buttons like the cron / firewall lists.
--}}
@if ($databases->isNotEmpty())
    <ul class="divide-y divide-brand-ink/10">
        @foreach ($databases->sortBy('name') as $db)
            @php
                $rowTargets = implode(',', array_filter([
                    'openBackupModal',
                    'openEditDatabaseModal',
                    $db->engine === 'sqlite' ? 'openSqliteConsoleModal' : null,
                ]));

                $engineLabel = match ($db->engine) {
                    'postgres' => 'PostgreSQL',
                    'mariadb' => 'MariaDB',
                    'mongodb' => 'MongoDB',
                    'clickhouse' => 'ClickHouse',
                    'sqlite' => 'SQLite',
                    default => 'MySQL',
                };

                $rowBtn = 'inline-flex h-7 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50';
            @endphp
            <x-workspace-table-row
                wire:key="server-db-{{ $db->id }}"
                :wire-target="$rowTargets"
                class="group relative flex items-start gap-3 py-3 pl-5 pr-3 transition-colors hover:bg-brand-sand/15 sm:gap-4 sm:pl-6 sm:pr-4"
            >
                <span class="absolute bottom-0 left-0 top-0 w-1 bg-emerald-500" aria-hidden="true"></span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <h4 class="truncate font-mono text-sm font-semibold text-brand-ink">{{ $db->name }}</h4>
                        <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/50 px-1.5 py-0.5 text-[11px] text-brand-ink/80 ring-1 ring-brand-ink/10">
                            <x-heroicon-m-circle-stack class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                            {{ $engineLabel }}
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-md bg-white px-1.5 py-0.5 text-[11px] text-brand-ink/80 ring-1 ring-brand-ink/10">
                            @if ($db->engine === 'sqlite')
                                <x-heroicon-m-document class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                                {{ __('file-based') }}
                            @else
                                <x-heroicon-m-user class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                                {{ __('1 user') }}
                            @endif
                        </span>
                    </div>
                    @if ($db->engine === 'sqlite' && filled($db->host))
                        <p class="mt-0.5 break-all font-mono text-[11px] text-brand-mist">{{ $db->host }}</p>
                    @endif
                    @if (filled($db->description))
                        <p class="mt-0.5 text-[11px] leading-relaxed text-brand-moss">{{ $db->description }}</p>
                    @endif
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-1.5 self-center">
                    <button
                        type="button"
                        wire:click="openEditDatabaseModal(@js($db->id))"
                        wire:loading.attr="disabled"
                        wire:target="openEditDatabaseModal"
                        class="{{ $rowBtn }}"
                    >
                        <x-heroicon-m-pencil-square class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="openEditDatabaseModal" aria-hidden="true" />
                        <span wire:loading wire:target="openEditDatabaseModal" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                            <x-spinner variant="forest" size="sm" />
                        </span>
                        {{ __('Edit') }}
                    </button>
                    @if ($db->engine === 'sqlite')
                        <button
                            type="button"
                            wire:click="openSqliteConsoleModal(@js($db->id))"
                            wire:loading.attr="disabled"
                            wire:target="openSqliteConsoleModal"
                            class="{{ $rowBtn }}"
                        >
                            <x-heroicon-m-command-line class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="openSqliteConsoleModal" aria-hidden="true" />
                            <span wire:loading wire:target="openSqliteConsoleModal" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                <x-spinner variant="forest" size="sm" />
                            </span>
                            {{ __('Run SQL') }}
                        </button>
                    @endif
                    <button
                        type="button"
                        wire:click="openBackupModal(@js($db->id))"
                        wire:loading.attr="disabled"
                        wire:target="openBackupModal"
                        class="{{ $rowBtn }}"
                    >
                        <x-heroicon-m-cloud-arrow-down class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="openBackupModal" aria-hidden="true" />
                        <span wire:loading wire:target="openBackupModal" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                            <x-spinner variant="forest" size="sm" />
                        </span>
                        {{ __('Backup') }}
                    </button>
                    <x-dropdown align="right" width="w-56">
                        <x-slot name="trigger">
                            <button
                                type="button"
                                class="{{ $rowBtn }}"
                                aria-label="{{ __('More database actions') }}"
                            >
                                {{ __('More') }}
                                <x-heroicon-m-chevron-down class="h-3 w-3 shrink-0" aria-hidden="true" />
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            @if ($db->engine !== 'sqlite')
                                <button
                                    type="button"
                                    wire:click="openCredentialsModal(@js($db->id))"
                                    class="group flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-key class="h-4 w-4 shrink-0 text-brand-moss group-hover:text-brand-forest" />
                                    <span>{{ __('See credentials') }}</span>
                                </button>
                            @endif
                            @if ($db->engine === 'sqlite' && filled($db->host))
                                <button
                                    type="button"
                                    x-data="{ copied: false }"
                                    @click="navigator.clipboard.writeText(@js($db->host)); copied = true; clearTimeout(window._dplyDbPathCopyT); window._dplyDbPathCopyT = setTimeout(() => copied = false, 2000)"
                                    class="group flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-folder class="h-4 w-4 shrink-0 text-brand-moss group-hover:text-brand-forest" />
                                    <span x-show="!copied" x-cloak>{{ __('Copy file path') }}</span>
                                    <span x-show="copied" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                                </button>
                            @endif
                        </x-slot>
                    </x-dropdown>
                </div>
            </x-workspace-table-row>
        @endforeach
    </ul>
@endif
