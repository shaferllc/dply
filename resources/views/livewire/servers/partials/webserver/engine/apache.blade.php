            @if ($key === 'apache' && $engine_subtab === 'modules' && $isActive && $engineHasFullControls($key))
                <div class="space-y-4 mb-6" wire:key="apache-modules-config">
                    <div class="{{ $card }}">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-puzzle-piece class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Modules') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Apache modules') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Enable / disable Apache modules without dropping to SSH. Each toggle runs `a2enmod` or `a2dismod`, validates with `apachectl configtest`, and reloads Apache. Failed validates auto-revert the toggle.') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                wire:click="loadApacheModulesConfig"
                                wire:loading.attr="disabled"
                                wire:target="loadApacheModulesConfig"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                            >
                                <span wire:loading.remove wire:target="loadApacheModulesConfig" class="inline-flex">
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                </span>
                                <span wire:loading wire:target="loadApacheModulesConfig" class="inline-flex">
                                    <x-spinner class="h-3.5 w-3.5" />
                                </span>
                                {{ __('Reload from server') }}
                            </button>
                        </div>

                        <div class="px-6 py-6 sm:px-7">
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
                                class="mt-5 w-full rounded-xl border border-brand-ink/10 bg-white px-6 py-10 text-center text-sm text-brand-moss"
                            >
                                <x-spinner variant="forest" class="mx-auto h-5 w-5" />
                                <p class="mt-2">{{ __('Listing modules…') }}</p>
                            </div>

                            <div
                                wire:loading.remove
                                wire:target="loadApacheModulesConfig,loadActiveEngineSubtabData"
                                class="mt-5 w-full rounded-xl border border-dashed border-brand-ink/15 bg-white px-6 py-10 text-center text-sm text-brand-moss"
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
                    <div class="flex flex-wrap items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-cog-6-tooth class="h-5 w-5" aria-hidden="true" />
                        </span>
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
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Options') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Apache global options') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Top-level directives in /etc/apache2/apache2.conf — keep-alive, timeouts, server tokens — plus MPM worker tuning inside the active `<IfModule mpm_*_module>` block. Site / module / conf fragments under sites-enabled / mods-enabled / conf-enabled pass through. Save runs `apachectl configtest` and reloads; a failed validate auto-restores the previous file.') }}
                                </p>
                                @if ($apache_globals_loaded)
                                    <p class="mt-2 inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                        <x-heroicon-o-cpu-chip class="h-3 w-3" /> MPM: {{ $apache_globals_mpm }}
                                    </p>
                                @endif
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="loadApacheGlobalsConfig"
                            wire:loading.attr="disabled"
                            wire:target="loadApacheGlobalsConfig"
                            x-show="expanded"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadApacheGlobalsConfig" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            </span>
                            <span wire:loading wire:target="loadApacheGlobalsConfig" class="inline-flex">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                            {{ __('Reload from server') }}
                        </button>
                    </div>

                    <div x-show="expanded" x-cloak class="px-6 py-6 sm:px-7">
                        @if ($apache_globals_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $apache_globals_flash }}</div>
                        @endif
                        @if ($apache_globals_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $apache_globals_error }}</pre>
                            </div>
                        @endif

                        @if (! $apache_globals_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadApacheGlobalsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading apache2.conf…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadApacheGlobalsConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
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
                <div class="{{ $card }} p-6 sm:p-8 mb-6" wire:key="apache-cache-config">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Apache caching') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('RunCloud-style stacks use nginx FastCGI cache at the edge; on pure Apache, dply applies browser Expires headers per site when engine cache is enabled. Enable mod_expires and mod_deflate from the Modules tab.') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="loadApacheCacheConfig" wire:loading.attr="disabled" wire:target="loadApacheCacheConfig"
                                class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60">
                                <span wire:loading.remove wire:target="loadApacheCacheConfig"><x-heroicon-o-arrow-path class="h-3.5 w-3.5" /></span>
                                <span wire:loading wire:target="loadApacheCacheConfig"><x-spinner class="h-3.5 w-3.5" /></span>
                                {{ __('Reload') }}
                            </button>
                            <button type="button"
                                wire:click="openConfirmActionModal('purgeApacheEngineCacheConfirmed', [], @js(__('Purge disk cache')), @js(__('Remove mod_cache disk storage under /var/cache/apache2? Browser caches on visitor devices are not affected.')), @js(__('Purge cache')), true)"
                                wire:loading.attr="disabled"
                                @disabled($isDeployer || $actionInFlight || ! $opsReady)
                                class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-800 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60">
                                <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                {{ __('Purge disk cache') }}
                            </button>
                        </div>
                    </div>

                    @if ($apache_cache_flash)
                        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $apache_cache_flash }}</div>
                    @endif
                    @if ($apache_cache_error)
                        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                            <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $apache_cache_error }}</pre>
                        </div>
                    @endif

                    @if (! $apache_cache_loaded)
                        <p class="mt-5 text-sm text-brand-moss">
                            <span wire:loading wire:target="loadApacheCacheConfig,loadActiveEngineSubtabData" class="inline-flex items-center gap-2">
                                <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading cache settings…') }}
                            </span>
                        </p>
                    @else
                        <ul class="mt-5 space-y-2 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm">
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
                        <form wire:submit.prevent="saveApacheCacheConfig" class="mt-6 space-y-4">
                            <label class="inline-flex items-start gap-2">
                                <input type="checkbox" value="1" wire:model.live="apache_mod_cache_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                <span class="text-sm text-brand-ink">
                                    <span class="font-medium">{{ __('Track mod_cache disk caching') }}</span>
                                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Preference flag for future mod_cache automation. Purge uses the disk path above.') }}</span>
                                </span>
                            </label>
                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 pt-4">
                                <button type="button" wire:click="setEngineSubtab('modules')" class="text-xs font-semibold text-brand-forest underline decoration-brand-forest/30 underline-offset-2">
                                    {{ __('Open Modules tab →') }}
                                </button>
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveApacheCacheConfig" @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-60">
                                    {{ __('Save preferences') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            @include('livewire.servers.partials.webserver.engine._apache-custom-vhosts')
