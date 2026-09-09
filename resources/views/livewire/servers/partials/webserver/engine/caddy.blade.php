            @include('livewire.servers.partials.webserver.engine._caddy-modules')

            {{-- =============================================================
                 CADDY — CUSTOM ROUTES. Ad-hoc site blocks in sites-enabled.
                 Lives on the Routes sub-tab above the live-state table.
                 ============================================================= --}}
            @if ($key === 'caddy' && $isActive && $engineHasFullControls($key))
                <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'routes'" x-cloak @endif class="space-y-4 mb-6" wire:key="caddy-custom-routes-config">
                    <div class="{{ $card }} overflow-hidden">
                        <x-workspace-panel-head
                            dense
                            icon="heroicon-o-arrow-path-rounded-square"
                            :title="__('Custom Caddy routes')"
                            :count="$caddy_custom_routes_loaded ? (count($caddy_custom_routes_form) ?: null) : null"
                            :note="__('Add ad-hoc site blocks as `dply-custom-*.caddy` under sites-enabled. Dply-managed site routes are provisioned separately — use this for standalone hostnames, reverse proxies, or legacy configs.')"
                            class="border-b border-brand-ink/10"
                        >
                            <x-slot:actions>
                                <button
                                    type="button"
                                    wire:click="openAddCaddyCustomRouteForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-brand-forest px-2 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" />
                                    {{ __('Add route') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadCaddyCustomRoutesConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadCaddyCustomRoutesConfig"
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadCaddyCustomRoutesConfig" class="inline-flex">
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" />
                                    </span>
                                    <span wire:loading wire:target="loadCaddyCustomRoutesConfig" class="inline-flex">
                                        <x-spinner class="h-3.5 w-3.5" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        <div class="px-4 py-3.5 sm:px-5">
                        @if ($caddy_custom_routes_flash)
                            <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-900">{{ $caddy_custom_routes_flash }}</div>
                        @endif
                        @if ($caddy_custom_routes_error)
                            <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $caddy_custom_routes_error }}</pre>
                            </div>
                        @endif

                        @if ($caddy_custom_routes_show_add)
                            <form wire:submit.prevent="submitAddCaddyCustomRoute" class="mb-3 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add custom route') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Creates sites-enabled/dply-custom-{slug}.caddy and reloads Caddy after validate.') }}</p>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Slug') }}</span>
                                        <input type="text" wire:model.lazy="caddy_custom_routes_new.slug" placeholder="legacy-api" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" required />
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Hostnames') }}</span>
                                        <input type="text" wire:model.lazy="caddy_custom_routes_new.hosts" placeholder="api.example.com www.example.com" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" required />
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Document root') }}</span>
                                        <input type="text" wire:model.lazy="caddy_custom_routes_new.root" placeholder="/var/www/example/public" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        <span class="mt-1 block text-xs text-brand-mist">{{ __('Required for static and PHP routes. Leave empty when using reverse_proxy only.') }}</span>
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Backend (optional)') }}</span>
                                        <input type="text" wire:model.lazy="caddy_custom_routes_new.upstream" placeholder="127.0.0.1:3000 or unix:/run/php/php8.3-fpm.sock" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        <span class="mt-1 block text-xs text-brand-mist">{{ __('Leave empty for static file_server. PHP socket → php_fastcgi. http:// or host:port → reverse_proxy.') }}</span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button type="button" wire:click="cancelAddCaddyCustomRouteForm" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="submitAddCaddyCustomRoute" @disabled($actionInFlight) class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60">
                                        <span wire:loading.remove wire:target="submitAddCaddyCustomRoute" class="inline-flex"><x-heroicon-o-plus class="h-4 w-4" /></span>
                                        <span wire:loading wire:target="submitAddCaddyCustomRoute" class="inline-flex"><x-spinner variant="cream" class="h-4 w-4" /></span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        {{-- Dashed-card idle + empty states, matching the nginx
                             Hosts / Upstreams panels. --}}
                        @if (! $caddy_custom_routes_loaded)
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <div wire:loading.block wire:target="loadCaddyCustomRoutesConfig,loadActiveEngineSubtabData" class="flex flex-col items-center">
                                    <x-spinner variant="forest" class="h-5 w-5" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Reading custom route files…') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Listing dply-custom-*.caddy under sites-enabled over SSH.') }}</p>
                                </div>
                                <div wire:loading.remove wire:target="loadCaddyCustomRoutesConfig,loadActiveEngineSubtabData" class="flex flex-col items-center">
                                    <x-heroicon-o-arrow-path-rounded-square class="h-5 w-5 text-brand-mist" aria-hidden="true" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Custom routes not loaded') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Read dply-custom-*.caddy from this server to edit or remove standalone routes.') }}</p>
                                    <button
                                        type="button"
                                        wire:click="loadCaddyCustomRoutesConfig"
                                        class="mt-2.5 inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Reload from server') }}
                                    </button>
                                </div>
                            </div>
                        @elseif ($caddy_custom_routes_form === [])
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <x-heroicon-o-arrow-path-rounded-square class="mx-auto h-5 w-5 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('No custom routes') }}</p>
                                <p class="mx-auto mt-0.5 max-w-md text-xs leading-relaxed text-brand-moss">
                                    {{ __('Standalone site blocks you add here live in dply-custom-*.caddy. Routes Dply provisions per site are managed from the Sites workspace — both show in the live route table below.') }}
                                </p>
                                <div class="mt-2.5 flex flex-wrap items-center justify-center gap-1.5">
                                    <button
                                        type="button"
                                        wire:click="openAddCaddyCustomRouteForm"
                                        @disabled($isDeployer || $actionInFlight)
                                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Add route') }}
                                    </button>
                                    <a
                                        href="{{ route('servers.sites', $server) }}"
                                        wire:navigate
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        {{ __('Open Sites') }}
                                        <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    </a>
                                </div>
                            </div>
                        @endif
                        </div>
                    </div>

                    @if ($caddy_custom_routes_loaded && $caddy_custom_routes_form !== [])
                        <div class="space-y-4">
                            @foreach ($caddy_custom_routes_form as $routeSlug => $routeFields)
                                <form wire:submit.prevent="saveCaddyCustomRoute(@js($routeSlug))" class="{{ $card }} p-5 sm:p-6" wire:key="caddy-custom-route-{{ $routeSlug }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="font-mono text-sm font-semibold text-brand-ink">dply-custom-{{ $routeSlug }}.caddy</p>
                                            <p class="mt-0.5 text-xs text-brand-mist">{{ __('Custom route') }}</p>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('removeCaddyCustomRoute', [@js($routeSlug)], @js(__('Remove custom route: :slug', ['slug' => $routeSlug])), @js(__('Delete sites-enabled/dply-custom-:slug.caddy?', ['slug' => $routeSlug])), @js(__('Remove')), true)"
                                            @disabled($isDeployer || $actionInFlight)
                                            class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-xs font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <x-heroicon-o-trash class="h-4 w-4" />
                                            {{ __('Remove') }}
                                        </button>
                                    </div>

                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        <label class="block sm:col-span-2">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Hostnames') }}</span>
                                            <textarea wire:model.lazy="caddy_custom_routes_form.{{ $routeSlug }}.hosts" rows="2" spellcheck="false" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white p-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-sage/30"></textarea>
                                        </label>
                                        <label class="block sm:col-span-2">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Document root') }}</span>
                                            <input type="text" wire:model.lazy="caddy_custom_routes_form.{{ $routeSlug }}.root" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        </label>
                                        <label class="block sm:col-span-2">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Backend') }}</span>
                                            <input type="text" wire:model.lazy="caddy_custom_routes_form.{{ $routeSlug }}.upstream" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        </label>
                                    </div>

                                    <div class="mt-4 flex justify-end border-t border-brand-ink/10 pt-3">
                                        <button type="submit" wire:loading.attr="disabled" wire:target="saveCaddyCustomRoute(@js($routeSlug))" @disabled($isDeployer || $actionInFlight) class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60">
                                            <span wire:loading.remove wire:target="saveCaddyCustomRoute(@js($routeSlug))" class="inline-flex"><x-heroicon-o-check class="h-4 w-4" /></span>
                                            <span wire:loading wire:target="saveCaddyCustomRoute(@js($routeSlug))" class="inline-flex"><x-spinner variant="cream" class="h-4 w-4" /></span>
                                            {{ __('Save and reload Caddy') }}
                                        </button>
                                    </div>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 CADDY — GLOBAL OPTIONS CONFIG. The `{ ... }` block at the
                 top of /etc/caddy/Caddyfile. Lives on the Admin sub-tab
                 above the live-state table.
                 ============================================================= --}}
            @if ($key === 'caddy' && $isActive && $engineHasFullControls($key))
                @php
                    $caddyTopParams = \App\Services\Servers\CaddyGlobalOptionsConfig::TOP_PARAMS;
                    $caddyServersParams = \App\Services\Servers\CaddyGlobalOptionsConfig::SERVERS_PARAMS;
                    $caddyLogParams = \App\Services\Servers\CaddyGlobalOptionsConfig::LOG_PARAMS;
                @endphp
                <div
                    @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'admin'" x-cloak @endif
                    class="{{ $card }} mb-6 overflow-hidden"
                    wire:key="caddy-globals-config"
                    x-data="{
                        expanded: true,
                        storageKey: @js('dply.caddy-globals-expanded:'.$server->id),
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
                    {{-- Hand-rolled rather than x-workspace-panel-head: the whole
                         title row is the collapse toggle. Metrics track the dense
                         head, same as the nginx global-options panel. --}}
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                        <button
                            type="button"
                            x-on:click="toggle()"
                            class="group flex min-w-0 flex-1 items-center gap-2 text-left"
                            x-bind:aria-expanded="expanded.toString()"
                        >
                            <x-heroicon-o-chevron-down
                                class="h-4 w-4 shrink-0 text-brand-sage transition-transform"
                                x-bind:class="expanded ? '' : '-rotate-90'"
                                aria-hidden="true"
                            />
                            <span class="shrink-0 text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Caddy global options') }}</span>
                            <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                            <span
                                class="min-w-0 flex-1 truncate text-xs text-brand-mist"
                                title="{{ __('The leading `{ ... }` block in /etc/caddy/Caddyfile — ACME account email, admin endpoint, auto-HTTPS mode, server protocols, timeouts, and default log settings. Save runs `caddy validate` and reloads; a failed validate auto-restores the previous file.') }}"
                            >
                                {{ __('The leading `{ ... }` block in /etc/caddy/Caddyfile — ACME account email, admin endpoint, auto-HTTPS mode, server protocols, timeouts, and default log settings. Save runs `caddy validate` and reloads; a failed validate auto-restores the previous file.') }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="loadCaddyGlobalsConfig(true)"
                            wire:loading.attr="disabled"
                            wire:target="loadCaddyGlobalsConfig,loadActiveEngineSubtabData"
                            x-show="expanded"
                            class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadCaddyGlobalsConfig,loadActiveEngineSubtabData" class="inline-flex">
                                <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" />
                            </span>
                            <span wire:loading wire:target="loadCaddyGlobalsConfig,loadActiveEngineSubtabData" class="inline-flex">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                            {{ __('Reload from server') }}
                        </button>
                    </div>

                    <div x-show="expanded" x-cloak class="px-4 py-3.5 sm:px-5">
                        @if ($caddy_globals_flash)
                            <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-900">{{ $caddy_globals_flash }}</div>
                        @endif
                        @if ($caddy_globals_error)
                            <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $caddy_globals_error }}</pre>
                            </div>
                        @endif

                        @if (! $caddy_globals_loaded)
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <div wire:loading.block wire:target="loadCaddyGlobalsConfig,loadActiveEngineSubtabData" class="flex flex-col items-center">
                                    <x-spinner variant="forest" class="h-5 w-5" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Reading Caddyfile…') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Parsing the leading global-options block over SSH.') }}</p>
                                </div>
                                <div wire:loading.remove wire:target="loadCaddyGlobalsConfig,loadActiveEngineSubtabData" class="flex flex-col items-center">
                                    <x-heroicon-o-cog-6-tooth class="h-5 w-5 text-brand-mist" aria-hidden="true" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Global options not loaded') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Read the current ACME, admin, and log defaults from this server to edit them.') }}</p>
                                    <button
                                        type="button"
                                        wire:click="loadCaddyGlobalsConfig(true)"
                                        class="mt-2.5 inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Reload from server') }}
                                    </button>
                                </div>
                            </div>
                        @else
                            <form wire:submit.prevent="saveCaddyGlobalsConfig" class="space-y-4">
                                {{-- Top-level scalars (email, admin, default_sni, etc.). --}}
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Server') }}</p>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-2">
                                        @foreach ($caddyTopParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                @if ($meta['type'] === 'bool')
                                                    <span class="mt-1 inline-flex items-center gap-2">
                                                        <input
                                                            type="checkbox"
                                                            value="1"
                                                            wire:model.live="caddy_globals_form.{{ $paramKey }}"
                                                            @checked(($caddy_globals_form[$paramKey] ?? '0') === '1')
                                                            class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    </span>
                                                @else
                                                    <input
                                                        type="text"
                                                        wire:model.lazy="caddy_globals_form.{{ $paramKey }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                    />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- servers { protocols, timeouts {…} } --}}
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('servers { … }') }}</p>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-2">
                                        @foreach ($caddyServersParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                <input
                                                    type="text"
                                                    wire:model.lazy="caddy_globals_form.servers_{{ $paramKey }}"
                                                    placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                    class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                />
                                                <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- log default { output, format, level } --}}
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('log default { … }') }}</p>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-3">
                                        @foreach ($caddyLogParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                <input
                                                    type="text"
                                                    wire:model.lazy="caddy_globals_form.log_{{ $paramKey }}"
                                                    placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                    class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                />
                                                <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="saveCaddyGlobalsConfig"
                                        @disabled($isDeployer || $actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="saveCaddyGlobalsConfig" class="inline-flex">
                                            <x-heroicon-o-check class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="saveCaddyGlobalsConfig" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Save and reload Caddy') }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>

                @include('livewire.servers.partials.webserver.engine._caddy-admin-api-links')
            @endif

            {{-- =============================================================
                 HAPROXY — BACKENDS EDITOR. Each `backend <name>` block
                 gets a collapsible card with servers + balance algorithm
                 + health check + timeout overrides.
                 ============================================================= --}}
            @if ($key === 'caddy' && $isActive && $engineHasFullControls($key))
                @php
                    $caddySnippetsBusyTargets = 'loadCaddySnippetsConfig,saveCaddySnippetsConfig,submitAddCaddySnippet,confirmActionModal';
                @endphp
                <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'snippets'" x-cloak @endif class="space-y-4 mb-6" wire:key="caddy-snippets-config">
                    <div class="{{ $card }} overflow-hidden">
                        <x-workspace-panel-head
                            dense
                            icon="heroicon-o-code-bracket-square"
                            :title="__('Caddy snippets')"
                            :count="$caddy_snippets_loaded ? (count($caddy_snippets_form) ?: null) : null"
                            :note="__('Reusable `(name) { … }` blocks in /etc/caddy/Caddyfile that sites pull in via `import name`. Edits run `caddy validate` and reload; a failed validate auto-restores the previous file.')"
                            class="border-b border-brand-ink/10"
                        >
                            <x-slot:actions>
                                <button
                                    type="button"
                                    wire:click="openAddCaddySnippetForm"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $caddySnippetsBusyTargets }}"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-brand-forest px-2 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" />
                                    {{ __('Add snippet') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadCaddySnippetsConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $caddySnippetsBusyTargets }}"
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="{{ $caddySnippetsBusyTargets }}" class="inline-flex">
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" />
                                    </span>
                                    <span wire:loading wire:target="{{ $caddySnippetsBusyTargets }}" class="inline-flex">
                                        <x-spinner class="h-3.5 w-3.5" />
                                    </span>
                                    <span wire:loading wire:target="{{ $caddySnippetsBusyTargets }}">{{ __('Reading…') }}</span>
                                    <span wire:loading.remove wire:target="{{ $caddySnippetsBusyTargets }}">{{ __('Reload from server') }}</span>
                                </button>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        <div class="px-4 py-3.5 sm:px-5">
                        <div
                            wire:loading.block
                            wire:target="{{ $caddySnippetsBusyTargets }}"
                            class="mb-3 rounded-lg border border-sky-200 bg-sky-50/80 px-3 py-2 text-xs text-sky-900"
                        >
                            <span class="inline-flex items-center gap-2 font-semibold">
                                <x-spinner variant="forest" class="h-3.5 w-3.5" />
                                {{ __('Reading Caddyfile on the server…') }}
                            </span>
                            <p class="mt-0.5 text-xs text-sky-800">{{ __('SSH output appears in the console banner above when the read finishes.') }}</p>
                        </div>

                        @if ($caddy_snippets_flash)
                            <div wire:loading.remove wire:target="{{ $caddySnippetsBusyTargets }}" class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-900">{{ $caddy_snippets_flash }}</div>
                        @endif
                        @if ($caddy_snippets_error)
                            <div wire:loading.remove wire:target="{{ $caddySnippetsBusyTargets }}" class="mb-3 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $caddy_snippets_error }}</pre>
                            </div>
                        @endif

                        @if ($caddy_snippets_show_add)
                            <form
                                wire:submit.prevent="submitAddCaddySnippet"
                                class="mb-3 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4"
                            >
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a new snippet') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Names are referenced as `import <name>` in site blocks. Letters, digits, and `_ . -` only.') }}</p>

                                <div class="mt-4 grid gap-4">
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="caddy_snippets_new.name"
                                            placeholder="common_headers"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Body') }}</span>
                                        <textarea
                                            wire:model.lazy="caddy_snippets_new.body"
                                            rows="8"
                                            spellcheck="false"
                                            placeholder="header X-Frame-Options &quot;DENY&quot;{{ "\n" }}header X-Content-Type-Options &quot;nosniff&quot;"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"
                                            required
                                        ></textarea>
                                        <span class="mt-1 block text-xs text-brand-mist">{{ __('Any Caddyfile directives. dply re-indents on save so `caddy fmt` stays a no-op.') }}</span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button
                                        type="button"
                                        wire:click="cancelAddCaddySnippetForm"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        {{ __('Cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ $caddySnippetsBusyTargets }}"
                                        @disabled($actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="{{ $caddySnippetsBusyTargets }}" class="inline-flex">
                                            <x-heroicon-o-plus class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="{{ $caddySnippetsBusyTargets }}" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="{{ $caddySnippetsBusyTargets }}">{{ __('Creating…') }}</span>
                                        <span wire:loading.remove wire:target="{{ $caddySnippetsBusyTargets }}">{{ __('Create and reload') }}</span>
                                    </button>
                                </div>
                            </form>
                        @endif

                        {{-- The loaded-but-empty branch is new: a Caddyfile with no
                             (name) blocks previously rendered nothing here. --}}
                        @if (! $caddy_snippets_loaded)
                            <div wire:loading.remove wire:target="{{ $caddySnippetsBusyTargets }}" class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <x-heroicon-o-code-bracket-square class="mx-auto h-5 w-5 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Snippets not loaded') }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Read the Caddyfile from this server to edit its reusable blocks.') }}</p>
                                <button
                                    type="button"
                                    wire:click="loadCaddySnippetsConfig"
                                    class="mt-2.5 inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        @elseif (empty($caddy_snippets_form))
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <x-heroicon-o-code-bracket-square class="mx-auto h-5 w-5 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('No snippets') }}</p>
                                <p class="mx-auto mt-0.5 max-w-md text-xs leading-relaxed text-brand-moss">
                                    {{ __('The Caddyfile has no `(name) { … }` blocks yet. Add one to share a header set, matcher, or reverse-proxy config across sites via `import name`.') }}
                                </p>
                                <button
                                    type="button"
                                    wire:click="openAddCaddySnippetForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="mt-2.5 inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Add snippet') }}
                                </button>
                            </div>
                        @endif
                        </div>
                    </div>

                    @if ($caddy_snippets_loaded && ! empty($caddy_snippets_form))
                        <form wire:submit.prevent="saveCaddySnippetsConfig" class="space-y-4">
                            @foreach ($caddy_snippets_form as $snippetName => $body)
                                <div
                                    class="{{ $card }} p-5 sm:p-6"
                                    x-data="{
                                        expanded: false,
                                        storageKey: @js('dply.caddy-snippet-expanded:'.$server->id.':'.$snippetName),
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
                                    wire:key="caddy-snippet-{{ $snippetName }}"
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
                                                <span class="font-mono text-sm font-semibold text-brand-ink group-hover:text-brand-forest">({{ $snippetName }})</span>
                                                <span class="text-xs text-brand-mist">{{ __(':n line(s)', ['n' => substr_count((string) $body, "\n") + ($body === '' ? 0 : 1)]) }}</span>
                                            </span>
                                            <span class="mt-0.5 block truncate text-xs font-mono text-brand-mist">import {{ $snippetName }}</span>
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-4">
                                        <div class="flex items-center justify-end">
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('removeCaddySnippet', ['{{ $snippetName }}'], @js(__('Remove snippet: :name', ['name' => '('.$snippetName.')'])), @js(__('Remove the `(:name)` snippet block? Sites that still `import :name` will fail to validate on next reload.', ['name' => $snippetName])), @js(__('Remove')), true)"
                                                @disabled($isDeployer || $actionInFlight)
                                                class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-xs font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                <x-heroicon-o-trash class="h-4 w-4" />
                                                {{ __('Remove') }}
                                            </button>
                                        </div>
                                        <label class="block">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Body') }}</span>
                                            <textarea
                                                wire:model.lazy="caddy_snippets_form.{{ $snippetName }}"
                                                wire:key="caddy-snippet-textarea-{{ $snippetName }}"
                                                rows="8"
                                                spellcheck="false"
                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"
                                            >{{ $body }}</textarea>
                                        </label>
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $caddySnippetsBusyTargets }}"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="{{ $caddySnippetsBusyTargets }}" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="{{ $caddySnippetsBusyTargets }}" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="{{ $caddySnippetsBusyTargets }}">{{ __('Saving…') }}</span>
                                    <span wire:loading.remove wire:target="{{ $caddySnippetsBusyTargets }}">{{ __('Save and reload Caddy') }}</span>
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif
