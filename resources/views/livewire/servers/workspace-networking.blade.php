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
                                            {{ $db->allowed_from ?: __('no source set') }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($s->private_ip_address)
                            {{-- SSH tunnel helper (#8) --}}
                            @php
                                $tunnelCmds = [];
                                $localPort = 15400;
                                $sshUser = trim((string) $s->ssh_user) !== '' ? trim((string) $s->ssh_user) : 'deploy';
                                foreach ($databaseEnginesByServer->get($s->id, collect()) as $eng) {
                                    $tunnelCmds[] = [
                                        'label' => ($eng->engine === 'postgres' ? 'PostgreSQL' : ucfirst($eng->engine)).' '.$eng->port.' → localhost:'.$localPort,
                                        'command' => "ssh -L {$localPort}:{$s->private_ip_address}:{$eng->port} {$sshUser}@{$s->ip_address}",
                                    ];
                                    $localPort += 100;
                                }
                                foreach ($cacheServicesByServer->get($s->id, collect()) as $cache) {
                                    $tunnelCmds[] = [
                                        'label' => ucfirst($cache->engine).' '.$cache->port.' → localhost:'.$localPort,
                                        'command' => "ssh -L {$localPort}:{$s->private_ip_address}:{$cache->port} {$sshUser}@{$s->ip_address}",
                                    ];
                                    $localPort += 100;
                                }
                            @endphp
                            @if (! empty($tunnelCmds))
                                <x-cli-snippet
                                    :commands="$tunnelCmds"
                                    tone="details"
                                    :summary="__('SSH tunnel commands')"
                                    :intro="__('Run one of these from your machine, then point your client at localhost on the listed port. Tunnels through this server\'s own SSH — for a database scoped to a different host, use the jump-host access below.')"
                                    size="10"
                                    class="mt-3"
                                />
                            @endif
                            @if ($s->provider->value === 'hetzner' && $s->hetzner_network_id)
                                <div class="mt-2 flex items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('detachFromNetwork', ['{{ $s->id }}'], @js(__('Detach :name from network?', ['name' => $s->name])), @js(__('The private IP will be removed. Services using it will lose connectivity.')), @js(__('Detach')), true)"
                                        class="text-[11px] font-medium text-rose-600 hover:underline"
                                    >{{ __('Detach from network') }}</button>
                                </div>
                            @endif
                        @elseif ($s->provider->value === 'hetzner')
                            @if ($s->hetzner_network_id && ! $s->private_ip_address)
                                <div class="mt-3 flex items-center gap-2" wire:poll.5s>
                                    <svg class="h-3.5 w-3.5 animate-spin text-brand-sage" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                    <span class="text-[11px] text-brand-sage">{{ __('Assigning private IP on network :id…', ['id' => $s->hetzner_network_id]) }}</span>
                                </div>
                            @else
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
                                        x-on:click="$dispatch('open-modal', 'attach-network-modal-{{ $s->id }}'); $wire.loadHetznerNetworks()"
                                        class="text-[11px] font-medium text-brand-sage hover:underline"
                                    >{{ __('Attach to existing') }}</button>
                                </div>
                            @endif
                        @elseif ($s->provider->value === 'digitalocean')
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <p class="text-[11px] text-brand-mist">{{ __('No private IP — must be in a VPC.') }}</p>
                                <button
                                    type="button"
                                    wire:click="syncPrivateIp('{{ $s->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="syncPrivateIp('{{ $s->id }}')"
                                    class="text-[11px] font-medium text-brand-sage hover:underline disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="syncPrivateIp('{{ $s->id }}')">{{ __('Sync from DO') }}</span>
                                    <span wire:loading wire:target="syncPrivateIp('{{ $s->id }}')">{{ __('Syncing…') }}</span>
                                </button>
                            </div>
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
                            {{ __('Open individual databases to a specific CIDR — e.g. a VPC subnet like 10.0.0.0/8 or your app server IP like 203.0.113.5/32. A trusted source is required; leave remote access off to keep the port closed. Dply writes the pg_hba rule and opens the UFW port only to that source.') }}
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
                                @php
                                    $dbRemote = (bool) $db->remote_access;
                                    $jumpHosts = $dbRemote
                                        ? \App\Support\Servers\DatabaseJumpHostAccess::eligibleJumpHosts($db, $server, $peerServers)
                                        : collect();
                                @endphp
                                <div class="px-6 py-4 sm:px-7" wire:key="net-db-{{ $db->id }}">
                                  <div class="flex flex-wrap items-center justify-between gap-4">
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
                                                {{ $db->allowed_from ?: __('no source set') }}
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

                                  @if ($dbRemote)
                                    @php
                                        $accessRows = [];
                                        $jumpLocalPort = \App\Support\Servers\DatabaseJumpHostAccess::BASE_LOCAL_PORT;
                                        foreach ($jumpHosts as $jh) {
                                            $jumpCmds = \App\Support\Servers\DatabaseJumpHostAccess::commandsFor($db, $server, $jh, (int) $engineRow->port, $jumpLocalPort);
                                            $accessRows[] = ['label' => __('Tunnel via :name', ['name' => $jh->name]), 'command' => $jumpCmds['tunnel']];
                                            $accessRows[] = ['label' => __('Then connect'), 'command' => $jumpCmds['connect']];
                                            $jumpLocalPort += 10;
                                        }
                                    @endphp
                                    <div class="mt-3 rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-3.5 py-3">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Access via jump host') }}</p>
                                        @if (! empty($accessRows))
                                            <p class="mt-1 text-[11px] leading-relaxed text-brand-moss">
                                                {{ __(':db only accepts connections from :src, so connect through an allowlisted server: run the tunnel, then point your client at localhost on the listed port.', ['db' => $db->name, 'src' => $db->allowed_from]) }}
                                            </p>
                                            <x-cli-snippet :commands="$accessRows" tone="details" :summary="__('Show tunnel commands')" size="10" class="mt-2" />
                                        @else
                                            <p class="mt-1 text-[11px] leading-relaxed text-brand-moss">
                                                {{ __('No server in this organization has a private IP inside :src. Add an allowlisted server\'s private IP to the source above, or connect from a host that is already allowed.', ['src' => $db->allowed_from ?: __('the allowlist')]) }}
                                            </p>
                                        @endif
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

        {{-- ─── CACHE ENGINES — inline expose / lockdown controls (#3) ───── --}}
        @if ($cacheServices->isNotEmpty())
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('This server') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cache remote access') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Expose Redis-family caches to a VPC subnet. An AUTH password must be set first. Full config (port, auth, switch engine) is in the Caches workspace.') }}
                        </p>
                    </div>
                </div>
                <div class="divide-y divide-brand-ink/5">
                    @foreach ($cacheServices as $cache)
                        @php
                            $cacheLabel = match ($cache->engine) {
                                'redis' => 'Redis', 'valkey' => 'Valkey', 'keydb' => 'KeyDB', 'dragonfly' => 'Dragonfly',
                                default => ucfirst($cache->engine),
                            };
                            $isExposed = $cacheExposureByService[$cache->id]['exposed'] ?? false;
                            $exposedCidr = $cacheExposureByService[$cache->id]['rule']?->source ?? null;
                            $supportsExpose = \App\Models\ServerCacheService::engineSupportsAuth($cache->engine);
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 sm:px-7" wire:key="net-cache-{{ $cache->id }}">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $cacheLabel }} <span class="font-mono text-xs font-normal text-brand-mist">· port {{ $cache->port }}</span></p>
                                </div>
                                @if ($isExposed)
                                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-800 ring-1 ring-amber-200">
                                        <span aria-hidden="true" class="inline-block h-1 w-1 rounded-full bg-amber-500"></span>
                                        {{ $exposedCidr ?? 'exposed' }}
                                    </span>
                                @else
                                    <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-medium text-brand-moss ring-1 ring-brand-ink/10">
                                        {{ __('localhost') }}
                                    </span>
                                @endif
                            </div>

                            @if (! $supportsExpose)
                                <span class="text-xs text-brand-mist">{{ __('Expose not supported for :engine.', ['engine' => $cacheLabel]) }}</span>
                            @elseif ($isExposed)
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('lockdownCache', ['{{ $cache->id }}'], @js(__('Lock down :engine?', ['engine' => $cacheLabel])), @js(__('The bind will revert to 127.0.0.1 and the firewall rule will be removed.')), @js(__('Lock down')), true)"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-100"
                                >
                                    <x-heroicon-o-lock-closed class="h-3.5 w-3.5" />
                                    {{ __('Lock down') }}
                                </button>
                            @else
                                <div class="flex flex-wrap items-center gap-2">
                                    <input
                                        type="text"
                                        wire:model="cache_networking_allowed_from.{{ $cache->id }}"
                                        placeholder="10.0.0.0/8"
                                        class="w-36 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-forest focus:ring-1 focus:ring-brand-forest"
                                    />
                                    @error('cache_networking_allowed_from.'.$cache->id)
                                        <p class="w-full text-xs text-rose-700">{{ $message }}</p>
                                    @enderror
                                    <button
                                        type="button"
                                        wire:click="exposeCacheToNetwork('{{ $cache->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="exposeCacheToNetwork('{{ $cache->id }}')"
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                                    >
                                        <x-heroicon-o-globe-alt class="h-3.5 w-3.5" />
                                        <span wire:loading.remove wire:target="exposeCacheToNetwork('{{ $cache->id }}')">{{ __('Expose') }}</span>
                                        <span wire:loading wire:target="exposeCacheToNetwork('{{ $cache->id }}')">{{ __('…') }}</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-brand-ink/5 px-6 py-3 sm:px-7">
                    <a href="{{ route('servers.caches', $server) }}" wire:navigate class="text-[11px] font-medium text-brand-sage hover:underline">
                        {{ __('Full cache configuration →') }}
                    </a>
                </div>
            </section>
        @endif

        {{-- ─── NETWORK ROUTES ─────────────────────────────────────────────── --}}
        @if ($networkId > 0 && $server->provider->value === 'hetzner')
            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-map class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Network routes') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ $networkInfo ? $networkInfo['name'] : 'Network '.$networkId }}
                                @if ($networkInfo)
                                    <span class="ml-1.5 font-mono text-xs font-normal text-brand-mist">{{ $networkInfo['ip_range'] }}</span>
                                @endif
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Routes tell the network fabric where to forward packets for a given destination. Add a route when a server on this network should act as a gateway — e.g. routing your office CIDR through a WireGuard server, or bridging two subnets.') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Existing routes --}}
                @if (! empty($networkRoutes))
                    <div class="divide-y divide-brand-ink/5">
                        @foreach ($networkRoutes as $route)
                            @php
                                $gw = $route['gateway'];
                                $gwServer = $allServers->first(fn ($s) => $s->private_ip_address === $gw);
                            @endphp
                            <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 sm:px-7">
                                <div class="flex min-w-0 items-center gap-4">
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Destination') }}</p>
                                        <code class="font-mono text-sm font-semibold text-brand-ink">{{ $route['destination'] }}</code>
                                    </div>
                                    <x-heroicon-o-arrow-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Gateway') }}</p>
                                        <p class="font-mono text-sm font-semibold text-brand-ink">
                                            {{ $gw }}
                                            @if ($gwServer)
                                                <span class="ml-1 font-sans text-[11px] font-normal text-brand-mist">({{ $gwServer->name }})</span>
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('deleteRoute', [{{ json_encode($route['destination']) }}, {{ json_encode($route['gateway']) }}], @js(__('Delete route :dest?', ['dest' => $route['destination']])), @js(__('Removes this route from the Hetzner network. Traffic to :dest will no longer be forwarded to :gw.', ['dest' => $route['destination'], 'gw' => $route['gateway']])), @js(__('Delete route')), true)"
                                    class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                                >
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="px-6 py-5 sm:px-7">
                        <p class="text-sm text-brand-moss">{{ __('No routes yet. Add one below — most setups don\'t need routes unless you\'re running a VPN gateway or bridging subnets.') }}</p>
                    </div>
                @endif

                {{-- Add route form --}}
                <div class="border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-5 sm:px-7">
                    <p class="mb-4 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Add route') }}</p>
                    <div class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <div>
                            <x-input-label for="route_destination" :value="__('Destination (CIDR)')" />
                            <x-text-input
                                id="route_destination"
                                wire:model="route_destination"
                                class="mt-1 block w-full font-mono"
                                placeholder="192.168.1.0/24"
                                autocomplete="off"
                            />
                            @error('route_destination')
                                <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('The CIDR you want routed through this network.') }}</p>
                        </div>
                        <div>
                            <x-input-label for="route_gateway" :value="__('Gateway (private IP)')" />
                            <div class="relative mt-1">
                                <x-text-input
                                    id="route_gateway"
                                    wire:model="route_gateway"
                                    class="block w-full font-mono"
                                    placeholder="10.0.0.2"
                                    autocomplete="off"
                                    list="gateway-suggestions"
                                />
                                {{-- Datalist pre-fills from servers that have a private IP on this network --}}
                                <datalist id="gateway-suggestions">
                                    @foreach ($allServers->where('private_ip_address', '!=', null) as $s)
                                        <option value="{{ $s->private_ip_address }}" label="{{ $s->name }}">
                                    @endforeach
                                </datalist>
                            </div>
                            @error('route_gateway')
                                <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('The private IP of the server that will forward this traffic.') }}</p>
                        </div>
                        <div>
                            <button
                                type="button"
                                wire:click="addRoute"
                                wire:loading.attr="disabled"
                                wire:target="addRoute"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50 sm:w-auto"
                            >
                                <span wire:loading.remove wire:target="addRoute">{{ __('Add route') }}</span>
                                <span wire:loading wire:target="addRoute" class="inline-flex items-center gap-2">
                                    <x-spinner variant="white" size="sm" />
                                    {{ __('Adding…') }}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        @endif

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
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Choose an existing private network in your Hetzner account.') }}</p>
                </div>
                <div class="space-y-4 p-6">
                    <div>
                        <div class="flex items-center justify-between">
                            <x-input-label for="attach_net_inline_{{ $s->id }}" :value="__('Network')" />
                            <button
                                type="button"
                                wire:click="loadHetznerNetworks"
                                wire:loading.attr="disabled"
                                wire:target="loadHetznerNetworks"
                                class="text-[11px] font-medium text-brand-sage hover:underline disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="loadHetznerNetworks">{{ __('Refresh') }}</span>
                                <span wire:loading wire:target="loadHetznerNetworks">{{ __('Loading…') }}</span>
                            </button>
                        </div>

                        @if ($hetzner_networks_loading)
                            <p class="mt-2 text-sm text-brand-mist">{{ __('Loading networks…') }}</p>
                        @elseif (! empty($hetzner_networks))
                            <select
                                id="attach_net_inline_{{ $s->id }}"
                                wire:model="attach_network_id.{{ $s->id }}"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest"
                            >
                                <option value="">{{ __('— pick a network —') }}</option>
                                @foreach ($hetzner_networks as $net)
                                    <option value="{{ $net['id'] }}">{{ $net['name'] }} — {{ $net['ip_range'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <x-text-input
                                id="attach_net_inline_{{ $s->id }}"
                                wire:model="attach_network_id.{{ $s->id }}"
                                class="mt-1 block w-full font-mono"
                                placeholder="e.g. 1234567"
                            />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Click Refresh to load networks from your Hetzner account, or enter an ID manually.') }}</p>
                        @endif
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
