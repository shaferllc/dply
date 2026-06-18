{{-- Redis-native tile pack. server_role=redis/valkey hosts replace
     the generic Sites/Databases/Latest-deploy/Background tiles with
     INFO-derived values (memory, hit-rate, keys, persistence). Data
     comes from CacheServiceStats::overviewSnapshot() with a short
     (60s) TTL — see WorkspaceOverview::render(). --}}
@if ($isCacheRoleHost && $cacheTileData !== null)
    @php
        $engineLabel = match ($cacheTileEngine) {
            'redis' => __('Redis'),
            'valkey' => __('Valkey'),
            'keydb' => __('KeyDB'),
            'dragonfly' => __('Dragonfly'),
            default => ucfirst((string) $cacheTileEngine),
        };
        $cachesUrl = route('servers.caches', $server);
        $reachable = (bool) $cacheTileData['reachable'];
        $hitRate = $cacheTileData['hit_rate'];
        $hitRateLabel = $hitRate === null ? '—' : number_format($hitRate * 100, 1).'%';
        $usedPct = $cacheTileData['used_memory_pct'];
        $memoryHeadline = $cacheTileData['used_memory_human'] ?? '—';
        $memoryMeta = $cacheTileData['maxmemory_human'] !== null
            ? __('of :max', ['max' => $cacheTileData['maxmemory_human']])
            : __('No max set');
        $lastSaveAt = $cacheTileData['rdb_last_save_at'];
        $aofEnabled = $cacheTileData['aof_enabled'];
        $persistenceHeadline = match (true) {
            $aofEnabled === true => __('AOF on'),
            $lastSaveAt !== null => __('RDB :time', ['time' => $lastSaveAt->diffForHumans()]),
            default => __('No RDB yet'),
        };
        $persistenceMeta = match (true) {
            $aofEnabled === true && $lastSaveAt !== null => __('Last RDB :time', ['time' => $lastSaveAt->diffForHumans()]),
            $aofEnabled === true => __('AOF append-only persistence'),
            $lastSaveAt !== null => __('Last RDB snapshot'),
            default => __('Persistence disabled'),
        };
    @endphp
    <section class="dply-card overflow-hidden">
        <div class="px-6 pt-5 pb-4 sm:px-7">
            <div class="flex items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine workspace', ['engine' => $engineLabel]) }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Live values pulled from INFO. Each tile drops you onto the full Caches workspace.') }}</p>
                </div>
                @if (! $reachable)
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                        {{ __('Unreachable') }}
                    </span>
                @endif
            </div>
        </div>
        <div class="grid gap-3 p-6 sm:grid-cols-2 sm:p-7 lg:grid-cols-3">
            {{-- Engine + version --}}
            <a href="{{ $cachesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Engine') }}</p>
                <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $engineLabel }} {{ $cacheTileData['version'] ?? '' }}</p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">
                    @if ($cacheTileData['uptime_human'])
                        {{ __('Up :time', ['time' => $cacheTileData['uptime_human']]) }}
                    @else
                        {{ __('Not running') }}
                    @endif
                </p>
                <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                    {{ __('Open :engine', ['engine' => $engineLabel]) }}
                    <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                </p>
            </a>

            {{-- Memory used / max --}}
            <a href="{{ $cachesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Memory') }}</p>
                <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $memoryHeadline }}</p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $memoryMeta }}</p>
                @if ($usedPct !== null)
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/5">
                        <div class="h-full rounded-full {{ $usedPct >= 90 ? 'bg-rose-500' : ($usedPct >= 75 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $usedPct }}%"></div>
                    </div>
                @endif
            </a>

            {{-- Hit rate --}}
            <a href="{{ $cachesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Hit rate') }}</p>
                <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $hitRateLabel }}</p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">
                    @if ($cacheTileData['keyspace_hits'] !== null)
                        {{ __(':hits hits / :misses misses', ['hits' => number_format((int) $cacheTileData['keyspace_hits']), 'misses' => number_format((int) ($cacheTileData['keyspace_misses'] ?? 0))]) }}
                    @else
                        {{ __('No traffic yet') }}
                    @endif
                </p>
            </a>

            {{-- Total keys --}}
            <a href="{{ $cachesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Keys') }}</p>
                <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $cacheTileData['total_keys'] !== null ? number_format((int) $cacheTileData['total_keys']) : '—' }}</p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ __('Across all databases') }}</p>
            </a>

            {{-- Persistence (RDB / AOF) --}}
            <a href="{{ $cachesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Persistence') }}</p>
                <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $persistenceHeadline }}</p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $persistenceMeta }}</p>
            </a>

            {{-- Connected clients + ops/sec --}}
            <a href="{{ $cachesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Clients') }}</p>
                <p class="mt-1 flex items-baseline gap-1.5">
                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $cacheTileData['connected_clients'] !== null ? number_format((int) $cacheTileData['connected_clients']) : '—' }}</span>
                    <span class="text-[11px] text-brand-moss">{{ __('connected') }}</span>
                </p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">
                    @if ($cacheTileData['ops_per_sec'] !== null)
                        {{ __(':n ops/sec', ['n' => number_format((int) $cacheTileData['ops_per_sec'])]) }}
                    @else
                        {{ __('Idle') }}
                    @endif
                </p>
            </a>

            {{-- Replication role: master + replica count, or replica + master endpoint. --}}
            @php
                $roleValue = $cacheTileData['role'] ?? null;
                $replicaCount = (int) ($cacheTileData['connected_replicas'] ?? 0);
                $roleIsReplica = in_array($roleValue, ['slave', 'replica'], true);
                $roleHeadline = match (true) {
                    $roleIsReplica => __('Replica'),
                    $roleValue === 'master' => __('Master'),
                    default => '—',
                };
                $roleMeta = match (true) {
                    $roleIsReplica => __('Replicating from upstream'),
                    $roleValue === 'master' && $replicaCount > 0 => trans_choice('{1} :n replica connected|[2,*] :n replicas connected', $replicaCount, ['n' => $replicaCount]),
                    $roleValue === 'master' => __('Standalone — no replicas'),
                    default => __('Unknown'),
                };
            @endphp
            <a href="{{ $cachesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Role') }}</p>
                <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $roleHeadline }}</p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $roleMeta }}</p>
            </a>
        </div>
    </section>
@endif
