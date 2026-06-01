<x-server-workspace-layout
    :server="$server"
    active="networking"
    :title="__('Networking')"
    :description="__('All servers in your workspace — their private IPs, running services, and which databases are open to the network.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div class="space-y-6">

        {{-- ─── ALL SERVERS OVERVIEW ──────────────────────────────────────── --}}
        @php
            $allServers = $peerServers->prepend($server);
        @endphp

        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Workspace servers') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Server network map') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Every server in this workspace. Use private IPs in connection strings so traffic stays off the public internet.') }}
                    </p>
                </div>
                @php $hasHetznerWithoutNetwork = $allServers->contains(fn ($s) => $s->provider->value === 'hetzner' && ! $s->private_ip_address); @endphp
                @if ($hasHetznerWithoutNetwork)
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'create-network-modal')"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                    >
                        <x-heroicon-o-plus class="h-4 w-4" />
                        {{ __('Create private network') }}
                    </button>
                @endif
            </div>

            <div class="divide-y divide-brand-ink/5">
                @foreach ($allServers as $s)
                    @php
                        $isCurrent = $s->id === $server->id;
                        $sEngines = $databaseEnginesByServer->get($s->id, collect());
                        $sDbs = $databasesByServer->get($s->id, collect());
                        $sCaches = $cacheServicesByServer->get($s->id, collect());
                        $hasServices = $sEngines->isNotEmpty() || $sCaches->isNotEmpty();
                    @endphp
                    <div class="px-6 py-4 sm:px-7 {{ $isCurrent ? 'bg-brand-sage/5' : '' }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            {{-- Server identity --}}
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl {{ $isCurrent ? 'bg-brand-sage text-white ring-brand-sage/30' : 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' }} ring-1">
                                    <x-heroicon-o-server class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="truncate text-sm font-semibold text-brand-ink">{{ $s->name }}</p>
                                        @if ($isCurrent)
                                            <span class="rounded-full bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-semibold text-brand-forest">{{ __('this server') }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 font-mono text-[11px] text-brand-mist">
                                        @if ($s->private_ip_address)
                                            <span class="flex items-center gap-1">
                                                <x-heroicon-m-lock-closed class="h-2.5 w-2.5 text-emerald-500" aria-hidden="true" />
                                                {{ $s->private_ip_address }}
                                            </span>
                                        @endif
                                        @if ($s->ip_address)
                                            <span>{{ $s->ip_address }}</span>
                                        @endif
                                        @if ($s->region)
                                            <span class="font-sans text-[10px]">{{ $s->region }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Service badges --}}
                            @if ($hasServices)
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @foreach ($sEngines as $eng)
                                        @php
                                            $engDbs = $sDbs->where('engine', $eng->engine);
                                            $exposedCount = $engDbs->where('remote_access', true)->count();
                                            $totalCount = $engDbs->count();
                                        @endphp
                                        <span @class([
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1',
                                            'bg-amber-50 text-amber-800 ring-amber-200' => $exposedCount > 0,
                                            'bg-brand-sand/60 text-brand-moss ring-brand-ink/10' => $exposedCount === 0,
                                        ])>
                                            <x-heroicon-m-circle-stack class="h-2.5 w-2.5" aria-hidden="true" />
                                            {{ \App\Support\Servers\DatabaseEngineInfo::for($eng->engine)['label'] ?? ucfirst($eng->engine) }}
                                            @if ($totalCount > 0)
                                                · {{ __(':port', ['port' => $eng->port]) }}
                                            @endif
                                            @if ($exposedCount > 0)
                                                · {{ $exposedCount }}/{{ $totalCount }} {{ __('exposed') }}
                                            @endif
                                        </span>
                                    @endforeach
                                    @foreach ($sCaches as $cache)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                                            <x-heroicon-m-bolt class="h-2.5 w-2.5" aria-hidden="true" />
                                            {{ ucfirst($cache->engine) }} · {{ $cache->port }}
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-[11px] text-brand-mist">{{ __('No tracked services') }}</span>
                            @endif
                        </div>

                        {{-- Connection strings for exposed databases --}}
                        @if ($sDbs->where('remote_access', true)->isNotEmpty())
                            <div class="mt-3 space-y-1.5">
                                @foreach ($sDbs->where('remote_access', true) as $db)
                                    @php
                                        $connHost = $s->private_ip_address ?? $s->ip_address ?? 'unknown';
                                        $connPort = $sEngines->firstWhere('engine', $db->engine)?->port ?? \App\Models\ServerDatabaseEngine::defaultPortFor($db->engine);
                                        $connStr = "host={$connHost} port={$connPort} dbname={$db->name} user={$db->username}";
                                    @endphp
                                    <div class="flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                                        <x-heroicon-o-link class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                        <code class="min-w-0 flex-1 truncate font-mono text-[11px] text-brand-ink">{{ $connStr }}</code>
                                        <span class="shrink-0 rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 ring-1 ring-amber-200">
                                            {{ $db->allowed_from ?? '0.0.0.0/0' }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if (! $s->private_ip_address)
                            @if ($s->provider->value === 'hetzner')
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <span class="text-[11px] text-brand-mist">{{ __('No private network') }}</span>
                                    <span class="text-[11px] text-brand-mist">·</span>
                                    <button
                                        type="button"
                                        x-on:click="$dispatch('open-modal', 'create-network-modal')"
                                        class="text-[11px] font-medium text-brand-sage hover:underline"
                                    >{{ __('Create network') }}</button>
                                    <span class="text-[11px] text-brand-mist">·</span>
                                    <button
                                        type="button"
                                        x-data
                                        x-on:click="$dispatch('open-modal', 'attach-network-modal-{{ $s->id }}')"
                                        class="text-[11px] font-medium text-brand-sage hover:underline"
                                    >{{ __('Attach to existing') }}</button>
                                </div>
                            @else
                                <p class="mt-2 text-[11px] text-brand-mist">
                                    {{ __('No private IP — DigitalOcean VPCs must be set at create time.') }}
                                </p>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ─── THIS SERVER: PER-DATABASE ACCESS CONTROLS ─────────────────── --}}
        @if ($databaseEngines->isNotEmpty())
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('This server') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Per-database remote access') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Open individual databases to a CIDR — use a VPC subnet like 10.0.0.0/8 to allow only your own servers, or 0.0.0.0/0 for public access. Dply writes the pg_hba rule and opens the UFW port.') }}
                        </p>
                    </div>
                </div>

                @foreach ($databaseEngines as $engineRow)
                    @php
                        $dbs = $databasesByEngine->get($engineRow->engine, collect());
                        $engineLabel = \App\Support\Servers\DatabaseEngineInfo::for($engineRow->engine)['label'] ?? ucfirst($engineRow->engine);
                    @endphp
                    @if ($dbs->isNotEmpty())
                        <div class="border-b border-brand-ink/5 px-6 py-3 sm:px-7">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ $engineLabel }} · {{ __('port :port', ['port' => $engineRow->port]) }}</p>
                        </div>
                        <div class="divide-y divide-brand-ink/5">
                            @foreach ($dbs as $db)
                                @php $dbRemote = (bool) $db->remote_access; @endphp
                                <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 sm:px-7" wire:key="net-db-{{ $db->id }}">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate font-mono text-sm font-semibold text-brand-ink">{{ $db->name }}</p>
                                            @if ($db->username)
                                                <p class="truncate font-mono text-[11px] text-brand-mist">{{ $db->username }}</p>
                                            @endif
                                        </div>
                                        @if ($dbRemote)
                                            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-800 ring-1 ring-amber-200">
                                                <span aria-hidden="true" class="inline-block h-1 w-1 rounded-full bg-amber-500"></span>
                                                {{ $db->allowed_from ?? '0.0.0.0/0' }}
                                            </span>
                                        @else
                                            <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-medium text-brand-moss ring-1 ring-brand-ink/10">
                                                {{ __('localhost') }}
                                            </span>
                                        @endif
                                    </div>

                                    @if ($dbRemote)
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('toggleDatabaseNetworking', ['{{ $db->id }}', false], @js(__('Disable remote access for :name?', ['name' => $db->name])), @js(__('The pg_hba rule (or MySQL GRANT) for this database will be removed.')), @js(__('Disable')), true)"
                                            wire:loading.attr="disabled"
                                            wire:target="toggleDatabaseNetworking('{{ $db->id }}', false)"
                                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-100 disabled:opacity-50"
                                        >
                                            <x-heroicon-o-lock-closed class="h-3.5 w-3.5" />
                                            <span wire:loading.remove wire:target="toggleDatabaseNetworking('{{ $db->id }}', false)">{{ __('Disable') }}</span>
                                            <span wire:loading wire:target="toggleDatabaseNetworking('{{ $db->id }}', false)">{{ __('Working…') }}</span>
                                        </button>
                                    @else
                                        <div class="flex flex-wrap items-center gap-2">
                                            <input
                                                type="text"
                                                wire:model="db_networking_allowed_from.{{ $db->id }}"
                                                placeholder="10.0.0.0/8"
                                                class="w-36 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-forest focus:ring-1 focus:ring-brand-forest"
                                            />
                                            @error('db_networking_allowed_from.'.$db->id)
                                                <p class="w-full text-xs text-rose-700">{{ $message }}</p>
                                            @enderror
                                            <button
                                                type="button"
                                                wire:click="toggleDatabaseNetworking('{{ $db->id }}', true)"
                                                wire:loading.attr="disabled"
                                                wire:target="toggleDatabaseNetworking('{{ $db->id }}', true)"
                                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                                            >
                                                <x-heroicon-o-globe-alt class="h-3.5 w-3.5" />
                                                <span wire:loading.remove wire:target="toggleDatabaseNetworking('{{ $db->id }}', true)">{{ __('Expose') }}</span>
                                                <span wire:loading wire:target="toggleDatabaseNetworking('{{ $db->id }}', true)">{{ __('…') }}</span>
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach

                @if ($databaseEngines->isNotEmpty() && $databasesByEngine->flatten()->isEmpty())
                    <div class="px-6 py-5 sm:px-7">
                        <p class="text-sm text-brand-moss">{{ __('No databases yet. Create one from the Databases workspace.') }}</p>
                    </div>
                @endif
            </section>
        @endif

        {{-- ─── CACHE ENGINES (read-only pointer) ─────────────────────────── --}}
        @foreach ($cacheServices as $cache)
            @php
                $cacheLabel = match ($cache->engine) {
                    'redis' => 'Redis', 'valkey' => 'Valkey',
                    'keydb' => 'KeyDB', 'dragonfly' => 'Dragonfly',
                    default => ucfirst($cache->engine),
                };
            @endphp
            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-5 sm:px-7">
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-brand-ink">{{ $cacheLabel }} <span class="font-mono text-xs font-normal text-brand-mist">· port {{ $cache->port }}</span></p>
                            <p class="text-xs text-brand-moss">{{ __('Remote access is configured in the Caches workspace.') }}</p>
                        </div>
                    </div>
                    <a href="{{ route('servers.caches', $server) }}" wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        {{ __('Open Caches workspace') }}
                    </a>
                </div>
            </section>
        @endforeach

    </div>

    @include('livewire.partials.confirm-action-modal')

    {{-- Create private network modal --}}
    <x-modal name="create-network-modal" max-width="lg" focusable>
        <div class="bg-white">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hetzner') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create private network') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Dply creates the network in your Hetzner account and attaches every selected server. Private IPs appear here once assigned (~30 s).') }}
                    </p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'create-network-modal')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <div class="space-y-5 p-6">
                <div class="grid gap-5 sm:grid-cols-2 sm:items-end">
                    <div>
                        <x-input-label for="new_network_name" :value="__('Network name')" />
                        <x-text-input id="new_network_name" wire:model="new_network_name" class="mt-1 block w-full" placeholder="e.g. dply-private" />
                        @error('new_network_name') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-input-label for="new_network_ip_range" :value="__('IP range (CIDR)')" />
                        <x-text-input id="new_network_ip_range" wire:model="new_network_ip_range" class="mt-1 block w-full font-mono" placeholder="10.0.0.0/8" />
                        @error('new_network_ip_range') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-sm font-medium text-brand-ink">{{ __('Attach these servers') }}</p>
                    @error('new_network_server_ids') <p class="mb-2 text-xs text-rose-700">{{ $message }}</p> @enderror
                    <div class="space-y-2">
                        @foreach ($allServers->where('provider.value', 'hetzner') as $s)
                            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 hover:bg-brand-sand/20">
                                <input
                                    type="checkbox"
                                    wire:model="new_network_server_ids"
                                    value="{{ $s->id }}"
                                    class="rounded border-brand-ink/30 text-brand-forest focus:ring-brand-sage"
                                />
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $s->name }}
                                        @if ($s->id === $server->id)
                                            <span class="ml-1 rounded-full bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-medium text-brand-forest">{{ __('this server') }}</span>
                                        @endif
                                    </p>
                                    <p class="font-mono text-[11px] text-brand-mist">{{ $s->ip_address }} · {{ $s->region }}</p>
                                </div>
                                @if ($s->private_ip_address)
                                    <span class="ml-auto text-[11px] text-emerald-600">{{ __('already has private IP') }}</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-4">
                <button type="button" x-on:click="$dispatch('close-modal', 'create-network-modal')" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                    {{ __('Cancel') }}
                </button>
                <button
                    type="button"
                    wire:click="createNetwork"
                    wire:loading.attr="disabled"
                    wire:target="createNetwork"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="createNetwork">{{ __('Create network') }}</span>
                    <span wire:loading wire:target="createNetwork" class="inline-flex items-center gap-2">
                        <x-spinner variant="white" size="sm" />
                        {{ __('Creating…') }}
                    </span>
                </button>
            </div>
        </div>
    </x-modal>

    {{-- Attach-to-existing-network modals (one per Hetzner server without private IP) --}}
    @foreach ($allServers->where('provider.value', 'hetzner')->where('private_ip_address', null) as $s)
        <x-modal name="attach-network-modal-{{ $s->id }}" max-width="sm" focusable>
            <div class="bg-white">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Attach :name to network', ['name' => $s->name]) }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Enter an existing Hetzner Network ID to attach this server.') }}</p>
                </div>
                <div class="space-y-4 p-6">
                    <div>
                        <x-input-label for="attach_net_inline_{{ $s->id }}" :value="__('Network ID')" />
                        <x-text-input
                            id="attach_net_inline_{{ $s->id }}"
                            wire:model="attach_network_id.{{ $s->id }}"
                            class="mt-1 block w-full font-mono"
                            placeholder="e.g. 1234567"
                        />
                        @error('attach_network_id.'.$s->id) <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-4">
                    <button type="button" x-on:click="$dispatch('close-modal', 'attach-network-modal-{{ $s->id }}')" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="attachToNetwork('{{ $s->id }}')"
                        wire:loading.attr="disabled"
                        wire:target="attachToNetwork('{{ $s->id }}')"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="attachToNetwork('{{ $s->id }}')">{{ __('Attach') }}</span>
                        <span wire:loading wire:target="attachToNetwork('{{ $s->id }}')">{{ __('Attaching…') }}</span>
                    </button>
                </div>
            </div>
        </x-modal>
    @endforeach

</x-server-workspace-layout>
