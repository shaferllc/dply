@php
    $engineRunning = $engineRow && $engineRow->status === \App\Models\ServerDatabaseEngine::STATUS_RUNNING;
    $enginePort = $engineRow?->port ?? \App\Models\ServerDatabaseEngine::defaultPortFor($engine);
    $anyExposed = $engineDatabases->contains(fn ($db) => (bool) $db->remote_access);
@endphp

@if (! $showEngineWorkspace)
    <div class="{{ $card }} overflow-hidden px-6 py-6 sm:px-8">
        <x-empty-state
            borderless
            icon="heroicon-o-share"
            tone="sage"
            :title="__('Networking unavailable')"
            :description="__('Install :engine on Overview first — then configure per-database remote access here.', ['engine' => $dbEngineInfoForTab['label']])"
        >
            <x-slot:actions>
                <button type="button" wire:click="setEngineSubtab('overview')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90">
                    {{ __('Go to Overview') }}
                </button>
            </x-slot:actions>
        </x-empty-state>
    </div>
@elseif ($engineDatabases->isEmpty())
    <div class="{{ $card }} overflow-hidden">
        <div class="px-6 py-6 sm:px-8">
            <x-empty-state
                borderless
                icon="heroicon-o-share"
                tone="sage"
                :title="__('No databases yet')"
                :description="__('Create a database on the Databases tab first — each database gets its own remote access controls here.')"
            >
                <x-slot:actions>
                    <button type="button" wire:click="setEngineSubtab('databases')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90">
                        <x-heroicon-o-circle-stack class="h-4 w-4" aria-hidden="true" />
                        {{ __('Go to Databases') }}
                    </button>
                </x-slot:actions>
            </x-empty-state>
        </div>
    </div>
@else
    {{-- Engine-level status banner --}}
    <div class="{{ $card }} overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Networking') }}</p>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Per-database remote access') }}</h3>
                </div>
            </div>
            @if ($anyExposed)
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-medium text-amber-800 ring-1 ring-amber-200">
                    <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                    {{ __(':n exposed', ['n' => $engineDatabases->where('remote_access', true)->count()]) }}
                </span>
            @else
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-medium text-emerald-700 ring-1 ring-emerald-200">
                    <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    {{ __('Localhost only') }}
                </span>
            @endif
        </div>
        <div class="px-6 py-5 sm:px-8">
            <p class="max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Each database can be opened to a specific CIDR — a VPC subnet like 10.0.0.0/8 to allow only your own servers, or a single app server like 203.0.113.5/32. A trusted source is required; leave remote access off to keep the port closed. Dply writes the pg_hba rule (or MySQL GRANT) for that database only and opens the UFW rule for port :port to that source alone.', ['port' => $enginePort]) }}
            </p>
            @if ($anyExposed && ! $engineRunning)
                <p class="mt-2 max-w-2xl text-sm text-amber-700">
                    {{ __(':engine is not running — exposed databases are unreachable until the engine is started.', ['engine' => $dbEngineInfoForTab['label']]) }}
                </p>
            @endif
        </div>
    </div>

    {{-- Per-database rows --}}
    @foreach ($engineDatabases as $db)
        @php
            $dbRemote = (bool) $db->remote_access;
            $dbCidr = $db->allowed_from ?: __('no source set');
        @endphp
        <div class="{{ $card }} overflow-hidden" wire:key="db-networking-{{ $db->id }}">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/10 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-circle-stack class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-mono text-sm font-semibold text-brand-ink">{{ $db->name }}</p>
                        @if ($db->username)
                            <p class="truncate font-mono text-[11px] text-brand-mist">{{ $db->username }}</p>
                        @endif
                    </div>
                </div>
                @if ($dbRemote)
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-medium text-amber-800 ring-1 ring-amber-200">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                        {{ __('Exposed · :cidr', ['cidr' => $dbCidr]) }}
                    </span>
                @else
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-sand/60 px-2.5 py-1 text-[11px] font-medium text-brand-moss ring-1 ring-brand-ink/10">
                        {{ __('Localhost only') }}
                    </span>
                @endif
            </div>

            <div class="px-6 py-5 sm:px-8">
                @if ($dbRemote)
                    <p class="text-sm text-brand-moss">
                        {{ __('Connections to :name from :cidr on port :port are permitted.', ['name' => $db->name, 'cidr' => $dbCidr, 'port' => $enginePort]) }}
                    </p>
                    <div class="mt-4">
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('toggleDatabaseNetworking', ['{{ $db->id }}', false], @js(__('Disable remote access for :name?', ['name' => $db->name])), @js(__('The pg_hba rule for this database will be removed and active remote connections will be dropped.')), @js(__('Disable')), true)"
                            wire:loading.attr="disabled"
                            wire:target="toggleDatabaseNetworking('{{ $db->id }}', false)"
                            class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-800 hover:bg-amber-100 disabled:opacity-50"
                        >
                            <x-heroicon-o-lock-closed class="h-4 w-4" />
                            <span wire:loading.remove wire:target="toggleDatabaseNetworking('{{ $db->id }}', false)">{{ __('Disable remote access') }}</span>
                            <span wire:loading wire:target="toggleDatabaseNetworking('{{ $db->id }}', false)">{{ __('Working…') }}</span>
                        </button>
                    </div>
                @else
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex min-w-[14rem] flex-col gap-1">
                            <label for="db_networking_cidr_{{ $db->id }}" class="text-xs font-semibold text-brand-ink">
                                {{ __('Allow from (CIDR)') }}
                            </label>
                            <input
                                id="db_networking_cidr_{{ $db->id }}"
                                type="text"
                                wire:model="db_networking_allowed_from.{{ $db->id }}"
                                placeholder="10.0.0.0/8"
                                class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-forest focus:ring-1 focus:ring-brand-forest"
                            />
                            @error('db_networking_allowed_from.'.$db->id)
                                <p class="text-xs text-rose-700">{{ $message }}</p>
                            @enderror
                            <p class="text-[11px] text-brand-moss">{{ __('Required · 10.0.0.0/8 for a VPC · 203.0.113.5/32 for one app server') }}</p>
                        </div>
                        <button
                            type="button"
                            wire:click="toggleDatabaseNetworking('{{ $db->id }}', true)"
                            wire:loading.attr="disabled"
                            wire:target="toggleDatabaseNetworking('{{ $db->id }}', true)"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                        >
                            <x-heroicon-o-globe-alt class="h-4 w-4" />
                            <span wire:loading.remove wire:target="toggleDatabaseNetworking('{{ $db->id }}', true)">{{ __('Enable remote access') }}</span>
                            <span wire:loading wire:target="toggleDatabaseNetworking('{{ $db->id }}', true)">{{ __('Queueing…') }}</span>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
@endif
