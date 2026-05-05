@if ($databases->isNotEmpty())
    <ul class="space-y-3">
        @foreach ($databases->sortBy('name') as $db)
            <li class="relative flex gap-0 rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
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
                        <div class="flex shrink-0">
                            <x-dropdown align="right" width="w-56">
                                <x-slot name="trigger">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        {{ __('Actions') }}
                                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <button
                                        type="button"
                                        wire:click="openEditDatabaseModal(@js($db->id))"
                                        class="group flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-pencil-square class="h-4 w-4 shrink-0 text-brand-moss group-hover:text-brand-forest" />
                                        <span>{{ __('Edit') }}</span>
                                    </button>
                                    @if ($db->engine === 'sqlite')
                                        <button
                                            type="button"
                                            wire:click="openSqliteConsoleModal(@js($db->id))"
                                            class="group flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-command-line class="h-4 w-4 shrink-0 text-brand-moss group-hover:text-brand-forest" />
                                            <span>{{ __('Run SQL') }}</span>
                                        </button>
                                    @endif
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
                                    <button
                                        type="button"
                                        wire:click="openConnectionUrlModal(@js($db->id))"
                                        class="group flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-moss group-hover:text-brand-forest" />
                                        <span>{{ __('Show Connection URL') }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        x-data="{ copied: false }"
                                        @click="navigator.clipboard.writeText(@js($db->connectionUrl())); copied = true; clearTimeout(window._dplyDbCopyT); window._dplyDbCopyT = setTimeout(() => copied = false, 2000)"
                                        class="group flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-clipboard-document class="h-4 w-4 shrink-0 text-brand-moss group-hover:text-brand-forest" />
                                        <span x-show="!copied" x-cloak>{{ __('Copy Connection URL') }}</span>
                                        <span x-show="copied" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="queueExport(@js($db->id))"
                                        class="group flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-cloud-arrow-down class="h-4 w-4 shrink-0 text-brand-moss group-hover:text-brand-forest" />
                                        <span>{{ __('Backup') }}</span>
                                    </button>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
