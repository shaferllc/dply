            @if ($key === 'openlitespeed' && $engine_subtab === 'vhosts' && $isActive && $engineHasFullControls($key))
                @php $vhostParams = \App\Services\Servers\OpenLiteSpeedVhostsConfig::PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="ols-vhosts-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('OpenLiteSpeed vhost settings') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Per-vhost tunables in /usr/local/lsws/conf/vhosts/<name>/vhconf.conf. Each vhost maps to one Site — adds/removes happen in the Sites workspace.') }}
                                </p>
                                <p class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-amber-50/70 px-2.5 py-1 text-[11px] font-medium text-amber-900 ring-1 ring-amber-200">
                                    <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
                                    {{ __('Edits here are overwritten the next time you Apply the matching Site (or switch webserver). Use the Site workspace for durable changes.') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                wire:click="loadOlsVhostsConfig"
                                wire:loading.attr="disabled"
                                wire:target="loadOlsVhostsConfig"
                                class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                            >
                                <span wire:loading.remove wire:target="loadOlsVhostsConfig" class="inline-flex">
                                    <x-heroicon-o-arrow-path class="h-4 w-4" />
                                </span>
                                <span wire:loading wire:target="loadOlsVhostsConfig" class="inline-flex">
                                    <x-spinner class="h-4 w-4" />
                                </span>
                                {{ __('Reload from server') }}
                            </button>
                        </div>

                        @if ($ols_vhosts_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $ols_vhosts_flash }}</div>
                        @endif
                        @if ($ols_vhosts_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $ols_vhosts_error }}</pre>
                            </div>
                        @endif

                        @if (! $ols_vhosts_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadOlsVhostsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-4 w-4" /> {{ __('Reading config…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadOlsVhostsConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
                        @endif
                    </div>

                    @if ($ols_vhosts_loaded && ! empty($ols_vhosts_form))
                        <form wire:submit.prevent="saveOlsVhostsConfig" class="space-y-4">
                            @foreach ($ols_vhosts_form as $vhostName => $values)
                                @php $identity = $ols_vhosts_identity[$vhostName] ?? []; @endphp
                                <div
                                    class="{{ $card }} p-5 sm:p-6"
                                    x-data="{
                                        expanded: false,
                                        storageKey: @js('dply.ols-vhost-expanded:'.$server->id.':'.$vhostName),
                                        init() {
                                            try {
                                                const saved = window.localStorage?.getItem(this.storageKey);
                                                if (saved === '1') this.expanded = true;
                                            } catch (e) {}
                                        },
                                        toggle() {
                                            this.expanded = !this.expanded;
                                            try { window.localStorage?.setItem(this.storageKey, this.expanded ? '1' : '0'); } catch (e) {}
                                        },
                                    }"
                                    x-init="init()"
                                    wire:key="ols-vhost-{{ $vhostName }}"
                                >
                                    <button
                                        type="button"
                                        x-on:click="toggle()"
                                        class="group flex w-full items-start gap-3 text-left"
                                        x-bind:aria-expanded="expanded.toString()"
                                    >
                                        <x-heroicon-o-chevron-down
                                            class="mt-1 h-4 w-4 shrink-0 text-brand-moss transition-transform"
                                            x-bind:class="expanded ? '' : '-rotate-90'"
                                            aria-hidden="true"
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="font-mono text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ $vhostName }}</span>
                                                @if (! empty($identity['unreadable']))
                                                    <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-700">unreadable</span>
                                                @endif
                                                @if (! empty($identity['domains']))
                                                    <span class="text-[11px] text-brand-mist">{{ implode(', ', array_slice($identity['domains'], 0, 3)) }}@if (count($identity['domains']) > 3) +{{ count($identity['domains']) - 3 }} @endif</span>
                                                @endif
                                            </span>
                                            @if (! empty($identity['conf_path']))
                                                <span class="mt-0.5 block truncate text-[11px] font-mono text-brand-mist">{{ $identity['conf_path'] }}</span>
                                            @endif
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-5">
                                        @if (! empty($identity['unreadable']))
                                            <div class="rounded-md bg-rose-50/60 px-3 py-2 text-[11px] text-rose-900">
                                                {{ __('Could not read this vhost\'s vhconf.conf. Defaults shown — saving will create the file or overwrite it.') }}
                                            </div>
                                        @endif
                                        @if (! empty($identity['vh_root']))
                                            <p class="text-[11px] text-brand-mist">
                                                <span class="font-semibold">{{ __('vhRoot') }}</span>
                                                <span class="font-mono">{{ $identity['vh_root'] }}</span>
                                            </p>
                                        @endif

                                        <div class="grid gap-5 sm:grid-cols-2">
                                            @foreach ($vhostParams as $paramKey => $meta)
                                                @if ($meta['type'] === 'list')
                                                    @continue
                                                @endif
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    @if ($meta['type'] === 'bool')
                                                        <span class="mt-1 inline-flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                value="1"
                                                                wire:model.live="ols_vhosts_form.{{ $vhostName }}.{{ $paramKey }}"
                                                                @checked(($values[$paramKey] ?? '0') === '1')
                                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                                            />
                                                            <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                        </span>
                                                    @elseif ($meta['type'] === 'int')
                                                        <input
                                                            type="number"
                                                            wire:model.lazy="ols_vhosts_form.{{ $vhostName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @else
                                                        <input
                                                            type="text"
                                                            wire:model.lazy="ols_vhosts_form.{{ $vhostName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>

                                        @foreach ($vhostParams as $paramKey => $meta)
                                            @if ($meta['type'] !== 'list')
                                                @continue
                                            @endif
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                <textarea
                                                    rows="2"
                                                    wire:model.lazy="ols_vhosts_form.{{ $vhostName }}.{{ $paramKey }}"
                                                    placeholder="index.php, index.html"
                                                    class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                ></textarea>
                                                <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="saveOlsVhostsConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="saveOlsVhostsConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveOlsVhostsConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload OpenLiteSpeed') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 OPENLITESPEED — LISTENERS CONFIG. One collapsible card per
                 listener block. Identity (name/address/secure) is fixed at
                 create-time; TLS + protocol tunables are editable. The
                 dply-managed "Default" listener is rebuilt on switch, so
                 we surface the relevant edits but block removal.
                 ============================================================= --}}
            @if ($key === 'openlitespeed' && $engine_subtab === 'listeners' && $isActive && $engineHasFullControls($key))
                @php $listenerParams = \App\Services\Servers\OpenLiteSpeedListenersConfig::PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="ols-listeners-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('OpenLiteSpeed listeners') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Bind addresses + TLS termination defined in /usr/local/lsws/conf/httpd_config.conf. dply manages the "Default" :80 listener; add custom listeners for HTTPS or alt-port admin endpoints. Save validates and reloads; a failed validate auto-restores.') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="openAddOlsListenerForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4" />
                                    {{ __('Add listener') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadOlsListenersConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadOlsListenersConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadOlsListenersConfig" class="inline-flex">
                                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="loadOlsListenersConfig" class="inline-flex">
                                        <x-spinner class="h-4 w-4" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($ols_listeners_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $ols_listeners_flash }}</div>
                        @endif
                        @if ($ols_listeners_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $ols_listeners_error }}</pre>
                            </div>
                        @endif

                        @if ($ols_listeners_show_add)
                            <form
                                wire:submit.prevent="submitAddOlsListener"
                                class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5"
                            >
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a new listener') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Reserved name "Default" is owned by dply. For HTTPS listeners, point keyFile/certFile at an existing cert on disk.') }}</p>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="ols_listeners_new.name"
                                            placeholder="HTTPS"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Address') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="ols_listeners_new.address"
                                            placeholder="*:443"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('e.g. `*:443`, `127.0.0.1:8080`, `0.0.0.0:7080`.') }}</span>
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="inline-flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                value="1"
                                                wire:model.live="ols_listeners_new.secure"
                                                @checked(($ols_listeners_new['secure'] ?? '0') === '1')
                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                            />
                                            <span class="text-sm font-medium text-brand-ink">{{ __('TLS / HTTPS listener') }}</span>
                                        </span>
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('When on, OLS terminates TLS using keyFile + certFile below.') }}</span>
                                    </label>
                                    @if (($ols_listeners_new['secure'] ?? '0') === '1')
                                        <label class="block">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Private key path') }}</span>
                                            <input
                                                type="text"
                                                wire:model.lazy="ols_listeners_new.keyFile"
                                                placeholder="/etc/letsencrypt/live/example.com/privkey.pem"
                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            />
                                        </label>
                                        <label class="block">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Certificate path') }}</span>
                                            <input
                                                type="text"
                                                wire:model.lazy="ols_listeners_new.certFile"
                                                placeholder="/etc/letsencrypt/live/example.com/fullchain.pem"
                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            />
                                        </label>
                                    @endif
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button
                                        type="button"
                                        wire:click="cancelAddOlsListenerForm"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        {{ __('Cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="submitAddOlsListener"
                                        @disabled($actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="submitAddOlsListener" class="inline-flex">
                                            <x-heroicon-o-plus class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="submitAddOlsListener" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        @if (! $ols_listeners_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadOlsListenersConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-4 w-4" /> {{ __('Reading config…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadOlsListenersConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
                        @endif
                    </div>

                    @if ($ols_listeners_loaded && ! empty($ols_listeners_form))
                        <form wire:submit.prevent="saveOlsListenersConfig" class="space-y-4">
                            @foreach ($ols_listeners_form as $listenerName => $values)
                                @php
                                    $identity = $ols_listeners_identity[$listenerName] ?? [];
                                    $isSecure = ($identity['secure'] ?? '0') === '1';
                                    $isManaged = in_array($listenerName, \App\Services\Servers\OpenLiteSpeedListenersConfig::MANAGED_NAMES, true);
                                    $mapEntries = $ols_listeners_maps[$listenerName] ?? [];
                                @endphp
                                <div
                                    class="{{ $card }} p-5 sm:p-6"
                                    x-data="{
                                        expanded: false,
                                        storageKey: @js('dply.ols-listener-expanded:'.$server->id.':'.$listenerName),
                                        init() {
                                            try {
                                                const saved = window.localStorage?.getItem(this.storageKey);
                                                if (saved === '1') this.expanded = true;
                                            } catch (e) {}
                                        },
                                        toggle() {
                                            this.expanded = !this.expanded;
                                            try { window.localStorage?.setItem(this.storageKey, this.expanded ? '1' : '0'); } catch (e) {}
                                        },
                                    }"
                                    x-init="init()"
                                    wire:key="ols-listener-{{ $listenerName }}"
                                >
                                    <button
                                        type="button"
                                        x-on:click="toggle()"
                                        class="group flex w-full items-start gap-3 text-left"
                                        x-bind:aria-expanded="expanded.toString()"
                                    >
                                        <x-heroicon-o-chevron-down
                                            class="mt-1 h-4 w-4 shrink-0 text-brand-moss transition-transform"
                                            x-bind:class="expanded ? '' : '-rotate-90'"
                                            aria-hidden="true"
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="font-mono text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ $listenerName }}</span>
                                                @if ($isSecure)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">
                                                        <x-heroicon-o-lock-closed class="h-3 w-3" /> TLS
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">HTTP</span>
                                                @endif
                                                @if ($isManaged)
                                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700">dply</span>
                                                @endif
                                            </span>
                                            @if (! empty($identity['address']))
                                                <span class="mt-0.5 block truncate text-[11px] font-mono text-brand-mist">{{ $identity['address'] }}</span>
                                            @endif
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-5">
                                        @if (! $isManaged)
                                            <div class="flex items-center justify-end">
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('removeOlsListener', ['{{ $listenerName }}'], @js(__('Remove listener: :name', ['name' => $listenerName])), @js(__('Remove the `:name` listener? Sites mapped to this listener stop serving immediately on the bound port.', ['name' => $listenerName])), @js(__('Remove')), true)"
                                                    @disabled($isDeployer || $actionInFlight)
                                                    class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    <x-heroicon-o-trash class="h-4 w-4" />
                                                    {{ __('Remove') }}
                                                </button>
                                            </div>
                                        @else
                                            <p class="rounded-md bg-amber-50/60 px-3 py-2 text-[11px] text-amber-900">
                                                {{ __('Managed by dply — the switch flow / provisioner re-emits this listener on reconcile. Edits to tunables persist between reconciles; removal is blocked.') }}
                                            </p>
                                        @endif

                                        <div class="grid gap-5 sm:grid-cols-2">
                                            @foreach ($listenerParams as $paramKey => $meta)
                                                @php
                                                    // Skip TLS-only directives on plain HTTP listeners — they
                                                    // wouldn't apply and would just confuse the operator.
                                                    $tlsOnly = in_array($paramKey, ['keyFile', 'certFile', 'certChain', 'sslProtocol', 'enableSpdy', 'enableQuic', 'enableStapling', 'clientVerify'], true);
                                                @endphp
                                                @if ($tlsOnly && ! $isSecure)
                                                    @continue
                                                @endif
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    @if ($meta['type'] === 'bool')
                                                        <span class="mt-1 inline-flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                value="1"
                                                                wire:model.live="ols_listeners_form.{{ $listenerName }}.{{ $paramKey }}"
                                                                @checked(($values[$paramKey] ?? '0') === '1')
                                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                                            />
                                                            <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                        </span>
                                                    @elseif ($meta['type'] === 'int')
                                                        <input
                                                            type="number"
                                                            wire:model.lazy="ols_listeners_form.{{ $listenerName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @else
                                                        <input
                                                            type="text"
                                                            wire:model.lazy="ols_listeners_form.{{ $listenerName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>

                                        @if (! empty($mapEntries))
                                            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Vhost mappings (read-only — managed by site provisioning)') }}</p>
                                                <ul class="mt-2 space-y-1 font-mono text-[11px] text-brand-ink">
                                                    @foreach ($mapEntries as $mapEntry)
                                                        <li>{{ $mapEntry }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="saveOlsListenersConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="saveOlsListenersConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveOlsListenersConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload OpenLiteSpeed') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 OPENLITESPEED — EXTAPPS CONFIG. One collapsible card per
                 extprocessor block. Identity (name/type/address/path) is
                 read-only; tunables (maxConns, env, etc.) are editable.
                 ============================================================= --}}
            @if ($key === 'openlitespeed' && $engine_subtab === 'extapps' && $isActive && $engineHasFullControls($key))
                @php $extAppsParams = \App\Services\Servers\OpenLiteSpeedExtAppsConfig::PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="ols-extapps-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('OpenLiteSpeed external apps') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Per-extprocessor settings (LSAPI / FastCGI / proxy workers) in /usr/local/lsws/conf/httpd_config.conf. Save validates and reloads; a failed validate auto-restores.') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="openAddOlsExtAppForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4" />
                                    {{ __('Add ExtApp') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadOlsExtAppsConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadOlsExtAppsConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadOlsExtAppsConfig" class="inline-flex">
                                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="loadOlsExtAppsConfig" class="inline-flex">
                                        <x-spinner class="h-4 w-4" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($ols_extapps_show_add)
                            <form
                                wire:submit.prevent="submitAddOlsExtApp"
                                class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5"
                            >
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a new extprocessor') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Identity (name + type + address) is fixed once written. dply-managed `lsphp*` names are reserved.') }}</p>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="ols_extapps_new_app.name"
                                            placeholder="my-app"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Letters, digits, and `_ . -` only.') }}</span>
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Type') }}</span>
                                        <select
                                            wire:model.live="ols_extapps_new_app.type"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        >
                                            @foreach (\App\Services\Servers\OpenLiteSpeedExtAppsConfig::COMMON_TYPES as $tKey => $tLabel)
                                                <option value="{{ $tKey }}">{{ $tKey }} — {{ __($tLabel) }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Address') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="ols_extapps_new_app.address"
                                            placeholder="uds://tmp/lshttpd/my-app.sock"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Unix socket or `host:port`. e.g. uds://tmp/lshttpd/my-app.sock or 127.0.0.1:9000.') }}</span>
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Binary path (optional)') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="ols_extapps_new_app.path"
                                            placeholder="/usr/local/lsws/lsphp83/bin/lsphp"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Required for lsapi/fcgi when OLS spawns the worker. Leave blank for proxy.') }}</span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button
                                        type="button"
                                        wire:click="cancelAddOlsExtAppForm"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        {{ __('Cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="submitAddOlsExtApp"
                                        @disabled($actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="submitAddOlsExtApp" class="inline-flex">
                                            <x-heroicon-o-plus class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="submitAddOlsExtApp" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        @if ($ols_extapps_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $ols_extapps_flash }}</div>
                        @endif
                        @if ($ols_extapps_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $ols_extapps_error }}</pre>
                            </div>
                        @endif

                        @if (! $ols_extapps_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadOlsExtAppsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-4 w-4" /> {{ __('Reading config…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadOlsExtAppsConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
                        @endif
                    </div>

                    @if ($ols_extapps_loaded && ! empty($ols_extapps_form))
                        <form wire:submit.prevent="saveOlsExtAppsConfig" class="space-y-4">
                            @foreach ($ols_extapps_form as $appName => $values)
                                @php $identity = $ols_extapps_identity[$appName] ?? []; @endphp
                                <div
                                    class="{{ $card }} p-5 sm:p-6"
                                    x-data="{
                                        expanded: false,
                                        storageKey: @js('dply.ols-extapp-expanded:'.$server->id.':'.$appName),
                                        init() {
                                            try {
                                                const saved = window.localStorage?.getItem(this.storageKey);
                                                if (saved === '1') this.expanded = true;
                                            } catch (e) {}
                                        },
                                        toggle() {
                                            this.expanded = !this.expanded;
                                            try { window.localStorage?.setItem(this.storageKey, this.expanded ? '1' : '0'); } catch (e) {}
                                        },
                                    }"
                                    x-init="init()"
                                    wire:key="ols-extapp-{{ $appName }}"
                                >
                                    <button
                                        type="button"
                                        x-on:click="toggle()"
                                        class="group flex w-full items-start gap-3 text-left"
                                        x-bind:aria-expanded="expanded.toString()"
                                    >
                                        <x-heroicon-o-chevron-down
                                            class="mt-1 h-4 w-4 shrink-0 text-brand-moss transition-transform"
                                            x-bind:class="expanded ? '' : '-rotate-90'"
                                            aria-hidden="true"
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="font-mono text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ $appName }}</span>
                                                @if (! empty($identity['type']))
                                                    <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $identity['type'] }}</span>
                                                @endif
                                            </span>
                                            @if (! empty($identity['address']))
                                                <span class="mt-0.5 block truncate text-[11px] font-mono text-brand-mist">{{ $identity['address'] }}</span>
                                            @endif
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-5">
                                        @if (! empty($identity['path']))
                                            <p class="text-[11px] text-brand-mist">
                                                <span class="font-semibold">{{ __('Binary path') }}</span>
                                                <span class="font-mono">{{ $identity['path'] }}</span>
                                            </p>
                                        @endif

                                        @if (! preg_match('/^lsphp\d+$/', $appName))
                                            <div class="flex items-center justify-end">
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('removeOlsExtApp', ['{{ $appName }}'], @js(__('Remove ExtApp: :name', ['name' => $appName])), @js(__('Remove the `:name` extprocessor block? Any vhost still referencing it will fail to load on next reload.', ['name' => $appName])), @js(__('Remove')), true)"
                                                    @disabled($isDeployer || $actionInFlight)
                                                    class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    <x-heroicon-o-trash class="h-4 w-4" />
                                                    {{ __('Remove') }}
                                                </button>
                                            </div>
                                        @else
                                            <p class="rounded-md bg-brand-sand/30 px-3 py-2 text-[11px] text-brand-mist">
                                                {{ __('Managed by dply. Adjust this PHP version via the PHP workspace; remove the PHP version there to delete this block.') }}
                                            </p>
                                        @endif

                                        <div class="grid gap-5 sm:grid-cols-2">
                                            @foreach ($extAppsParams as $paramKey => $meta)
                                                @if ($meta['type'] === 'lines')
                                                    @continue
                                                @endif
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    @if ($meta['type'] === 'bool')
                                                        <span class="mt-1 inline-flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                value="1"
                                                                wire:model.live="ols_extapps_form.{{ $appName }}.{{ $paramKey }}"
                                                                @checked(($values[$paramKey] ?? '0') === '1')
                                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                                            />
                                                            <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                        </span>
                                                    @elseif ($meta['type'] === 'int')
                                                        <input
                                                            type="number"
                                                            wire:model.lazy="ols_extapps_form.{{ $appName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @else
                                                        <input
                                                            type="text"
                                                            wire:model.lazy="ols_extapps_form.{{ $appName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>

                                        @foreach ($extAppsParams as $paramKey => $meta)
                                            @if ($meta['type'] !== 'lines')
                                                @continue
                                            @endif
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                <textarea
                                                    rows="3"
                                                    wire:model.lazy="ols_extapps_form.{{ $appName }}.{{ $paramKey }}"
                                                    placeholder="{{ __('KEY=VALUE per line') }}"
                                                    class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                ></textarea>
                                                <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="saveOlsExtAppsConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="saveOlsExtAppsConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveOlsExtAppsConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload OpenLiteSpeed') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 OPENLITESPEED — MODULES. Register / unregister module blocks
                 in httpd_config.conf (see docs.openlitespeed.org/modules).
                 The cache module is protected — tune it on the Cache tab.
                 ============================================================= --}}
            @if ($key === 'openlitespeed' && $engine_subtab === 'modules' && $isActive && $engineHasFullControls($key))
                <div class="space-y-4 mb-6" wire:key="ols-modules-config">
                    <div class="{{ $card }}">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <x-icon-badge>
                                <x-heroicon-o-puzzle-piece class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Modules') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('OpenLiteSpeed modules') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Enable or disable modules by registering `module` blocks in httpd_config.conf. Each toggle validates with `lshttpd -t` and reloads OpenLiteSpeed; failed validates restore the previous config. Tune the cache module on the Cache tab.') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                wire:click="loadOlsModulesConfig"
                                wire:loading.attr="disabled"
                                wire:target="loadOlsModulesConfig"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                            >
                                <span wire:loading.remove wire:target="loadOlsModulesConfig" class="inline-flex">
                                    <x-heroicon-o-arrow-path class="h-4 w-4" />
                                </span>
                                <span wire:loading wire:target="loadOlsModulesConfig" class="inline-flex">
                                    <x-spinner class="h-4 w-4" />
                                </span>
                                {{ __('Reload from server') }}
                            </button>
                        </div>

                        <div class="px-6 py-6 sm:px-7">
                        @if ($ols_modules_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $ols_modules_flash }}</div>
                        @endif
                        @if ($ols_modules_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $ols_modules_error }}</pre>
                            </div>
                        @endif

                        @if (! $ols_modules_loaded)
                            <div
                                wire:loading.block
                                wire:target="loadOlsModulesConfig,loadActiveEngineSubtabData"
                                class="mt-5 w-full rounded-xl border border-brand-ink/10 bg-white px-6 py-10 text-center text-sm text-brand-moss"
                            >
                                <x-spinner variant="forest" class="mx-auto h-5 w-5" />
                                <p class="mt-2">{{ __('Listing modules…') }}</p>
                            </div>

                            <div
                                wire:loading.remove
                                wire:target="loadOlsModulesConfig,loadActiveEngineSubtabData"
                                class="mt-5 w-full rounded-xl border border-dashed border-brand-ink/15 bg-white px-6 py-10 text-center text-sm text-brand-moss"
                            >
                                <x-heroicon-o-puzzle-piece class="mx-auto h-5 w-5 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2">{{ __('Click "Reload from server" to list available modules.') }}</p>
                            </div>
                        @else
                            @php
                                $filtered = $ols_modules_filter === 'all'
                                    ? $ols_modules_list
                                    : array_values(array_filter($ols_modules_list, fn ($m) => $m['type'] === $ols_modules_filter));
                                $enabledCount = count(array_filter($ols_modules_list, fn ($m) => $m['enabled']));
                                $filters = [
                                    'all' => __('All'),
                                    'perf' => __('Perf'),
                                    'security' => __('Security'),
                                    'other' => __('Other'),
                                ];
                            @endphp
                            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 pt-4">
                                <p class="text-xs text-brand-moss">
                                    {{ __(':enabled of :total modules registered', ['enabled' => $enabledCount, 'total' => count($ols_modules_list)]) }}
                                </p>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($filters as $filterKey => $filterLabel)
                                        <button
                                            type="button"
                                            wire:click="setOlsModulesFilter('{{ $filterKey }}')"
                                            @class([
                                                'inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-[11px] font-medium transition',
                                                'border-brand-forest bg-brand-forest text-brand-cream' => $ols_modules_filter === $filterKey,
                                                'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $ols_modules_filter !== $filterKey,
                                            ])
                                        >
                                            {{ $filterLabel }}
                                            @if ($filterKey !== 'all')
                                                <span class="text-[10px] opacity-70">{{ count(array_filter($ols_modules_list, fn ($m) => $m['type'] === $filterKey)) }}</span>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mt-4 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-brand-sand/30 text-[11px] uppercase tracking-wide text-brand-mist">
                                        <tr>
                                            <th class="px-4 py-2 font-medium">{{ __('Module') }}</th>
                                            <th class="px-4 py-2 font-medium">{{ __('Type') }}</th>
                                            <th class="px-4 py-2 font-medium">{{ __('On disk') }}</th>
                                            <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                            <th class="px-4 py-2 font-medium text-right">{{ __('Action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5">
                                        @foreach ($filtered as $mod)
                                            <tr>
                                                <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $mod['name'] }}</td>
                                                <td class="px-4 py-2 text-xs">
                                                    <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $mod['type'] }}</span>
                                                </td>
                                                <td class="px-4 py-2 text-xs text-brand-moss">
                                                    @if ($mod['on_disk'])
                                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('.so present') }}</span>
                                                    @else
                                                        <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold text-brand-moss">{{ __('built-in') }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2 text-xs">
                                                    @if ($mod['enabled'])
                                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('registered') }}</span>
                                                    @else
                                                        <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold text-brand-moss">{{ __('not registered') }}</span>
                                                    @endif
                                                    @if ($mod['protected'])
                                                        <span class="ml-1 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700" title="{{ __('Managed on the Cache tab — disable is blocked here.') }}">{{ __('protected') }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2 text-right">
                                                    @if ($mod['protected'] && $mod['enabled'])
                                                        <button
                                                            type="button"
                                                            wire:click="setEngineSubtab('cache')"
                                                            class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-medium text-brand-forest hover:bg-brand-sand/40"
                                                        >
                                                            {{ __('Open Cache tab') }}
                                                        </button>
                                                    @elseif ($mod['enabled'])
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('toggleOlsModule', ['{{ $mod['name'] }}', false], @js(__('Disable module: :name', ['name' => $mod['name']])), @js(__('Remove the `module :name` block from httpd_config.conf? OpenLiteSpeed reloads after the change and the config reverts automatically if `lshttpd -t` fails.', ['name' => $mod['name']])), @js(__('Disable')), true)"
                                                            @disabled($isDeployer || $actionInFlight)
                                                            class="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-rose-50/30 px-2 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            <x-heroicon-o-no-symbol class="h-3 w-3" />
                                                            {{ __('Disable') }}
                                                        </button>
                                                    @else
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('toggleOlsModule', ['{{ $mod['name'] }}', true], @js(__('Enable module: :name', ['name' => $mod['name']])), @js(__('Register `module :name` in httpd_config.conf with starter parameters? OpenLiteSpeed reloads after the change and the config reverts automatically if `lshttpd -t` fails.', ['name' => $mod['name']])), @js(__('Enable')), false)"
                                                            @disabled($isDeployer || $actionInFlight)
                                                            class="inline-flex items-center gap-1 rounded-md border border-brand-forest bg-brand-forest px-2 py-1 text-[11px] font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            <x-heroicon-o-power class="h-3 w-3" />
                                                            {{ __('Enable') }}
                                                        </button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- =============================================================
                 OPENLITESPEED — CACHE MODULE CONFIG. Adjustable common
                 LSCache directives. The form sits above the live-state
                 table on the same sub-tab. Auto-loads on first arrival;
                 manual reload via the inline button.
                 ============================================================= --}}
            @if ($key === 'openlitespeed' && $engine_subtab === 'cache' && $isActive && $engineHasFullControls($key))
                @php $olsParams = \App\Services\Servers\OpenLiteSpeedCacheModuleConfig::PARAMS; @endphp
                <div
                    class="{{ $card }} p-6 sm:p-8 mb-6"
                    wire:key="ols-cache-config"
                    x-data="{
                        expanded: true,
                        storageKey: @js('dply.ols-cache-expanded:'.$server->id),
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
                                <h3 class="text-base font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('OpenLiteSpeed cache module') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Server-level LSCache settings written into /usr/local/lsws/conf/httpd_config.conf. Save validates with `lshttpd -t` and reloads the daemon; a failed validate auto-restores the previous file.') }}
                                </p>
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="loadOlsCacheConfig"
                            wire:loading.attr="disabled"
                            wire:target="loadOlsCacheConfig"
                            x-show="expanded"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadOlsCacheConfig" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-4 w-4" />
                            </span>
                            <span wire:loading wire:target="loadOlsCacheConfig" class="inline-flex">
                                <x-spinner class="h-4 w-4" />
                            </span>
                            {{ __('Reload from server') }}
                        </button>
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('purgeOlsLscacheConfirmed', [], @js(__('Purge LSCache')), @js(__('Remove all server-level LSCache storage and send PURGE requests to local vhosts? Site content is not deleted — only cached responses are cleared.')), @js(__('Purge cache')), true)"
                            wire:loading.attr="disabled"
                            wire:target="confirmActionModal, purgeOlsLscacheConfirmed"
                            x-show="expanded"
                            @disabled($isDeployer || $actionInFlight || ! $opsReady)
                            class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-800 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <x-heroicon-o-trash class="h-4 w-4" />
                            {{ __('Purge all LSCache') }}
                        </button>
                    </div>

                    <div x-show="expanded" x-cloak>
                    @if ($ols_cache_flash)
                        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">
                            {{ $ols_cache_flash }}
                        </div>
                    @endif
                    @if ($ols_cache_error)
                        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                            <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $ols_cache_error }}</pre>
                        </div>
                    @endif

                    @if (! $ols_cache_loaded)
                        <p class="mt-5 text-sm text-brand-moss">
                            <span wire:loading wire:target="loadOlsCacheConfig" class="inline-flex items-center gap-2">
                                <x-spinner class="h-4 w-4" /> {{ __('Reading config…') }}
                            </span>
                            <span wire:loading.remove wire:target="loadOlsCacheConfig">
                                {{ __('Click "Reload from server" to fetch current values.') }}
                            </span>
                        </p>
                    @else
                        <form wire:submit.prevent="saveOlsCacheConfig" class="mt-6 space-y-6">
                            <div class="grid gap-5 sm:grid-cols-2">
                                @foreach ($olsParams as $paramKey => $meta)
                                    @if ($meta['type'] === 'lines')
                                        @continue
                                    @endif
                                    <label class="block">
                                        <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                        @if ($meta['type'] === 'bool')
                                            <span class="mt-1 inline-flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    value="1"
                                                    wire:model.live="ols_cache_form.{{ $paramKey }}"
                                                    @checked(($ols_cache_form[$paramKey] ?? '0') === '1')
                                                    class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                                />
                                                <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </span>
                                        @else
                                            <input
                                                type="number"
                                                min="0"
                                                wire:model.lazy="ols_cache_form.{{ $paramKey }}"
                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm font-medium text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            />
                                            <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>

                            @foreach ($olsParams as $paramKey => $meta)
                                @if ($meta['type'] !== 'lines')
                                    @continue
                                @endif
                                <label class="block">
                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                    <textarea
                                        rows="3"
                                        wire:model.lazy="ols_cache_form.{{ $paramKey }}"
                                        placeholder="{{ __('One per line') }}"
                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                    ></textarea>
                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                </label>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="saveOlsCacheConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="saveOlsCacheConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveOlsCacheConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload OpenLiteSpeed') }}
                                </button>
                            </div>
                        </form>
                    @endif
                    </div>
                </div>
            @endif
