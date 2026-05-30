@if ($key === 'caddy' && $isActive && $engineHasFullControls($key))
    @php
        $caddyModulesBusyTargets = 'loadCaddyModulesInventory,refreshCaddyModulesInventory,submitAddCaddyModule,requestAddCaddyModule,openConfirmInstallCaddyModule,installCaddyModuleConfirmed,queueCatalogCaddyModule,queueCaddyModulesRebuild,queueRestoreCaddyPackageBinary,confirmActionModal,removeCaddyModulePlugin,openCaddyModuleBrowse,closeCaddyModuleBrowse,resetCaddyModulesCompiledFilters,setCaddyModulesFilter';
        $caddyModuleCatalog = $caddy_modules_available_catalog !== [] ? $caddy_modules_available_catalog : (array) config('caddy_modules.catalog', []);
        $caddyModuleFilters = [
            'all' => __('All'),
            'handlers' => __('Handlers'),
            'matchers' => __('Matchers'),
            'tls' => __('TLS'),
            'dns' => __('DNS'),
            'storage' => __('Storage'),
            'core' => __('Core'),
            'other' => __('Other'),
        ];
        $installedFiltered = array_values(array_filter(
            $caddy_modules_installed,
            function (array $module) use ($caddy_modules_filter, $caddy_modules_search): bool {
                if ($caddy_modules_filter !== 'all' && ($module['kind'] ?? 'other') !== $caddy_modules_filter) {
                    return false;
                }
                $search = strtolower(trim($caddy_modules_search));
                if ($search === '') {
                    return true;
                }

                return str_contains(strtolower((string) ($module['id'] ?? '')), $search);
            },
        ));
        $caddyModulesBuilding = (bool) ($caddyModulesBuildState['active'] ?? false);
        $caddyModulesBuildingMessage = (string) ($caddyModulesBuildState['message'] ?? __('Building custom Caddy binary…'));
        $caddyModulesBuildingMode = (string) ($caddyModulesBuildState['mode'] ?? 'rebuild');
    @endphp
    <div
        @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'modules'" x-cloak @endif
        class="space-y-4 mb-6"
        wire:key="caddy-modules-panel"
    >
        <div class="{{ $card }}">
            <div class="flex flex-wrap items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-puzzle-piece class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Modules') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Caddy modules') }}</h3>
                    <p class="mt-1 max-w-3xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Community plugins compile into the Caddy binary via xcaddy — they are not runtime toggles like Apache modules. Add plugins here, rebuild, and validate against your Caddyfile before restart.') }}
                        <a href="https://caddyserver.com/docs/modules" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-forest underline-offset-2 hover:underline">{{ __('Browse all modules') }}</a>
                    </p>
                    @if ($caddy_modules_caddy_version)
                        <p class="mt-1 text-[11px] tabular-nums text-brand-mist">
                            {{ __('Installed binary: :version', ['version' => $caddy_modules_caddy_version]) }}
                            @if ($caddy_modules_custom_binary)
                                <span class="ml-1 inline-flex rounded-full bg-amber-50 px-1.5 py-0.5 font-semibold text-amber-900 ring-1 ring-amber-200">{{ __('Custom build') }}</span>
                            @else
                                <span class="ml-1 inline-flex rounded-full bg-brand-sand/80 px-1.5 py-0.5 font-semibold text-brand-moss ring-1 ring-brand-ink/10">{{ __('Package default') }}</span>
                            @endif
                        </p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        wire:click="openAddCaddyModuleForm"
                        wire:loading.attr="disabled"
                        wire:target="{{ $caddyModulesBusyTargets }}"
                        @disabled($isDeployer || $actionInFlight)
                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5" />
                        {{ __('Add plugin') }}
                    </button>
                    <button
                        type="button"
                        wire:click="refreshCaddyModulesInventory"
                        wire:loading.attr="disabled"
                        wire:target="{{ $caddyModulesBusyTargets }}"
                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="{{ $caddyModulesBusyTargets }}" class="inline-flex">
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                        </span>
                        <span wire:loading wire:target="{{ $caddyModulesBusyTargets }}" class="inline-flex">
                            <x-spinner class="h-3.5 w-3.5" />
                        </span>
                        <span wire:loading wire:target="{{ $caddyModulesBusyTargets }}">{{ __('Refreshing…') }}</span>
                        <span wire:loading.remove wire:target="{{ $caddyModulesBusyTargets }}">{{ __('Refresh inventory') }}</span>
                    </button>
                </div>
            </div>

            <div class="px-6 py-6 sm:px-7">
                @if ($caddyModulesBuilding)
                    <div
                        class="mb-4 rounded-xl border border-amber-200 bg-amber-50/90 px-4 py-4 text-sm text-amber-950 shadow-sm"
                        wire:key="caddy-modules-building-banner"
                        role="status"
                        aria-live="polite"
                    >
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/80 ring-1 ring-amber-200">
                                <x-spinner variant="forest" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">{{ $caddyModulesBuildingMessage }}</p>
                                <p class="mt-1 text-xs text-amber-900/90">
                                    @if ($caddyModulesBuildingMode === 'restore')
                                        {{ __('Reinstalling the distro Caddy package on the server. This usually takes a minute or two.') }}
                                    @else
                                        {{ __('Compiling Caddy with xcaddy on the server — installs Go if needed, validates the new binary, then restarts Caddy. This can take several minutes.') }}
                                    @endif
                                </p>
                                <p class="mt-2 text-xs text-amber-800">{{ __('Live output streams in the console banner above.') }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                @if (! $caddyModulesBuilding)
                    <div
                        wire:loading
                        wire:target="{{ $caddyModulesBusyTargets }}"
                        class="mb-4 rounded-lg border border-sky-200 bg-sky-50/80 px-4 py-3 text-sm text-sky-900"
                    >
                        <span class="inline-flex items-center gap-2 font-medium">
                            <x-spinner variant="forest" class="h-4 w-4" />
                            {{ __('Working on the server…') }}
                        </span>
                        <p class="mt-1 text-xs text-sky-800">{{ __('Rebuilds can take several minutes — output streams in the console banner above.') }}</p>
                    </div>
                @endif

                @if ($caddy_modules_flash)
                    <div wire:loading.remove wire:target="{{ $caddyModulesBusyTargets }}" class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $caddy_modules_flash }}</div>
                @endif
                @if ($caddy_modules_error)
                    <div wire:loading.remove wire:target="{{ $caddyModulesBusyTargets }}" class="mb-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                        <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $caddy_modules_error }}</pre>
                    </div>
                @endif

                {{-- Build manifest --}}
                <div @class([
                    'rounded-xl border bg-white p-4 sm:p-5 transition',
                    'border-amber-200 ring-2 ring-amber-100/80' => $caddyModulesBuilding,
                    'border-brand-ink/10' => ! $caddyModulesBuilding,
                ])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h4 class="text-sm font-semibold text-brand-ink">{{ __('Custom build manifest') }}</h4>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Plugins listed here are passed to `xcaddy build --with …` on rebuild.') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                wire:click="queueCaddyModulesRebuild"
                                wire:loading.attr="disabled"
                                wire:target="{{ $caddyModulesBusyTargets }}"
                                @disabled($isDeployer || $actionInFlight || $caddyModulesBuilding || count($caddy_modules_plugins) === 0)
                                class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                @if ($caddyModulesBuilding && $caddyModulesBuildingMode === 'rebuild')
                                    <x-spinner variant="cream" class="h-3.5 w-3.5" />
                                    {{ __('Building…') }}
                                @else
                                    <span wire:loading.remove wire:target="queueCaddyModulesRebuild" class="inline-flex">
                                        <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5" />
                                    </span>
                                    <span wire:loading wire:target="queueCaddyModulesRebuild" class="inline-flex">
                                        <x-spinner variant="cream" class="h-3.5 w-3.5" />
                                    </span>
                                    <span wire:loading wire:target="queueCaddyModulesRebuild">{{ __('Rebuilding…') }}</span>
                                    <span wire:loading.remove wire:target="queueCaddyModulesRebuild">{{ __('Rebuild Caddy') }}</span>
                                @endif
                            </button>
                            @if ($caddy_modules_custom_binary)
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('queueRestoreCaddyPackageBinary', [], @js(__('Restore apt Caddy package?')), @js(__('Reinstalls the distro Caddy package and clears the custom plugin manifest. Use this to drop back to the default module set.')), @js(__('Restore package')), true)"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $caddyModulesBusyTargets }}"
                                    @disabled($isDeployer || $actionInFlight || $caddyModulesBuilding)
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {{ __('Restore package') }}
                                </button>
                            @endif
                        </div>
                    </div>

                    @if ($caddy_modules_plugins === [])
                        <p class="mt-4 text-sm text-brand-moss">{{ __('No custom plugins queued — the server is running the default apt build.') }}</p>
                    @else
                        <ul class="mt-4 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10">
                            @foreach ($caddy_modules_plugins as $plugin)
                                <li class="px-4 py-4 sm:px-5" wire:key="caddy-plugin-{{ $plugin['path'] }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="font-medium text-brand-ink">{{ $plugin['label'] }}</p>
                                                @if ($plugin['compiled'] ?? false)
                                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 ring-1 ring-emerald-200">{{ __('Compiled') }}</span>
                                                @elseif ($caddy_modules_loaded)
                                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-900 ring-1 ring-amber-200">{{ __('Pending rebuild') }}</span>
                                                @endif
                                            </div>
                                            <p class="mt-0.5 font-mono text-[11px] text-brand-moss">
                                                {{ $plugin['path'] }}{{ ($plugin['version'] ?? '') !== '' ? '@'.$plugin['version'] : '' }}
                                            </p>
                                            @if (($plugin['description'] ?? '') !== '')
                                                <p class="mt-2 max-w-3xl text-xs leading-relaxed text-brand-moss">{{ $plugin['description'] }}</p>
                                            @endif
                                            @if (($plugin['module_ids'] ?? []) !== [])
                                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Module IDs') }}</span>
                                                    @foreach (array_slice($plugin['module_ids'], 0, 4) as $moduleId)
                                                        <span class="inline-flex rounded-md bg-brand-sand/50 px-1.5 py-0.5 font-mono text-[10px] text-brand-ink ring-1 ring-brand-ink/10">{{ $moduleId }}</span>
                                                    @endforeach
                                                    @if (count($plugin['module_ids']) > 4)
                                                        <span class="text-[10px] text-brand-mist">{{ __('+:count more', ['count' => count($plugin['module_ids']) - 4]) }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                            @if (($plugin['repo'] ?? '') !== '' || ($plugin['docs_url'] ?? '') !== '')
                                                <div class="mt-2 flex flex-wrap items-center gap-3 text-[11px]">
                                                    @if (($plugin['repo'] ?? '') !== '')
                                                        <a href="{{ $plugin['repo'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-brand-forest underline-offset-2 hover:underline">
                                                            <x-heroicon-o-code-bracket-square class="h-3.5 w-3.5" aria-hidden="true" />
                                                            {{ __('Repository') }}
                                                        </a>
                                                    @endif
                                                    @if (($plugin['docs_url'] ?? '') !== '')
                                                        <a href="{{ $plugin['docs_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-brand-forest underline-offset-2 hover:underline">
                                                            <x-heroicon-o-book-open class="h-3.5 w-3.5" aria-hidden="true" />
                                                            {{ __('Documentation') }}
                                                        </a>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('removeCaddyModulePlugin', [@js($plugin['path'])], @js(__('Remove plugin: :path', ['path' => $plugin['path']])), @js(__('Remove this plugin from the manifest and rebuild Caddy without it?')), @js(__('Remove & rebuild')), true)"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $caddyModulesBusyTargets }}"
                                            @disabled($isDeployer || $actionInFlight || $caddyModulesBuilding)
                                            class="inline-flex shrink-0 items-center gap-1 rounded-md border border-rose-200 bg-rose-50/40 px-2.5 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                            {{ __('Remove') }}
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @if ($caddy_modules_show_add)
                    <form wire:submit.prevent="requestAddCaddyModule" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Add xcaddy plugin') }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Use the module import path from the docs, e.g. `github.com/caddy-dns/cloudflare`. Optional `@v1.2.3` pin goes in the version field.') }}</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <label class="block sm:col-span-2">
                                <span class="block text-xs font-medium text-brand-ink">{{ __('Module path') }}</span>
                                <input
                                    type="text"
                                    wire:model.lazy="caddy_modules_new.path"
                                    placeholder="github.com/caddy-dns/cloudflare"
                                    class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                    required
                                />
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="block text-xs font-medium text-brand-ink">{{ __('Version pin (optional)') }}</span>
                                <input
                                    type="text"
                                    wire:model.lazy="caddy_modules_new.version"
                                    placeholder="v0.2.1"
                                    class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                />
                            </label>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                            <button type="button" wire:click="cancelAddCaddyModuleForm" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="{{ $caddyModulesBusyTargets }}" @disabled($actionInFlight) class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60">
                                <span wire:loading.remove wire:target="requestAddCaddyModule,confirmActionModal" class="inline-flex"><x-heroicon-o-plus class="h-3.5 w-3.5" /></span>
                                <span wire:loading wire:target="requestAddCaddyModule,confirmActionModal" class="inline-flex"><x-spinner variant="cream" class="h-3.5 w-3.5" /></span>
                                <span wire:loading wire:target="requestAddCaddyModule,confirmActionModal">{{ __('Reviewing…') }}</span>
                                <span wire:loading.remove wire:target="requestAddCaddyModule,confirmActionModal">{{ __('Review & add') }}</span>
                            </button>
                        </div>
                    </form>
                @endif

                @if ($caddyModuleCatalog !== [])
                    <div class="mt-6">
                        <h4 class="text-sm font-semibold text-brand-ink">{{ __('Popular plugins') }}</h4>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('One-click add from common community modules. Already installed or queued plugins are hidden.') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($caddyModuleCatalog as $catalogPath => $catalogMeta)
                                <button
                                    type="button"
                                    wire:click="openConfirmInstallCaddyModule(@js($catalogPath))"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $caddyModulesBusyTargets }}"
                                    @disabled($isDeployer || $actionInFlight || $caddyModulesBuilding)
                                    title="{{ $catalogMeta['description'] ?? '' }}"
                                    class="inline-flex items-center gap-1 rounded-full border border-brand-ink/15 bg-white px-3 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-3 w-3" />
                                    {{ $catalogMeta['label'] ?? $catalogPath }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @elseif ($caddy_modules_loaded)
                    <p class="mt-6 text-sm text-brand-moss">{{ __('All popular plugins are already installed or queued in your build manifest.') }}</p>
                @endif

                <div class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h4 class="text-sm font-semibold text-brand-ink">{{ __('Browse community modules') }}</h4>
                            <p class="mt-1 max-w-2xl text-xs text-brand-moss">
                                {{ __('Search the full Caddy module registry — the same index as') }}
                                <a href="https://caddyserver.com/docs/modules" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-forest underline-offset-2 hover:underline">caddyserver.com/docs/modules</a>.
                                {{ __('Only non-standard plugins that can be compiled with xcaddy are listed.') }}
                            </p>
                        </div>
                        @if (! $caddy_modules_show_browse)
                            <button
                                type="button"
                                wire:click="openCaddyModuleBrowse"
                                wire:loading.attr="disabled"
                                wire:target="{{ $caddyModulesBusyTargets }}"
                                @disabled($isDeployer || $actionInFlight || $caddyModulesBuilding)
                                class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" />
                                {{ __('Browse all modules') }}
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="closeCaddyModuleBrowse"
                                wire:loading.attr="disabled"
                                wire:target="{{ $caddyModulesBusyTargets }}"
                                class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                {{ __('Close browser') }}
                            </button>
                        @endif
                    </div>

                    @if ($caddy_modules_show_browse)
                        <div class="mt-4 space-y-3" wire:key="caddy-module-browser">
                            <input
                                type="search"
                                wire:model.live.debounce.300ms="caddy_modules_browse_search"
                                placeholder="{{ __('Search by module ID, package path, or description…') }}"
                                class="block w-full rounded-md border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                            />

                            @if ($caddy_modules_browse_error)
                                <div class="rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">{{ $caddy_modules_browse_error }}</div>
                            @endif

                            <div wire:loading wire:target="caddy_modules_browse_search,openCaddyModuleBrowse,loadCaddyModulesInventory" class="text-xs text-brand-moss">
                                <span class="inline-flex items-center gap-2">
                                    <x-spinner class="h-3.5 w-3.5" />
                                    {{ __('Loading module registry…') }}
                                </span>
                            </div>

                            <div wire:loading.remove wire:target="caddy_modules_browse_search,openCaddyModuleBrowse,loadCaddyModulesInventory">
                                @if ($caddy_modules_browse_packages === [])
                                    <p class="text-sm text-brand-moss">
                                        @if (trim($caddy_modules_browse_search) !== '')
                                            {{ __('No community modules match your search.') }}
                                        @else
                                            {{ __('No additional community modules to add — everything in the registry is already installed or queued.') }}
                                        @endif
                                    </p>
                                @else
                                    <p class="text-[11px] text-brand-mist">{{ __(':count module package(s) available to add', ['count' => count($caddy_modules_browse_packages)]) }}</p>
                                    <ul class="max-h-96 divide-y divide-brand-ink/10 overflow-auto rounded-lg border border-brand-ink/10 bg-white">
                                        @foreach ($caddy_modules_browse_packages as $browsePackage)
                                            <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-3" wire:key="caddy-browse-{{ md5($browsePackage['path']) }}">
                                                <div class="min-w-0 flex-1">
                                                    <p class="font-medium text-brand-ink">{{ $browsePackage['label'] }}</p>
                                                    <p class="mt-0.5 font-mono text-[11px] text-brand-moss">{{ $browsePackage['path'] }}</p>
                                                    @if ($browsePackage['description'] !== '')
                                                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $browsePackage['description'] }}</p>
                                                    @endif
                                                    @if (($browsePackage['module_ids'] ?? []) !== [])
                                                        <p class="mt-1 text-[10px] text-brand-mist">{{ __('Module IDs: :ids', ['ids' => implode(', ', array_slice($browsePackage['module_ids'], 0, 3)).(count($browsePackage['module_ids']) > 3 ? '…' : '')]) }}</p>
                                                    @endif
                                                </div>
                                                <div class="flex shrink-0 flex-col items-end gap-1.5">
                                                    @if ($browsePackage['repo'] !== '')
                                                        <a href="{{ $browsePackage['repo'] }}" target="_blank" rel="noopener noreferrer" class="text-[10px] font-medium text-brand-forest underline-offset-2 hover:underline">{{ __('Repo') }}</a>
                                                    @endif
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmInstallCaddyModule(@js($browsePackage['path']))"
                                                        wire:loading.attr="disabled"
                                                        wire:target="{{ $caddyModulesBusyTargets }}"
                                                        @disabled($isDeployer || $actionInFlight || $caddyModulesBuilding)
                                                        class="inline-flex items-center gap-1 rounded-md bg-brand-forest px-2.5 py-1 text-[11px] font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        <x-heroicon-o-plus class="h-3 w-3" />
                                                        {{ __('Review & add') }}
                                                    </button>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Installed inventory --}}
                <div class="mt-8 border-t border-brand-ink/10 pt-6">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h4 class="text-sm font-semibold text-brand-ink">{{ __('Compiled modules') }}</h4>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Live output from `caddy list-modules` on this server.') }}</p>
                        </div>
                        @if ($caddy_modules_loaded)
                            <p class="text-xs text-brand-moss">{{ __(':count module IDs', ['count' => count($caddy_modules_installed)]) }}</p>
                        @endif
                    </div>

                    @if (! $caddy_modules_loaded)
                        <p class="mt-4 text-sm text-brand-moss">
                            <span wire:loading wire:target="{{ $caddyModulesBusyTargets }}" class="inline-flex items-center gap-2">
                                <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading module inventory…') }}
                            </span>
                            <span wire:loading.remove wire:target="{{ $caddyModulesBusyTargets }}">
                                {{ __('Open this tab or click Refresh to probe the server.') }}
                            </span>
                        </p>
                    @else
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            @foreach ($caddyModuleFilters as $filterKey => $filterLabel)
                                <button
                                    type="button"
                                    wire:click="setCaddyModulesFilter('{{ $filterKey }}')"
                                    @class([
                                        'inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-[11px] font-medium transition',
                                        'border-brand-forest bg-brand-forest text-brand-cream' => $caddy_modules_filter === $filterKey,
                                        'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $caddy_modules_filter !== $filterKey,
                                    ])
                                >
                                    {{ $filterLabel }}
                                </button>
                            @endforeach
                            <input
                                type="search"
                                wire:model.live.debounce.300ms="caddy_modules_search"
                                placeholder="{{ __('Filter module IDs…') }}"
                                class="ml-auto min-w-[12rem] rounded-md border-brand-ink/15 bg-white px-3 py-1.5 text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                            />
                        </div>

                        @if ($installedFiltered === [])
                            @php
                                $compiledFilterLabel = $caddyModuleFilters[$caddy_modules_filter] ?? $caddy_modules_filter;
                                $compiledSearch = trim($caddy_modules_search);
                                $compiledFiltersActive = $caddy_modules_filter !== 'all' || $compiledSearch !== '';
                                $compiledTotal = count($caddy_modules_installed);
                            @endphp
                            <div
                                class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-6 py-10 text-center sm:px-8"
                                role="status"
                                aria-live="polite"
                            >
                                <span class="mx-auto inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-white text-brand-mist ring-1 ring-brand-ink/10">
                                    @if ($compiledTotal === 0)
                                        <x-heroicon-o-puzzle-piece class="h-5 w-5" aria-hidden="true" />
                                    @else
                                        <x-heroicon-o-funnel class="h-5 w-5" aria-hidden="true" />
                                    @endif
                                </span>

                                @if ($compiledTotal === 0)
                                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No compiled modules reported') }}</p>
                                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                                        {{ __('`caddy list-modules` did not return any module IDs on this server. Refresh after a rebuild or check that Caddy is running the expected binary.') }}
                                    </p>
                                @else
                                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No modules match this filter') }}</p>
                                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                                        @if ($caddy_modules_filter !== 'all' && $compiledSearch !== '')
                                            {{ __('No :kind modules include “:query”. Try another category or broaden your search.', ['kind' => strtolower($compiledFilterLabel), 'query' => $compiledSearch]) }}
                                        @elseif ($caddy_modules_filter !== 'all')
                                            {{ __('This binary has no compiled :kind modules. Switch to All to browse everything in the build, or pick another category.', ['kind' => strtolower($compiledFilterLabel)]) }}
                                        @else
                                            {{ __('No module IDs contain “:query”. Try a shorter search term or clear the filter.', ['query' => $compiledSearch]) }}
                                        @endif
                                    </p>
                                    <p class="mt-3 text-[11px] tabular-nums text-brand-mist">
                                        {{ __('Showing 0 of :total module IDs', ['total' => $compiledTotal]) }}
                                    </p>
                                    @if ($compiledFiltersActive)
                                        <button
                                            type="button"
                                            wire:click="resetCaddyModulesCompiledFilters"
                                            class="mt-4 inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Clear filters') }}
                                        </button>
                                    @endif
                                @endif
                            </div>
                        @else
                            <div class="mt-4 max-h-96 overflow-auto rounded-lg border border-brand-ink/10">
                                <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                                    <thead class="sticky top-0 bg-brand-sand/80 backdrop-blur">
                                        <tr>
                                            <th class="px-3 py-2 font-semibold text-brand-moss">{{ __('Module ID') }}</th>
                                            <th class="px-3 py-2 font-semibold text-brand-moss">{{ __('Kind') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                                        @foreach ($installedFiltered as $module)
                                            <tr wire:key="caddy-mod-{{ md5($module['id']) }}">
                                                <td class="px-3 py-2 font-mono text-brand-ink">{{ $module['id'] }}</td>
                                                <td class="px-3 py-2 capitalize text-brand-moss">{{ $module['kind'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif
