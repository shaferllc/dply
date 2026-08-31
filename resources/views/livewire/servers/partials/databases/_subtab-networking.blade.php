@php
    $engineRunning = $engineRow && $engineRow->status === \App\Models\ServerDatabaseEngine::STATUS_RUNNING;
    $enginePort = $engineRow?->port ?? \App\Models\ServerDatabaseEngine::defaultPortFor($engine);
    $anyExposed = $engineDatabases->contains(fn ($db) => (bool) $db->remote_access);
    // ClickHouse (and Mongo) have no per-database grant concept — pg_hba lines and
    // host-scoped GRANTs have no equivalent — so their only exposure control is
    // engine-level. That control had a backing method and job but was never wired
    // to a view, which left those engines with no way to be opened at all.
    $supportsPerDb = \App\Support\Servers\DatabaseEngineInstallScripts::supportsPerDatabaseRemoteAccess($engine);
    $engineExposed = (bool) ($engineRow?->remote_access ?? false);
    $engineCidr = trim((string) ($engineRow?->allowed_from ?? '')) ?: __('no source set');
@endphp

@if (! $showEngineWorkspace)
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-share"
            :title="__('Per-database remote access')"
            :note="__('Each database gets its own remote-access controls once the engine is installed.')"
            class="border-b border-brand-ink/10"
        />
        <div class="px-4 py-5 sm:px-5">
        <x-empty-state
            borderless
            compact
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
    </div>
@elseif (! $supportsPerDb)
    {{-- Engine-level exposure: the whole listener opens to one CIDR, and the
         firewall is the boundary (there is no per-database grant to scope). --}}
    <div class="{{ $card }}" x-data="{ explain: false }">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-share"
            :title="__('Engine remote access')"
            :tone="$engineExposed ? 'amber' : null"
            :note="__('Open :engine to one trusted CIDR, or leave it closed.', ['engine' => $dbEngineInfoForTab['label']])"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <button
                    type="button"
                    x-on:click="explain = ! explain"
                    x-bind:aria-expanded="explain.toString()"
                    class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-2xs font-semibold text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink"
                >
                    <x-heroicon-o-information-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('How it works') }}
                </button>
                @if ($engineExposed)
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-50 px-2 py-0.5 text-2xs font-semibold text-amber-800 ring-1 ring-amber-200">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                        {{ __('Exposed') }} <span class="font-mono font-normal">{{ $engineCidr }}</span>
                    </span>
                @else
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-2xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        {{ __('Localhost only') }}
                    </span>
                @endif
            </x-slot:actions>
        </x-workspace-panel-head>

        <div x-show="explain" x-collapse x-cloak class="border-b border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5 text-xs leading-relaxed text-brand-moss sm:px-4">
            <p>{{ __(':engine exposes access for the whole server, not per database — there is no per-database grant to scope, so the firewall is the boundary. Enabling binds the listener to all interfaces and opens UFW on port :port to your CIDR alone.', ['engine' => $dbEngineInfoForTab['label'], 'port' => $enginePort]) }}</p>
            <p class="mt-1.5">{{ __('Prefer a private range like 10.0.0.0/8 so traffic stays off the public internet. Shipping logs from other servers does not need this at all — the dply Logs aggregator takes an mTLS link on its own port and writes to :engine locally.', ['engine' => $dbEngineInfoForTab['label']]) }}</p>
        </div>

        @unless ($engineRunning)
            <p class="flex items-center gap-1.5 border-b border-brand-ink/10 bg-amber-50/60 px-3 py-2 text-xs text-amber-800 sm:px-4">
                <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __(':engine is not running — start it before changing remote access.', ['engine' => $dbEngineInfoForTab['label']]) }}
            </p>
        @endunless

        <div class="px-3 py-3 sm:px-4">
            @if ($engineExposed)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <p class="min-w-0 flex-1 text-xs text-brand-moss">
                        {{ __('Reachable from :cidr on port :port.', ['cidr' => $engineCidr, 'port' => $enginePort]) }}
                    </p>
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('toggleDatabaseEngineRemoteAccess', [@js($engine), false], @js(__('Close remote access for :engine?', ['engine' => $dbEngineInfoForTab['label']])), @js(__('The listener returns to localhost only and the firewall rule is removed. Anything connecting from off-box will lose access.')), @js(__('Close')), true)"
                        wire:loading.attr="disabled"
                        wire:target="toggleDatabaseEngineRemoteAccess"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 disabled:opacity-50"
                    >
                        <x-heroicon-o-lock-closed class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span wire:loading.remove wire:target="toggleDatabaseEngineRemoteAccess">{{ __('Close') }}</span>
                        <span wire:loading wire:target="toggleDatabaseEngineRemoteAccess">{{ __('Working…') }}</span>
                    </button>
                </div>
            @else
                @php $peers = collect($engineRemotePeers ?? []); @endphp

                {{-- Pick servers off the private network. Each becomes its own /32
                     rule, so admitting two hosts never means widening a mask to a
                     range that quietly admits a third. --}}
                @if ($peers->isNotEmpty())
                    <fieldset @disabled(! $engineRunning) class="min-w-0">
                        <legend class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            {{ __('Servers on this private network') }}
                        </legend>
                        <ul class="mt-1.5 divide-y divide-brand-ink/5 overflow-hidden rounded-lg border border-brand-ink/10">
                            @foreach ($peers as $peer)
                                <li>
                                    <label class="flex cursor-pointer items-center gap-2.5 px-2.5 py-2 hover:bg-brand-sand/30">
                                        <input
                                            type="checkbox"
                                            value="{{ $peer->id }}"
                                            wire:model="engine_remote_server_ids"
                                            @disabled(! $engineRunning)
                                            class="h-3.5 w-3.5 shrink-0 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest/40 disabled:cursor-not-allowed"
                                        />
                                        <span class="min-w-0 flex-1 truncate text-xs font-semibold text-brand-ink">{{ $peer->name }}</span>
                                        <span class="shrink-0 font-mono text-2xs text-brand-moss">{{ $peer->private_ip_address }}/32</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        @error('engine_remote_server_ids')
                            <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                    </fieldset>
                @else
                    <p class="text-xs text-brand-moss">
                        {{ __('No other servers on this private network yet — attach one on the Networking workspace, or enter a source CIDR below.') }}
                    </p>
                @endif

                <div class="mt-3 flex flex-wrap items-end gap-x-2 gap-y-1.5 border-t border-brand-ink/10 pt-3">
                    <div class="min-w-0 flex-1">
                        <label for="engine-remote-cidr-{{ $engine }}" class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            {{ $peers->isNotEmpty() ? __('Or a source CIDR') : __('Allowed source CIDR') }}
                        </label>
                        <input
                            id="engine-remote-cidr-{{ $engine }}"
                            type="text"
                            wire:model="remote_access_allowed_from"
                            placeholder="10.0.0.0/8"
                            @disabled(! $engineRunning)
                            class="mt-1 w-full max-w-xs rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-forest focus:ring-1 focus:ring-brand-forest disabled:cursor-not-allowed disabled:bg-brand-sand/40"
                        />
                        <p class="mt-1 text-2xs text-brand-mist">{{ __('Used only when no server is ticked — for sources dply does not know about.') }}</p>
                        @error('remote_access_allowed_from')
                            <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>
                    <button
                        type="button"
                        wire:click="toggleDatabaseEngineRemoteAccess(@js($engine), true)"
                        wire:loading.attr="disabled"
                        wire:target="toggleDatabaseEngineRemoteAccess"
                        @disabled(! $engineRunning)
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span wire:loading.remove wire:target="toggleDatabaseEngineRemoteAccess">{{ __('Expose') }}</span>
                        <span wire:loading wire:target="toggleDatabaseEngineRemoteAccess">{{ __('…') }}</span>
                    </button>
                </div>
            @endif
        </div>
    </div>
