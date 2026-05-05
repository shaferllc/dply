@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
    $engines = ['redis', 'valkey', 'memcached', 'keydb', 'dragonfly'];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="caches"
    :title="__('Caches')"
    :description="__('Install and manage the cache service on this server — Redis, Valkey, Memcached, KeyDB, or Dragonfly.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        @php
            $pollWhileWorking = $activeCacheService && in_array($activeCacheService->status, [
                \App\Models\ServerCacheService::STATUS_PENDING,
                \App\Models\ServerCacheService::STATUS_INSTALLING,
                \App\Models\ServerCacheService::STATUS_UNINSTALLING,
            ], true);
        @endphp
        @if ($pollWhileWorking)
            {{-- Poll the component every 4s while a queued job is in flight so the operator
                 sees status flip from queued → installing → running without manual refresh.
                 This element vanishes the moment status leaves the in-flight set, so polling
                 stops automatically — the workspace is idle when the cache is idle. --}}
            <div wire:poll.4s class="hidden" aria-hidden="true"></div>
        @endif

        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
            <x-server-workspace-tablist :aria-label="__('Cache workspace sections')" class="sm:min-w-0 sm:flex-1">
                <x-server-workspace-tab
                    id="cache-tab-overview"
                    :active="$workspace_tab === 'overview'"
                    wire:click="setWorkspaceTab('overview')"
                >
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Overview') }}
                    </span>
                </x-server-workspace-tab>
                @foreach ($engines as $engine)
                    <x-server-workspace-tab
                        :id="'cache-tab-'.$engine"
                        :active="$workspace_tab === $engine"
                        wire:click="setWorkspaceTab('{{ $engine }}')"
                    >
                        <span class="inline-flex items-center gap-2">
                            {{-- Engine-specific icon. The Redis-family share `bolt` (Redis's logo
                                 is a bolt; valkey/keydb/dragonfly are wire-compatible forks of
                                 the same protocol) and memcached gets `archive-box` to signal
                                 its different role as a slab-allocated key/value cache.       --}}
                            @switch($engine)
                                @case('memcached')
                                    <x-heroicon-o-archive-box class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    @break
                                @case('valkey')
                                    <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-sky-600" aria-hidden="true" />
                                    @break
                                @case('keydb')
                                    <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-violet-600" aria-hidden="true" />
                                    @break
                                @case('dragonfly')
                                    <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                                    @break
                                @default
                                    <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
                            @endswitch
                            {{ $engineLabels[$engine] }}
                            @if ($activeCacheService && $activeCacheService->engine === $engine)
                                @if (in_array($activeCacheService->status, [
                                    \App\Models\ServerCacheService::STATUS_PENDING,
                                    \App\Models\ServerCacheService::STATUS_INSTALLING,
                                    \App\Models\ServerCacheService::STATUS_UNINSTALLING,
                                ], true))
                                    <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700">
                                        <x-spinner variant="forest" />
                                        {{ __('Working') }}
                                    </span>
                                @elseif ($activeCacheService->status === \App\Models\ServerCacheService::STATUS_FAILED)
                                    <span class="inline-flex items-center rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">{{ __('Failed') }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('Active') }}</span>
                                @endif
                            @endif
                        </span>
                    </x-server-workspace-tab>
                @endforeach
                <x-server-workspace-tab
                    id="cache-tab-advanced"
                    :active="$workspace_tab === 'advanced'"
                    wire:click="setWorkspaceTab('advanced')"
                >
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Advanced') }}
                    </span>
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 sm:pb-0.5">
                <button
                    type="button"
                    wire:click="refreshCacheCapabilities"
                    wire:loading.attr="disabled"
                    title="{{ __('Re-run engine detection (cached for a few minutes)') }}"
                    class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="refreshCacheCapabilities">{{ __('Recheck engines') }}</span>
                    <span wire:loading wire:target="refreshCacheCapabilities" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" />
                    </span>
                </button>
            </div>
        </div>

        <div
            class="relative"
            wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150"
            wire:target="setWorkspaceTab,installCacheService,uninstallCacheService,switchCacheService,restartCacheService,stopCacheService,startCacheService,flushCacheService,setAuthPassword,clearAuthPassword,saveCacheConfig,loadCacheConfig,saveCacheMemorySettings,loadCacheMemorySettings,loadCacheClients"
        >
            <div
                class="pointer-events-none absolute inset-x-0 top-0 z-10 hidden items-center justify-center pt-12"
                wire:loading.delay.shortest.flex
                wire:target="setWorkspaceTab,installCacheService,uninstallCacheService,switchCacheService,restartCacheService,stopCacheService,startCacheService,flushCacheService,setAuthPassword,clearAuthPassword,saveCacheConfig,loadCacheConfig,saveCacheMemorySettings,loadCacheMemorySettings,loadCacheClients"
                aria-live="polite"
            >
                <div class="dply-card flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-brand-ink shadow-lg">
                    <x-spinner variant="forest" />
                    <span>{{ __('Loading…') }}</span>
                </div>
            </div>

        <x-server-workspace-tab-panel
            id="cache-panel-overview"
            labelled-by="cache-tab-overview"
            :hidden="$workspace_tab !== 'overview'"
            panel-class="space-y-8"
        >
            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Cache service status') }}</h2>
                @if (! $activeCacheService)
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('No cache service is installed on this server yet. Pick an engine from the tabs above to install one.') }}
                    </p>
                @else
                    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Engine') }}</dt>
                            <dd class="mt-1 text-sm text-brand-ink">{{ $engineLabels[$activeCacheService->engine] ?? ucfirst($activeCacheService->engine) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                            <dd class="mt-1 text-sm text-brand-ink">
                                @switch($activeCacheService->status)
                                    @case(\App\Models\ServerCacheService::STATUS_RUNNING)
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Running') }}</span>
                                        @break
                                    @case(\App\Models\ServerCacheService::STATUS_STOPPED)
                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Stopped') }}</span>
                                        @break
                                    @case(\App\Models\ServerCacheService::STATUS_PENDING)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                            <x-spinner variant="forest" />
                                            {{ __('Queued…') }}
                                        </span>
                                        @break
                                    @case(\App\Models\ServerCacheService::STATUS_INSTALLING)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                            <x-spinner variant="forest" />
                                            {{ __('Installing…') }}
                                        </span>
                                        @break
                                    @case(\App\Models\ServerCacheService::STATUS_UNINSTALLING)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                            <x-spinner variant="forest" />
                                            {{ __('Uninstalling…') }}
                                        </span>
                                        @break
                                    @case(\App\Models\ServerCacheService::STATUS_FAILED)
                                        <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700" title="{{ $activeCacheService->error_message }}">{{ __('Failed') }}</span>
                                        @break
                                    @default
                                        <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-medium text-brand-ink">{{ ucfirst($activeCacheService->status) }}</span>
                                @endswitch
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                            <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $activeCacheService->version ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Port') }}</dt>
                            <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $activeCacheService->port }}</dd>
                        </div>
                    </dl>

                    @if ($activeCacheService->status === \App\Models\ServerCacheService::STATUS_FAILED && filled($activeCacheService->error_message))
                        <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">
                            {{ $activeCacheService->error_message }}
                        </p>
                    @endif

                    <div class="mt-6 flex flex-wrap gap-2">
                        @if (in_array($activeCacheService->status, [\App\Models\ServerCacheService::STATUS_RUNNING, \App\Models\ServerCacheService::STATUS_STOPPED, \App\Models\ServerCacheService::STATUS_FAILED], true))
                            <button type="button" wire:click="restartCacheService" wire:loading.attr="disabled" wire:target="restartCacheService" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                <span wire:loading.remove wire:target="restartCacheService">{{ __('Restart') }}</span>
                                <span wire:loading wire:target="restartCacheService">{{ __('Restarting…') }}</span>
                            </button>
                            @if ($activeCacheService->status !== \App\Models\ServerCacheService::STATUS_STOPPED)
                                <button type="button" wire:click="stopCacheService" wire:loading.attr="disabled" wire:target="stopCacheService" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                    {{ __('Stop') }}
                                </button>
                            @else
                                <button type="button" wire:click="startCacheService" wire:loading.attr="disabled" wire:target="startCacheService" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                    {{ __('Start') }}
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('flushCacheService', [], @js(__('Flush all keys')), @js(__('Drop every key in the cache. App sessions, queued tags, and rate-limit counters will all be reset. Cannot be undone.')), @js(__('Flush all keys')), true)"
                                class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                            >
                                {{ __('Flush all keys') }}
                            </button>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('uninstallCacheService', [], @js(__('Uninstall :engine', ['engine' => $engineLabels[$activeCacheService->engine] ?? $activeCacheService->engine])), @js(__('apt purge will remove the package and its data dirs. Cached entries will be lost.')), @js(__('Uninstall')), true)"
                                class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                            >
                                {{ __('Uninstall') }}
                            </button>
                        @endif
                    </div>
                @endif
            </div>

            @if ($activeCacheService)
                @include('livewire.servers.partials.cache-stats', ['stats' => $cacheStats, 'card' => $card])
                @include('livewire.servers.partials.cache-connection-snippet', ['cacheService' => $activeCacheService, 'card' => $card])

                @if (\App\Models\ServerCacheService::engineSupportsAuth($activeCacheService->engine))
                    <div class="{{ $card }} p-6 sm:p-8">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('AUTH password') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss">
                            @if (filled($activeCacheService->auth_password))
                                {{ __('A password is set. Apps connecting to this engine must send AUTH. Rotate by entering a new value below.') }}
                            @else
                                {{ __('No AUTH password is set. Anything that can reach the loopback port can issue commands. Set one below to require authentication.') }}
                            @endif
                        </p>
                        <form wire:submit="setAuthPassword" class="mt-6 grid max-w-xl grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <div class="flex items-end justify-between gap-2">
                                    <x-input-label for="new_auth_password" :value="__('New password')" class="mb-0" />
                                    <button type="button" wire:click="generateAuthPassword" wire:loading.attr="disabled" wire:target="setAuthPassword,generateAuthPassword" class="mb-1 text-xs font-medium text-brand-forest hover:underline disabled:opacity-50">{{ __('Generate') }}</button>
                                </div>
                                <x-text-input id="new_auth_password" type="password" wire:model="new_auth_password" autocomplete="new-password" class="mt-1 block w-full text-sm" placeholder="••••••••" wire:loading.attr="disabled" wire:target="setAuthPassword" />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('12–256 printable characters. Edit /etc/{engine}/{engine}.conf, restart the service, and verify with the new password — all atomic, with config rollback on verify failure.') }}</p>
                                <x-input-error :messages="$errors->get('new_auth_password')" class="mt-1" />
                            </div>
                            <div class="sm:col-span-2 flex flex-wrap gap-2">
                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="setAuthPassword">
                                    <span wire:loading.remove wire:target="setAuthPassword">{{ filled($activeCacheService->auth_password) ? __('Rotate password') : __('Set password') }}</span>
                                    <span wire:loading wire:target="setAuthPassword">{{ __('Updating…') }}</span>
                                </x-primary-button>
                                @if (filled($activeCacheService->auth_password))
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('clearAuthPassword', [], @js(__('Clear AUTH password')), @js(__('Allow unauthenticated commands on the loopback port? Only safe if no other process can reach this server.')), @js(__('Clear password')), true)"
                                        class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                                    >
                                        {{ __('Clear password') }}
                                    </button>
                                @endif
                            </div>
                        </form>
                    </div>
                @endif

                @if (\App\Models\ServerCacheService::engineSupportsAuth($activeCacheService->engine))
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Memory limits') }}</h2>
                                <p class="mt-2 text-sm text-brand-moss">{{ __('Cap the engine\'s memory usage and pick what happens when the cap is hit. Backed by maxmemory + maxmemory-policy in the config file.') }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if (! $cacheMemoryLoaded && $cacheMemoryError === null)
                                    <button type="button" wire:click="loadCacheMemorySettings" wire:loading.attr="disabled" wire:target="loadCacheMemorySettings" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                        <span wire:loading.remove wire:target="loadCacheMemorySettings">{{ __('Load current settings') }}</span>
                                        <span wire:loading wire:target="loadCacheMemorySettings">{{ __('Loading…') }}</span>
                                    </button>
                                @else
                                    <button type="button" wire:click="hideCacheMemorySettings" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Hide') }}</button>
                                @endif
                            </div>
                        </div>

                        @if ($cacheMemoryError)
                            <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $cacheMemoryError }}</p>
                        @elseif ($cacheMemoryLoaded)
                            <form wire:submit="saveCacheMemorySettings" class="mt-6 grid max-w-xl grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="cache_maxmemory" :value="__('maxmemory')" />
                                    <x-text-input id="cache_maxmemory" wire:model="cache_maxmemory" class="mt-1 block w-full font-mono text-sm" placeholder="256mb" wire:loading.attr="disabled" wire:target="saveCacheMemorySettings" />
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('e.g. 256mb, 1gb, 0 for no limit. Empty removes the directive entirely.') }}</p>
                                    <x-input-error :messages="$errors->get('cache_maxmemory')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="cache_maxmemory_policy" :value="__('maxmemory-policy')" />
                                    <select id="cache_maxmemory_policy" wire:model="cache_maxmemory_policy" wire:loading.attr="disabled" wire:target="saveCacheMemorySettings" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm">
                                        @foreach (\App\Support\Servers\CacheServiceMemoryConfig::POLICIES as $policyOption)
                                            <option value="{{ $policyOption }}">{{ $policyOption }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('What happens when the cap is hit. allkeys-lru is the most common pick for Laravel cache stores.') }}</p>
                                    <x-input-error :messages="$errors->get('cache_maxmemory_policy')" class="mt-1" />
                                </div>
                                <div class="sm:col-span-2 flex flex-wrap gap-2">
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveCacheMemorySettings">
                                        <span wire:loading.remove wire:target="saveCacheMemorySettings">{{ __('Save and restart') }}</span>
                                        <span wire:loading wire:target="saveCacheMemorySettings">{{ __('Saving…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        @endif
                    </div>

                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Connected clients') }}</h2>
                                <p class="mt-2 text-sm text-brand-moss">{{ __('Snapshot of CLIENT LIST. Pulled on demand — refresh to see who\'s connected right now.') }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($cacheClients === null && $cacheClientsError === null)
                                    <button type="button" wire:click="loadCacheClients" wire:loading.attr="disabled" wire:target="loadCacheClients" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                        <span wire:loading.remove wire:target="loadCacheClients">{{ __('Load clients') }}</span>
                                        <span wire:loading wire:target="loadCacheClients">{{ __('Loading…') }}</span>
                                    </button>
                                @else
                                    <button type="button" wire:click="loadCacheClients" wire:loading.attr="disabled" wire:target="loadCacheClients" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">{{ __('Refresh') }}</button>
                                    <button type="button" wire:click="hideCacheClients" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Hide') }}</button>
                                @endif
                            </div>
                        </div>

                        @if ($cacheClientsError)
                            <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $cacheClientsError }}</p>
                        @elseif ($cacheClients !== null)
                            @if (count($cacheClients) === 0)
                                <p class="mt-4 text-sm text-brand-moss">{{ __('No clients connected.') }}</p>
                            @else
                                <div class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10">
                                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                        <thead class="bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wide text-brand-mist">
                                            <tr>
                                                <th class="px-4 py-3">{{ __('ID') }}</th>
                                                <th class="px-4 py-3">{{ __('Address') }}</th>
                                                <th class="px-4 py-3">{{ __('Name') }}</th>
                                                <th class="px-4 py-3">{{ __('Age (s)') }}</th>
                                                <th class="px-4 py-3">{{ __('Idle (s)') }}</th>
                                                <th class="px-4 py-3">{{ __('DB') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-brand-ink/10 bg-white">
                                            @foreach ($cacheClients as $client)
                                                <tr>
                                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-ink">{{ $client['id'] }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-ink">{{ $client['addr'] }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-moss">{{ $client['name'] ?: '—' }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-moss">{{ $client['age'] }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-moss">{{ $client['idle'] }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-moss">{{ $client['db'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif

                <div class="{{ $card }} p-6 sm:p-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Server config file') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss">
                                @if ($cacheConfigEditing)
                                    {{ __('Editing the live config. Save will write, restart the engine, verify it accepts the new config, and roll back if anything goes wrong.') }}
                                @else
                                    {{ __('Read-only view of the engine\'s main config file. Click Edit to change it — Dply backs up, restarts, verifies, and rolls back automatically on failure.') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($cacheConfigEditing)
                                {{-- Edit-mode controls render with the form below. --}}
                            @elseif ($cacheConfigContent === null && $cacheConfigError === null)
                                <button type="button" wire:click="loadCacheConfig" wire:loading.attr="disabled" wire:target="loadCacheConfig" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="loadCacheConfig">{{ __('Load config') }}</span>
                                    <span wire:loading wire:target="loadCacheConfig">{{ __('Loading…') }}</span>
                                </button>
                            @else
                                <button type="button" wire:click="loadCacheConfig" wire:loading.attr="disabled" wire:target="loadCacheConfig" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">{{ __('Refresh') }}</button>
                                @if ($cacheConfigContent !== null)
                                    <button type="button" wire:click="startEditingCacheConfig" wire:loading.attr="disabled" wire:target="startEditingCacheConfig" class="inline-flex items-center gap-2 rounded-lg border border-brand-forest/30 bg-brand-forest/10 px-3 py-1.5 text-sm font-medium text-brand-forest hover:bg-brand-forest/15">
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                        {{ __('Edit') }}
                                    </button>
                                @endif
                                <button type="button" wire:click="hideCacheConfig" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Hide') }}</button>
                            @endif
                        </div>
                    </div>

                    @if ($cacheConfigError)
                        <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $cacheConfigError }}</p>
                    @elseif ($cacheConfigEditing)
                        @if ($cacheConfigPath)
                            <p class="mt-4 break-all font-mono text-xs text-brand-mist">{{ $cacheConfigPath }}</p>
                        @endif
                        <form wire:submit="saveCacheConfig" class="mt-3 space-y-3">
                            <textarea
                                id="cache_config_draft"
                                wire:model="cacheConfigDraft"
                                rows="20"
                                spellcheck="false"
                                class="block w-full rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs leading-relaxed text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                            ></textarea>
                            <x-input-error :messages="$errors->get('cacheConfigDraft')" />
                            <div class="flex flex-wrap gap-2">
                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveCacheConfig">
                                    <span wire:loading.remove wire:target="saveCacheConfig">{{ __('Save and restart') }}</span>
                                    <span wire:loading wire:target="saveCacheConfig">{{ __('Saving…') }}</span>
                                </x-primary-button>
                                <x-secondary-button type="button" wire:click="cancelEditingCacheConfig">{{ __('Cancel') }}</x-secondary-button>
                            </div>
                        </form>
                    @elseif ($cacheConfigContent !== null)
                        @if ($cacheConfigPath)
                            <p class="mt-4 break-all font-mono text-xs text-brand-mist">{{ $cacheConfigPath }}</p>
                        @endif
                        <pre class="mt-3 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs leading-relaxed text-brand-ink whitespace-pre">{{ $cacheConfigContent }}</pre>
                    @endif
                </div>
            @endif
        </x-server-workspace-tab-panel>

        @foreach ($engines as $engine)
            <x-server-workspace-tab-panel
                :id="'cache-panel-'.$engine"
                :labelled-by="'cache-tab-'.$engine"
                :hidden="$workspace_tab !== $engine"
                panel-class="space-y-8"
            >
                @php
                    $isActiveEngine = $activeCacheService && $activeCacheService->engine === $engine;
                    $hasOtherEngine = $activeCacheService && $activeCacheService->engine !== $engine;
                    $probeRunning = (bool) ($capabilities[$engine] ?? false);
                @endphp
                <div class="{{ $card }} p-6 sm:p-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ $engineLabels[$engine] }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ $engineDescriptions[$engine] }}</p>
                        </div>
                        @if ($isActiveEngine)
                            <span class="inline-flex h-fit items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                {{ __('Installed on this server') }}
                            </span>
                        @endif
                    </div>

                    @if ($hasOtherEngine)
                        @php
                            $oldLabel = $engineLabels[$activeCacheService->engine] ?? $activeCacheService->engine;
                            $newLabel = $engineLabels[$engine];
                            $bothSupportAuth = \App\Models\ServerCacheService::engineSupportsAuth($activeCacheService->engine)
                                && \App\Models\ServerCacheService::engineSupportsAuth($engine);
                        @endphp
                        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <p>
                                {{ __(':other is currently installed. Switch will uninstall :other and install :this.', [
                                    'other' => $oldLabel,
                                    'this' => $newLabel,
                                ]) }}
                                @if ($bothSupportAuth)
                                    {{ __('Your AUTH password and maxmemory settings will be carried over.') }}
                                @else
                                    {{ __('Settings will not carry over (different memory model).') }}
                                @endif
                            </p>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('switchCacheService', ['{{ $engine }}'], @js(__('Switch to :engine', ['engine' => $newLabel])), @js(__('Uninstall :other and install :new on this server. Settings carry over only between redis-family engines.', ['other' => $oldLabel, 'new' => $newLabel])), @js(__('Switch')), true)"
                                wire:loading.attr="disabled"
                                wire:target="switchCacheService"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                            >
                                <x-heroicon-o-arrows-right-left class="h-4 w-4" />
                                <span wire:loading.remove wire:target="switchCacheService">{{ __('Switch to :engine', ['engine' => $newLabel]) }}</span>
                                <span wire:loading wire:target="switchCacheService">{{ __('Queueing switch…') }}</span>
                            </button>
                            <p class="text-xs text-brand-moss">
                                {{ __('Apt purges :other and installs :new — usually 5–15 minutes on a small box.', ['other' => $oldLabel, 'new' => $newLabel]) }}
                            </p>
                        </div>
                    @elseif ($isActiveEngine)
                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Probe') }}</dt>
                                <dd class="mt-1 text-sm text-brand-ink">
                                    @if ($probeRunning)
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Reachable') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Not reachable') }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                                <dd class="mt-1 text-sm text-brand-ink">{{ ucfirst($activeCacheService->status) }}</dd>
                            </div>
                        </div>
                        <p class="mt-4 text-sm text-brand-moss">
                            {{ __('Use the Overview tab for restart / stop / uninstall actions.') }}
                        </p>
                    @else
                        <div class="mt-6 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="installCacheService('{{ $engine }}')"
                                wire:loading.attr="disabled"
                                wire:target="installCacheService"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                            >
                                <x-heroicon-o-cloud-arrow-down class="h-4 w-4" />
                                <span wire:loading.remove wire:target="installCacheService">{{ __('Install :engine', ['engine' => $engineLabels[$engine]]) }}</span>
                                <span wire:loading wire:target="installCacheService">{{ __('Queueing install…') }}</span>
                            </button>
                            <p class="text-xs text-brand-moss">
                                {{ __('Runs apt + systemctl over SSH; takes a few minutes on a small box.') }}
                            </p>
                        </div>
                    @endif
                </div>
            </x-server-workspace-tab-panel>
        @endforeach

        <x-server-workspace-tab-panel
            id="cache-panel-advanced"
            labelled-by="cache-tab-advanced"
            :hidden="$workspace_tab !== 'advanced'"
            panel-class="space-y-8"
        >
            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Recent install / uninstall / restart / stop / start / flush events on the cache service for this server.') }}</p>
                <ul class="mt-6 divide-y divide-brand-ink/10 text-sm">
                    @forelse ($cacheAuditEvents as $ev)
                        <li class="py-3">
                            <span class="font-medium text-brand-ink">{{ $ev->event }}</span>
                            <span class="text-brand-mist"> · </span>
                            <span class="text-brand-moss">{{ $ev->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                            @if ($ev->user)
                                <span class="text-brand-mist"> · </span>
                                <span class="text-brand-moss">{{ $ev->user->name }}</span>
                            @endif
                            @if (filled($ev->meta) && is_array($ev->meta) && isset($ev->meta['engine']))
                                <span class="text-brand-mist"> · </span>
                                <span class="font-mono text-xs text-brand-moss">{{ $ev->meta['engine'] }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="py-4 text-brand-moss">{{ __('No events yet.') }}</li>
                    @endforelse
                </ul>
            </div>
        </x-server-workspace-tab-panel>
        </div>
    @else
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before you can use this section.') }}
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</x-server-workspace-layout>
