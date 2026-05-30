            @php
                $envoyOverview = null;
                $envoyVersion = null;
                $envoyLiveStateErrors = [];
                if ($key === 'envoy' && $isActive) {
                    $envoyLive = data_get($server->meta ?? [], 'webserver_live_state.envoy');
                    $envoyState = \App\Services\Servers\LiveState\EngineLiveState::fromArray($envoyLive);
                    $envoyOverview = [
                        'listeners' => count($envoyState?->units['listeners'] ?? []),
                        'virtualhosts' => count($envoyState?->units['virtualhosts'] ?? []),
                        'clusters' => count($envoyState?->units['clusters'] ?? []),
                    ];
                    $envoyVersion = data_get($envoyState?->units ?? [], 'runtime.0.version');
                    $envoyLiveStateErrors = \App\Services\Servers\LiveState\EngineLiveState::probeErrorLines(
                        data_get($envoyState?->engineSpecific ?? [], 'errors', []),
                    );
                }
                $envoyConfigFrom = ! empty($isEdgeProxyPanel) ? 'edge-proxy' : 'webserver';
                $envoyConfigReturnSub = ($engine_subtab === '' || $engine_subtab === 'config') ? 'overview' : $engine_subtab;
            @endphp

            @if ($key === 'envoy' && ($engine_subtab === 'overview' || ($optimisticEngineSubtabs ?? false)) && $isActive)
                <div
                    @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'overview'" x-cloak @endif
                    class="{{ $card }} p-6 sm:p-8 mb-6"
                    wire:key="envoy-overview-admin"
                >
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Envoy admin interface') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm text-brand-moss">
                        {{ __('dply probes Envoy on 127.0.0.1:9901 (localhost-only). The admin UI exposes live config, stats, clusters, and maintenance endpoints.') }}
                    </p>

                    @if ($envoyLiveStateErrors !== [])
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-950">
                            <p class="font-semibold">{{ __('Live state could not be loaded') }}</p>
                            <p class="mt-1 text-xs leading-relaxed whitespace-pre-line">{{ implode("\n", $envoyLiveStateErrors) }}</p>
                            <p class="mt-2 text-xs text-amber-900/90">
                                {{ __('Until the admin API on :port responds, counts stay empty. If systemd shows inactive, use Start Envoy; otherwise use Repair admin on :port, then Refresh live state.', ['port' => '9901']) }}
                            </p>
                        </div>
                    @elseif (is_array($envoyOverview) && ($envoyVersion === null || $envoyVersion === '?' || $envoyVersion === ''))
                        <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3 text-xs text-brand-moss">
                            {{ __('Counts are from the last successful admin probe. If everything is zero, provision sites on this server or check /etc/envoy/envoy.yaml on the box.') }}
                        </div>
                    @endif

                    @php
                        $envoyDplyAdminUrl = $server->edgeProxy() === 'envoy'
                            ? route('servers.envoy.admin', ['server' => $server])
                            : null;
                    @endphp

                    <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Version') }}</dt>
                            <dd class="mt-1 font-mono text-sm text-brand-ink">{{ is_string($envoyVersion) && $envoyVersion !== '' ? $envoyVersion : '—' }}</dd>
                        </div>
                        @if (is_array($envoyOverview))
                            @foreach (['listeners' => __('Listeners'), 'virtualhosts' => __('Virtual hosts'), 'clusters' => __('Clusters')] as $metric => $label)
                                <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ $label }}</dt>
                                    <dd class="mt-1 text-sm text-brand-ink tabular-nums">{{ number_format((int) ($envoyOverview[$metric] ?? 0)) }}</dd>
                                </div>
                            @endforeach
                        @endif
                    </dl>

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        @if ($envoyDplyAdminUrl)
                            <div class="rounded-xl border border-brand-sage/30 bg-brand-sage/10 p-4 sm:p-5">
                                <div class="flex flex-wrap items-start gap-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/20 text-brand-forest ring-1 ring-brand-sage/30">
                                        <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Dply admin') }}</p>
                                        <h4 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Open Envoy admin (signed in)') }}</h4>
                                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                            {{ __('Envoy stays on localhost :9901 on the server. This URL proxies the admin UI over SSH — only members of your organization who can view this server can open it.', ['port' => '9901']) }}
                                        </p>
                                        <div class="mt-3 flex flex-wrap items-center gap-2">
                                            <a
                                                href="{{ $envoyDplyAdminUrl }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-ink"
                                            >
                                                {{ __('Open Envoy admin') }}
                                                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            </a>
                                            @if ($engineHasFullControls($key) && ! $isDeployer)
                                                @if ($envoyLiveStateErrors !== [])
                                                    <button
                                                        type="button"
                                                        wire:click="startEnvoyService"
                                                        wire:loading.attr="disabled"
                                                        wire:target="startEnvoyService"
                                                        @disabled(! $opsReady || ($inflightEdgeProxy ?? false) || ($inflightWebserverSwitch ?? false))
                                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                                    >
                                                        <span wire:loading.remove wire:target="startEnvoyService">{{ __('Start Envoy') }}</span>
                                                        <span wire:loading wire:target="startEnvoyService" class="inline-flex items-center gap-1.5">
                                                            <x-spinner variant="forest" size="sm" />
                                                            {{ __('Starting…') }}
                                                        </span>
                                                    </button>
                                                @endif
                                                <button
                                                    type="button"
                                                    wire:click="repairEnvoyAdminApi"
                                                    wire:loading.attr="disabled"
                                                    wire:target="repairEnvoyAdminApi"
                                                    @disabled(! $opsReady || ($inflightEdgeProxy ?? false) || ($inflightWebserverSwitch ?? false))
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                                >
                                                    <span wire:loading.remove wire:target="repairEnvoyAdminApi">{{ __('Repair admin on :port', ['port' => '9901']) }}</span>
                                                    <span wire:loading wire:target="repairEnvoyAdminApi" class="inline-flex items-center gap-1.5">
                                                        <x-spinner variant="forest" size="sm" />
                                                        {{ __('Repairing…') }}
                                                    </span>
                                                </button>
                                            @endif
                                        </div>
                                        <p class="mt-2 break-all font-mono text-[11px] text-brand-moss">{{ $envoyDplyAdminUrl }}</p>
                                    </div>
                                </div>
                            </div>
                        @endif
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5 {{ $envoyDplyAdminUrl ? '' : 'lg:col-span-2' }}">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Local admin (on server)') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Used by dply live-state probes — not reachable from your browser.') }}</p>
                            <code class="mt-3 block break-all rounded-lg bg-white px-3 py-2 font-mono text-[11px] text-brand-ink ring-1 ring-brand-ink/10">http://127.0.0.1:9901/</code>
                        </div>
                    </div>

                    @if ($engineHasFullControls($key) && ! $isDeployer)
                        <div class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5">
                            <h4 class="text-sm font-semibold text-brand-ink">{{ __('Maintenance') }}</h4>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Graceful drain stops accepting new connections while existing ones finish. Health-check fail marks Envoy unhealthy for upstream-aware tooling.') }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    wire:click="drainEnvoyListeners"
                                    wire:loading.attr="disabled"
                                    wire:target="drainEnvoyListeners"
                                    @disabled(! $opsReady || ($inflightEdgeProxy ?? false) || ($inflightWebserverSwitch ?? false))
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="drainEnvoyListeners">{{ __('Drain listeners') }}</span>
                                    <span wire:loading wire:target="drainEnvoyListeners" class="inline-flex items-center gap-1.5">
                                        <x-spinner variant="forest" size="sm" />
                                        {{ __('Draining…') }}
                                    </span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="healthcheckFailEnvoy"
                                    wire:loading.attr="disabled"
                                    wire:target="healthcheckFailEnvoy"
                                    @disabled(! $opsReady || ($inflightEdgeProxy ?? false) || ($inflightWebserverSwitch ?? false))
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="healthcheckFailEnvoy">{{ __('Fail health checks') }}</span>
                                    <span wire:loading wire:target="healthcheckFailEnvoy" class="inline-flex items-center gap-1.5">
                                        <x-spinner variant="forest" size="sm" />
                                        {{ __('Working…') }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    @endif

                    @if (! empty($isEdgeProxyPanel) && ($isActive ?? false))
                        @include('livewire.servers.partials.edge-proxy.remove-active-panel', [
                            'info' => ['label' => $info['label'] ?? __('Envoy')],
                        ])
                    @endif

                    <p class="mt-4 text-xs text-brand-mist">
                        <a href="https://www.envoyproxy.io/docs/envoy/latest/operations/admin" target="_blank" rel="noopener noreferrer" class="text-brand-forest hover:underline">{{ __('Envoy admin operations') }}</a>
                    </p>
                </div>
            @endif

            @if ($key === 'envoy' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'static' || ($optimisticEngineSubtabs ?? false)))
                <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'static'" x-cloak @endif>
                @php
                    $envoyParams = \App\Services\Servers\EnvoyStaticConfigOptions::PARAMS;
                    $envoyGroups = \App\Services\Servers\EnvoyStaticConfigOptions::PARAM_GROUPS;
                @endphp
                <div class="{{ $card }} p-6 sm:p-8 mb-6" wire:key="envoy-static-config">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Envoy static settings') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Operator-tunable fields in /etc/envoy/envoy.yaml. Site routing blocks are regenerated when you apply edge backends or provision sites.') }}
                            </p>
                            <p class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-amber-50/70 px-2.5 py-1 text-[11px] font-medium text-amber-900 ring-1 ring-amber-200">
                                <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5" />
                                {{ __('Saving restarts Envoy — edge briefly drops connections.') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            wire:click="loadEnvoyStaticConfig"
                            wire:loading.attr="disabled"
                            wire:target="loadEnvoyStaticConfig"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadEnvoyStaticConfig" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            </span>
                            <span wire:loading wire:target="loadEnvoyStaticConfig" class="inline-flex">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                            {{ __('Reload from server') }}
                        </button>
                    </div>

                    @if ($envoy_static_flash)
                        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $envoy_static_flash }}</div>
                    @endif
                    @if ($envoy_static_error)
                        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                            <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $envoy_static_error }}</pre>
                        </div>
                    @endif

                    @if (! $envoy_static_loaded)
                        <p class="mt-5 text-sm text-brand-moss">
                            <span wire:loading wire:target="loadEnvoyStaticConfig" class="inline-flex items-center gap-2">
                                <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading envoy.yaml…') }}
                            </span>
                            <span wire:loading.remove wire:target="loadEnvoyStaticConfig">
                                {{ __('Click "Reload from server" to fetch current values.') }}
                            </span>
                        </p>
                    @else
                        <form wire:submit.prevent="saveEnvoyStaticConfig" class="mt-6 space-y-8">
                            @foreach ($envoyGroups as $groupKey => $groupLabel)
                                @php
                                    $groupParams = array_filter(
                                        $envoyParams,
                                        fn ($meta) => ($meta['group'] ?? '') === $groupKey,
                                    );
                                @endphp
                                @if ($groupParams !== [])
                                    <fieldset class="space-y-4">
                                        <legend class="text-sm font-semibold text-brand-ink border-b border-brand-ink/10 pb-2 w-full">{{ __($groupLabel) }}</legend>
                                        <div class="grid gap-5 sm:grid-cols-2">
                                            @foreach ($groupParams as $paramKey => $meta)
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    <input type="text"
                                                        wire:model.lazy="envoy_static_form.{{ $paramKey }}"
                                                        placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @endif
                            @endforeach
                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveEnvoyStaticConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream disabled:opacity-60">
                                    <span wire:loading wire:target="saveEnvoyStaticConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and restart Envoy') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
                </div>
            @endif

            @if ($key === 'envoy' && $engine_subtab === 'clusters' && $isActive && $engineHasFullControls($key))
                @php $envoyClusterParams = \App\Services\Servers\EnvoyCustomClustersConfig::PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="envoy-clusters-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('Custom clusters') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Extra upstream pools for non-Caddy targets. dply-managed site clusters are regenerated automatically; custom clusters persist in server settings and merge into envoy.yaml on every edge routing rebuild.') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="openAddEnvoyClusterForm" @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:opacity-60">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                    {{ __('Add cluster') }}
                                </button>
                                <button type="button" wire:click="loadEnvoyClustersConfig" wire:loading.attr="disabled" wire:target="loadEnvoyClustersConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60">
                                    <span wire:loading.remove wire:target="loadEnvoyClustersConfig"><x-heroicon-o-arrow-path class="h-3.5 w-3.5" /></span>
                                    <span wire:loading wire:target="loadEnvoyClustersConfig"><x-spinner class="h-3.5 w-3.5" /></span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($envoy_clusters_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $envoy_clusters_flash }}</div>
                        @endif
                        @if ($envoy_clusters_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $envoy_clusters_error }}</pre>
                            </div>
                        @endif

                        @if ($envoy_clusters_show_add)
                            <form wire:submit.prevent="submitAddEnvoyCluster" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a custom cluster') }}</p>
                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input type="text" wire:model.lazy="envoy_clusters_new.name" placeholder="api_pool" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" required />
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Endpoints (one host:port per line)') }}</span>
                                        <textarea wire:model.lazy="envoy_clusters_new.endpoints" rows="4" spellcheck="false" placeholder="127.0.0.1:8080{{ "\n" }}127.0.0.1:8081" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs text-emerald-100"></textarea>
                                    </label>
                                    @foreach ($envoyClusterParams as $paramKey => $meta)
                                        <label class="block">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                            <input type="text" wire:model.lazy="envoy_clusters_new.{{ $paramKey }}" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" />
                                        </label>
                                    @endforeach
                                </div>
                                <div class="mt-4 flex justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button type="button" wire:click="cancelAddEnvoyClusterForm" class="rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium">{{ __('Cancel') }}</button>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="submitAddEnvoyCluster" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Create and regenerate') }}</button>
                                </div>
                            </form>
                        @endif

                        @if (! $envoy_clusters_loaded)
                            <p class="mt-5 text-sm text-brand-moss">{{ __('Click "Reload from server" to fetch custom clusters.') }}</p>
                        @endif
                    </div>

                    @if ($envoy_clusters_loaded && ! empty($envoy_clusters_form))
                        <form wire:submit.prevent="saveEnvoyClustersConfig" class="space-y-4">
                            @foreach ($envoy_clusters_form as $clusterName => $payload)
                                <div class="{{ $card }} p-5 sm:p-6" wire:key="envoy-cluster-{{ $clusterName }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-mono text-sm font-semibold text-brand-ink">{{ $clusterName }}</p>
                                            <p class="mt-0.5 text-[11px] text-brand-mist">{{ __(':n endpoint(s)', ['n' => count($payload['endpoints'] ?? [])]) }}</p>
                                        </div>
                                        <button type="button"
                                            wire:click="openConfirmActionModal('removeEnvoyCluster', ['{{ $clusterName }}'], @js(__('Remove cluster: :name', ['name' => $clusterName])), @js(__('Remove `:name` from persisted custom clusters and regenerate edge routing?', ['name' => $clusterName])), @js(__('Remove')), true)"
                                            @disabled($isDeployer || $actionInFlight)
                                            class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800">
                                            <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                            {{ __('Remove') }}
                                        </button>
                                    </div>
                                    <label class="mt-4 block">
                                        <span class="block text-sm font-medium text-brand-ink">{{ __('Endpoints (one per line)') }}</span>
                                        <textarea wire:model.lazy="envoy_clusters_endpoints_text.{{ $clusterName }}" rows="4" spellcheck="false" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs text-emerald-100">{{ $envoy_clusters_endpoints_text[$clusterName] ?? '' }}</textarea>
                                    </label>
                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        @foreach ($envoyClusterParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                <input type="text" wire:model.lazy="envoy_clusters_form.{{ $clusterName }}.values.{{ $paramKey }}" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" />
                                                <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                            <div class="flex justify-end border-t border-brand-ink/10 pt-4">
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveEnvoyClustersConfig" @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream">
                                    {{ __('Save and regenerate routing') }}
                                </button>
                            </div>
                        </form>
                    @elseif ($envoy_clusters_loaded)
                        <x-empty-state class="mt-2" icon="heroicon-o-server" :title="__('No custom clusters yet')" :description="__('Add a pool for external upstreams, or route to them manually in the Configuration editor.')" />
                    @endif
                </div>
            @endif

            @if ($key === 'envoy' && $engine_subtab === 'virtualhosts' && $isActive && $engineHasFullControls($key))
                <div class="space-y-4 mb-6" wire:key="envoy-virtualhosts-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('Custom virtual hosts') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Extra Host → cluster routes merged into envoy.yaml before the catch-all. dply site virtual hosts are regenerated automatically. Target a custom cluster name or an existing dply site cluster (`cluster_*`).') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="openAddEnvoyVirtualHostForm" @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:opacity-60">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                    {{ __('Add virtual host') }}
                                </button>
                                <button type="button" wire:click="loadEnvoyVirtualHostsConfig" wire:loading.attr="disabled" wire:target="loadEnvoyVirtualHostsConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60">
                                    <span wire:loading.remove wire:target="loadEnvoyVirtualHostsConfig"><x-heroicon-o-arrow-path class="h-3.5 w-3.5" /></span>
                                    <span wire:loading wire:target="loadEnvoyVirtualHostsConfig"><x-spinner class="h-3.5 w-3.5" /></span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($envoy_virtualhosts_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $envoy_virtualhosts_flash }}</div>
                        @endif
                        @if ($envoy_virtualhosts_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $envoy_virtualhosts_error }}</pre>
                            </div>
                        @endif

                        @if ($envoy_virtualhosts_show_add)
                            <form wire:submit.prevent="submitAddEnvoyVirtualHost" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a custom virtual host') }}</p>
                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input type="text" wire:model.lazy="envoy_virtualhosts_new.name" placeholder="api_gateway" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" required />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Target cluster') }}</span>
                                        <input type="text" wire:model.lazy="envoy_virtualhosts_new.cluster" placeholder="api_pool" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" required />
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Domains (comma or newline separated)') }}</span>
                                        <textarea wire:model.lazy="envoy_virtualhosts_new.domains" rows="3" spellcheck="false" placeholder="api.example.com{{ "\n" }}legacy.example.com" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs text-emerald-100"></textarea>
                                    </label>
                                </div>
                                <div class="mt-4 flex justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button type="button" wire:click="cancelAddEnvoyVirtualHostForm" class="rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium">{{ __('Cancel') }}</button>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="submitAddEnvoyVirtualHost" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Create and regenerate') }}</button>
                                </div>
                            </form>
                        @endif

                        @if (! $envoy_virtualhosts_loaded)
                            <p class="mt-5 text-sm text-brand-moss">{{ __('Click "Reload from server" to fetch custom virtual hosts.') }}</p>
                        @endif
                    </div>

                    @if ($envoy_virtualhosts_loaded && ! empty($envoy_virtualhosts_form))
                        <form wire:submit.prevent="saveEnvoyVirtualHostsConfig" class="space-y-4">
                            @foreach ($envoy_virtualhosts_form as $vhostName => $payload)
                                <div class="{{ $card }} p-5 sm:p-6" wire:key="envoy-vhost-{{ $vhostName }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-mono text-sm font-semibold text-brand-ink">{{ $vhostName }}</p>
                                            <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Routes to :cluster', ['cluster' => $payload['cluster'] ?? '—']) }}</p>
                                        </div>
                                        <button type="button"
                                            wire:click="openConfirmActionModal('removeEnvoyVirtualHost', ['{{ $vhostName }}'], @js(__('Remove virtual host: :name', ['name' => $vhostName])), @js(__('Remove `:name` from persisted custom virtual hosts and regenerate edge routing?', ['name' => $vhostName])), @js(__('Remove')), true)"
                                            @disabled($isDeployer || $actionInFlight)
                                            class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800">
                                            <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                            {{ __('Remove') }}
                                        </button>
                                    </div>
                                    <label class="mt-4 block">
                                        <span class="block text-sm font-medium text-brand-ink">{{ __('Domains') }}</span>
                                        <textarea wire:model.lazy="envoy_virtualhosts_domains_text.{{ $vhostName }}" rows="3" spellcheck="false" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs text-emerald-100"></textarea>
                                    </label>
                                    <label class="mt-4 block">
                                        <span class="block text-sm font-medium text-brand-ink">{{ __('Target cluster') }}</span>
                                        <input type="text" wire:model.lazy="envoy_virtualhosts_form.{{ $vhostName }}.cluster" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" />
                                    </label>
                                </div>
                            @endforeach
                            <div class="flex justify-end border-t border-brand-ink/10 pt-4">
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveEnvoyVirtualHostsConfig" @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream">
                                    {{ __('Save and regenerate routing') }}
                                </button>
                            </div>
                        </form>
                    @elseif ($envoy_virtualhosts_loaded)
                        <x-empty-state class="mt-2" icon="heroicon-o-server-stack" :title="__('No custom virtual hosts yet')" :description="__('Add routes for external hostnames or split traffic to custom clusters.')" />
                    @endif
                </div>
            @endif

            @if ($key === 'envoy' && $engine_subtab === 'listeners' && $isActive && $engineHasFullControls($key))
                <div class="space-y-4 mb-6" wire:key="envoy-listeners-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('Custom listeners') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Extra HTTP listeners alongside the primary :80 ingress. Shared mode mirrors site + custom virtual hosts on the alt port; cluster mode sends all traffic on that port to one cluster.', ['port' => ':80']) }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="openAddEnvoyListenerForm" @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:opacity-60">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                    {{ __('Add listener') }}
                                </button>
                                <button type="button" wire:click="loadEnvoyListenersConfig" wire:loading.attr="disabled" wire:target="loadEnvoyListenersConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60">
                                    <span wire:loading.remove wire:target="loadEnvoyListenersConfig"><x-heroicon-o-arrow-path class="h-3.5 w-3.5" /></span>
                                    <span wire:loading wire:target="loadEnvoyListenersConfig"><x-spinner class="h-3.5 w-3.5" /></span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($envoy_listeners_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $envoy_listeners_flash }}</div>
                        @endif
                        @if ($envoy_listeners_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $envoy_listeners_error }}</pre>
                            </div>
                        @endif

                        @if ($envoy_listeners_show_add)
                            <form wire:submit.prevent="submitAddEnvoyListener" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a custom listener') }}</p>
                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input type="text" wire:model.lazy="envoy_listeners_new.name" placeholder="alt_http" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" required />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Bind address') }}</span>
                                        <input type="text" wire:model.lazy="envoy_listeners_new.address" placeholder="0.0.0.0" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Port') }}</span>
                                        <input type="number" wire:model.lazy="envoy_listeners_new.port" min="1" max="65535" placeholder="8080" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" required />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Mode') }}</span>
                                        <select wire:model.live="envoy_listeners_new.mode" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm">
                                            <option value="shared">{{ __('Shared — mirror site + custom virtual hosts') }}</option>
                                            <option value="cluster">{{ __('Cluster — route all traffic to one cluster') }}</option>
                                        </select>
                                    </label>
                                    @if (($envoy_listeners_new['mode'] ?? 'shared') === 'cluster')
                                        <label class="block sm:col-span-2">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Default cluster') }}</span>
                                            <input type="text" wire:model.lazy="envoy_listeners_new.default_cluster" placeholder="api_pool" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" required />
                                        </label>
                                    @endif
                                </div>
                                <div class="mt-4 flex justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button type="button" wire:click="cancelAddEnvoyListenerForm" class="rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium">{{ __('Cancel') }}</button>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="submitAddEnvoyListener" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Create and regenerate') }}</button>
                                </div>
                            </form>
                        @endif

                        @if (! $envoy_listeners_loaded)
                            <p class="mt-5 text-sm text-brand-moss">{{ __('Click "Reload from server" to fetch custom listeners.') }}</p>
                        @endif
                    </div>

                    @if ($envoy_listeners_loaded && ! empty($envoy_listeners_form))
                        <form wire:submit.prevent="saveEnvoyListenersConfig" class="space-y-4">
                            @foreach ($envoy_listeners_form as $listenerName => $values)
                                <div class="{{ $card }} p-5 sm:p-6" wire:key="envoy-listener-{{ $listenerName }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-mono text-sm font-semibold text-brand-ink">{{ $listenerName }}</p>
                                            <p class="mt-0.5 text-[11px] text-brand-mist">{{ __(':address::port', ['address' => $values['address'] ?? '0.0.0.0', 'port' => $values['port'] ?? '—']) }}</p>
                                        </div>
                                        <button type="button"
                                            wire:click="openConfirmActionModal('removeEnvoyListener', ['{{ $listenerName }}'], @js(__('Remove listener: :name', ['name' => $listenerName])), @js(__('Remove `:name` from persisted custom listeners and regenerate edge routing?', ['name' => $listenerName])), @js(__('Remove')), true)"
                                            @disabled($isDeployer || $actionInFlight)
                                            class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800">
                                            <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                            {{ __('Remove') }}
                                        </button>
                                    </div>
                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        <label class="block">
                                            <span class="block text-sm font-medium text-brand-ink">{{ __('Bind address') }}</span>
                                            <input type="text" wire:model.lazy="envoy_listeners_form.{{ $listenerName }}.address" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" />
                                        </label>
                                        <label class="block">
                                            <span class="block text-sm font-medium text-brand-ink">{{ __('Port') }}</span>
                                            <input type="number" wire:model.lazy="envoy_listeners_form.{{ $listenerName }}.port" min="1" max="65535" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" />
                                        </label>
                                        <label class="block sm:col-span-2">
                                            <span class="block text-sm font-medium text-brand-ink">{{ __('Mode') }}</span>
                                            <select wire:model.live="envoy_listeners_form.{{ $listenerName }}.mode" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm">
                                                <option value="shared">{{ __('Shared — mirror site + custom virtual hosts') }}</option>
                                                <option value="cluster">{{ __('Cluster — route all traffic to one cluster') }}</option>
                                            </select>
                                        </label>
                                        @if (($values['mode'] ?? 'shared') === 'cluster')
                                            <label class="block sm:col-span-2">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __('Default cluster') }}</span>
                                                <input type="text" wire:model.lazy="envoy_listeners_form.{{ $listenerName }}.default_cluster" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" />
                                            </label>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                            <div class="flex justify-end border-t border-brand-ink/10 pt-4">
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveEnvoyListenersConfig" @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream">
                                    {{ __('Save and regenerate routing') }}
                                </button>
                            </div>
                        </form>
                    @elseif ($envoy_listeners_loaded)
                        <x-empty-state class="mt-2" icon="heroicon-o-signal" :title="__('No custom listeners yet')" :description="__('Add an alt-port listener for admin endpoints, split ingress, or cluster-only ports.')" />
                    @endif
                </div>
            @endif