@elseif ($engineDatabases->isEmpty())
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-share"
            :title="__('Per-database remote access')"
            :note="__('Each database gets its own remote-access controls once it exists.')"
            class="border-b border-brand-ink/10"
        />
        <div class="px-4 py-5 sm:px-5">
            <x-empty-state
                borderless
                compact
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
    {{-- One card, hairline rows — the merged workspace chrome. Each database
         used to render its own dply-card, so a host with five databases stacked
         five bordered cards for one control each. The long "how it works" prose
         moved out of the dense head (which truncates it to an ellipsis, so the
         part that actually matters — a source is REQUIRED — was unreadable)
         into a collapsible explainer. --}}
    <div class="{{ $card }}" x-data="{ explain: false }">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-share"
            :title="__('Per-database remote access')"
            :tone="$anyExposed ? 'amber' : null"
            :note="__('Open a database to one trusted CIDR, or leave it closed.')"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <button
                    type="button"
                    x-on:click="explain = ! explain"
                    x-bind:aria-expanded="explain.toString()"
                    class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-2xs font-semibold text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink"
                >
                    <x-heroicon-o-information-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('How it works') }}
                </button>
                @if ($anyExposed)
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-50 px-2 py-0.5 text-2xs font-semibold text-amber-800 ring-1 ring-amber-200">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                        {{ __(':n exposed', ['n' => $engineDatabases->where('remote_access', true)->count()]) }}
                    </span>
                @else
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-2xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        {{ __('Localhost only') }}
                    </span>
                @endif
            </x-slot:actions>
        </x-workspace-panel-head>

        <div x-show="explain" x-collapse x-cloak class="border-b border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5 text-xs leading-relaxed text-brand-moss sm:px-4">
            <p>{{ __('A trusted source is required — there is no "open to the world" option. Give a VPC subnet like 10.0.0.0/8 to reach the database from your own servers only, or a single host like 203.0.113.5/32 for one app server.') }}</p>
            <p class="mt-1.5">{{ __('Enabling writes the :rule for that database alone and opens UFW on port :port to that source only. Every other database on this engine stays closed.', ['rule' => $engine === 'postgres' ? __('pg_hba rule') : __('GRANT'), 'port' => $enginePort]) }}</p>
        </div>

        @if ($anyExposed && ! $engineRunning)
            <p class="flex items-center gap-1.5 border-b border-brand-ink/10 bg-amber-50/60 px-3 py-2 text-xs text-amber-800 sm:px-4">
                <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __(':engine is not running — exposed databases are unreachable until the engine is started.', ['engine' => $dbEngineInfoForTab['label']]) }}
            </p>
        @endif

        <ul class="divide-y divide-brand-ink/10">
            @foreach ($engineDatabases as $db)
                @php
                    $dbRemote = (bool) $db->remote_access;
                    $dbCidr = $db->allowed_from ?: __('no source set');
                @endphp
                <li wire:key="db-networking-{{ $db->id }}" class="px-3 py-2.5 sm:px-4">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <x-heroicon-o-circle-stack class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        <p class="truncate font-mono text-sm font-semibold text-brand-ink">{{ $db->name }}</p>
                        @if ($db->username)
                            <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                            <p class="truncate font-mono text-xs text-brand-mist">{{ $db->username }}</p>
                        @endif
                        {{-- Connection details + an ssh -L tunnel for this database.
                             Loopback-only is exactly when an operator needs the
                             tunnel command, so it belongs on this row. --}}
                        @if (\App\Support\Servers\DatabaseWorkspaceEngines::family($db->engine) !== 'sqlite')
                            <button type="button" wire:click="openDatabaseConnect(@js((string) $db->id))"
                                class="ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                <x-heroicon-o-bolt class="h-3.5 w-3.5 text-brand-sage" aria-hidden="true" />
                                {{ __('Connect') }}
                            </button>
                        @endif
                        <span class="{{ \App\Support\Servers\DatabaseWorkspaceEngines::family($db->engine) !== 'sqlite' ? 'shrink-0' : 'ml-auto shrink-0' }}">
                            @if ($dbRemote)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2 py-0.5 text-2xs font-semibold text-amber-800 ring-1 ring-amber-200">
                                    <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                    {{ __('Exposed') }}
                                    <span class="font-mono font-normal">{{ $dbCidr }}</span>
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-2xs font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                                    {{ __('Localhost only') }}
                                </span>
                            @endif
                        </span>
                    </div>

                    <div class="mt-1.5">
                        @if ($dbRemote)
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5">
                                <p class="min-w-0 flex-1 text-xs text-brand-moss">
                                    {{ __('Reachable from :cidr on port :port.', ['cidr' => $dbCidr, 'port' => $enginePort]) }}
                                </p>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('toggleDatabaseNetworking', ['{{ $db->id }}', false], @js(__('Disable remote access for :name?', ['name' => $db->name])), @js(__('The pg_hba rule for this database will be removed and active remote connections will be dropped.')), @js(__('Disable')), true)"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleDatabaseNetworking('{{ $db->id }}', false)"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 disabled:opacity-50"
                                >
                                    <x-heroicon-o-lock-closed class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    <span wire:loading.remove wire:target="toggleDatabaseNetworking('{{ $db->id }}', false)">{{ __('Close') }}</span>
                                    <span wire:loading wire:target="toggleDatabaseNetworking('{{ $db->id }}', false)">{{ __('Working…') }}</span>
                                </button>
                            </div>
                        @else
                            {{-- The control is one line: source in, Enable out. The old
                                 stacked label + helper + oversized button made a single
                                 text field look like a form section. --}}
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5">
                                <label for="db_networking_cidr_{{ $db->id }}" class="shrink-0 text-xs font-semibold text-brand-ink">
                                    {{ __('Allow from') }}
                                </label>
                                <input
                                    id="db_networking_cidr_{{ $db->id }}"
                                    type="text"
                                    wire:model="db_networking_allowed_from.{{ $db->id }}"
                                    placeholder="10.0.0.0/8"
                                    spellcheck="false"
                                    autocomplete="off"
                                    class="w-40 shrink-0 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-forest focus:ring-1 focus:ring-brand-forest"
                                />
                                {{-- Private-range shortcuts: the three RFC1918 blocks cover
                                     almost every "my own servers" answer, and typing a CIDR
                                     from memory is where operators get it wrong. --}}
                                <span class="flex shrink-0 items-center gap-1">
                                    @foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $preset)
                                        <button
                                            type="button"
                                            x-on:click="$wire.set('db_networking_allowed_from.{{ $db->id }}', '{{ $preset }}')"
                                            class="rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 font-mono text-2xs font-semibold text-brand-moss hover:bg-brand-sage/15 hover:text-brand-ink"
                                        >{{ $preset }}</button>
                                    @endforeach
                                </span>
                                <button
                                    type="button"
                                    wire:click="toggleDatabaseNetworking('{{ $db->id }}', true)"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleDatabaseNetworking('{{ $db->id }}', true)"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                                >
                                    <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    <span wire:loading.remove wire:target="toggleDatabaseNetworking('{{ $db->id }}', true)">{{ __('Enable') }}</span>
                                    <span wire:loading wire:target="toggleDatabaseNetworking('{{ $db->id }}', true)">{{ __('Queueing…') }}</span>
                                </button>
                            </div>
                            @error('db_networking_allowed_from.'.$db->id)
                                <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                            @enderror
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif
