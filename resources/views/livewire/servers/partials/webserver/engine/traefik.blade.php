            @php
                $traefikOverview = null;
                $traefikVersion = null;
                $traefikLiveStateErrors = [];
                if ($key === 'traefik' && $isActive) {
                    $traefikLive = data_get($server->meta ?? [], 'webserver_live_state.traefik');
                    $traefikState = \App\Services\Servers\LiveState\EngineLiveState::fromArray($traefikLive);
                    $traefikOverview = data_get($traefikState?->engineSpecific ?? [], 'overview');
                    $traefikVersion = data_get($traefikState?->engineSpecific ?? [], 'version');
                    $traefikLiveStateErrors = array_values(array_filter((array) data_get($traefikState?->engineSpecific ?? [], 'errors', [])));
                }
                $traefikConfigFrom = ! empty($isEdgeProxyPanel) ? 'edge-proxy' : 'webserver';
                $traefikConfigReturnSub = ($engine_subtab === '' || $engine_subtab === 'config') ? 'overview' : $engine_subtab;
            @endphp

            @if ($key === 'traefik' && ($engine_subtab === 'overview' || ($optimisticEngineSubtabs ?? false)) && $isActive)
                <div
                    @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'overview'" x-cloak @endif
                    class="{{ $card }} p-6 sm:p-8 mb-6"
                    wire:key="traefik-overview-api"
                >
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Traefik API & dashboard') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm text-brand-moss">
                        {{ __('dply probes Traefik on 127.0.0.1:9094 (localhost-only). The dashboard and REST API match the official Traefik operations docs.') }}
                    </p>
                    @if ($traefikLiveStateErrors !== [])
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-950">
                            <p class="font-semibold">{{ __('Live state could not be loaded') }}</p>
                            <p class="mt-1 text-xs leading-relaxed whitespace-pre-line">{{ implode("\n", $traefikLiveStateErrors) }}</p>
                            <p class="mt-2 text-xs text-amber-900/90">
                                {{ __('Until the API on :port responds, version and router counts stay empty. If systemd shows inactive, use Start Traefik; otherwise use Repair API on :port, then Refresh live state on the Routers tab.', ['port' => '9094']) }}
                            </p>
                        </div>
                    @elseif (is_array($traefikOverview) && ($traefikVersion === null || ! is_array($traefikVersion)))
                        <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3 text-xs text-brand-moss">
                            {{ __('Counts are from the last successful API probe. If everything is zero, Traefik may have no routes yet — add sites on this server or check /etc/traefik/dynamic/ on the box.') }}
                        </div>
                    @endif
                    @php
                        $traefikDplyDashboardUrl = $server->edgeProxy() === 'traefik'
                            ? route('servers.traefik.dashboard', ['server' => $server])
                            : null;
                        $traefikPublicDashboardUrl = null;
                        if (
                            $server->edgeProxy() === 'traefik'
                            && ($traefik_dashboard_form['enabled'] ?? '0') === '1'
                            && filled($server->ip_address)
                        ) {
                            $publicPath = rtrim((string) ($traefik_dashboard_form['path'] ?? '/traefik-dashboard'), '/');
                            $traefikPublicDashboardUrl = 'http://'.$server->ip_address.$publicPath.'/';
                        }
                    @endphp

                    <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Version') }}</dt>
                            <dd class="mt-1 font-mono text-sm text-brand-ink">
                                {{ is_array($traefikVersion) ? ($traefikVersion['Version'] ?? $traefikVersion['version'] ?? '—') : '—' }}
                            </dd>
                        </div>
                        @if (is_array($traefikOverview))
                            @foreach (['http' => __('HTTP'), 'tcp' => __('TCP'), 'udp' => __('UDP')] as $layer => $layerLabel)
                                @php
                                    $layerData = $traefikOverview[$layer] ?? null;
                                    $routerCount = is_array($layerData) ? count($layerData['routers'] ?? []) : 0;
                                    $serviceCount = is_array($layerData) ? count($layerData['services'] ?? []) : 0;
                                    $mwCount = is_array($layerData) ? count($layerData['middlewares'] ?? []) : 0;
                                @endphp
                                <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ $layerLabel }}</dt>
                                    <dd class="mt-1 text-sm text-brand-ink tabular-nums">
                                        {{ __(':r routers · :s services', ['r' => $routerCount, 's' => $serviceCount]) }}
                                        @if ($layer === 'http' && $mwCount > 0)
                                            <span class="text-brand-moss"> · {{ __(':m middlewares', ['m' => $mwCount]) }}</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        @endif
                    </dl>

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        @if ($traefikDplyDashboardUrl)
                            <div class="rounded-xl border border-brand-sage/30 bg-brand-sage/10 p-4 sm:p-5">
                                <div class="flex flex-wrap items-start gap-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/20 text-brand-forest ring-1 ring-brand-sage/30">
                                        <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Dply dashboard') }}</p>
                                        <h4 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Open Traefik admin (signed in)') }}</h4>
                                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                            {{ __('Traefik stays on localhost :9094 on the server. This URL proxies the dashboard and API over SSH — only members of your organization who can view this server can open it.') }}
                                        </p>
                                        <div class="mt-3 flex flex-wrap items-center gap-2">
                                            <a
                                                href="{{ $traefikDplyDashboardUrl }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-ink"
                                            >
                                                {{ __('Open Traefik dashboard') }}
                                                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            </a>
                                            @if ($engineHasFullControls($key) && ! $isDeployer)
                                                @if ($traefikLiveStateErrors !== [])
                                                    <button
                                                        type="button"
                                                        wire:click="startTraefikService"
                                                        wire:loading.attr="disabled"
                                                        wire:target="startTraefikService"
                                                        @disabled(! $opsReady || ($inflightEdgeProxy ?? false) || ($inflightWebserverSwitch ?? false))
                                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                                    >
                                                        <span wire:loading.remove wire:target="startTraefikService">{{ __('Start Traefik') }}</span>
                                                        <span wire:loading wire:target="startTraefikService" class="inline-flex items-center gap-1.5">
                                                            <x-spinner variant="forest" size="sm" />
                                                            {{ __('Starting…') }}
                                                        </span>
                                                    </button>
                                                @endif
                                                <button
                                                    type="button"
                                                    wire:click="repairTraefikAdminApi"
                                                    wire:loading.attr="disabled"
                                                    wire:target="repairTraefikAdminApi"
                                                    @disabled(! $opsReady || ($inflightEdgeProxy ?? false) || ($inflightWebserverSwitch ?? false))
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                                >
                                                    <span wire:loading.remove wire:target="repairTraefikAdminApi">{{ __('Repair API on :port', ['port' => '9094']) }}</span>
                                                    <span wire:loading wire:target="repairTraefikAdminApi" class="inline-flex items-center gap-1.5">
                                                        <x-spinner variant="forest" size="sm" />
                                                        {{ __('Repairing…') }}
                                                    </span>
                                                </button>
                                            @endif
                                        </div>
                                        <p class="mt-2 break-all font-mono text-[11px] text-brand-moss">{{ $traefikDplyDashboardUrl }}</p>
                                        <p class="mt-2 text-[11px] text-brand-moss">
                                            {{ __('If the dashboard shows a connection error, use Repair API to rewrite traefik.yml to dply defaults (keeps your public web port, restores 127.0.0.1:9094) and restart Traefik. Check the console output if it fails.') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endif
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5 {{ $traefikDplyDashboardUrl ? '' : 'lg:col-span-2' }}">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Local API (on server)') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Used by dply live-state probes — not reachable from your browser.') }}</p>
                            <code class="mt-3 block break-all rounded-lg bg-white px-3 py-2 font-mono text-[11px] text-brand-ink ring-1 ring-brand-ink/10">http://127.0.0.1:9094/dashboard/</code>
                        </div>
                    </div>

                    @if ($traefikPublicDashboardUrl)
                        <div class="mt-4 rounded-xl border border-amber-200/80 bg-amber-50/50 p-4 sm:p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-900">{{ __('Public dashboard URL') }}</p>
                            <p class="mt-1 text-xs text-amber-900/90">
                                {{ __('Exposed on the server\'s web entry point (:80). Anyone who can reach this IP can open the dashboard unless HTTP basic auth is configured below.') }}
                            </p>
                            <a
                                href="{{ $traefikPublicDashboardUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-forest hover:text-brand-ink"
                            >
                                {{ __('Open public dashboard') }}
                                <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </a>
                            <code class="mt-2 block break-all rounded-lg bg-white/80 px-3 py-2 font-mono text-[11px] text-brand-ink ring-1 ring-amber-200">{{ $traefikPublicDashboardUrl }}</code>
                        </div>
                    @endif

                    @if ($engineHasFullControls($key) && ! $isDeployer)
                        <form wire:submit.prevent="saveTraefikDashboardConfig" class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5">
                            <h4 class="text-sm font-semibold text-brand-ink">{{ __('Public dashboard (optional)') }}</h4>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Also expose the Traefik dashboard on the server\'s :80 web entry point. Prefer the Dply dashboard link above for day-to-day use. Optional HTTP basic auth applies to the public URL only.') }}</p>
                            @if ($traefik_dashboard_flash)
                                <div class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-sm text-emerald-900">{{ $traefik_dashboard_flash }}</div>
                            @endif
                            @if ($traefik_dashboard_error)
                                <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-sm text-rose-900">{{ $traefik_dashboard_error }}</div>
                            @endif
                            <label class="mt-4 inline-flex items-center gap-2">
                                <input type="checkbox" value="1" wire:model.live="traefik_dashboard_form.enabled" @checked(($traefik_dashboard_form['enabled'] ?? '0') === '1') class="h-4 w-4 rounded text-brand-forest" />
                                <span class="text-sm text-brand-ink">{{ __('Expose dashboard on web entry point') }}</span>
                            </label>
                            @if (($traefik_dashboard_form['enabled'] ?? '0') === '1')
                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="text-xs font-medium">{{ __('URL path prefix') }}</span>
                                        <input type="text" wire:model.lazy="traefik_dashboard_form.path" placeholder="/traefik-dashboard" class="mt-1 w-full rounded-md border-brand-ink/15 font-mono text-sm" />
                                        @if (filled($server->ip_address))
                                            @php
                                                $previewPublicPath = rtrim((string) ($traefik_dashboard_form['path'] ?? '/traefik-dashboard'), '/');
                                            @endphp
                                            <span class="mt-1 block break-all font-mono text-[11px] text-brand-mist">http://{{ $server->ip_address }}{{ $previewPublicPath }}/</span>
                                        @else
                                            <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Public URL appears here after the server has an IP address.') }}</span>
                                        @endif
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium">{{ __('Basic auth username (optional)') }}</span>
                                        <input type="text" wire:model.lazy="traefik_dashboard_form.username" autocomplete="off" class="mt-1 w-full rounded-md border-brand-ink/15 text-sm" />
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="text-xs font-medium">{{ __('Basic auth password (optional)') }}</span>
                                        <input type="password" wire:model.lazy="traefik_dashboard_form.password" autocomplete="new-password" class="mt-1 w-full max-w-md rounded-md border-brand-ink/15 text-sm" />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Leave blank to keep the existing password when updating.') }}</span>
                                    </label>
                                </div>
                            @endif
                            <div class="mt-4 flex justify-end">
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveTraefikDashboardConfig"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream disabled:opacity-60">
                                    {{ __('Save dashboard exposure') }}
                                </button>
                            </div>
                        </form>
                    @endif

                    @if (! empty($isEdgeProxyPanel) && ($isActive ?? false))
                        @include('livewire.servers.partials.edge-proxy.remove-active-panel', [
                            'info' => ['label' => $info['label'] ?? __('Traefik')],
                        ])
                    @endif

                    <p class="mt-4 text-xs text-brand-mist">
                        <a href="https://doc.traefik.io/traefik/" target="_blank" rel="noopener noreferrer" class="text-brand-forest hover:underline">{{ __('Traefik documentation') }}</a>
                        ·
                        <a href="https://doc.traefik.io/traefik/operations/api/" target="_blank" rel="noopener noreferrer" class="text-brand-forest hover:underline">{{ __('REST API reference') }}</a>
                    </p>
                </div>
            @endif

            @if ($key === 'traefik' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'static' || ($optimisticEngineSubtabs ?? false)))
                <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'static'" x-cloak @endif>
                @php
                    $traefikParams = \App\Services\Servers\TraefikStaticConfigOptions::PARAMS;
                    $traefikGroups = \App\Services\Servers\TraefikStaticConfigOptions::PARAM_GROUPS;
                @endphp
                <div
                    class="{{ $card }} p-6 sm:p-8 mb-6"
                    wire:key="traefik-static-config"
                    x-data="{
                        expanded: true,
                        storageKey: @js('dply.traefik-static-expanded:'.$server->id),
                        init() {
                            try {
                                const saved = window.localStorage?.getItem(this.storageKey);
                                if (saved === '0') this.expanded = false;
                            } catch (e) {}
                        },
                        toggle() {
                            this.expanded = !this.expanded;
                            try { window.localStorage?.setItem(this.storageKey, this.expanded ? '1' : '0'); } catch (e) {}
                        },
                    }"
                    x-init="init()"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <button
                            type="button"
                            x-on:click="toggle()"
                            class="group flex min-w-0 flex-1 items-start gap-3 text-left"
                            x-bind:aria-expanded="expanded.toString()"
                        >
                            <x-heroicon-o-chevron-down
                                class="mt-1 h-4 w-4 shrink-0 text-brand-moss transition-transform"
                                x-bind:class="expanded ? '' : '-rotate-90'"
                                aria-hidden="true"
                            />
                            <span class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Traefik static config') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Settings in /etc/traefik/traefik.yml — entry points, providers, API, logging, ACME, and global options per the Traefik static configuration reference.') }}
                                </p>
                                <p class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-amber-50/70 px-2.5 py-1 text-[11px] font-medium text-amber-900 ring-1 ring-amber-200">
                                    <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5" />
                                    {{ __('Static config requires a Traefik RESTART (not reload). Edge briefly drops connections on save.') }}
                                </p>
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="loadTraefikStaticConfig"
                            wire:loading.attr="disabled"
                            wire:target="loadTraefikStaticConfig"
                            x-show="expanded"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadTraefikStaticConfig" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            </span>
                            <span wire:loading wire:target="loadTraefikStaticConfig" class="inline-flex">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                            {{ __('Reload from server') }}
                        </button>
                    </div>

                    <div x-show="expanded" x-cloak>
                        @if ($traefik_static_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $traefik_static_flash }}</div>
                        @endif
                        @if ($traefik_static_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $traefik_static_error }}</pre>
                            </div>
                        @endif

                        @if (! $traefik_static_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadTraefikStaticConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading traefik.yml…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadTraefikStaticConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
                        @else
                            <form wire:submit.prevent="saveTraefikStaticConfig" class="mt-6 space-y-8">
                                @foreach ($traefikGroups as $groupKey => $groupLabel)
                                    @php
                                        $groupParams = array_filter(
                                            $traefikParams,
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
                                                        <span class="mt-0.5 block font-mono text-[10px] text-brand-mist">{{ $meta['path'] }}</span>
                                                        @if ($meta['type'] === 'bool')
                                                            <span class="mt-2 inline-flex items-center gap-2">
                                                                <input type="checkbox" value="1"
                                                                    wire:model.live="traefik_static_form.{{ $paramKey }}"
                                                                    @checked(($traefik_static_form[$paramKey] ?? '0') === '1')
                                                                    class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                                                <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                            </span>
                                                        @else
                                                            <input type="text"
                                                                wire:model.lazy="traefik_static_form.{{ $paramKey }}"
                                                                placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                            <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                        @endif
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endif
                                @endforeach

                                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="saveTraefikStaticConfig"
                                        @disabled($isDeployer || $actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="saveTraefikStaticConfig" class="inline-flex">
                                            <x-heroicon-o-arrow-path-rounded-square class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="saveTraefikStaticConfig" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Save and restart Traefik') }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
                </div>
            @endif

            @if ($key === 'traefik' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'dynamic' || ($optimisticEngineSubtabs ?? false)))
                <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'dynamic'" x-cloak @endif>
                <div class="{{ $card }} p-6 sm:p-8 mb-6" wire:key="traefik-dynamic-files">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Dynamic routing files') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Per-site and custom routes in /etc/traefik/dynamic/*.yml. Traefik hot-reloads these via the file provider (no restart).') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            wire:click="loadTraefikDynamicConfigs"
                            wire:loading.attr="disabled"
                            wire:target="loadTraefikDynamicConfigs"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadTraefikDynamicConfigs" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            </span>
                            <span wire:loading wire:target="loadTraefikDynamicConfigs" class="inline-flex">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                            {{ __('Refresh list') }}
                        </button>
                    </div>

                    @if ($traefik_dynamic_error)
                        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">{{ $traefik_dynamic_error }}</div>
                    @endif

                    @if (! $traefik_dynamic_loaded)
                        <p class="mt-5 text-sm text-brand-moss">
                            <span wire:loading wire:target="loadTraefikDynamicConfigs" class="inline-flex items-center gap-2">
                                <x-spinner class="h-3.5 w-3.5" /> {{ __('Listing dynamic files…') }}
                            </span>
                        </p>
                    @elseif ($traefik_dynamic_files === [])
                        <x-empty-state
                            class="mt-6"
                            icon="heroicon-o-document-text"
                            :title="__('No dynamic YAML files yet')"
                            :description="__('Site routes appear here after provisioning. You can also add files manually in the Configuration editor.')"
                        />
                    @else
                        <div class="mt-6 overflow-x-auto rounded-lg border border-brand-ink/10">
                            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                <thead class="bg-brand-sand/30">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-medium text-brand-ink">{{ __('File') }}</th>
                                        <th class="px-4 py-2 text-left font-medium text-brand-ink">{{ __('Size') }}</th>
                                        <th class="px-4 py-2 text-right font-medium text-brand-ink">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5 bg-white">
                                    @foreach ($traefik_dynamic_files as $dynFile)
                                        <tr>
                                            <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $dynFile['basename'] }}</td>
                                            <td class="px-4 py-2 tabular-nums text-xs text-brand-moss">{{ number_format((int) ($dynFile['size'] ?? 0)) }} B</td>
                                            <td class="px-4 py-2 text-right">
                                                <a
                                                    href="{{ route('servers.configuration', ['server' => $server, 'scope' => 'traefik', 'from' => $traefikConfigFrom, 'return_sub' => 'dynamic', 'file' => $dynFile['path']]) }}"
                                                    wire:navigate
                                                    class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                >
                                                    <x-heroicon-o-pencil-square class="h-3 w-3" />
                                                    {{ __('Edit in Configuration') }}
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
                </div>
            @endif

            @include('livewire.servers.partials.webserver.engine._traefik-providers-setup')
            @include('livewire.servers.partials.webserver.engine._traefik-entrypoints')
            @include('livewire.servers.partials.webserver.engine._traefik-custom-routes')
            @include('livewire.servers.partials.webserver.engine._traefik-custom-middlewares')
            @include('livewire.servers.partials.webserver.engine._traefik-http-services')
            @include('livewire.servers.partials.webserver.engine._traefik-tcp-routes')
            @include('livewire.servers.partials.webserver.engine._traefik-udp-routes')
