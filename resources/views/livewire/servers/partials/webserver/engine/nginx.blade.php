            @if ($key === 'nginx' && $engine_subtab === 'modules' && $isActive && $engineHasFullControls($key))
                <div class="space-y-4 mb-6" wire:key="nginx-modules-config">
                    <div class="{{ $card }}">
                        <x-workspace-panel-head
                            dense
                            icon="heroicon-o-puzzle-piece"
                            :title="__('nginx dynamic modules')"
                            :count="$nginx_modules_loaded && $nginx_modules_list !== []
                                ? count(array_filter($nginx_modules_list, fn ($m) => $m['enabled'])).'/'.count($nginx_modules_list)
                                : null"
                            :note="__('Install `libnginx-mod-*` packages and enable loadable modules via modules-enabled — same workflow as Debian/Ubuntu dynamic modules. Each change runs `nginx -t` and reloads; failed validates auto-revert the symlink.')"
                            class="border-b border-brand-ink/10"
                        >
                            <x-slot:actions>
                                <button
                                    type="button"
                                    wire:click="loadNginxModulesConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadNginxModulesConfig"
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadNginxModulesConfig" class="inline-flex">
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    </span>
                                    <span wire:loading wire:target="loadNginxModulesConfig" class="inline-flex">
                                        <x-spinner class="h-3.5 w-3.5" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        <div class="px-4 py-3.5 sm:px-5">
                        @if ($nginx_modules_flash)
                            <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-900">{{ $nginx_modules_flash }}</div>
                        @endif
                        @if ($nginx_modules_error)
                            <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $nginx_modules_error }}</pre>
                            </div>
                        @endif

                        {{-- Idle states get the dashed card treatment (same as
                             Hosts / Upstreams): icon, what's missing, and the
                             action that fixes it. --}}
                        @if (! $nginx_modules_loaded)
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <div
                                    wire:loading.block
                                    wire:target="loadNginxModulesConfig,loadActiveEngineSubtabData"
                                    class="flex flex-col items-center"
                                >
                                    <x-spinner variant="forest" class="h-5 w-5" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Listing modules…') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Reading `nginx -V` and modules-enabled over SSH.') }}</p>
                                </div>

                                <div
                                    wire:loading.remove
                                    wire:target="loadNginxModulesConfig,loadActiveEngineSubtabData"
                                    class="flex flex-col items-center"
                                >
                                    <x-heroicon-o-puzzle-piece class="h-5 w-5 text-brand-mist" aria-hidden="true" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Modules not loaded') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Read this server to list installable and enabled dynamic modules.') }}</p>
                                    <button
                                        type="button"
                                        wire:click="loadNginxModulesConfig"
                                        class="mt-2.5 inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Reload from server') }}
                                    </button>
                                </div>
                            </div>
                        @elseif ($nginx_modules_supports_dynamic)
                            @php
                                $filtered = $nginx_modules_filter === 'all'
                                    ? $nginx_modules_list
                                    : array_values(array_filter($nginx_modules_list, fn ($m) => $m['type'] === $nginx_modules_filter));
                                $enabledCount = count(array_filter($nginx_modules_list, fn ($m) => $m['enabled']));
                                $filters = [
                                    'all' => __('All'),
                                    'tls' => __('TLS'),
                                    'stream' => __('Stream'),
                                    'mail' => __('Mail'),
                                    'geo' => __('Geo'),
                                    'content' => __('Content'),
                                    'auth' => __('Authentication'),
                                    'perf' => __('Perf'),
                                    'security' => __('Security'),
                                    'observability' => __('Observability'),
                                    'other' => __('Other'),
                                ];
                            @endphp
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs text-brand-moss">
                                    {{ __(':enabled of :total dynamic modules enabled', ['enabled' => $enabledCount, 'total' => count($nginx_modules_list)]) }}
                                </p>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($filters as $filterKey => $filterLabel)
                                        <button
                                            type="button"
                                            wire:click="setNginxModulesFilter('{{ $filterKey }}')"
                                            @class([
                                                'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium transition',
                                                'border-brand-forest bg-brand-forest text-brand-cream' => $nginx_modules_filter === $filterKey,
                                                'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $nginx_modules_filter !== $filterKey,
                                            ])
                                        >
                                            {{ $filterLabel }}
                                            @if ($filterKey !== 'all')
                                                <span class="text-2xs opacity-70">{{ count(array_filter($nginx_modules_list, fn ($m) => $m['type'] === $filterKey)) }}</span>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mt-2.5 overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-brand-sand/30 text-xs uppercase tracking-wide text-brand-mist">
                                        <tr>
                                            <th class="px-3 py-1.5 font-medium">{{ __('Module') }}</th>
                                            <th class="px-3 py-1.5 font-medium">{{ __('Package') }}</th>
                                            <th class="px-3 py-1.5 font-medium">{{ __('Type') }}</th>
                                            <th class="px-3 py-1.5 font-medium">{{ __('Status') }}</th>
                                            <th class="px-3 py-1.5 text-right font-medium">{{ __('Action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5">
                                        @foreach ($filtered as $mod)
                                            <tr wire:key="nginx-mod-{{ $mod['name'] }}">
                                                <td class="px-3 py-1.5 font-mono text-brand-ink">{{ $mod['name'] }}</td>
                                                <td class="px-3 py-1.5 font-mono text-xs text-brand-moss">{{ $mod['package'] ?: '—' }}</td>
                                                <td class="px-3 py-1.5">
                                                    <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ $mod['type'] }}</span>
                                                </td>
                                                <td class="px-3 py-1.5">
                                                    @if (! $mod['installed'])
                                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-2xs font-semibold text-amber-800">{{ __('not installed') }}</span>
                                                    @elseif ($mod['enabled'])
                                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-2xs font-semibold text-emerald-700">{{ __('enabled') }}</span>
                                                    @else
                                                        <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-2xs font-semibold text-brand-moss">{{ __('disabled') }}</span>
                                                    @endif
                                                    @if ($mod['protected'])
                                                        <span class="ml-1 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-2xs font-semibold text-amber-700" title="{{ __('Required for dply — disabling is blocked.') }}">{{ __('protected') }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-1.5 text-right">
                                                    @if ($mod['protected'] && $mod['enabled'])
                                                        <span class="text-brand-mist text-xs">—</span>
                                                    @elseif ($mod['enabled'])
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('toggleNginxModule', ['{{ $mod['name'] }}', false], @js(__('Disable module: :name', ['name' => $mod['name']])), @js(__('Remove the modules-enabled symlink for :name? nginx reloads after the change and reverts automatically if `nginx -t` fails.', ['name' => $mod['name']])), @js(__('Disable')), true)"
                                                            @disabled($isDeployer || $actionInFlight)
                                                            class="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-rose-50/30 px-2 py-1 text-xs font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            <x-heroicon-o-no-symbol class="h-3 w-3" />
                                                            {{ __('Disable') }}
                                                        </button>
                                                    @else
                                                        <button
                                                            type="button"
                                                            wire:click="toggleNginxModule('{{ $mod['name'] }}', true)"
                                                            @disabled($isDeployer || $actionInFlight)
                                                            class="inline-flex items-center gap-1 rounded-md border border-brand-forest bg-brand-forest px-2 py-1 text-xs font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            <x-heroicon-o-power class="h-3 w-3" />
                                                            {{ $mod['installed'] ? __('Enable') : __('Install & enable') }}
                                                        </button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if ($nginx_modules_builtins !== [])
                                <details class="mt-2.5 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                                    <summary class="cursor-pointer text-xs font-semibold text-brand-ink">{{ __('Built-in modules (:n)', ['n' => count($nginx_modules_builtins)]) }}</summary>
                                    <p class="mt-1.5 text-xs text-brand-moss">{{ __('Compiled into this nginx binary (`nginx -V`). These cannot be toggled from Dply.') }}</p>
                                    <ul class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach ($nginx_modules_builtins as $builtin)
                                            <li class="rounded-md bg-white px-2 py-1 font-mono text-xs text-brand-ink ring-1 ring-brand-ink/10">{{ $builtin['name'] }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        @else
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <x-heroicon-o-puzzle-piece class="mx-auto h-5 w-5 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('No dynamic module support') }}</p>
                                <p class="mx-auto mt-0.5 max-w-md text-xs leading-relaxed text-brand-moss">
                                    {{ __('This nginx binary was built without loadable-module support, so there is no modules-enabled directory to manage. Everything it can do is compiled in — the live table below lists what `nginx -V` reports.') }}
                                </p>
                            </div>
                        @endif
                        </div>
                    </div>
                </div>
            @endif

            @if ($key === 'nginx' && $engine_subtab === 'hosts' && $isActive && $engineHasFullControls($key))
                <div class="space-y-4 mb-6" wire:key="nginx-custom-hosts-config">
                    <div class="{{ $card }} overflow-hidden">
                        <x-workspace-panel-head
                            dense
                            icon="heroicon-o-globe-alt"
                            :title="__('Custom nginx hosts')"
                            :count="$nginx_custom_hosts_loaded ? (count($nginx_custom_hosts_form) ?: null) : null"
                            :note="__('Add ad-hoc `server {}` blocks as `dply-custom-*.conf` under sites-available. Dply-managed site vhosts are provisioned separately — use this for standalone hostnames or legacy configs.')"
                            class="border-b border-brand-ink/10"
                        >
                            <x-slot:actions>
                                <button
                                    type="button"
                                    wire:click="openAddNginxCustomHostForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-brand-forest px-2 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" />
                                    {{ __('Add host') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadNginxCustomHostsConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadNginxCustomHostsConfig"
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadNginxCustomHostsConfig" class="inline-flex">
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" />
                                    </span>
                                    <span wire:loading wire:target="loadNginxCustomHostsConfig" class="inline-flex">
                                        <x-spinner class="h-3.5 w-3.5" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        <div class="px-4 py-3.5 sm:px-5">

                        @if ($nginx_custom_hosts_flash)
                            <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-900">{{ $nginx_custom_hosts_flash }}</div>
                        @endif
                        @if ($nginx_custom_hosts_error)
                            <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $nginx_custom_hosts_error }}</pre>
                            </div>
                        @endif

                        @if ($nginx_custom_hosts_show_add)
                            <form wire:submit.prevent="submitAddNginxCustomHost" class="mb-3 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add custom host') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Creates sites-available/dply-custom-{slug}.conf and symlinks it into sites-enabled.') }}</p>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Slug') }}</span>
                                        <input type="text" wire:model.lazy="nginx_custom_hosts_new.slug" placeholder="legacy-api" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" required />
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Server names') }}</span>
                                        <input type="text" wire:model.lazy="nginx_custom_hosts_new.server_names" placeholder="api.example.com www.example.com" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" required />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Listen (one per line)') }}</span>
                                        <textarea wire:model.lazy="nginx_custom_hosts_new.listen" rows="3" spellcheck="false" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"></textarea>
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Document root') }}</span>
                                        <input type="text" wire:model.lazy="nginx_custom_hosts_new.root" placeholder="/var/www/example/public" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" required />
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Upstream (optional)') }}</span>
                                        <input type="text" wire:model.lazy="nginx_custom_hosts_new.upstream" placeholder="unix:/run/php/php8.3-fpm.sock" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        <span class="mt-1 block text-xs text-brand-mist">{{ __('fastcgi_pass or proxy_pass target — PHP socket, upstream name, or http:// backend.') }}</span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button type="button" wire:click="cancelAddNginxCustomHostForm" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="submitAddNginxCustomHost" @disabled($actionInFlight) class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60">
                                        <span wire:loading.remove wire:target="submitAddNginxCustomHost" class="inline-flex"><x-heroicon-o-plus class="h-4 w-4" /></span>
                                        <span wire:loading wire:target="submitAddNginxCustomHost" class="inline-flex"><x-spinner variant="cream" class="h-4 w-4" /></span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        {{-- Both idle states get the same dashed card so the panel
                             doesn't collapse to one naked sentence floating in
                             whitespace: icon, one line of what's missing, and the
                             action that fixes it. --}}
                        @if (! $nginx_custom_hosts_loaded)
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <div wire:loading wire:target="loadNginxCustomHostsConfig" class="flex flex-col items-center">
                                    <x-spinner variant="forest" class="h-5 w-5" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Reading custom host files…') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Listing dply-custom-*.conf under sites-available over SSH.') }}</p>
                                </div>
                                <div wire:loading.remove wire:target="loadNginxCustomHostsConfig" class="flex flex-col items-center">
                                    <x-heroicon-o-globe-alt class="h-5 w-5 text-brand-mist" aria-hidden="true" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Custom hosts not loaded') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Read dply-custom-*.conf from this server to edit or remove standalone hosts.') }}</p>
                                    <button
                                        type="button"
                                        wire:click="loadNginxCustomHostsConfig"
                                        class="mt-2.5 inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Reload from server') }}
                                    </button>
                                </div>
                            </div>
                        @elseif ($nginx_custom_hosts_form === [])
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <x-heroicon-o-globe-alt class="mx-auto h-5 w-5 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('No custom hosts') }}</p>
                                <p class="mx-auto mt-0.5 max-w-md text-xs leading-relaxed text-brand-moss">
                                    {{ __('Standalone `server {}` blocks you add here live in dply-custom-*.conf. Site vhosts Dply provisions are managed from the Sites workspace — both show in the live server-block table below.') }}
                                </p>
                                <div class="mt-2.5 flex flex-wrap items-center justify-center gap-1.5">
                                    <button
                                        type="button"
                                        wire:click="openAddNginxCustomHostForm"
                                        @disabled($isDeployer || $actionInFlight)
                                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Add host') }}
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

                    @if ($nginx_custom_hosts_loaded && $nginx_custom_hosts_form !== [])
                        <div class="space-y-4">
                            @foreach ($nginx_custom_hosts_form as $hostSlug => $hostFields)
                                <form wire:submit.prevent="saveNginxCustomHost(@js($hostSlug))" class="{{ $card }} p-5 sm:p-6" wire:key="nginx-custom-host-{{ $hostSlug }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="font-mono text-sm font-semibold text-brand-ink">dply-custom-{{ $hostSlug }}.conf</p>
                                            <p class="mt-0.5 text-xs text-brand-mist">{{ __('Custom host') }}</p>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('removeNginxCustomHost', [@js($hostSlug)], @js(__('Remove custom host: :slug', ['slug' => $hostSlug])), @js(__('Delete sites-available/dply-custom-:slug.conf and its sites-enabled symlink?', ['slug' => $hostSlug])), @js(__('Remove')), true)"
                                            @disabled($isDeployer || $actionInFlight)
                                            class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-xs font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <x-heroicon-o-trash class="h-4 w-4" />
                                            {{ __('Remove') }}
                                        </button>
                                    </div>

                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        <label class="block sm:col-span-2">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Server names') }}</span>
                                            <textarea wire:model.lazy="nginx_custom_hosts_form.{{ $hostSlug }}.server_names" rows="2" spellcheck="false" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white p-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-sage/30"></textarea>
                                        </label>
                                        <label class="block">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Listen') }}</span>
                                            <textarea wire:model.lazy="nginx_custom_hosts_form.{{ $hostSlug }}.listen" rows="3" spellcheck="false" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"></textarea>
                                        </label>
                                        <label class="block">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Document root') }}</span>
                                            <input type="text" wire:model.lazy="nginx_custom_hosts_form.{{ $hostSlug }}.root" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        </label>
                                        <label class="block sm:col-span-2">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Upstream') }}</span>
                                            <input type="text" wire:model.lazy="nginx_custom_hosts_form.{{ $hostSlug }}.upstream" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        </label>
                                    </div>

                                    <div class="mt-4 flex justify-end border-t border-brand-ink/10 pt-3">
                                        <button type="submit" wire:loading.attr="disabled" wire:target="saveNginxCustomHost(@js($hostSlug))" @disabled($isDeployer || $actionInFlight) class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60">
                                            <span wire:loading.remove wire:target="saveNginxCustomHost(@js($hostSlug))" class="inline-flex"><x-heroicon-o-check class="h-4 w-4" /></span>
                                            <span wire:loading wire:target="saveNginxCustomHost(@js($hostSlug))" class="inline-flex"><x-spinner variant="cream" class="h-4 w-4" /></span>
                                            {{ __('Save and reload nginx') }}
                                        </button>
                                    </div>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            @if ($key === 'nginx' && $engine_subtab === 'upstreams' && $isActive && $engineHasFullControls($key))
                @php $nginxPoolParams = \App\Services\Servers\NginxUpstreamsConfig::POOL_PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="nginx-upstreams-config">
                    <div class="{{ $card }} overflow-hidden">
                        <x-workspace-panel-head
                            dense
                            icon="heroicon-o-arrows-right-left"
                            :title="__('nginx upstreams')"
                            :count="$nginx_upstreams_loaded ? (count($nginx_upstreams_form) ?: null) : null"
                            :note="__('Reusable `upstream <name> { server <addr>; … }` pools at the http level of /etc/nginx/nginx.conf. Sites reference them via `proxy_pass http://<name>` or `fastcgi_pass <name>`. Per-site upstream blocks under sites-enabled are managed by the per-site provisioner.')"
                            class="border-b border-brand-ink/10"
                        >
                            <x-slot:actions>
                                <button
                                    type="button"
                                    wire:click="openAddNginxUpstreamForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-brand-forest px-2 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" />
                                    {{ __('Add upstream') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadNginxUpstreamsConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadNginxUpstreamsConfig"
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadNginxUpstreamsConfig" class="inline-flex">
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" />
                                    </span>
                                    <span wire:loading wire:target="loadNginxUpstreamsConfig" class="inline-flex">
                                        <x-spinner class="h-3.5 w-3.5" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        <div class="px-4 py-3.5 sm:px-5">
                        @if ($nginx_upstreams_flash)
                            <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-900">{{ $nginx_upstreams_flash }}</div>
                        @endif
                        @if ($nginx_upstreams_error)
                            <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $nginx_upstreams_error }}</pre>
                            </div>
                        @endif

                        @if ($nginx_upstreams_show_add)
                            <form
                                wire:submit.prevent="submitAddNginxUpstream"
                                class="mb-3 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4"
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
                                        <span class="mt-1 block text-xs text-brand-mist">{{ __('Any nginx server-line: `host:port`, `unix:/path`, optionally followed by `weight=N`, `max_fails=N`, `fail_timeout=Ns`, `backup`, `down`.') }}</span>
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
                                            <x-heroicon-o-plus class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="submitAddNginxUpstream" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        {{-- Idle states get the dashed card treatment (same as
                             Hosts): icon, what's missing, and the action that
                             fixes it — not a lone sentence in whitespace. The
                             loaded-but-empty branch is new; before it, a server
                             with no http-level pools showed nothing at all. --}}
                        @if (! $nginx_upstreams_loaded)
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <div wire:loading wire:target="loadNginxUpstreamsConfig" class="flex flex-col items-center">
                                    <x-spinner variant="forest" class="h-5 w-5" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Reading nginx.conf…') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Parsing http-level upstream blocks over SSH.') }}</p>
                                </div>
                                <div wire:loading.remove wire:target="loadNginxUpstreamsConfig" class="flex flex-col items-center">
                                    <x-heroicon-o-arrows-right-left class="h-5 w-5 text-brand-mist" aria-hidden="true" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Upstreams not loaded') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Read /etc/nginx/nginx.conf from this server to edit pool members and parameters.') }}</p>
                                    <button
                                        type="button"
                                        wire:click="loadNginxUpstreamsConfig"
                                        class="mt-2.5 inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Reload from server') }}
                                    </button>
                                </div>
                            </div>
                        @elseif (empty($nginx_upstreams_form))
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <x-heroicon-o-arrows-right-left class="mx-auto h-5 w-5 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('No shared upstreams') }}</p>
                                <p class="mx-auto mt-0.5 max-w-md text-xs leading-relaxed text-brand-moss">
                                    {{ __('Nothing at the http level of nginx.conf yet. Add a pool to load-balance several backends behind one name — per-site fastcgi_pass sockets keep working without one, and every live pool still shows in the table below.') }}
                                </p>
                                <button
                                    type="button"
                                    wire:click="openAddNginxUpstreamForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="mt-2.5 inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Add upstream') }}
                                </button>
                            </div>
                        @endif
                        </div>
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
                                                <span class="text-xs text-brand-mist">{{ __(':n backend(s)', ['n' => count($payload['servers'] ?? [])]) }}</span>
                                            </span>
                                            <span class="mt-0.5 block truncate text-xs font-mono text-brand-mist">{{ implode(', ', $payload['servers'] ?? []) ?: '—' }}</span>
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-5">
                                        <div class="flex items-center justify-end">
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('removeNginxUpstream', ['{{ $upstreamName }}'], @js(__('Remove upstream: :name', ['name' => $upstreamName])), @js(__('Remove the `:name` upstream block? Sites that still `proxy_pass http://:name` will fail to validate on next reload.', ['name' => $upstreamName])), @js(__('Remove')), true)"
                                                @disabled($isDeployer || $actionInFlight)
                                                class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-xs font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                <x-heroicon-o-trash class="h-4 w-4" />
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
                                            <span class="mt-1 block text-xs text-brand-mist">{{ __('host:port, unix:/path, optionally with weight=N, max_fails=N, fail_timeout=Ns, backup, down.') }}</span>
                                        </label>

                                        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                            @foreach ($nginxPoolParams as $paramKey => $meta)
                                                <label class="block">
                                                    <span class="block text-xs font-medium text-brand-ink">{{ __($meta['label']) }}</span>
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
                 NGINX — TLS CERTIFICATES. Read-only: the live table below lists
                 every ssl_certificate path from `nginx -T` with its expiry.
                 Issuance and renewal are the Certificates module's job, so this
                 strip is signposting rather than a second place to edit certs.
                 ============================================================= --}}
            @if ($key === 'nginx' && $engine_subtab === 'certs' && $isActive && $engineHasFullControls($key))
                <div class="{{ $card }} mb-6 overflow-hidden" wire:key="nginx-certs-context">
                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-lock-closed"
                        :title="__('TLS certificates')"
                        :note="__('Read-only view of what nginx is actually serving. Certificates are issued and renewed per site — nginx only points at the files on disk, so fix an expiry at the source rather than editing paths here.')"
                        class="border-b border-brand-ink/10"
                    >
                        <x-slot:actions>
                            @feature('workspace.cert_inventory')
                                <a
                                    href="{{ route('servers.cert-inventory', $server) }}"
                                    wire:navigate
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    {{ __('Cert inventory') }}
                                    <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                </a>
                            @endfeature
                            <a
                                href="{{ route('servers.sites', $server) }}"
                                wire:navigate
                                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                {{ __('Open Sites') }}
                                <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </a>
                        </x-slot:actions>
                    </x-workspace-panel-head>

                    @php
                        // Expiry summary straight off the probe payload the table
                        // below renders — no extra SSH. Rows carry an ISO-ish
                        // "Not After" string or '?' when openssl couldn't read it.
                        $nginxCertUnits = data_get($server->meta ?? [], 'webserver_live_state.nginx.units.certs', []);
                        $nginxCertSoonest = null;
                        $nginxCertUnreadable = 0;
                        foreach ((array) $nginxCertUnits as $certRow) {
                            $expiry = (string) data_get($certRow, 'expiry', '?');
                            if ($expiry === '' || $expiry === '?') {
                                $nginxCertUnreadable++;
                                continue;
                            }
                            try {
                                $parsed = \Illuminate\Support\Carbon::parse($expiry);
                            } catch (\Throwable) {
                                $nginxCertUnreadable++;
                                continue;
                            }
                            if ($nginxCertSoonest === null || $parsed->lt($nginxCertSoonest)) {
                                $nginxCertSoonest = $parsed;
                            }
                        }
                    @endphp

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-4 py-2.5 text-xs text-brand-moss sm:px-5">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="font-semibold text-brand-ink">{{ trans_choice(':count certificate|:count certificates', count((array) $nginxCertUnits), ['count' => count((array) $nginxCertUnits)]) }}</span>
                            {{ __('in the live config') }}
                        </span>
                        @if ($nginxCertSoonest)
                            @php $nginxCertDays = (int) now()->diffInDays($nginxCertSoonest, false); @endphp
                            <span @class([
                                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 font-semibold ring-1',
                                'bg-rose-50 text-rose-700 ring-rose-200' => $nginxCertDays < 8,
                                'bg-amber-50 text-amber-900 ring-amber-200' => $nginxCertDays >= 8 && $nginxCertDays < 30,
                                'bg-emerald-50 text-emerald-700 ring-emerald-200' => $nginxCertDays >= 30,
                            ])>
                                <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Soonest expiry :date', ['date' => $nginxCertSoonest->format('Y-m-d')]) }}
                                <span class="font-normal opacity-80">· {{ $nginxCertDays >= 0 ? trans_choice(':count day left|:count days left', $nginxCertDays, ['count' => $nginxCertDays]) : __('expired') }}</span>
                            </span>
                        @endif
                        @if ($nginxCertUnreadable > 0)
                            <span class="inline-flex items-center gap-1.5 text-brand-mist">
                                <x-heroicon-o-question-mark-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ trans_choice(':count path openssl could not read|:count paths openssl could not read', $nginxCertUnreadable, ['count' => $nginxCertUnreadable]) }}
                            </span>
                        @endif
                    </div>
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
                    class="{{ $card }} mb-6 overflow-hidden"
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
                    {{-- Hand-rolled rather than x-workspace-panel-head: the whole
                         title row is the collapse toggle. Metrics track the dense
                         head so it lines up with every other panel. --}}
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
                            <span class="shrink-0 text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('nginx global options') }}</span>
                            <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                            <span
                                class="min-w-0 flex-1 truncate text-xs text-brand-mist"
                                title="{{ __('Top of /etc/nginx/nginx.conf — worker count + rlimits, events block, and http block defaults. Site blocks under sites-enabled / conf.d pass through untouched. Save runs `nginx -t` and reloads; a failed validate auto-restores the previous file.') }}"
                            >
                                {{ __('Top of /etc/nginx/nginx.conf — worker count + rlimits, events block, and http block defaults. Site blocks under sites-enabled / conf.d pass through untouched. Save runs `nginx -t` and reloads; a failed validate auto-restores the previous file.') }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="loadNginxGlobalsConfig"
                            wire:loading.attr="disabled"
                            wire:target="loadNginxGlobalsConfig"
                            x-show="expanded"
                            class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadNginxGlobalsConfig" class="inline-flex">
                                <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" />
                            </span>
                            <span wire:loading wire:target="loadNginxGlobalsConfig" class="inline-flex">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                            {{ __('Reload from server') }}
                        </button>
                    </div>

                    <div x-show="expanded" x-cloak class="px-4 py-3.5 sm:px-5">
                        @if ($nginx_globals_flash)
                            <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-900">{{ $nginx_globals_flash }}</div>
                        @endif
                        @if ($nginx_globals_error)
                            <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $nginx_globals_error }}</pre>
                            </div>
                        @endif

                        @if (! $nginx_globals_loaded)
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                                <div wire:loading.block wire:target="loadNginxGlobalsConfig,loadActiveEngineSubtabData" class="flex flex-col items-center">
                                    <x-spinner variant="forest" class="h-5 w-5" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Reading nginx.conf…') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Parsing worker, events, and http defaults over SSH.') }}</p>
                                </div>
                                <div wire:loading.remove wire:target="loadNginxGlobalsConfig,loadActiveEngineSubtabData" class="flex flex-col items-center">
                                    <x-heroicon-o-cpu-chip class="h-5 w-5 text-brand-mist" aria-hidden="true" />
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Global options not loaded') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Read the current worker and http defaults from this server to edit them.') }}</p>
                                    <button
                                        type="button"
                                        wire:click="loadNginxGlobalsConfig"
                                        class="mt-2.5 inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Reload from server') }}
                                    </button>
                                </div>
                            </div>
                        @else
                            <form wire:submit.prevent="saveNginxGlobalsConfig" class="space-y-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Top-level') }}</p>
                                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                        @foreach ($nginxTopParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-xs font-medium text-brand-ink">{{ __($meta['label']) }}</span>
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
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('events { … }') }}</p>
                                    <div class="mt-2 grid gap-3 sm:grid-cols-3">
                                        @foreach ($nginxEventsParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-xs font-medium text-brand-ink">{{ __($meta['label']) }}</span>
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
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('http { … }') }}</p>
                                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                        @foreach ($nginxHttpParams as $paramKey => $meta)
                                            <label class="block">
                                                <span class="block text-xs font-medium text-brand-ink">{{ __($meta['label']) }}</span>
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

                                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="saveNginxGlobalsConfig"
                                        @disabled($isDeployer || $actionInFlight)
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="saveNginxGlobalsConfig" class="inline-flex">
                                            <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" />
                                        </span>
                                        <span wire:loading wire:target="saveNginxGlobalsConfig" class="inline-flex">
                                            <x-spinner variant="cream" class="h-3.5 w-3.5" />
                                        </span>
                                        {{ __('Save and reload nginx') }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            @if ($key === 'nginx' && $engine_subtab === 'cache' && $isActive && $engineHasFullControls($key))
                @php $nginxCacheParams = \App\Services\Servers\NginxEngineCacheConfig::PARAMS; @endphp
                <div class="{{ $card }} mb-6 overflow-hidden" wire:key="nginx-cache-config">
                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-bolt"
                        :title="__('nginx FastCGI / proxy cache')"
                        :note="__('Shared cache zones written to :path. Enable per-site engine HTTP cache in Sites → Caching.', ['path' => $nginx_cache_meta['conf_path'] ?? config('sites.nginx_engine_http_cache_conf')])"
                        class="border-b border-brand-ink/10"
                    >
                        <x-slot:actions>
                            <button type="button" wire:click="loadNginxCacheConfig" wire:loading.attr="disabled" wire:target="loadNginxCacheConfig"
                                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                                <span wire:loading.remove wire:target="loadNginxCacheConfig" class="inline-flex"><x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" /></span>
                                <span wire:loading wire:target="loadNginxCacheConfig" class="inline-flex"><x-spinner class="h-3.5 w-3.5" /></span>
                                {{ __('Reload') }}
                            </button>
                            <button type="button"
                                wire:click="openConfirmActionModal('purgeNginxEngineCacheConfirmed', [], @js(__('Purge engine cache')), @js(__('Remove all FastCGI and proxy cache files on disk and send PURGE requests to local vhosts?')), @js(__('Purge cache')), true)"
                                wire:loading.attr="disabled"
                                @disabled($isDeployer || $actionInFlight || ! $opsReady)
                                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-rose-200 bg-rose-50 px-2 text-xs font-semibold text-rose-800 shadow-sm hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60">
                                <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" />
                                {{ __('Purge all cache') }}
                            </button>
                        </x-slot:actions>
                    </x-workspace-panel-head>

                    <div class="px-4 py-3.5 sm:px-5">
                    @if ($nginx_cache_flash)
                        <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-900">{{ $nginx_cache_flash }}</div>
                    @endif
                    @if ($nginx_cache_error)
                        <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">
                            <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $nginx_cache_error }}</pre>
                        </div>
                    @endif

                    {{-- The idle half of this state is new: previously an unloaded
                         panel rendered a spinner while the read was in flight and
                         nothing at all once it settled, so a failed or skipped
                         auto-load left an empty card with no way back. --}}
                    @if (! $nginx_cache_loaded)
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center">
                            <div wire:loading.block wire:target="loadNginxCacheConfig,loadActiveEngineSubtabData" class="flex flex-col items-center">
                                <x-spinner variant="forest" class="h-5 w-5" />
                                <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Reading cache settings…') }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Parsing the shared cache-zone conf over SSH.') }}</p>
                            </div>
                            <div wire:loading.remove wire:target="loadNginxCacheConfig,loadActiveEngineSubtabData" class="flex flex-col items-center">
                                <x-heroicon-o-bolt class="h-5 w-5 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Cache settings not loaded') }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Read the zone sizes and inactive windows from this server to edit them.') }}</p>
                                <button
                                    type="button"
                                    wire:click="loadNginxCacheConfig"
                                    class="mt-2.5 inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Reload') }}
                                </button>
                            </div>
                        </div>
                    @else
                        <dl class="grid gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3 text-xs sm:grid-cols-2">
                            <div><dt class="font-semibold text-brand-moss">{{ __('FastCGI zone') }}</dt><dd class="mt-0.5 break-all font-mono text-brand-ink">{{ $nginx_cache_meta['fcgi_zone'] ?? '—' }} → {{ $nginx_cache_meta['fcgi_path'] ?? '—' }}</dd></div>
                            <div><dt class="font-semibold text-brand-moss">{{ __('Proxy zone') }}</dt><dd class="mt-0.5 break-all font-mono text-brand-ink">{{ $nginx_cache_meta['proxy_zone'] ?? '—' }} → {{ $nginx_cache_meta['proxy_path'] ?? '—' }}</dd></div>
                        </dl>
                        <form wire:submit.prevent="saveNginxCacheConfig" class="mt-3 space-y-3">
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($nginxCacheParams as $paramKey => $meta)
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                        <input type="number" min="1" wire:model.lazy="nginx_cache_form.{{ $paramKey }}"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm font-medium text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="flex justify-end border-t border-brand-ink/10 pt-3">
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveNginxCacheConfig" @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-60">
                                    <span wire:loading.remove wire:target="saveNginxCacheConfig" class="inline-flex"><x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" /></span>
                                    <span wire:loading wire:target="saveNginxCacheConfig" class="inline-flex"><x-spinner variant="cream" class="h-3.5 w-3.5" /></span>
                                    {{ __('Save and reload nginx') }}
                                </button>
                            </div>
                        </form>
                    @endif
                    </div>
                </div>
            @endif
