            @if ($key === 'nginx' && $engine_subtab === 'upstreams' && $isActive && $engineHasFullControls($key))
                @php $nginxPoolParams = \App\Services\Servers\NginxUpstreamsConfig::POOL_PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="nginx-upstreams-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('nginx upstreams') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Reusable `upstream <name> { server <addr>; … }` pools at the http level of /etc/nginx/nginx.conf. Sites reference them via `proxy_pass http://<name>` or `fastcgi_pass <name>`. Per-site upstream blocks under sites-enabled are managed by the per-site provisioner.') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="openAddNginxUpstreamForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                    {{ __('Add upstream') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadNginxUpstreamsConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadNginxUpstreamsConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadNginxUpstreamsConfig" class="inline-flex">
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                    </span>
                                    <span wire:loading wire:target="loadNginxUpstreamsConfig" class="inline-flex">
                                        <x-spinner class="h-3.5 w-3.5" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($nginx_upstreams_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $nginx_upstreams_flash }}</div>
                        @endif
                        @if ($nginx_upstreams_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $nginx_upstreams_error }}</pre>
                            </div>
                        @endif

                        @if ($nginx_upstreams_show_add)
                            <form
                                wire:submit.prevent="submitAddNginxUpstream"
                                class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5"
                            >
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a new upstream') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Sites reference the name as `proxy_pass http://<name>` or `fastcgi_pass <name>`.') }}</p>

                                <div class="mt-4 grid gap-4">
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="nginx_upstreams_new.name"
                                            placeholder="my_backend"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Servers (one per line)') }}</span>
                                        <textarea
                                            wire:model.lazy="nginx_upstreams_new.servers"
                                            rows="4"
                                            spellcheck="false"
                                            placeholder="127.0.0.1:8081{{ "\n" }}127.0.0.1:8082 weight=2{{ "\n" }}unix:/run/php/php8.3-fpm.sock"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"
                                            required
                                        ></textarea>
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Any nginx server-line: `host:port`, `unix:/path`, optionally followed by `weight=N`, `max_fails=N`, `fail_timeout=Ns`, `backup`, `down`.') }}</span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button
                                        type="button"
                                        wire:click="cancelAddNginxUpstreamForm"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        {{ __('Cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="submitAddNginxUpstream"
                                        @disabled($actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="submitAddNginxUpstream" class="inline-flex">
                                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                        </span>
                                        <span wire:loading wire:target="submitAddNginxUpstream" class="inline-flex">
                                            <x-spinner variant="cream" class="h-3.5 w-3.5" />
                                        </span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        @if (! $nginx_upstreams_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadNginxUpstreamsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading nginx.conf…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadNginxUpstreamsConfig">
                                    {{ __('Click "Reload from server" to fetch current upstreams.') }}
                                </span>
                            </p>
                        @endif
                    </div>

                    @if ($nginx_upstreams_loaded && ! empty($nginx_upstreams_form))
                        <form wire:submit.prevent="saveNginxUpstreamsConfig" class="space-y-4">
                            @foreach ($nginx_upstreams_form as $upstreamName => $payload)
                                <div
                                    class="{{ $card }} p-5 sm:p-6"
                                    x-data="{
                                        expanded: false,
                                        storageKey: @js('dply.nginx-upstream-expanded:'.$server->id.':'.$upstreamName),
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
                                    wire:key="nginx-upstream-{{ $upstreamName }}"
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
                                                <span class="font-mono text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ $upstreamName }}</span>
                                                <span class="text-[11px] text-brand-mist">{{ __(':n backend(s)', ['n' => count($payload['servers'] ?? [])]) }}</span>
                                            </span>
                                            <span class="mt-0.5 block truncate text-[11px] font-mono text-brand-mist">{{ implode(', ', $payload['servers'] ?? []) ?: '—' }}</span>
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-5">
                                        <div class="flex items-center justify-end">
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('removeNginxUpstream', ['{{ $upstreamName }}'], @js(__('Remove upstream: :name', ['name' => $upstreamName])), @js(__('Remove the `:name` upstream block? Sites that still `proxy_pass http://:name` will fail to validate on next reload.', ['name' => $upstreamName])), @js(__('Remove')), true)"
                                                @disabled($isDeployer || $actionInFlight)
                                                class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                                {{ __('Remove') }}
                                            </button>
                                        </div>

                                        <label class="block">
                                            <span class="block text-sm font-medium text-brand-ink">{{ __('Servers (one per line)') }}</span>
                                            <textarea
                                                wire:model.lazy="nginx_upstreams_servers_text.{{ $upstreamName }}"
                                                wire:key="nginx-upstream-servers-{{ $upstreamName }}"
                                                rows="5"
                                                spellcheck="false"
                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"
                                            >{{ $nginx_upstreams_servers_text[$upstreamName] ?? '' }}</textarea>
                                            <span class="mt-1 block text-[11px] text-brand-mist">{{ __('host:port, unix:/path, optionally with weight=N, max_fails=N, fail_timeout=Ns, backup, down.') }}</span>
                                        </label>

                                        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                            @foreach ($nginxPoolParams as $paramKey => $meta)
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    @if ($meta['type'] === 'bool')
                                                        <span class="mt-1 inline-flex items-center gap-2">
                                                            <input type="checkbox" value="1"
                                                                wire:model.live="nginx_upstreams_form.{{ $upstreamName }}.values.{{ $paramKey }}"
                                                                @checked(($payload['values'][$paramKey] ?? '0') === '1')
                                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                                            <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                        </span>
                                                    @elseif ($meta['type'] === 'int')
                                                        <input type="number"
                                                            wire:model.lazy="nginx_upstreams_form.{{ $upstreamName }}.values.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @else
                                                        <input type="text"
                                                            wire:model.lazy="nginx_upstreams_form.{{ $upstreamName }}.values.{{ $paramKey }}"
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
                                    wire:target="saveNginxUpstreamsConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="saveNginxUpstreamsConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveNginxUpstreamsConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload nginx') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 APACHE — GLOBAL OPTIONS CONFIG. Lives on the Workers sub-tab
                 above the runtime mod_status table. Edits the top of
                 /etc/apache2/apache2.conf (top-level scalars + IfModule
                 mpm_*_module block for MPM worker tuning).
                 ============================================================= --}}
            @if ($key === 'nginx' && $engine_subtab === 'workers' && $isActive && $engineHasFullControls($key))
                @php
                    $nginxTopParams = \App\Services\Servers\NginxGlobalOptionsConfig::TOP_PARAMS;
                    $nginxEventsParams = \App\Services\Servers\NginxGlobalOptionsConfig::EVENTS_PARAMS;
                    $nginxHttpParams = \App\Services\Servers\NginxGlobalOptionsConfig::HTTP_PARAMS;
                @endphp
                <div
                    class="{{ $card }} p-6 sm:p-8 mb-6"
                    wire:key="nginx-globals-config"
                    x-data="{
                        expanded: true,
                        storageKey: @js('dply.nginx-globals-expanded:'.$server->id),
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
                                <h3 class="text-base font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('nginx global options') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Top of /etc/nginx/nginx.conf — worker count + rlimits, events block, and http block defaults. Site blocks under sites-enabled / conf.d pass through untouched. Save runs `nginx -t` and reloads; a failed validate auto-restores the previous file.') }}
                                </p>
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="loadNginxGlobalsConfig"
                            wire:loading.attr="disabled"
                            wire:target="loadNginxGlobalsConfig"
                            x-show="expanded"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadNginxGlobalsConfig" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            </span>
                            <span wire:loading wire:target="loadNginxGlobalsConfig" class="inline-flex">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                            {{ __('Reload from server') }}
                        </button>
                    </div>

                    <div x-show="expanded" x-cloak>
                        @if ($nginx_globals_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $nginx_globals_flash }}</div>
                        @endif
                        @if ($nginx_globals_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $nginx_globals_error }}</pre>
                            </div>
                        @endif

                        @if (! $nginx_globals_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadNginxGlobalsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading nginx.conf…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadNginxGlobalsConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
                        @else
                            <form wire:submit.prevent="saveNginxGlobalsConfig" class="mt-6 space-y-6">
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Top-level') }}</p>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-2">
                                        @foreach ($nginxTopParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                @if ($meta['type'] === 'int')
                                                    <input type="number"
                                                        wire:model.lazy="nginx_globals_form.{{ $paramKey }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                @else
                                                    <input type="text"
                                                        wire:model.lazy="nginx_globals_form.{{ $paramKey }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                @endif
                                                <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('events { … }') }}</p>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-3">
                                        @foreach ($nginxEventsParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                @if ($meta['type'] === 'bool')
                                                    <span class="mt-1 inline-flex items-center gap-2">
                                                        <input type="checkbox" value="1"
                                                            wire:model.live="nginx_globals_form.events_{{ $paramKey }}"
                                                            @checked(($nginx_globals_form['events_'.$paramKey] ?? '0') === '1')
                                                            class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                                        <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    </span>
                                                @elseif ($meta['type'] === 'int')
                                                    <input type="number"
                                                        wire:model.lazy="nginx_globals_form.events_{{ $paramKey }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                @else
                                                    <input type="text"
                                                        wire:model.lazy="nginx_globals_form.events_{{ $paramKey }}"
                                                        placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('http { … }') }}</p>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-2">
                                        @foreach ($nginxHttpParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                @if ($meta['type'] === 'bool')
                                                    <span class="mt-1 inline-flex items-center gap-2">
                                                        <input type="checkbox" value="1"
                                                            wire:model.live="nginx_globals_form.http_{{ $paramKey }}"
                                                            @checked(($nginx_globals_form['http_'.$paramKey] ?? '0') === '1')
                                                            class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                                        <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    </span>
                                                @elseif ($meta['type'] === 'int')
                                                    <input type="number"
                                                        wire:model.lazy="nginx_globals_form.http_{{ $paramKey }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                @else
                                                    <input type="text"
                                                        wire:model.lazy="nginx_globals_form.http_{{ $paramKey }}"
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
                                        wire:target="saveNginxGlobalsConfig"
                                        @disabled($isDeployer || $actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="saveNginxGlobalsConfig" class="inline-flex">
                                            <x-heroicon-o-check class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="saveNginxGlobalsConfig" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Save and reload nginx') }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
