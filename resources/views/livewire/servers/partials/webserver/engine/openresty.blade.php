            @php
                $openrestyOverview = null;
                $openrestyVersion = null;
                $openrestyLiveStateErrors = [];
                if ($key === 'openresty' && $isActive) {
                    $openrestyLive = data_get($server->meta ?? [], 'webserver_live_state.openresty');
                    $openrestyState = \App\Services\Servers\LiveState\EngineLiveState::fromArray($openrestyLive);
                    $openrestyOverview = [
                        'servers' => count($openrestyState?->units['servers'] ?? []),
                        'upstreams' => count($openrestyState?->units['upstreams'] ?? []),
                    ];
                    $openrestyVersion = data_get($openrestyState?->units ?? [], 'runtime.0.version');
                    $openrestyLiveStateErrors = \App\Services\Servers\LiveState\EngineLiveState::probeErrorLines(
                        data_get($openrestyState?->engineSpecific ?? [], 'errors', []),
                    );
                }
            @endphp

            @if ($key === 'openresty' && ($engine_subtab === 'overview' || ($optimisticEngineSubtabs ?? false)) && $isActive)
                <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'overview'" x-cloak @endif class="{{ $card }} p-6 sm:p-8 mb-6" wire:key="openresty-overview">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('OpenResty edge proxy') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm text-brand-moss">
                        {{ __('nginx + LuaJIT on :80 in front of per-site Caddy backends on high ports. dply generates /etc/openresty/nginx.conf and reloads on every edge routing rebuild.', ['port' => ':80']) }}
                    </p>

                    @if ($openrestyLiveStateErrors !== [])
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-950">
                            <p class="font-semibold">{{ __('Live state could not be loaded') }}</p>
                            <p class="mt-1 text-xs whitespace-pre-line">{{ implode("\n", $openrestyLiveStateErrors) }}</p>
                        </div>
                    @endif

                    <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Version') }}</dt>
                            <dd class="mt-1 font-mono text-sm text-brand-ink">{{ is_string($openrestyVersion) && $openrestyVersion !== '' ? $openrestyVersion : '—' }}</dd>
                        </div>
                        @if (is_array($openrestyOverview))
                            @foreach (['servers' => __('Server blocks'), 'upstreams' => __('Upstreams')] as $metric => $label)
                                <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ $label }}</dt>
                                    <dd class="mt-1 text-sm tabular-nums">{{ number_format((int) ($openrestyOverview[$metric] ?? 0)) }}</dd>
                                </div>
                            @endforeach
                        @endif
                    </dl>

                    @if (! empty($isEdgeProxyPanel) && ($isActive ?? false))
                        @include('livewire.servers.partials.edge-proxy.remove-active-panel', [
                            'info' => ['label' => $info['label'] ?? __('OpenResty')],
                        ])
                    @endif
                </div>
            @endif

            @if ($key === 'openresty' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'static' || ($optimisticEngineSubtabs ?? false)))
                <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'static'" x-cloak @endif>
                @php
                    $openrestyParams = \App\Services\Servers\OpenRestyStaticConfigOptions::PARAMS;
                    $openrestyGroups = \App\Services\Servers\OpenRestyStaticConfigOptions::PARAM_GROUPS;
                @endphp
                <div class="{{ $card }} p-6 sm:p-8 mb-6" wire:key="openresty-static-config">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('OpenResty static settings') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Worker and HTTP defaults merged into nginx.conf on every edge routing rebuild.') }}</p>
                        </div>
                        <button type="button" wire:click="loadOpenRestyStaticConfig" wire:loading.attr="disabled" wire:target="loadOpenRestyStaticConfig"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium">
                            <x-heroicon-o-arrow-path class="h-4 w-4" /> {{ __('Reload from server') }}
                        </button>
                    </div>
                    @if ($openresty_static_flash)
                        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $openresty_static_flash }}</div>
                    @endif
                    @if ($openresty_static_error)
                        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900"><pre class="whitespace-pre-wrap font-mono text-xs">{{ $openresty_static_error }}</pre></div>
                    @endif
                    @if ($openresty_static_loaded)
                        <form wire:submit.prevent="saveOpenRestyStaticConfig" class="mt-6 space-y-6">
                            @foreach ($openrestyGroups as $groupKey => $groupLabel)
                                @php $groupParams = array_filter($openrestyParams, fn ($m) => ($m['group'] ?? '') === $groupKey); @endphp
                                @if ($groupParams !== [])
                                    <fieldset class="space-y-4">
                                        <legend class="text-sm font-semibold text-brand-ink border-b border-brand-ink/10 pb-2 w-full">{{ __($groupLabel) }}</legend>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            @foreach ($groupParams as $paramKey => $meta)
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    <input type="text" wire:model.lazy="openresty_static_form.{{ $paramKey }}" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm" />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @endif
                            @endforeach
                            <div class="flex justify-end border-t border-brand-ink/10 pt-4">
                                <button type="submit" @disabled($isDeployer || $actionInFlight) class="rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream">{{ __('Save and regenerate routing') }}</button>
                            </div>
                        </form>
                    @else
                        <p class="mt-5 text-sm text-brand-moss">{{ __('Click "Reload from server" to fetch current values.') }}</p>
                    @endif
                </div>
                </div>
            @endif

            @if ($key === 'openresty' && $engine_subtab === 'upstreams' && $isActive && $engineHasFullControls($key))
                <div class="space-y-4 mb-6" wire:key="openresty-upstreams-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('Custom upstreams') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Extra upstream pools for non-Caddy targets. Site upstreams (`bk_*`) regenerate automatically.') }}</p>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" wire:click="openAddOpenRestyUpstreamForm" @disabled($isDeployer || $actionInFlight) class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream"><x-heroicon-o-plus class="inline h-4 w-4" /> {{ __('Add upstream') }}</button>
                                <button type="button" wire:click="loadOpenRestyUpstreamsConfig" class="rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs">{{ __('Reload') }}</button>
                            </div>
                        </div>
                        @if ($openresty_upstreams_flash)<div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $openresty_upstreams_flash }}</div>@endif
                        @if ($openresty_upstreams_error)<div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900"><pre class="whitespace-pre-wrap font-mono text-xs">{{ $openresty_upstreams_error }}</pre></div>@endif
                        @if ($openresty_upstreams_show_add)
                            <form wire:submit.prevent="submitAddOpenRestyUpstream" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <label class="block"><span class="text-xs font-medium">{{ __('Name') }}</span><input type="text" wire:model.lazy="openresty_upstreams_new.name" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm" required /></label>
                                    <label class="block sm:col-span-2"><span class="text-xs font-medium">{{ __('Servers (host:port, comma or newline)') }}</span><textarea wire:model.lazy="openresty_upstreams_new.servers" rows="3" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs text-emerald-100"></textarea></label>
                                </div>
                                <div class="mt-4 flex justify-end gap-2"><button type="button" wire:click="cancelAddOpenRestyUpstreamForm">{{ __('Cancel') }}</button><button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Create') }}</button></div>
                            </form>
                        @endif
                    </div>
                    @if ($openresty_upstreams_loaded && ! empty($openresty_upstreams_form))
                        <form wire:submit.prevent="saveOpenRestyUpstreamsConfig" class="space-y-4">
                            @foreach ($openresty_upstreams_form as $upstreamName => $payload)
                                <div class="{{ $card }} p-5" wire:key="openresty-upstream-{{ $upstreamName }}">
                                    <div class="flex justify-between gap-3">
                                        <p class="font-mono text-sm font-semibold">{{ $upstreamName }}</p>
                                        <button type="button" wire:click="openConfirmActionModal('removeOpenRestyUpstream', ['{{ $upstreamName }}'], @js(__('Remove upstream: :name', ['name' => $upstreamName])), @js(__('Remove `:name` and regenerate edge routing?', ['name' => $upstreamName])), @js(__('Remove')), true)" class="text-xs text-rose-800">{{ __('Remove') }}</button>
                                    </div>
                                    <textarea wire:model.lazy="openresty_upstreams_servers_text.{{ $upstreamName }}" rows="3" class="mt-3 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs text-emerald-100"></textarea>
                                </div>
                            @endforeach
                            <div class="flex justify-end"><button type="submit" @disabled($isDeployer || $actionInFlight) class="rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream">{{ __('Save and regenerate routing') }}</button></div>
                        </form>
                    @elseif ($openresty_upstreams_loaded)
                        <x-empty-state icon="heroicon-o-server" :title="__('No custom upstreams yet')" />
                    @endif
                </div>
            @endif

            @if ($key === 'openresty' && $engine_subtab === 'servers' && $isActive && $engineHasFullControls($key))
                <div class="space-y-4 mb-6" wire:key="openresty-servers-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('Custom server blocks') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Extra Host → upstream routes merged before the catch-all. Reference custom upstreams or dply site pools (`bk_*`).') }}</p>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" wire:click="openAddOpenRestyServerForm" @disabled($isDeployer || $actionInFlight) class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream"><x-heroicon-o-plus class="inline h-4 w-4" /> {{ __('Add server block') }}</button>
                                <button type="button" wire:click="loadOpenRestyServersConfig" class="rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs">{{ __('Reload') }}</button>
                            </div>
                        </div>
                        @if ($openresty_servers_flash)<div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $openresty_servers_flash }}</div>@endif
                        @if ($openresty_servers_error)<div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900"><pre class="whitespace-pre-wrap font-mono text-xs">{{ $openresty_servers_error }}</pre></div>@endif
                        @if ($openresty_servers_show_add)
                            <form wire:submit.prevent="submitAddOpenRestyServer" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <label class="block"><span class="text-xs font-medium">{{ __('Name') }}</span><input type="text" wire:model.lazy="openresty_servers_new.name" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm" required /></label>
                                    <label class="block"><span class="text-xs font-medium">{{ __('Upstream') }}</span><input type="text" wire:model.lazy="openresty_servers_new.upstream" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm" required /></label>
                                    <label class="block sm:col-span-2"><span class="text-xs font-medium">{{ __('server_name values') }}</span><textarea wire:model.lazy="openresty_servers_new.server_names" rows="3" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs text-emerald-100"></textarea></label>
                                </div>
                                <div class="mt-4 flex justify-end gap-2"><button type="button" wire:click="cancelAddOpenRestyServerForm">{{ __('Cancel') }}</button><button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Create') }}</button></div>
                            </form>
                        @endif
                    </div>
                    @if ($openresty_servers_loaded && ! empty($openresty_servers_form))
                        <form wire:submit.prevent="saveOpenRestyServersConfig" class="space-y-4">
                            @foreach ($openresty_servers_form as $serverName => $payload)
                                <div class="{{ $card }} p-5" wire:key="openresty-server-{{ $serverName }}">
                                    <div class="flex justify-between gap-3">
                                        <p class="font-mono text-sm font-semibold">{{ $serverName }}</p>
                                        <button type="button" wire:click="openConfirmActionModal('removeOpenRestyServer', ['{{ $serverName }}'], @js(__('Remove server: :name', ['name' => $serverName])), @js(__('Remove `:name` and regenerate edge routing?', ['name' => $serverName])), @js(__('Remove')), true)" class="text-xs text-rose-800">{{ __('Remove') }}</button>
                                    </div>
                                    <label class="mt-3 block"><span class="text-sm">{{ __('server_name') }}</span><textarea wire:model.lazy="openresty_servers_names_text.{{ $serverName }}" rows="2" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs text-emerald-100"></textarea></label>
                                    <label class="mt-3 block"><span class="text-sm">{{ __('Upstream') }}</span><input type="text" wire:model.lazy="openresty_servers_form.{{ $serverName }}.upstream" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm" /></label>
                                </div>
                            @endforeach
                            <div class="flex justify-end"><button type="submit" @disabled($isDeployer || $actionInFlight) class="rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream">{{ __('Save and regenerate routing') }}</button></div>
                        </form>
                    @elseif ($openresty_servers_loaded)
                        <x-empty-state icon="heroicon-o-server-stack" :title="__('No custom server blocks yet')" />
                    @endif
                </div>
            @endif
