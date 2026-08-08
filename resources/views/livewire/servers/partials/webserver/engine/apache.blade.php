            @if ($key === 'apache' && $engine_subtab === 'modules' && $isActive && $engineHasFullControls($key))
                <div class="space-y-4 mb-6" wire:key="apache-modules-config">
                    <div class="{{ $card }}">
                        <x-workspace-panel-head
                            dense
                            icon="heroicon-o-puzzle-piece"
                            :title="__('Apache modules')"
                            :note="__('Enable / disable Apache modules without dropping to SSH. Each toggle runs `a2enmod` or `a2dismod`, validates with `apachectl configtest`, and reloads Apache. Failed validates auto-revert the toggle.')"
                            class="border-b border-brand-ink/10"
                        >
                            <x-slot:actions>
                                <button
                                    type="button"
                                    wire:click="loadApacheModulesConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadApacheModulesConfig"
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadApacheModulesConfig" class="inline-flex">
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    </span>
                                    <span wire:loading wire:target="loadApacheModulesConfig" class="inline-flex">
                                        <x-spinner class="h-3.5 w-3.5" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        <div class="px-4 py-3.5 sm:px-5">
                        @if ($apache_modules_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $apache_modules_flash }}</div>
                        @endif
                        @if ($apache_modules_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $apache_modules_error }}</pre>
                            </div>
                        @endif

                        @if (! $apache_modules_loaded)
                            <div
                                wire:loading.block
                                wire:target="loadApacheModulesConfig,loadActiveEngineSubtabData"
                                class="mt-5 w-full rounded-xl border border-brand-ink/10 bg-white px-4 py-8 text-center text-sm text-brand-moss"
                            >
                                <x-spinner variant="forest" class="mx-auto h-5 w-5" />
                                <p class="mt-2">{{ __('Listing modules…') }}</p>
                            </div>

                            <div
                                wire:loading.remove
                                wire:target="loadApacheModulesConfig,loadActiveEngineSubtabData"
                                class="mt-5 w-full rounded-xl border border-dashed border-brand-ink/15 bg-white px-4 py-8 text-center text-sm text-brand-moss"
                            >
                                <x-heroicon-o-puzzle-piece class="mx-auto h-5 w-5 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2">{{ __('Click "Reload from server" to list available modules.') }}</p>
                            </div>
                        @else
                            @php
                                $filtered = $apache_modules_filter === 'all'
                                    ? $apache_modules_list
                                    : array_values(array_filter($apache_modules_list, fn ($m) => $m['type'] === $apache_modules_filter));
                                $enabledCount = count(array_filter($apache_modules_list, fn ($m) => $m['enabled']));
                                $filters = [
                                    'all' => __('All'),
                                    'core' => __('Core'),
                                    'mpm' => __('MPM'),
                                    'tls' => __('TLS'),
                                    'auth' => __('Authentication'),
                                    'proxy' => __('Proxy'),
                                    'perf' => __('Perf'),
                                    'security' => __('Security'),
                                    'observability' => __('Logs'),
                                    'other' => __('Other'),
                                ];
                            @endphp
                            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 pt-4">
                                <p class="text-xs text-brand-moss">
                                    {{ __(':enabled of :total modules enabled', ['enabled' => $enabledCount, 'total' => count($apache_modules_list)]) }}
                                </p>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($filters as $filterKey => $filterLabel)
                                        <button
                                            type="button"
                                            wire:click="setApacheModulesFilter('{{ $filterKey }}')"
                                            @class([
                                                'inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-[11px] font-medium transition',
                                                'border-brand-forest bg-brand-forest text-brand-cream' => $apache_modules_filter === $filterKey,
                                                'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $apache_modules_filter !== $filterKey,
                                            ])
                                        >
                                            {{ $filterLabel }}
                                            @if ($filterKey !== 'all')
                                                <span class="text-[10px] opacity-70">{{ count(array_filter($apache_modules_list, fn ($m) => $m['type'] === $filterKey)) }}</span>
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
                                                <td class="px-4 py-2 text-xs">
                                                    @if ($mod['enabled'])
                                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('enabled') }}</span>
                                                    @else
                                                        <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold text-brand-moss">{{ __('disabled') }}</span>
                                                    @endif
                                                    @if ($mod['protected'])
                                                        <span class="ml-1 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700" title="{{ __('dply provisioner depends on this module — disabling is blocked.') }}">{{ __('protected') }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2 text-right">
                                                    @if ($mod['protected'] && $mod['enabled'])
                                                        <span class="text-brand-mist text-[11px]">—</span>
                                                    @elseif ($mod['enabled'])
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('toggleApacheModule', ['{{ $mod['name'] }}', false], @js(__('Disable module: :name', ['name' => $mod['name']])), @js(__('Run `a2dismod :name`? Apache reloads after the toggle and the change reverts automatically if `apachectl configtest` fails.', ['name' => $mod['name']])), @js(__('Disable')), true)"
                                                            @disabled($isDeployer || $actionInFlight)
                                                            class="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-rose-50/30 px-2 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            <x-heroicon-o-no-symbol class="h-3 w-3" />
                                                            {{ __('Disable') }}
                                                        </button>
                                                    @else
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('toggleApacheModule', ['{{ $mod['name'] }}', true], @js(__('Enable module: :name', ['name' => $mod['name']])), @js(__('Run `a2enmod :name`? Apache reloads after the toggle and the change reverts automatically if `apachectl configtest` fails.', ['name' => $mod['name']])), @js(__('Enable')), false)"
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
                 NGINX — UPSTREAMS EDITOR. Each `upstream <name> { ... }`
                 block in /etc/nginx/nginx.conf gets a collapsible card
                 with its servers list + pool tunables. Add/remove + per-
                 upstream save all stream through the manage_action banner.
                 ============================================================= --}}
            @if ($key === 'apache' && $engine_subtab === 'workers' && $isActive && $engineHasFullControls($key))
                @php
                    $apacheTopParams = \App\Services\Servers\ApacheGlobalOptionsConfig::TOP_PARAMS;
                    $apacheMpmParams = \App\Services\Servers\ApacheGlobalOptionsConfig::MPM_PARAMS;
                @endphp
                <div
                    class="{{ $card }} mb-6"
                    wire:key="apache-globals-config"
                    x-data="{
                        expanded: true,
                        storageKey: @js('dply.apache-globals-expanded:'.$server->id),
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
                    {{-- Dense head that doubles as the disclosure control: the whole
                         title area is the toggle, the chevron sits where the icon
                         would, and the loaded MPM rides the count pill. The prose
                         that used to run four lines here is now the truncated note
                         (full text on hover) — the detail belongs in the form. --}}
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                        <button
                            type="button"
                            x-on:click="toggle()"
                            class="group flex min-w-0 flex-1 items-center gap-2 text-left"
                            x-bind:aria-expanded="expanded.toString()"
                        >
                            <x-heroicon-m-chevron-down
                                class="h-4 w-4 shrink-0 text-brand-sage transition-transform"
                                x-bind:class="expanded ? '' : '-rotate-90'"
                                aria-hidden="true"
                            />
                            <h3 class="shrink-0 text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Apache global options') }}</h3>
                            @if ($apache_globals_loaded)
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-white px-1.5 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                                    <x-heroicon-m-cpu-chip class="h-3 w-3" aria-hidden="true" /> {{ $apache_globals_mpm }}
                                </span>
                            @endif
                            <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                            <span
                                class="min-w-0 flex-1 truncate text-[11px] text-brand-mist"
                                title="{{ __('Top-level directives in /etc/apache2/apache2.conf — keep-alive, timeouts, server tokens — plus MPM worker tuning inside the active `<IfModule mpm_*_module>` block. Site / module / conf fragments under sites-enabled / mods-enabled / conf-enabled pass through. Save runs `apachectl configtest` and reloads; a failed validate auto-restores the previous file.') }}"
                            >{{ __('Keep-alive, timeouts, server tokens, and MPM worker tuning. Save validates with `apachectl configtest` and reloads.') }}</span>
                        </button>
                        {{-- Quiet ghost action: a bordered white button on a tinted
                             head read as the loudest thing in the row, and it
                             duplicated the primary CTA in the empty state below.
                             Here it's a refresh affordance — it only gains a
                             surface on hover. Label collapses to the icon on
                             narrow viewports so it never crowds the note. --}}
                        <button
                            type="button"
                            wire:click="loadApacheGlobalsConfig"
                            wire:loading.attr="disabled"
                            wire:target="loadApacheGlobalsConfig"
                            x-show="expanded"
                            :title="@js($apache_globals_loaded ? __('Reload from server') : __('Fetch current values from the server'))"
                            class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md px-1.5 text-[11px] font-semibold text-brand-moss transition hover:bg-white hover:text-brand-ink hover:shadow-sm disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadApacheGlobalsConfig" class="inline-flex">
                                <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </span>
                            <span wire:loading wire:target="loadApacheGlobalsConfig" class="inline-flex">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                            <span class="hidden sm:inline">{{ $apache_globals_loaded ? __('Reload') : __('Load') }}</span>
                            <span class="sr-only">{{ __('Reload from server') }}</span>
                        </button>
                    </div>

                    <div x-show="expanded" x-cloak class="px-4 py-3.5 sm:px-5">
                        @if ($apache_globals_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $apache_globals_flash }}</div>
                        @endif
                        @if ($apache_globals_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $apache_globals_error }}</pre>
                            </div>
                        @endif

                        @if (! $apache_globals_loaded)
                            {{-- Reading: skeleton the form we're about to paint,
                                 rather than a line of text under an empty panel. --}}
                            <div wire:loading.block wire:target="loadApacheGlobalsConfig" aria-busy="true" aria-live="polite">
                                <span class="sr-only">{{ __('Reading apache2.conf…') }}</span>
                                @php $bar = 'animate-pulse rounded bg-brand-ink/10'; @endphp
                                <p class="flex items-center gap-2 text-[11px] text-brand-moss">
                                    <x-spinner class="h-3.5 w-3.5 shrink-0" /> {{ __('Reading apache2.conf…') }}
                                </p>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2" aria-hidden="true">
                                    @foreach (range(1, 6) as $field)
                                        <div class="space-y-1.5">
                                            <div class="h-2.5 w-28 {{ $bar }}"></div>
                                            <div class="h-8 w-full rounded-lg {{ $bar }}"></div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Idle: the instruction ("Click Reload from server…")
                                 pointed at a control somewhere else on the row. The
                                 action belongs here, where the operator is looking. --}}
                            <div wire:loading.remove wire:target="loadApacheGlobalsConfig">
                                <x-empty-state
                                    compact
                                    icon="heroicon-o-cog-6-tooth"
                                    :title="__('Global options not loaded yet')"
                                    :description="__('dply reads /etc/apache2/apache2.conf over SSH on demand, so the form starts empty. Fetch the current values to edit keep-alive, timeouts, server tokens, and MPM workers.')"
                                >
                                    <x-slot:actions>
                                        <button
                                            type="button"
                                            wire:click="loadApacheGlobalsConfig"
                                            wire:loading.attr="disabled"
                                            wire:target="loadApacheGlobalsConfig"
                                            class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-brand-ink px-3 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60"
                                        >
                                            <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Load from server') }}
                                        </button>
                                    </x-slot:actions>
                                </x-empty-state>
                            </div>
                        @else
                            <form wire:submit.prevent="saveApacheGlobalsConfig" class="mt-6 space-y-6">
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Top-level') }}</p>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-2">
                                        @foreach ($apacheTopParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                @if ($meta['type'] === 'bool')
                                                    <span class="mt-1 inline-flex items-center gap-2">
                                                        <input type="checkbox" value="1"
                                                            wire:model.live="apache_globals_form.{{ $paramKey }}"
                                                            @checked(in_array(($apache_globals_form[$paramKey] ?? 'Off'), ['On', 'on', '1', 'true', 'yes'], true))
                                                            class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                                        <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    </span>
                                                @elseif ($meta['type'] === 'int')
                                                    <input type="number"
                                                        wire:model.lazy="apache_globals_form.{{ $paramKey }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                @else
                                                    <input type="text"
                                                        wire:model.lazy="apache_globals_form.{{ $paramKey }}"
                                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('<IfModule :mpm> { … }', ['mpm' => $apache_globals_mpm]) }}</p>
                                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('MPM directives may live in /etc/apache2/mods-available/mpm_event.conf instead of apache2.conf — if so, dply will report "no changes" and you should edit the mods file via the Config sub-tab.') }}</p>
                                    <div class="mt-3 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach ($apacheMpmParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                <input type="number"
                                                    wire:model.lazy="apache_globals_form.mpm_{{ $paramKey }}"
                                                    class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="saveApacheGlobalsConfig"
                                        @disabled($isDeployer || $actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="saveApacheGlobalsConfig" class="inline-flex">
                                            <x-heroicon-o-check class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="saveApacheGlobalsConfig" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Save and reload Apache') }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            @if ($key === 'apache' && $engine_subtab === 'cache' && $isActive && $engineHasFullControls($key))
                <div class="{{ $card }}" wire:key="apache-cache-config">
                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-bolt"
                        :title="__('Apache caching')"
                        :note="__('Apache has no full-page cache of its own. dply applies browser Expires headers per site when engine cache is enabled, and can purge mod_cache\'s on-disk store. Enable mod_expires and mod_deflate from the Modules tab.')"
                        class="border-b border-brand-ink/10"
                    >
                        <x-slot:actions>
                            <button type="button" wire:click="loadApacheCacheConfig" wire:loading.attr="disabled" wire:target="loadApacheCacheConfig"
                                class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60">
                                <span wire:loading.remove wire:target="loadApacheCacheConfig" class="inline-flex"><x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /></span>
                                <span wire:loading wire:target="loadApacheCacheConfig" class="inline-flex"><x-spinner class="h-3.5 w-3.5" /></span>
                                {{ __('Reload') }}
                            </button>
                            <button type="button"
                                wire:click="openConfirmActionModal('purgeApacheEngineCacheConfirmed', [], @js(__('Purge disk cache')), @js(__('Remove mod_cache disk storage under /var/cache/apache2? Browser caches on visitor devices are not affected.')), @js(__('Purge cache')), true)"
                                wire:loading.attr="disabled"
                                @disabled($isDeployer || $actionInFlight || ! $opsReady)
                                class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-rose-200 bg-rose-50 px-2 text-[11px] font-semibold text-rose-800 shadow-sm transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60">
                                <x-heroicon-m-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Purge disk cache') }}
                            </button>
                        </x-slot:actions>
                    </x-workspace-panel-head>

                    <div class="px-4 py-3.5 sm:px-5">
                    @if ($apache_cache_flash)
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-900">{{ $apache_cache_flash }}</div>
                    @endif
                    @if ($apache_cache_error)
                        <div class="rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">
                            <pre class="whitespace-pre-wrap break-words font-mono text-[11px]">{{ $apache_cache_error }}</pre>
                        </div>
                    @endif

                    @if (! $apache_cache_loaded)
                        <p class="text-xs text-brand-moss">
                            <span wire:loading wire:target="loadApacheCacheConfig,loadActiveEngineSubtabData" class="inline-flex items-center gap-2">
                                <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading cache settings…') }}
                            </span>
                            <span wire:loading.remove wire:target="loadApacheCacheConfig,loadActiveEngineSubtabData">
                                {{ __('Click "Reload" to read the current cache settings from the server.') }}
                            </span>
                        </p>
                    @else
                        <ul class="space-y-1.5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3 text-xs">
                            <li class="flex items-center justify-between gap-3">
                                <span class="text-brand-moss">{{ __('mod_expires') }}</span>
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1',
                                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => ($apache_cache_status['mod_expires_enabled'] ?? false),
                                    'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! ($apache_cache_status['mod_expires_enabled'] ?? false),
                                ])>{{ ($apache_cache_status['mod_expires_enabled'] ?? false) ? __('Enabled') : __('Disabled') }}</span>
                            </li>
                            <li class="flex items-center justify-between gap-3">
                                <span class="text-brand-moss">{{ __('mod_deflate') }}</span>
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1',
                                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => ($apache_cache_status['mod_deflate_enabled'] ?? false),
                                    'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! ($apache_cache_status['mod_deflate_enabled'] ?? false),
                                ])>{{ ($apache_cache_status['mod_deflate_enabled'] ?? false) ? __('Enabled') : __('Disabled') }}</span>
                            </li>
                            <li class="text-xs text-brand-mist">
                                {{ __('Disk cache path: :path', ['path' => $apache_cache_status['disk_cache_path'] ?? '/var/cache/apache2/mod_cache_disk']) }}
                            </li>
                        </ul>
                        <form wire:submit.prevent="saveApacheCacheConfig" class="mt-3 space-y-3">
                            <label class="inline-flex items-start gap-2">
                                <input type="checkbox" value="1" wire:model.live="apache_mod_cache_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                <span class="text-xs text-brand-ink">
                                    <span class="font-semibold">{{ __('Track mod_cache disk caching') }}</span>
                                    <span class="mt-0.5 block text-[11px] text-brand-moss">{{ __('Preference flag for future mod_cache automation. Purge uses the disk path above.') }}</span>
                                </span>
                            </label>
                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 pt-3">
                                <button type="button" wire:click="setEngineSubtab('modules')" class="text-[11px] font-semibold text-brand-forest underline decoration-brand-forest/30 underline-offset-2">
                                    {{ __('Open Modules tab →') }}
                                </button>
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveApacheCacheConfig" @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-brand-ink px-3 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60">
                                    {{ __('Save preferences') }}
                                </button>
                            </div>
                        </form>
                    @endif
                    </div>
                </div>
            @endif

            @include('livewire.servers.partials.webserver.engine._apache-custom-vhosts')
