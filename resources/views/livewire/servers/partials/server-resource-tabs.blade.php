{{--
    Combined "resources" control for a fleet server row/card. One compact tab
    strip — Sites · Services · Related — with a single full-width panel below
    showing only the active tab. Replaces the three separate disclosures, which
    tiled raggedly when more than one was open.

    Expects in scope: $server (with `sites`, `databaseEngines`, `cacheServices`
    eager-loaded) and $relatedServers (the per-server peer map from
    Servers\Index::relatedServersMap()). Pure Alpine — no extra queries.
--}}
@php
    $siteCount = $server->sites_count ?? $server->sites->count();

    $dbEngines = $server->databaseEngines ?? collect();
    $cacheServices = $server->cacheServices ?? collect();
    $serviceCount = $dbEngines->count() + $cacheServices->count();

    $related = $relatedServers[$server->id] ?? [];
    $relatedCount = count($related);

    $engineLabel = static fn (?string $engine): string => match (strtolower((string) $engine)) {
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'postgres', 'postgresql' => 'PostgreSQL',
        'redis' => 'Redis',
        'valkey' => 'Valkey',
        'keydb' => 'KeyDB',
        'memcached' => 'Memcached',
        default => ucfirst((string) $engine),
    };
    $statusTone = static fn (?string $status): ?string => match ((string) $status) {
        'running' => null,
        'failed' => 'danger',
        'stopped' => 'info',
        'pending', 'installing', 'uninstalling' => 'warning',
        default => null,
    };

    $tabBtnBase = 'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold shadow-sm transition';
    $panelBox = 'divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10 bg-white';
@endphp

@if ($siteCount > 0 || $serviceCount > 0 || $relatedCount > 0)
    <div x-data="{ tab: '' }" class="mt-2">
        {{-- Tab strip --}}
        <div class="flex flex-wrap items-center gap-2">
            @if ($siteCount > 0)
                <button
                    type="button"
                    @click="tab = (tab === 'sites' ? '' : 'sites')"
                    x-bind:aria-expanded="tab === 'sites'"
                    class="{{ $tabBtnBase }}"
                    x-bind:class="tab === 'sites' ? 'border-brand-ink/15 bg-brand-sand/60 text-brand-ink' : 'border-brand-ink/10 bg-white text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink'"
                >
                    <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ trans_choice(':count site|:count sites', $siteCount, ['count' => $siteCount]) }}
                    <span class="transition-transform" x-bind:class="{ 'rotate-180': tab === 'sites' }">
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    </span>
                </button>
            @endif
            @if ($serviceCount > 0)
                <button
                    type="button"
                    @click="tab = (tab === 'services' ? '' : 'services')"
                    x-bind:aria-expanded="tab === 'services'"
                    class="{{ $tabBtnBase }}"
                    x-bind:class="tab === 'services' ? 'border-brand-ink/15 bg-brand-sand/60 text-brand-ink' : 'border-brand-ink/10 bg-white text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink'"
                >
                    <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ trans_choice(':count service|:count services', $serviceCount, ['count' => $serviceCount]) }}
                    <span class="transition-transform" x-bind:class="{ 'rotate-180': tab === 'services' }">
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    </span>
                </button>
            @endif
            @if ($relatedCount > 0)
                <button
                    type="button"
                    @click="tab = (tab === 'related' ? '' : 'related')"
                    x-bind:aria-expanded="tab === 'related'"
                    class="{{ $tabBtnBase }}"
                    x-bind:class="tab === 'related' ? 'border-brand-ink/15 bg-brand-sand/60 text-brand-ink' : 'border-brand-ink/10 bg-white text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink'"
                >
                    <x-heroicon-o-share class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ trans_choice(':count related|:count related', $relatedCount, ['count' => $relatedCount]) }}
                    <span class="transition-transform" x-bind:class="{ 'rotate-180': tab === 'related' }">
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    </span>
                </button>
            @endif
        </div>

        {{-- Single full-width panel; only the active tab's list renders. --}}
        <div x-show="tab !== ''" x-collapse x-cloak class="mt-2">
            @if ($siteCount > 0)
                <ul x-show="tab === 'sites'" x-cloak class="{{ $panelBox }}">
                    @foreach ($server->sites as $site)
                        @php
                            $siteIsProvisioning = $site->isProvisioning();
                            $siteIsFailed = $site->provisioningState() === 'failed'
                                || in_array($site->status, [
                                    \App\Models\Site::STATUS_ERROR,
                                    \App\Models\Site::STATUS_CONTAINER_FAILED,
                                    \App\Models\Site::STATUS_EDGE_FAILED,
                                    \App\Models\Site::STATUS_SCAFFOLD_FAILED,
                                ], true);
                            $siteStatusTone = $siteIsFailed
                                ? 'danger'
                                : ($siteIsProvisioning ? 'warning' : ($site->isReadyForTraffic() ? 'success' : 'info'));
                            $siteSslTone = match ($site->ssl_status) {
                                \App\Models\Site::SSL_ACTIVE => 'success',
                                \App\Models\Site::SSL_PENDING => 'warning',
                                \App\Models\Site::SSL_FAILED => 'danger',
                                default => null,
                            };
                            $sitePhp = $site->phpVersion();
                            $siteRuntimeVersion = $site->runtimeVersion();
                            $siteRuntimeChip = $sitePhp
                                ? __('PHP :v', ['v' => $sitePhp])
                                : ($siteRuntimeVersion ? trim(ucfirst((string) ($site->runtimeKey() ?? '')).' '.$siteRuntimeVersion) : null);
                            $siteLastDeploy = $site->last_deploy_at;
                        @endphp
                        <li>
                            <a href="{{ route('sites.show', [$server, $site]) }}" wire:navigate class="block px-3 py-2.5 transition hover:bg-brand-sand/30">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate text-xs font-semibold text-brand-ink">{{ $site->name }}</span>
                                    <span class="flex shrink-0 items-center gap-1">
                                        <x-badge size="sm" :tone="$siteStatusTone">{{ $site->statusLabel() }}</x-badge>
                                        @if ($siteSslTone !== null)
                                            <x-badge size="sm" :tone="$siteSslTone">{{ __('SSL') }}</x-badge>
                                        @endif
                                    </span>
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-moss">
                                    @if ($site->type)
                                        <span class="inline-flex items-center gap-1">
                                            <x-heroicon-o-cpu-chip class="h-3 w-3 shrink-0 text-brand-sage" aria-hidden="true" />
                                            {{ $site->type->label() }}
                                        </span>
                                        <span class="text-brand-mist">·</span>
                                    @endif
                                    @if ($siteRuntimeChip)
                                        <span>{{ $siteRuntimeChip }}</span>
                                        <span class="text-brand-mist">·</span>
                                    @endif
                                    @if ($siteLastDeploy)
                                        <span class="inline-flex items-center gap-1" title="{{ $siteLastDeploy }}">
                                            <x-heroicon-o-rocket-launch class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                            {{ __('Deployed :ago', ['ago' => $siteLastDeploy->diffForHumans()]) }}
                                        </span>
                                    @else
                                        <span>{{ __('Not deployed yet') }}</span>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($serviceCount > 0)
                <ul x-show="tab === 'services'" x-cloak class="{{ $panelBox }}">
                    @foreach ($dbEngines as $engine)
                        @php $tone = $statusTone($engine->status); @endphp
                        <li class="flex items-center justify-between gap-3 px-3 py-2.5">
                            <span class="inline-flex min-w-0 items-center gap-2">
                                <x-heroicon-o-circle-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate text-xs font-semibold text-brand-ink">{{ $engineLabel($engine->engine) }}</span>
                                @if ($engine->version)
                                    <span class="font-mono text-[11px] text-brand-moss">{{ $engine->version }}</span>
                                @endif
                                @if ($engine->is_default)
                                    <span class="rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Default') }}</span>
                                @endif
                            </span>
                            <span class="flex shrink-0 items-center gap-1">
                                <span class="text-[11px] uppercase tracking-wide text-brand-mist">{{ __('Database') }}</span>
                                @if ($tone !== null)
                                    <x-badge size="sm" :tone="$tone">{{ ucfirst((string) $engine->status) }}</x-badge>
                                @endif
                            </span>
                        </li>
                    @endforeach
                    @foreach ($cacheServices as $cache)
                        @php $tone = $statusTone($cache->status); @endphp
                        <li class="flex items-center justify-between gap-3 px-3 py-2.5">
                            <span class="inline-flex min-w-0 items-center gap-2">
                                <x-heroicon-o-bolt class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate text-xs font-semibold text-brand-ink">{{ $engineLabel($cache->engine) }}</span>
                                @if ($cache->version)
                                    <span class="font-mono text-[11px] text-brand-moss">{{ $cache->version }}</span>
                                @endif
                            </span>
                            <span class="flex shrink-0 items-center gap-1">
                                <span class="text-[11px] uppercase tracking-wide text-brand-mist">{{ __('Cache') }}</span>
                                @if ($tone !== null)
                                    <x-badge size="sm" :tone="$tone">{{ ucfirst((string) $cache->status) }}</x-badge>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($relatedCount > 0)
                <ul x-show="tab === 'related'" x-cloak class="{{ $panelBox }}">
                    @foreach ($related as $peer)
                        @php $peerServer = $peer['server']; @endphp
                        <li>
                            <a href="{{ route('servers.show', $peerServer) }}" wire:navigate class="flex items-center justify-between gap-3 px-3 py-2.5 transition hover:bg-brand-sand/30">
                                <span class="inline-flex min-w-0 items-center gap-2">
                                    <x-heroicon-o-server class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <span class="truncate text-xs font-semibold text-brand-ink">{{ $peerServer->name }}</span>
                                    @if ($peerServer->ip_address)
                                        <span class="font-mono text-[11px] text-brand-moss">{{ $peerServer->ip_address }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                    {{ $peer['reason'] }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
