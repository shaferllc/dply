            @if ($key === 'haproxy' && $engine_subtab === 'backends' && $isActive && $engineHasFullControls($key))
                @php $haproxyBackendParams = \App\Services\Servers\HaproxyBackendsConfig::PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="haproxy-backends-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('HAProxy backends') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Each `backend <name>` block is a pool a frontend routes traffic to. dply provisions a default `caddy_backends` pool with one server per per-site Caddy backend port; add new backends for non-Caddy upstreams (alt apps, mTLS pools, etc.).') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="openAddHaproxyBackendForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4" />
                                    {{ __('Add backend') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadHaproxyBackendsConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadHaproxyBackendsConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadHaproxyBackendsConfig" class="inline-flex">
                                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="loadHaproxyBackendsConfig" class="inline-flex">
                                        <x-spinner class="h-4 w-4" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($haproxy_backends_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $haproxy_backends_flash }}</div>
                        @endif
                        @if ($haproxy_backends_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $haproxy_backends_error }}</pre>
                            </div>
                        @endif

                        @if ($haproxy_backends_show_add)
                            <form
                                wire:submit.prevent="submitAddHaproxyBackend"
                                class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5"
                            >
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a new backend') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Each server line: `<label> <host>:<port> [check] [weight=N] [maxconn=N] [backup] [disabled]`.') }}</p>

                                <div class="mt-4 grid gap-4">
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input type="text"
                                            wire:model.lazy="haproxy_backends_new.name"
                                            placeholder="api_pool"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('balance') }}</span>
                                        <select
                                            wire:model.lazy="haproxy_backends_new.balance"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        >
                                            <option value="roundrobin">roundrobin</option>
                                            <option value="leastconn">leastconn</option>
                                            <option value="source">source (sticky by IP)</option>
                                            <option value="uri">uri (sticky by URL hash)</option>
                                            <option value="static-rr">static-rr</option>
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Servers (one per line)') }}</span>
                                        <textarea
                                            wire:model.lazy="haproxy_backends_new.servers"
                                            rows="4"
                                            spellcheck="false"
                                            placeholder="app1 127.0.0.1:8080 check{{ "\n" }}app2 127.0.0.1:8081 check"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"
                                            required></textarea>
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Add `check` to enable health checking on each server; combine with `option httpchk GET /health` on the backend.') }}</span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button type="button"
                                        wire:click="cancelAddHaproxyBackendForm"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                                        {{ __('Cancel') }}
                                    </button>
                                    <button type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="submitAddHaproxyBackend"
                                        @disabled($actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60">
                                        <span wire:loading.remove wire:target="submitAddHaproxyBackend" class="inline-flex">
                                            <x-heroicon-o-plus class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="submitAddHaproxyBackend" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        @if (! $haproxy_backends_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadHaproxyBackendsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-4 w-4" /> {{ __('Reading haproxy.cfg…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadHaproxyBackendsConfig">
                                    {{ __('Click "Reload from server" to fetch current backends.') }}
                                </span>
                            </p>
                        @endif
                    </div>

                    @if ($haproxy_backends_loaded && ! empty($haproxy_backends_form))
                        <form wire:submit.prevent="saveHaproxyBackendsConfig" class="space-y-4">
                            @foreach ($haproxy_backends_form as $backendName => $payload)
                                <div
                                    class="{{ $card }} p-5 sm:p-6"
                                    x-data="{
                                        expanded: false,
                                        storageKey: @js('dply.haproxy-backend-expanded:'.$server->id.':'.$backendName),
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
                                    wire:key="haproxy-backend-{{ $backendName }}"
                                >
                                    <button type="button"
                                        x-on:click="toggle()"
                                        class="group flex w-full items-start gap-3 text-left"
                                        x-bind:aria-expanded="expanded.toString()"
                                    >
                                        <x-heroicon-o-chevron-down
                                            class="mt-1 h-4 w-4 shrink-0 text-brand-moss transition-transform"
                                            x-bind:class="expanded ? '' : '-rotate-90'"
                                            aria-hidden="true" />
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="font-mono text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ $backendName }}</span>
                                                <span class="text-[11px] text-brand-mist">{{ __(':n server(s)', ['n' => count($payload['servers'] ?? [])]) }}</span>
                                                @if (! empty($payload['values']['balance']))
                                                    <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $payload['values']['balance'] }}</span>
                                                @endif
                                            </span>
                                            <span class="mt-0.5 block truncate text-[11px] font-mono text-brand-mist">{{ implode(', ', $payload['servers'] ?? []) ?: '—' }}</span>
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-5">
                                        <div class="flex items-center justify-end">
                                            <button type="button"
                                                wire:click="openConfirmActionModal('removeHaproxyBackend', ['{{ $backendName }}'], @js(__('Remove backend: :name', ['name' => $backendName])), @js(__('Remove the `:name` backend block? Frontends still routing here will fail validation on next reload.', ['name' => $backendName])), @js(__('Remove')), true)"
                                                @disabled($isDeployer || $actionInFlight)
                                                class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60">
                                                <x-heroicon-o-trash class="h-4 w-4" />
                                                {{ __('Remove') }}
                                            </button>
                                        </div>

                                        <label class="block">
                                            <span class="block text-sm font-medium text-brand-ink">{{ __('Servers (one per line)') }}</span>
                                            <textarea
                                                wire:model.lazy="haproxy_backends_servers_text.{{ $backendName }}"
                                                wire:key="haproxy-backend-servers-{{ $backendName }}"
                                                rows="5"
                                                spellcheck="false"
                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30">{{ $haproxy_backends_servers_text[$backendName] ?? '' }}</textarea>
                                            <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Each line: `<label> <host>:<port> [check] [weight=N] [maxconn=N] [backup] [disabled]`.') }}</span>
                                        </label>

                                        <div class="grid gap-5 sm:grid-cols-2">
                                            @foreach ($haproxyBackendParams as $paramKey => $meta)
                                                @php $formKey = $paramKey; @endphp
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    @if ($meta['type'] === 'bool')
                                                        <span class="mt-1 inline-flex items-center gap-2">
                                                            <input type="checkbox" value="1"
                                                                wire:model.live="haproxy_backends_form.{{ $backendName }}.values.{{ $formKey }}"
                                                                @checked(($payload['values'][$formKey] ?? '0') === '1')
                                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                                            <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                        </span>
                                                    @else
                                                        <input type="text"
                                                            wire:model.lazy="haproxy_backends_form.{{ $backendName }}.values.{{ $formKey }}"
                                                            placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="saveHaproxyBackendsConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60">
                                    <span wire:loading.remove wire:target="saveHaproxyBackendsConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveHaproxyBackendsConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload HAProxy') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 HAPROXY — FRONTENDS EDITOR. Each `frontend <name>` block
                 gets a collapsible card with its binds + tunables. Add /
                 remove + per-frontend save stream through the manage_action
                 banner.
                 ============================================================= --}}
            @if ($key === 'haproxy' && $engine_subtab === 'frontends' && $isActive && $engineHasFullControls($key))
                @php $haproxyFrontendParams = \App\Services\Servers\HaproxyFrontendsConfig::PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="haproxy-frontends-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('HAProxy frontends') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Each `frontend <name>` block declares where HAProxy listens (`bind`) and where the traffic goes (`default_backend`). dply provisions a default frontend on :80 routing to the Caddy-backend pool; add more for alt-port listeners or split routing.') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="openAddHaproxyFrontendForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4" />
                                    {{ __('Add frontend') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadHaproxyFrontendsConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadHaproxyFrontendsConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadHaproxyFrontendsConfig" class="inline-flex">
                                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="loadHaproxyFrontendsConfig" class="inline-flex">
                                        <x-spinner class="h-4 w-4" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($haproxy_frontends_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $haproxy_frontends_flash }}</div>
                        @endif
                        @if ($haproxy_frontends_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $haproxy_frontends_error }}</pre>
                            </div>
                        @endif

                        @if ($haproxy_frontends_show_add)
                            <form
                                wire:submit.prevent="submitAddHaproxyFrontend"
                                class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5"
                            >
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a new frontend') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('At minimum: name, one bind line, and a default_backend that references an existing backend.') }}</p>

                                <div class="mt-4 grid gap-4">
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="haproxy_frontends_new.name"
                                            placeholder="https_in"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Binds (one per line)') }}</span>
                                        <textarea
                                            wire:model.lazy="haproxy_frontends_new.binds"
                                            rows="3"
                                            spellcheck="false"
                                            placeholder="*:8080{{ "\n" }}127.0.0.1:7070"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"
                                            required
                                        ></textarea>
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Any HAProxy bind expression — `*:80`, `127.0.0.1:7070`, `*:443 ssl crt /etc/ssl/...`, etc.') }}</span>
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('default_backend') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="haproxy_frontends_new.default_backend"
                                            placeholder="caddy_backends"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Name of an existing `backend <name>` block. Skip for ACL-only routing — the validate will fail if neither default_backend nor a use_backend ACL is present.') }}</span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button
                                        type="button"
                                        wire:click="cancelAddHaproxyFrontendForm"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        {{ __('Cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="submitAddHaproxyFrontend"
                                        @disabled($actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="submitAddHaproxyFrontend" class="inline-flex">
                                            <x-heroicon-o-plus class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="submitAddHaproxyFrontend" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        @if (! $haproxy_frontends_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadHaproxyFrontendsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-4 w-4" /> {{ __('Reading haproxy.cfg…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadHaproxyFrontendsConfig">
                                    {{ __('Click "Reload from server" to fetch current frontends.') }}
                                </span>
                            </p>
                        @endif
                    </div>

                    @if ($haproxy_frontends_loaded && ! empty($haproxy_frontends_form))
                        <form wire:submit.prevent="saveHaproxyFrontendsConfig" class="space-y-4">
                            @foreach ($haproxy_frontends_form as $frontendName => $payload)
                                <div
                                    class="{{ $card }} p-5 sm:p-6"
                                    x-data="{
                                        expanded: false,
                                        storageKey: @js('dply.haproxy-frontend-expanded:'.$server->id.':'.$frontendName),
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
                                    wire:key="haproxy-frontend-{{ $frontendName }}"
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
                                                <span class="font-mono text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ $frontendName }}</span>
                                                <span class="text-[11px] text-brand-mist">{{ __(':n bind(s)', ['n' => count($payload['binds'] ?? [])]) }}</span>
                                                @if (! empty($payload['values']['default_backend']))
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                                        → {{ $payload['values']['default_backend'] }}
                                                    </span>
                                                @endif
                                            </span>
                                            <span class="mt-0.5 block truncate text-[11px] font-mono text-brand-mist">{{ implode(', ', $payload['binds'] ?? []) ?: '—' }}</span>
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-5">
                                        <div class="flex items-center justify-end">
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('removeHaproxyFrontend', ['{{ $frontendName }}'], @js(__('Remove frontend: :name', ['name' => $frontendName])), @js(__('Remove the `:name` frontend block? Traffic to its bound ports stops being routed immediately.', ['name' => $frontendName])), @js(__('Remove')), true)"
                                                @disabled($isDeployer || $actionInFlight)
                                                class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                <x-heroicon-o-trash class="h-4 w-4" />
                                                {{ __('Remove') }}
                                            </button>
                                        </div>

                                        <label class="block">
                                            <span class="block text-sm font-medium text-brand-ink">{{ __('Binds (one per line)') }}</span>
                                            <textarea
                                                wire:model.lazy="haproxy_frontends_binds_text.{{ $frontendName }}"
                                                wire:key="haproxy-frontend-binds-{{ $frontendName }}"
                                                rows="4"
                                                spellcheck="false"
                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"
                                            >{{ $haproxy_frontends_binds_text[$frontendName] ?? '' }}</textarea>
                                            <span class="mt-1 block text-[11px] text-brand-mist">{{ __('e.g. `*:80`, `127.0.0.1:7070`, `*:443 ssl crt /etc/ssl/<cert>.pem`.') }}</span>
                                        </label>

                                        <div class="grid gap-5 sm:grid-cols-2">
                                            @foreach ($haproxyFrontendParams as $paramKey => $meta)
                                                @php $formKey = $paramKey; @endphp
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    @if ($meta['type'] === 'bool')
                                                        <span class="mt-1 inline-flex items-center gap-2">
                                                            <input type="checkbox" value="1"
                                                                wire:model.live="haproxy_frontends_form.{{ $frontendName }}.values.{{ $formKey }}"
                                                                @checked(($payload['values'][$formKey] ?? '0') === '1')
                                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                                            <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                        </span>
                                                    @elseif ($meta['type'] === 'int')
                                                        <input type="number"
                                                            wire:model.lazy="haproxy_frontends_form.{{ $frontendName }}.values.{{ $formKey }}"
                                                            placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @else
                                                        <input type="text"
                                                            wire:model.lazy="haproxy_frontends_form.{{ $frontendName }}.values.{{ $formKey }}"
                                                            placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="saveHaproxyFrontendsConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="saveHaproxyFrontendsConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveHaproxyFrontendsConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload HAProxy') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 TRAEFIK — STATIC CONFIG. Edits /etc/traefik/traefik.yml.
                 Static config requires a RESTART (Traefik doesn't watch this
                 file), so the save banner warns about the connection drop.
                 Dynamic config (/etc/traefik/dynamic/*.yml) hot-reloads via
                 the file provider; that's out of scope here.
                 ============================================================= --}}
            @if ($key === 'haproxy' && $engine_subtab === 'runtime' && $isActive && $engineHasFullControls($key))
                @php
                    $haproxyGlobalParams = \App\Services\Servers\HaproxyGlobalOptionsConfig::GLOBAL_PARAMS;
                    $haproxyDefaultsParams = \App\Services\Servers\HaproxyGlobalOptionsConfig::DEFAULTS_PARAMS;
                    $slug = static fn (string $k): string => str_replace([' ', '-'], '_', $k);
                @endphp
                <div
                    class="{{ $card }} p-6 sm:p-8 mb-6"
                    wire:key="haproxy-globals-config"
                    x-data="{
                        expanded: true,
                        storageKey: @js('dply.haproxy-globals-expanded:'.$server->id),
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
                                <h3 class="text-base font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('HAProxy global options') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Edits the `global` (process-level) and `defaults` (per-section inheritance) sections of /etc/haproxy/haproxy.cfg. Frontend / backend / listen / cache blocks pass through. Save runs `haproxy -c -f` and reloads; a failed validate auto-restores the previous file.') }}
                                </p>
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="loadHaproxyGlobalsConfig"
                            wire:loading.attr="disabled"
                            wire:target="loadHaproxyGlobalsConfig"
                            x-show="expanded"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadHaproxyGlobalsConfig" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-4 w-4" />
                            </span>
                            <span wire:loading wire:target="loadHaproxyGlobalsConfig" class="inline-flex">
                                <x-spinner class="h-4 w-4" />
                            </span>
                            {{ __('Reload from server') }}
                        </button>
                    </div>

                    <div x-show="expanded" x-cloak>
                        @if ($haproxy_globals_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $haproxy_globals_flash }}</div>
                        @endif
                        @if ($haproxy_globals_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $haproxy_globals_error }}</pre>
                            </div>
                        @endif

                        @if (! $haproxy_globals_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadHaproxyGlobalsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-4 w-4" /> {{ __('Reading haproxy.cfg…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadHaproxyGlobalsConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
                        @else
                            <form wire:submit.prevent="saveHaproxyGlobalsConfig" class="mt-6 space-y-6">
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('global { … }') }}</p>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-2">
                                        @foreach ($haproxyGlobalParams as $paramKey => $meta)
                                            @php $formKey = 'global_'.$slug($paramKey); @endphp
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                @if ($meta['type'] === 'bool')
                                                    <span class="mt-1 inline-flex items-center gap-2">
                                                        <input type="checkbox" value="1"
                                                            wire:model.live="haproxy_globals_form.{{ $formKey }}"
                                                            @checked(($haproxy_globals_form[$formKey] ?? '0') === '1')
                                                            class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                                        <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    </span>
                                                @elseif ($meta['type'] === 'int')
                                                    <input type="number"
                                                        wire:model.lazy="haproxy_globals_form.{{ $formKey }}"
                                                        placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                @else
                                                    <input type="text"
                                                        wire:model.lazy="haproxy_globals_form.{{ $formKey }}"
                                                        placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('defaults { … }') }}</p>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-2">
                                        @foreach ($haproxyDefaultsParams as $paramKey => $meta)
                                            @php $formKey = 'defaults_'.$slug($paramKey); @endphp
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                @if ($meta['type'] === 'bool')
                                                    <span class="mt-1 inline-flex items-center gap-2">
                                                        <input type="checkbox" value="1"
                                                            wire:model.live="haproxy_globals_form.{{ $formKey }}"
                                                            @checked(($haproxy_globals_form[$formKey] ?? '0') === '1')
                                                            class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                                        <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    </span>
                                                @else
                                                    <input type="text"
                                                        wire:model.lazy="haproxy_globals_form.{{ $formKey }}"
                                                        placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="saveHaproxyGlobalsConfig"
                                        @disabled($isDeployer || $actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="saveHaproxyGlobalsConfig" class="inline-flex">
                                            <x-heroicon-o-check class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="saveHaproxyGlobalsConfig" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Save and reload HAProxy') }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
