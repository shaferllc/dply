@if ($databases->isNotEmpty())
    <ul class="space-y-3">
        @foreach ($databases->sortBy('name') as $db)
            @php
                $rowTargets = implode(',', array_filter([
                    'openBackupModal',
                    'openEditDatabaseModal',
                    $db->engine === 'sqlite' ? 'openSqliteConsoleModal' : null,
                ]));
            @endphp
            <x-workspace-table-row
                wire:key="server-db-{{ $db->id }}"
                :wire-target="$rowTargets"
                class="relative flex gap-0 rounded-2xl border border-brand-ink/10 bg-white shadow-sm"
            >
                <div class="w-1 shrink-0 rounded-l-2xl bg-emerald-500" aria-hidden="true"></div>
                <div class="min-w-0 flex-1 p-4 sm:p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="font-mono text-base font-semibold text-brand-ink">{{ $db->name }}</p>
                            <p class="mt-1 text-sm text-brand-moss">
                                @php
                                    $engineLabel = match ($db->engine) {
                                        'postgres' => 'PostgreSQL',
                                        'sqlite' => 'SQLite',
                                        default => 'MySQL',
                                    };
                                    $engineSubline = $db->engine === 'sqlite'
                                        ? __(':engine · file-based', ['engine' => $engineLabel])
                                        : __(':engine · 1 database user', ['engine' => $engineLabel]);
                                @endphp
                                {{ $engineSubline }}
                            </p>
                            @if ($db->engine === 'sqlite' && filled($db->host))
                                <p class="mt-1 break-all font-mono text-xs text-brand-mist">{{ $db->host }}</p>
                            @endif
                            @if (filled($db->description))
                                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ $db->description }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2 self-start">
                            <button
                                type="button"
                                wire:click="openEditDatabaseModal(@js($db->id))"
                                wire:loading.attr="disabled"
                                wire:target="openEditDatabaseModal"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <x-heroicon-o-pencil-square class="h-4 w-4" wire:loading.remove wire:target="openEditDatabaseModal" />
                                <span wire:loading wire:target="openEditDatabaseModal" class="inline-flex h-4 w-4 items-center justify-center">
                                    <x-spinner variant="forest" size="sm" />
                                </span>
                                <span wire:loading.remove wire:target="openEditDatabaseModal">{{ __('Edit') }}</span>
                            </button>
                            @if ($db->engine === 'sqlite')
                                <button
                                    type="button"
                                    wire:click="openSqliteConsoleModal(@js($db->id))"
                                    wire:loading.attr="disabled"
                                    wire:target="openSqliteConsoleModal"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <x-heroicon-o-command-line class="h-4 w-4" wire:loading.remove wire:target="openSqliteConsoleModal" />
                                    <span wire:loading wire:target="openSqliteConsoleModal" class="inline-flex h-4 w-4 items-center justify-center">
                                        <x-spinner variant="forest" size="sm" />
                                    </span>
                                    <span wire:loading.remove wire:target="openSqliteConsoleModal">{{ __('Run SQL') }}</span>
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="openBackupModal(@js($db->id))"
                                wire:loading.attr="disabled"
                                wire:target="openBackupModal"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <x-heroicon-o-cloud-arrow-down class="h-4 w-4" wire:loading.remove wire:target="openBackupModal" />
                                <span wire:loading wire:target="openBackupModal" class="inline-flex h-4 w-4 items-center justify-center">
                                    <x-spinner variant="forest" size="sm" />
                                </span>
                                <span>{{ __('Backup') }}</span>
                            </button>
                            <x-dropdown align="right" width="w-56">
                                <x-slot name="trigger">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                        aria-label="{{ __('More database actions') }}"
                                    >
                                        {{ __('More') }}
                                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
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
                    </div>
                </div>
            </x-workspace-table-row>
        @endforeach
    </ul>
@endif
